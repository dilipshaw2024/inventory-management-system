<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Unit;
use App\Models\ProductUom;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementAllocation;
use App\Models\Promotion;
use App\Models\AuditLog;
use App\Services\InventoryLedgerService;
use App\Services\TaxCalculationService;
use App\Services\UomConversionService;
use App\Services\SerialLifecycleService;
use App\Services\MfaService;
use App\Services\DemandForecastService;
use App\Services\TaxRateResolver;
use App\Services\WebhookService;
use App\Services\AuditIntegrityService;
use App\Services\RecurringJournalService;
use App\Services\WarehouseFulfillmentService;
use App\Services\ProductLifecycleService;
use App\Models\Delivery;
use App\Models\DeliveryOperation;
use App\Models\DeliveryTrackingEvent;
use App\Models\ProductionOrder;
use App\Models\ProductionOperation;
use App\Models\WorkCenter;
use App\Models\InventoryStatusTransfer;
use App\Services\ProductionOperationService;
use App\Services\InventoryLocationHierarchyService;
use App\Services\InventoryLocationCapacityService;
use App\Models\InventoryLocation;
use App\Services\InventoryAnalyticsService;
use App\Services\CustomerCreditService;
use App\Services\SupplierPerformanceScoringService;
use App\Services\SupplierPayablesService;
use App\Services\SupplierPaymentService;
use App\Services\ProductionCostService;
use App\Services\AssetValuationService;
use App\Services\ServiceSlaService;
use App\Services\ProductionCapacityService;
use App\Services\PlanningCalendarService;
use App\Services\Integrations\CarrierTrackingAdapterRegistry;
use App\Services\Integrations\CarrierTrackingAdapter;
use App\Services\Integrations\BankStatementAdapterRegistry;
use App\Services\Integrations\BankStatementAdapter;
use App\Services\ProductionSchedulingService;
use App\Services\PasswordPolicyService;
use App\Services\InventoryStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Illuminate\Validation\Rules\Password;

class ErpFoundationTest extends TestCase
{
    public function test_planning_calendar_skips_weekends_and_holidays(): void
    {
        $date = (new PlanningCalendarService())->addWorkingDaysWithCalendar('2026-09-18', 2, [
            'weekend_days' => [0, 6], 'holidays' => ['2026-09-21'],
        ]);

        self::assertSame('2026-09-23', $date->toDateString());
    }

    public function test_supplier_calendar_can_use_a_different_holiday_schedule(): void
    {
        $date = (new PlanningCalendarService())->addWorkingDaysWithCalendar('2026-09-18', 2, [
            'weekend_days' => [0, 6], 'holidays' => ['2026-09-22'],
        ]);

        self::assertSame('2026-09-23', $date->toDateString());
    }

    public function test_production_slots_preserve_cursor_and_skip_non_working_days(): void
    {
        $service = new ProductionSchedulingService();
        [$start, $end] = $service->slot('2026-09-18 16:00:00', 120, 8);
        self::assertSame('2026-09-21 08:00', $start->format('Y-m-d H:i'));
        self::assertSame('2026-09-21 10:00', $end->format('Y-m-d H:i'));
    }

    public function test_production_slots_honor_configured_shift_window(): void
    {
        [$start, $end] = (new ProductionSchedulingService())->slot('2026-09-18 05:00:00', 120, 8, ['shift_start' => '06:00', 'shift_end' => '14:00']);
        self::assertSame('2026-09-18 06:00', $start->format('Y-m-d H:i'));
        self::assertSame('2026-09-18 08:00', $end->format('Y-m-d H:i'));
    }

    public function test_production_slots_reject_invalid_shift_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProductionSchedulingService())->slot('2026-09-18 08:00:00', 60, 8, ['shift_start' => '16:00', 'shift_end' => '08:00']);
    }

    public function test_zero_duration_production_slot_uses_configured_shift_start(): void
    {
        [$start, $end] = (new ProductionSchedulingService())->slot('2026-09-18 05:00:00', 0, 8, ['shift_start' => '06:30', 'shift_end' => '14:30']);
        self::assertSame('2026-09-18 06:30', $start->format('Y-m-d H:i'));
        self::assertTrue($start->equalTo($end));
    }

    public function test_production_slots_reject_malformed_shift_time(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProductionSchedulingService())->slot('2026-09-18 08:00:00', 60, 8, ['shift_start' => '8am']);
    }

    public function test_status_transfers_between_unavailable_states_do_not_change_physical_stock(): void
    {
        $service = new InventoryStatusService();

        self::assertFalse($service->changesPhysicalStock(new InventoryStatusTransfer([
            'from_status' => 'quarantine', 'to_status' => 'damaged',
        ])));
        self::assertTrue($service->changesPhysicalStock(new InventoryStatusTransfer([
            'from_status' => 'available', 'to_status' => 'quarantine',
        ])));
        self::assertTrue($service->changesPhysicalStock(new InventoryStatusTransfer([
            'from_status' => 'damaged', 'to_status' => 'scrap',
        ])));
    }

    public function test_negative_stock_override_requires_independent_approval(): void
    {
        $method = new \ReflectionMethod(InventoryLedgerService::class, 'hasIndependentApproval');
        $method->setAccessible(true);
        $service = new InventoryLedgerService();

        self::assertTrue($method->invoke($service, new \App\Models\InventoryDocument([
            'status' => 'approved', 'created_by' => 10, 'approved_by' => 20,
        ])));
        self::assertFalse($method->invoke($service, new \App\Models\InventoryDocument([
            'status' => 'approved', 'created_by' => 10, 'approved_by' => 10,
        ])));
        self::assertFalse($method->invoke($service, new \App\Models\InventoryDocument([
            'status' => 'pending', 'created_by' => 10, 'approved_by' => 20,
        ])));
    }

    public function test_password_policy_requires_complexity(): void
    {
        $rules = (new PasswordPolicyService())->rules();
        $passwordRule = end($rules);

        self::assertContains('confirmed', $rules);
        self::assertCount(4, $rules);
        self::assertInstanceOf(Password::class, $passwordRule);
    }

    public function test_base_uom_quantity_is_unchanged(): void
    {
        $product = new Product(['unit_id' => 4]);
        self::assertSame(12.5, (new UomConversionService())->toStock($product, 12.5, 4));
    }

    public function test_uom_conversion_rejects_incompatible_dimensions(): void
    {
        $product = new Product(['unit_id' => 1]);
        $product->setRelation('unit', new Unit(['dimension' => 'weight']));
        $uom = new ProductUom(['unit_id' => 2, 'conversion_to_stock' => 1, 'is_active' => true]);
        $uom->setRelation('unit', new Unit(['dimension' => 'volume']));
        $product->setRelation('uoms', collect([$uom]));

        $this->expectException(\InvalidArgumentException::class);
        (new UomConversionService())->toStock($product, 2, 2);
    }

    public function test_uom_conversion_rejects_wrong_transaction_usage(): void
    {
        $product = new Product(['unit_id' => 1]);
        $product->setRelation('unit', new Unit(['dimension' => 'unit']));
        $uom = new ProductUom(['unit_id' => 2, 'conversion_to_stock' => 12, 'usage' => 'purchase', 'is_active' => true]);
        $uom->setRelation('unit', new Unit(['dimension' => 'unit']));
        $product->setRelation('uoms', collect([$uom]));

        $this->expectException(\InvalidArgumentException::class);
        (new UomConversionService())->toStock($product, 2, 2, 'sales');
    }

    public function test_exclusive_and_inclusive_tax_calculations_are_consistent(): void
    {
        $tax = new TaxCalculationService();
        self::assertSame(18.0, $tax->exclusive(120, 15));
        self::assertSame(['net' => 120.0, 'tax' => 18.0], $tax->inclusive(138, 15));
    }

    public function test_tax_rate_resolver_honors_effective_period_and_legacy_fallback(): void
    {
        $product = new Product(['tax_rate' => 5, 'tax_rate_id' => 1]);
        $product->setRelation('taxRate', (object) [
            'rate' => 18, 'is_active' => true,
            'effective_from' => CarbonImmutable::parse('2026-01-01'),
            'effective_until' => CarbonImmutable::parse('2026-12-31'),
        ]);
        $resolver = new TaxRateResolver();
        self::assertSame(18.0, $resolver->rateFor($product, '2026-06-01'));
        self::assertSame(5.0, $resolver->rateFor($product, '2027-01-01'));
        $product->company_id = 3;
        $product->taxRate->company_id = 4;
        self::assertSame(5.0, $resolver->rateFor($product, '2026-06-01'));
    }

    public function test_tax_rate_resolver_does_not_require_a_linked_record_for_legacy_products(): void
    {
        self::assertSame(7.5, (new TaxRateResolver())->rateFor(new Product(['tax_rate' => 7.5]), '2026-06-01'));
    }

    public function test_inventory_ledger_rejects_non_positive_movements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InventoryLedgerService())->post(1, 'receipt', 0);
    }

    public function test_inventory_movements_reject_update_and_delete_events(): void
    {
        InventoryMovement::setEventDispatcher(new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container()));
        $movement = new InventoryMovement(['id' => 10, 'quantity' => 1]);
        $dispatcher = InventoryMovement::getEventDispatcher();

        try {
            $dispatcher->dispatch('eloquent.updating: '.InventoryMovement::class, $movement);
            self::fail('Updating an inventory movement should be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }

        try {
            $dispatcher->dispatch('eloquent.deleting: '.InventoryMovement::class, $movement);
            self::fail('Deleting an inventory movement should be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('cannot be deleted', strtolower($exception->getMessage()));
        }
    }

    public function test_inventory_movement_allocations_reject_update_and_delete_events(): void
    {
        InventoryMovementAllocation::setEventDispatcher(new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container()));
        $allocation = new InventoryMovementAllocation(['id' => 10, 'quantity' => 1]);
        $dispatcher = InventoryMovementAllocation::getEventDispatcher();

        try {
            $dispatcher->dispatch('eloquent.updating: '.InventoryMovementAllocation::class, $allocation);
            self::fail('Updating a movement allocation should be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }

        try {
            $dispatcher->dispatch('eloquent.deleting: '.InventoryMovementAllocation::class, $allocation);
            self::fail('Deleting a movement allocation should be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('cannot be deleted', strtolower($exception->getMessage()));
        }
    }

    public function test_delivery_tracking_events_reject_update_and_delete_events(): void
    {
        DeliveryTrackingEvent::setEventDispatcher(new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container()));
        $event = new DeliveryTrackingEvent(['id' => 10, 'carrier_status' => 'in_transit']);
        $dispatcher = DeliveryTrackingEvent::getEventDispatcher();

        try {
            $dispatcher->dispatch('eloquent.updating: '.DeliveryTrackingEvent::class, $event);
            self::fail('Updating a delivery tracking event should be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }

        try {
            $dispatcher->dispatch('eloquent.deleting: '.DeliveryTrackingEvent::class, $event);
            self::fail('Deleting a delivery tracking event should be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('cannot be deleted', strtolower($exception->getMessage()));
        }
    }

    public function test_generic_carrier_adapter_normalizes_common_payload_aliases(): void
    {
        $normalized = (new CarrierTrackingAdapterRegistry())->resolve('generic')->normalize([
            'deliveryId' => 42, 'status' => 'in_transit', 'occurred_at' => '2026-09-15 10:00:00',
            'event_id' => 'carrier-event-42', 'location' => 'Mumbai Hub', 'message' => 'Moving',
        ]);

        self::assertSame(42, $normalized['delivery_id']);
        self::assertSame('in_transit', $normalized['carrier_status']);
        self::assertSame('carrier-event-42', $normalized['external_reference']);
        self::assertSame('Mumbai Hub', $normalized['event_location']);
    }

    public function test_carrier_registry_accepts_custom_provider_adapters(): void
    {
        $registry = new CarrierTrackingAdapterRegistry();
        $adapter = new class implements CarrierTrackingAdapter {
            public function key(): string { return 'test-carrier'; }
            public function normalize(array $payload): array { return ['delivery_id' => 1, 'carrier_status' => 'in_transit', 'event_at' => '2026-09-15 10:00:00']; }
        };
        $registry->register($adapter);

        self::assertSame('in_transit', $registry->resolve('TEST-CARRIER')->normalize([])['carrier_status']);
    }

    public function test_generic_bank_adapter_normalizes_common_payload_aliases(): void
    {
        $normalized = (new BankStatementAdapterRegistry())->resolve('GENERIC')->normalize([
            'account_id' => 7, 'date' => '2026-09-15', 'amount' => '-125.50', 'transaction_id' => 'BANK-7', 'narration' => 'Supplier payment',
        ]);

        self::assertSame(7, $normalized['bank_account_id']);
        self::assertSame('2026-09-15', $normalized['transaction_date']);
        self::assertSame('BANK-7', $normalized['external_reference']);
    }

    public function test_bank_registry_accepts_custom_provider_adapters(): void
    {
        $registry = new BankStatementAdapterRegistry();
        $adapter = new class implements BankStatementAdapter {
            public function key(): string { return 'test-bank'; }
            public function normalize(array $payload): array { return ['bank_account_id' => 1, 'transaction_date' => '2026-09-15', 'amount' => 1]; }
        };
        $registry->register($adapter);

        self::assertSame(1, $registry->resolve('TEST-BANK')->normalize([])['bank_account_id']);
    }

    public function test_promotion_usage_limit_is_enforced(): void
    {
        $service = new \App\Services\PromotionService();
        $available = new Promotion(['is_active' => true, 'usage_limit' => 2, 'usage_count' => 1]);
        $service->assertRedeemable($available);

        $this->expectException(\RuntimeException::class);
        $service->assertRedeemable(new Promotion(['is_active' => true, 'usage_limit' => 1, 'usage_count' => 1]));
    }

    public function test_serial_lifecycle_is_noop_for_untracked_products(): void
    {
        $serials = (new SerialLifecycleService())->issue(new Product(['tracking_type' => 'none']), 3);
        self::assertCount(0, $serials);
    }

    public function test_serial_receipt_rejects_non_serial_products(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SerialLifecycleService())->receive(new Product(['tracking_type' => 'none', 'name' => 'Bulk item']), 'SER-001');
    }

    public function test_totp_verification_accepts_standard_vector_and_rejects_invalid_code(): void
    {
        $mfa = new MfaService();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        self::assertTrue($mfa->verify($secret, '287082', 59));
        self::assertFalse($mfa->verify($secret, '000000', 59));
    }

    public function test_demand_forecast_confidence_band_is_non_negative_and_deterministic(): void
    {
        $band = (new DemandForecastService())->confidenceBand(new Collection([2, 4, 6, 8]), 5, 4, 20);
        self::assertEqualsWithDelta(9.879, $band['low'], 0.001);
        self::assertEqualsWithDelta(30.121, $band['high'], 0.001);
    }

    public function test_demand_forecast_can_apply_weekly_seasonality(): void
    {
        $history = collect(array_fill(0, 14, 1.0));
        $history[0] = 10.0;
        $history[7] = 10.0;
        $forecast = (new DemandForecastService())->seasonalForecast(
            $history,
            CarbonImmutable::parse('2026-01-05'),
            CarbonImmutable::parse('2026-01-19'),
            7,
            1.0
        );
        self::assertEqualsWithDelta(16.0, $forecast, 0.0001);
    }

    public function test_webhook_signature_is_hmac_sha256(): void
    {
        self::assertSame('sha256='.hash_hmac('sha256', '{"event":"stock.updated"}', 'secret'), (new WebhookService())->signature('{"event":"stock.updated"}', 'secret'));
    }

    public function test_audit_integrity_hash_detects_changes(): void
    {
        $integrity = new AuditIntegrityService();
        $audit = new AuditLog(['id' => 7, 'company_id' => 3, 'action' => 'stock.adjusted', 'new_values' => ['quantity' => 5]]);
        $audit->integrity_hash = $integrity->hash($audit, null);
        self::assertTrue($integrity->valid($audit, null));
        $audit->action = 'stock.deleted';
        self::assertFalse($integrity->valid($audit, null));
    }

    public function test_recurring_journal_schedule_advances_without_month_overflow(): void
    {
        $service = new RecurringJournalService();
        self::assertSame('2026-02-28', $service->nextRunDate(CarbonImmutable::parse('2026-01-31'), 'monthly')->toDateString());
        self::assertSame('2026-02-14', $service->nextRunDate(CarbonImmutable::parse('2026-02-07'), 'weekly')->toDateString());
        self::assertSame('2026-02-10', $service->nextRunDate(CarbonImmutable::parse('2026-02-07'), 'daily', 3)->toDateString());
    }

    public function test_production_operation_cost_uses_rates_and_scales_partial_receipts(): void
    {
        $operation = new ProductionOperation(['actual_setup_minutes' => 30, 'actual_run_minutes' => 60]);
        $operation->setRelation('workCenter', new WorkCenter(['labor_rate' => 20, 'machine_rate' => 10]));
        self::assertEqualsWithDelta(22.5, (new ProductionCostService())->operationCost([$operation], 50, 100), 0.0001);
    }

    public function test_production_operation_cost_falls_back_to_routing_minutes(): void
    {
        $operation = new ProductionOperation(['actual_setup_minutes' => null, 'actual_run_minutes' => null]);
        $operation->setRelation('workCenter', new WorkCenter(['labor_rate' => 60, 'machine_rate' => 0]));
        $operation->setRelation('routingOperation', new \App\Models\RoutingOperation(['setup_minutes' => 10, 'run_minutes' => 20]));
        self::assertEqualsWithDelta(30.0, (new ProductionCostService())->operationCost([$operation], 100, 100), 0.0001);
    }

    public function test_bom_snapshot_expansion_handles_nested_scrap(): void
    {
        $snapshot = ['id' => 1, 'output_quantity' => 1, 'lines' => [['component_product_id' => 10, 'quantity' => 2, 'scrap_percent' => 10, 'child' => ['id' => 2, 'output_quantity' => 1, 'lines' => [['component_product_id' => 20, 'quantity' => 3, 'scrap_percent' => 0, 'child' => null]], 'byproducts' => []]]], 'byproducts' => []];
        $requirements = (new \App\Services\BomExplosionService())->leafRequirementsFromSnapshot($snapshot, 1);
        self::assertEqualsWithDelta(6.6, $requirements[20], 0.0001);
    }

    public function test_asset_valuation_calculates_straight_line_book_value(): void
    {
        $asset = (object) [
            'id' => 7, 'acquisition_cost' => 1200, 'salvage_value' => 200,
            'useful_life_months' => 10, 'in_service_date' => CarbonImmutable::parse('2026-01-15'),
            'depreciation_method' => 'straight_line',
        ];
        $valuation = (new AssetValuationService())->snapshot($asset, CarbonImmutable::parse('2026-04-15'));
        self::assertSame(3, $valuation['months_elapsed']);
        self::assertEqualsWithDelta(300.0, $valuation['depreciation_to_date'], 0.0001);
        self::assertEqualsWithDelta(900.0, $valuation['book_value'], 0.0001);
    }

    public function test_asset_valuation_does_not_depreciate_before_service_date_or_below_salvage(): void
    {
        $asset = (object) [
            'acquisition_cost' => 500, 'salvage_value' => 100, 'useful_life_months' => 4,
            'in_service_date' => CarbonImmutable::parse('2026-06-01'),
        ];
        $service = new AssetValuationService();
        self::assertSame(0.0, $service->snapshot($asset, CarbonImmutable::parse('2026-05-31'))['depreciation_to_date']);
        self::assertEqualsWithDelta(100.0, $service->snapshot($asset, CarbonImmutable::parse('2027-01-01'))['book_value'], 0.0001);
    }

    public function test_asset_valuation_supports_declining_balance_method(): void
    {
        $asset = (object) [
            'acquisition_cost' => 1000, 'salvage_value' => 100, 'useful_life_months' => 10,
            'in_service_date' => CarbonImmutable::parse('2026-01-01'), 'depreciation_method' => 'declining_balance',
        ];
        $valuation = (new AssetValuationService())->snapshot($asset, CarbonImmutable::parse('2026-03-01'));
        self::assertSame('declining_balance', $valuation['method']);
        self::assertEqualsWithDelta(360.0, $valuation['depreciation_to_date'], 0.0001);
        self::assertEqualsWithDelta(640.0, $valuation['book_value'], 0.0001);
    }

    public function test_asset_valuation_supports_units_of_production_method(): void
    {
        $asset = (object) [
            'acquisition_cost' => 1000, 'salvage_value' => 100, 'depreciation_method' => 'units_of_production',
            'depreciation_units_total' => 9000, 'depreciation_units_used' => 2700,
            'in_service_date' => CarbonImmutable::parse('2026-01-01'),
        ];
        $valuation = (new AssetValuationService())->snapshot($asset, CarbonImmutable::parse('2026-01-02'));
        self::assertEqualsWithDelta(270.0, $valuation['depreciation_to_date'], 0.0001);
        self::assertEqualsWithDelta(730.0, $valuation['book_value'], 0.0001);
        $asset->depreciation_units_used = 9000;
        self::assertTrue((new AssetValuationService())->snapshot($asset)['fully_depreciated']);
    }

    public function test_service_sla_classification_is_deterministic(): void
    {
        $service = new ServiceSlaService();
        $now = CarbonImmutable::parse('2026-09-12 10:00:00');
        $due = $now->addHour();
        self::assertSame('not_tracked', $service->status(null, null, 'open', $now));
        self::assertSame('due', $service->status($due, null, 'open', $now));
        self::assertSame('met', $service->status($due, $now, 'assigned', $now));
        self::assertSame('breached', $service->status($now->subMinute(), null, 'open', $now));
        self::assertSame('closed', $service->status($due, null, 'resolved', $now));
    }

    public function test_production_capacity_calculation_scales_setup_and_run_time(): void
    {
        $operations = [
            (object) ['planned_quantity' => 10, 'routingOperation' => (object) ['setup_minutes' => 30, 'run_minutes' => 6]],
            (object) ['planned_quantity' => 5, 'routingOperation' => (object) ['setup_minutes' => 0, 'run_minutes' => 12]],
        ];
        $service = new ProductionCapacityService();
        self::assertEqualsWithDelta(2.5, $service->plannedLoadHours($operations), 0.0001);
        self::assertSame(125.0, $service->utilizationPercent(2.5, 2));
        self::assertNull($service->utilizationPercent(2.5, 0));
    }

    public function test_inventory_analytics_rejects_unknown_abc_basis_before_querying(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InventoryAnalyticsService())->rowsForCompany(1, '2026-01-01', '2026-01-31', null, 'margin');
    }

    public function test_dispatch_requires_completed_picking_and_packing(): void
    {
        $delivery = new Delivery(['status' => 'pending']);
        $delivery->setRelation('operations', collect([
            new DeliveryOperation(['operation_type' => 'pick', 'status' => 'completed']),
            new DeliveryOperation(['operation_type' => 'pack', 'status' => 'pending']),
        ]));

        $this->expectException(\RuntimeException::class);
        (new WarehouseFulfillmentService())->assertReadyForDispatch($delivery);
    }

    public function test_product_lifecycle_blocks_discontinued_sales_and_allows_active_sales(): void
    {
        $service = new ProductLifecycleService();
        $service->assertSellable(new Product(['status' => 1, 'lifecycle_status' => 'active', 'can_sell' => true, 'name' => 'Active item']));
        $this->expectException(\RuntimeException::class);
        $service->assertSellable(new Product(['status' => 1, 'lifecycle_status' => 'discontinued', 'can_sell' => true, 'name' => 'Old item']));
    }

    public function test_product_lifecycle_availability_requires_active_master(): void
    {
        $service = new ProductLifecycleService();
        self::assertTrue($service->isAvailable(new Product(['status' => 1, 'lifecycle_status' => 'active'])));
        self::assertFalse($service->isAvailable(new Product(['status' => 1, 'lifecycle_status' => 'blocked'])));
    }

    public function test_product_lifecycle_rejects_non_stock_manufacturing_items(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ProductLifecycleService())->assertStockManaged(new Product(['status' => 1, 'lifecycle_status' => 'active', 'is_stock_item' => false, 'name' => 'Consulting']));
    }

    public function test_product_lifecycle_rejects_archiving_product_with_stock(): void
    {
        $product = new Product(['status' => 1, 'lifecycle_status' => 'active', 'is_stock_item' => true, 'quantity' => 4, 'name' => 'Stocked item']);
        $this->expectException(\RuntimeException::class);
        (new ProductLifecycleService())->assertTransitionAllowed($product, ['lifecycle_status' => 'archived']);
    }

    public function test_supplier_payables_status_classifies_current_overdue_and_escalated(): void
    {
        $service = new SupplierPayablesService();
        self::assertSame('current', $service->collectionStatus(0, 0));
        self::assertSame('overdue', $service->collectionStatus(100, 30));
        self::assertSame('escalated', $service->collectionStatus(100, 90));
    }

    public function test_supplier_due_date_uses_supplier_terms_when_invoice_due_date_is_empty(): void
    {
        self::assertSame('2026-02-09', (new SupplierPayablesService())->dueDateFromValues(null, '2026-01-10', '2026-01-10', 30)->toDateString());
    }

    public function test_supplier_explicit_due_date_takes_precedence_over_payment_terms(): void
    {
        self::assertSame('2026-01-20', (new SupplierPayablesService())->dueDateFromValues('2026-01-20', '2026-01-10', '2026-01-10', 30)->toDateString());
    }

    public function test_production_completion_requires_routing_operations(): void
    {
        $order = new ProductionOrder();
        $order->setRelation('operations', collect([
            new ProductionOperation(['status' => 'in_progress']),
        ]));

        $this->expectException(\RuntimeException::class);
        (new ProductionOperationService())->assertReadyForCompletion($order);
    }

    public function test_inventory_location_hierarchy_accepts_only_the_preceding_parent_level(): void
    {
        $service = new InventoryLocationHierarchyService();
        $service->assertParentLevel('rack', 'zone');
        $service->assertParentLevel('shelf', 'rack');
        $service->assertParentLevel('bin', 'shelf');

        $this->expectException(InvalidArgumentException::class);
        $service->assertParentLevel('bin', 'zone');
    }

    public function test_physical_location_capacity_rejects_overweight_receipts(): void
    {
        $location = new InventoryLocation(['code' => 'BIN-01', 'capacity' => 20, 'capacity_weight_kg' => 10, 'capacity_volume_m3' => 1]);
        $existing = new InventoryMovement(['movement_type' => 'receipt', 'quantity' => 5]);
        $existingProduct = new Product(['weight_kg' => 1, 'length_m' => 0.1, 'width_m' => 0.1, 'height_m' => 0.1]);
        $existing->setRelation('product', $existingProduct);
        $location->setRelation('movements', collect([$existing]));

        $this->expectException(\RuntimeException::class);
        (new InventoryLocationCapacityService())->assertCanReceive($location, new Product(['weight_kg' => 2, 'length_m' => 0.1, 'width_m' => 0.1, 'height_m' => 0.1]), 3);
    }

    public function test_location_occupancy_accounts_for_quarantine_transitions(): void
    {
        $location = new InventoryLocation(['code' => 'BIN-02']);
        $product = new Product(['weight_kg' => 0, 'length_m' => 0, 'width_m' => 0, 'height_m' => 0]);
        $receipt = new InventoryMovement(['movement_type' => 'receipt', 'quantity' => 10]);
        $quarantineIn = new InventoryMovement(['movement_type' => 'quarantine_in', 'quantity' => 4]);
        $quarantineOut = new InventoryMovement(['movement_type' => 'quarantine_out', 'quantity' => 1]);
        $receipt->setRelation('product', $product);
        $quarantineIn->setRelation('product', $product);
        $quarantineOut->setRelation('product', $product);
        $location->setRelation('movements', collect([
            $receipt,
            $quarantineIn,
            $quarantineOut,
        ]));

        self::assertSame(7.0, (new InventoryLocationCapacityService())->occupied($location)['quantity']);
    }

    public function test_customer_credit_collection_status_is_deterministic(): void
    {
        $service = new CustomerCreditService();
        $customer = new \App\Models\Customer(['credit_hold' => false]);
        self::assertSame('current', $service->collectionStatus($customer, 0, 0));
        self::assertSame('overdue', $service->collectionStatus($customer, 10, 30));
        self::assertSame('escalated', $service->collectionStatus($customer, 10, 90));
        $customer->credit_hold = true;
        self::assertSame('on_hold', $service->collectionStatus($customer, 10, 30));
    }

    public function test_supplier_score_uses_bounded_weighted_metrics(): void
    {
        $score = (new SupplierPerformanceScoringService())->score([
            'fill_rate' => 100, 'on_time_rate' => 80, 'quality_pass_rate' => 90, 'price_variance' => 5,
        ]);
        self::assertSame(91.5, $score);
        self::assertSame(0.0, (new SupplierPerformanceScoringService())->score([
            'fill_rate' => 0, 'on_time_rate' => 0, 'quality_pass_rate' => 0, 'price_variance' => 200,
        ]));
    }

    public function test_customer_payment_allocation_rejects_currency_mismatch(): void
    {
        $this->expectException(\RuntimeException::class);
        (new \App\Services\CustomerPaymentAllocationService())->assertCurrencyCompatible('USD', 'EUR');
    }

    public function test_supplier_payment_allocation_accepts_matching_currency(): void
    {
        (new SupplierPaymentService())->assertCurrencyCompatible('usd', 'USD');
        self::assertTrue(true);
    }

    public function test_approval_escalation_notification_contains_routing_data(): void
    {
        $notification = new \App\Notifications\ApprovalEscalationNotification([
            'document_type' => \App\Models\PurchaseOrder::class,
            'document_id' => 42,
            'approval_step' => 2,
            'required_permission' => 'purchasing.approve',
        ]);

        self::assertSame([
            'document_type' => \App\Models\PurchaseOrder::class,
            'document_id' => 42,
            'approval_step' => 2,
            'required_permission' => 'purchasing.approve',
            'alert_type' => 'approval.escalation',
        ], $notification->toDatabase(new \App\Models\User()));
    }

    public function test_approval_rejection_notification_contains_reason(): void
    {
        $notification = new \App\Notifications\ApprovalRejectionNotification([
            'document_type' => \App\Models\PurchaseOrder::class,
            'document_id' => 7,
            'action' => 'purchase_order.rejected',
            'rejection_reason' => 'Supplier quote expired',
            'rejected_by' => 9,
        ]);

        self::assertSame('approval.rejected', $notification->toDatabase(new \App\Models\User())['alert_type']);
        self::assertSame('Supplier quote expired', $notification->toDatabase(new \App\Models\User())['rejection_reason']);
    }
}
