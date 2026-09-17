<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use App\Services\PlanningCalendarService;
use App\Services\ReplenishmentPlanningService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GeneratePurchaseOrders extends Command
{
    protected $signature = 'erp:planning:generate-purchase-orders {--company= : Limit generation to a company ID} {--dry-run : Report proposals without creating purchase orders}';
    protected $description = 'Generate draft purchase orders for approved replenishment shortages.';

    public function handle(ReplenishmentPlanningService $planning): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))->pluck('id');
        foreach ($companies as $companyId) {
            $proposals = $planning->proposalsForCompany((int) $companyId);
            if ($proposals->isEmpty()) {
                $this->info('Company '.$companyId.': no purchase shortage found.');
                continue;
            }
            if ($this->option('dry-run')) {
                $this->info('Company '.$companyId.': '.$proposals->count().' proposal(s).');
                foreach ($proposals as $proposal) $this->line('  '.$proposal['product']->name.' — '.$proposal['quantity'].' for supplier '.$proposal['supplier_id']);
                continue;
            }
            $orders = DB::transaction(function () use ($proposals, $companyId): Collection {
                $remaining = $proposals->map(function (array $proposal) use ($companyId): ?array {
                    // The planning snapshot may have become stale while another
                    // scheduler/API request was creating an open purchase order.
                    // Lock the product first so the no-existing-line case is also
                    // serialized; then lock open lines and re-net immediately
                    // before creating drafts so the same shortage is not ordered twice.
                    $product = Product::withoutGlobalScope('company')
                        ->whereKey($proposal['product_id'])
                        ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                        ->lockForUpdate()->first();
                    if (!$product) return null;
                    $openLines = PurchaseOrderLine::where('product_id', $proposal['product_id'])
                        ->whereHas('purchaseOrder', fn ($query) => $query
                            ->where('company_id', $companyId)
                            ->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))
                        ->lockForUpdate()
                        ->get();
                    $open = (float) $openLines->sum(fn (PurchaseOrderLine $line): float => max(0, (float) $line->ordered_qty - (float) $line->received_qty));
                    $proposal['quantity'] = max(0, (float) $proposal['quantity'] - $open);
                    return $proposal['quantity'] > 0 ? $proposal : null;
                })->filter()->values();

                return $remaining->groupBy('supplier_id')->map(function (Collection $lines, $supplierId) use ($companyId): PurchaseOrder {
                    $planningDays = (int) $lines->max('planning_days');
                    $order = PurchaseOrder::create([
                        'company_id' => $companyId,
                        'supplier_id' => $supplierId,
                        'date' => now()->toDateString(),
                        'expected_date' => app(PlanningCalendarService::class)->addWorkingDaysForSupplier(now()->toDateString(), $planningDays, $lines->first()['product']->supplier, (int) $companyId)->toDateString(),
                        'description' => 'Generated from scheduled replenishment proposals; approval required.',
                        'po_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), (int) $companyId),
                        'created_by' => null,
                        'status' => 'draft',
                    ]);
                    foreach ($lines as $line) {
                        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $line['product']->id, 'ordered_qty' => $line['quantity'], 'unit_price' => $line['unit_price']]);
                    }
                    app(AuditService::class)->record('purchase_order.created_from_scheduled_replenishment', $order, null, $order->toArray());
                    return $order;
                });
            });
            $this->info('Company '.$companyId.': created '.$orders->count().' draft purchase order(s).');
        }
        return self::SUCCESS;
    }

}
