<?php

namespace App\Console\Commands;

use App\Models\BillOfMaterial;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\ProductionOrder;
use App\Services\AuditService;
use App\Services\BomExplosionService;
use App\Services\InventoryAvailabilityService;
use App\Services\NumberingSequenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateProductionOrders extends Command
{
    protected $signature = 'erp:planning:generate-production-orders {--company= : Limit generation to a company ID} {--dry-run : Report proposed orders without creating them}';
    protected $description = 'Create draft production orders for finished goods below their planning target.';

    public function handle(): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->pluck('id');

        foreach ($companies as $companyId) {
            $boms = BillOfMaterial::withoutGlobalScopes()
                ->with('product')
                ->where('is_active', true)->where('approval_status', 'approved')
                ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                ->whereHas('product', fn ($query) => $query->withoutGlobalScope('company')->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()->toDateString()))
                ->get();
            [$openPurchase, $openProduction] = $this->openSupplyForCompany((int) $companyId);
            $created = 0;

            foreach ($boms as $bom) {
                if (!$bom->product) continue;
                $target = $bom->product->max_stock !== null
                    ? (float) $bom->product->max_stock
                    : max((float) $bom->product->reorder_level * 2, (float) $bom->output_quantity);
                $available = app(InventoryAvailabilityService::class)->available($bom->product, true, null, (int) $companyId);

                $openPurchaseQuantity = (float) ($openPurchase[$bom->product_id] ?? 0);
                $openProductionQuantity = (float) ($openProduction[$bom->product_id] ?? 0);
                $quantity = max(0, $target - $available - $openPurchaseQuantity - $openProductionQuantity);
                if ($quantity <= 0.000001) continue;

                if ($this->option('dry-run')) {
                    $this->line('Company '.$companyId.': '.$bom->product->name.' — '.$quantity.' unit(s) from BOM '.$bom->code);
                    continue;
                }

                if ($this->createOrder($bom, (int) $companyId, $target)) $created++;
            }

            $this->info('Company '.$companyId.': '.($this->option('dry-run') ? 'dry-run completed.' : $created.' draft production order(s) created.'));
        }

        return self::SUCCESS;
    }

    private function createOrder(BillOfMaterial $bom, int $companyId, float $target): bool
    {
        return DB::transaction(function () use ($bom, $companyId, $target): bool {
            $product = Product::withoutGlobalScope('company')
                ->whereKey($bom->product_id)
                ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                ->lockForUpdate()->first();
            if (!$product) return false;
            $available = app(InventoryAvailabilityService::class)->available($product, true, null, $companyId);
            $open = ProductionOrder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('product_id', $bom->product_id)
                ->whereIn('status', ['draft', 'released', 'in_progress'])
                ->lockForUpdate()
                ->get()
                ->sum(fn (ProductionOrder $order): float => max(0, (float) $order->planned_quantity - (float) $order->completed_quantity));
            $openPurchase = PurchaseOrderLine::whereHas('purchaseOrder', fn ($query) => $query
                ->where('company_id', $companyId)
                ->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))
                ->where('product_id', $bom->product_id)
                ->get()
                ->sum(fn (PurchaseOrderLine $line): float => max(0, (float) $line->ordered_qty - (float) $line->received_qty));
            $quantity = max(0, $target - $available - $open - $openPurchase);
            if ($quantity <= 0.000001) return false;

            $plannedDate = now()->toDateString();
            $order = ProductionOrder::create([
                'company_id' => $companyId,
                'bom_id' => $bom->id,
                'product_id' => $bom->product_id,
                'planned_quantity' => $quantity,
                'planned_date' => $plannedDate,
                'description' => 'Generated from production planning shortage; approval required.',
                'order_no' => app(NumberingSequenceService::class)->nextOrFallback('production_order', 'MO-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId),
                'created_by' => null,
                'status' => 'draft',
                'bom_version' => $bom->version ?: '1',
                'bom_snapshot' => app(BomExplosionService::class)->snapshot($bom, $companyId, $plannedDate),
            ]);
            app(AuditService::class)->record('production_order.created_from_scheduled_planning', $order, null, $order->toArray());
            return true;
        });
    }

    /** @return array{0: array<int, float>, 1: array<int, float>} */
    private function openSupplyForCompany(int $companyId): array
    {
        $openPurchase = PurchaseOrderLine::whereHas('purchaseOrder', fn ($query) => $query
            ->where('company_id', $companyId)
            ->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))
            ->get()
            ->groupBy('product_id')
            ->map(fn ($lines): float => (float) $lines->sum(fn (PurchaseOrderLine $line): float => max(0, (float) $line->ordered_qty - (float) $line->received_qty)))
            ->all();
        $openProduction = ProductionOrder::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['draft', 'released', 'in_progress'])
            ->get()
            ->groupBy('product_id')
            ->map(fn ($orders): float => (float) $orders->sum(fn (ProductionOrder $order): float => max(0, (float) $order->planned_quantity - (float) $order->completed_quantity)))
            ->all();

        return [$openPurchase, $openProduction];
    }
}
