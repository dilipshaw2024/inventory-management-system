<?php

namespace App\Services;

use App\Models\InvoiceDetail;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\InventoryReturn;
use App\Models\InventoryCostLayer;
use Illuminate\Support\Collection;

class InventoryAnalyticsService
{
    public function rowsForCompany(int $companyId, string $from, string $to, ?int $productId = null, string $abcBasis = 'revenue', ?int $categoryId = null): Collection
    {
        if (!in_array($abcBasis, ['revenue', 'movement', 'value'], true)) throw new \InvalidArgumentException('Unsupported ABC analysis basis.');
        $products = Product::withoutGlobalScope('company')->with('category')->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->where('status', 1)->when($productId, fn ($query, $id) => $query->whereKey($id))->when($categoryId, fn ($query, $id) => $query->where('category_id', $id))->orderBy('name')->get();
        $sales = InvoiceDetail::where('status', 1)->whereBetween('date', [$from, $to])->whereHas('invoice', fn ($query) => $query->whereIn('status', [1, 'approved'])->where(function ($scope) use ($companyId): void { $scope->where('company_id', $companyId)->orWhereNull('company_id'); }))->get()->groupBy('product_id');
        $movements = InventoryMovement::whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->get()->groupBy('product_id');
        $balances = InventoryMovement::where('posted_at', '<=', $to.' 23:59:59')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->selectRaw("product_id, COUNT(*) AS movement_count, COALESCE(SUM(CASE WHEN movement_type IN ('opening','receipt','transfer_in','adjustment_in','return_in','quarantine_out','release') THEN quantity WHEN movement_type IN ('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in') THEN -quantity ELSE 0 END), 0) AS balance")->groupBy('product_id')->get()->keyBy('product_id');
        $layers = InventoryCostLayer::withoutGlobalScopes()->whereIn('product_id', $products->pluck('id'))->where('received_at', '<=', $to.' 23:59:59')->with(['consumptions.movement'])->get();
        $layerValues = $layers->groupBy('product_id')->map(function (Collection $productLayers) use ($to): float {
            return (float) $productLayers->sum(function (InventoryCostLayer $layer) use ($to): float {
                $consumed = (float) $layer->consumptions->filter(function ($consumption) use ($to): bool {
                    $date = $consumption->movement?->posted_at?->toDateString() ?? optional($consumption->created_at)->toDateString();
                    return $date !== null && $date <= $to;
                })->sum('quantity');
                return max(0, (float) $layer->original_quantity - $consumed) * (float) $layer->unit_cost;
            });
        });
        $returns = InventoryReturn::with('lines.product')->where('return_type', 'sales')->where('status', 'approved')->whereBetween('date', [$from, $to])->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->get()->flatMap(fn (InventoryReturn $return) => $return->lines->map(fn ($line): array => ['return' => $return, 'line' => $line]))->groupBy(fn (array $item): int => (int) $item['line']->product_id);
        $returnCosts = InventoryMovement::where('movement_type', 'return_in')->where('reference_type', (new InventoryReturn())->getMorphClass())->whereIn('reference_id', $returns->flatMap(fn ($items) => $items->pluck('return.id'))->unique())->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->get()->groupBy('product_id')->map(fn ($rows): float => (float) $rows->sum(fn ($movement): float => (float) $movement->quantity * (float) $movement->unit_cost));
        $issues = $movements->map(fn ($productMovements) => $productMovements->where('movement_type', 'issue'));
        // Keep report balance math aligned with InventoryAvailabilityService
        // and StockController: status releases add physical availability,
        // while quarantine holds remove it.
        $inbound = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $outbound = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        $rows = $products->map(function (Product $product) use ($sales, $issues, $movements, $balances, $layerValues, $returns, $returnCosts, $inbound, $outbound): array {
            $productSales = $sales->get($product->id, collect()); $productReturns = $returns->get($product->id, collect()); $revenue = (float) $productSales->sum('selling_price') - (float) $productReturns->sum(fn (array $item): float => (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? $product->sales_price ?? 0)); $quantitySold = (float) $productSales->sum('selling_qty') - (float) $productReturns->sum(fn (array $item): float => (float) $item['line']->quantity); $cogs = (float) $issues->get($product->id, collect())->sum(fn ($movement): float => (float) $movement->quantity * (float) $movement->unit_cost);
            $cogs -= $returnCosts->has($product->id) ? (float) $returnCosts->get($product->id) : (float) $productReturns->sum(fn (array $item): float => (float) $item['line']->quantity * (float) ($item['line']->product?->purchase_price ?? $product->purchase_price ?? 0));
            $periodMovements = $movements->get($product->id, collect());
            $netMovement = (float) $periodMovements->whereIn('movement_type', $inbound)->sum('quantity') - (float) $periodMovements->whereIn('movement_type', $outbound)->sum('quantity');
            $balance = $balances->get($product->id); $closingStock = $balance && (int) $balance->movement_count > 0 ? (float) $balance->balance : (float) $product->quantity; $openingStock = max(0, $closingStock - $netMovement); $averageStock = max(0, ($openingStock + $closingStock) / 2); $periodDays = max(1, \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1); $daysOfInventory = $quantitySold > 0 ? ($averageStock / ($quantitySold / $periodDays)) : null;
            $inventoryValue = $layerValues->has($product->id) ? (float) $layerValues->get($product->id) : $closingStock * (float) ($product->purchase_price ?? 0);
            $grossProfit = $revenue - $cogs;
            return ['product' => $product, 'revenue' => $revenue, 'cogs' => $cogs, 'gross_profit' => $grossProfit, 'margin_percent' => $revenue != 0 ? ($grossProfit / $revenue) * 100 : 0, 'quantity_sold' => $quantitySold, 'opening_stock' => $openingStock, 'average_stock' => $averageStock, 'inventory_value' => $inventoryValue, 'turnover' => $averageStock > 0 ? $quantitySold / $averageStock : 0, 'days_of_inventory' => $daysOfInventory];
        })->filter(function (array $row) use ($abcBasis): bool {
            return $abcBasis === 'value'
                ? $row['inventory_value'] > 0
                : ($row['revenue'] > 0 || $row['cogs'] > 0 || $row['quantity_sold'] > 0);
        })->sortByDesc('revenue')->values();
        $metric = match ($abcBasis) { 'movement' => 'quantity_sold', 'value' => 'inventory_value', default => 'revenue' };
        $rows = $rows->sortByDesc($metric)->values();
        $aThreshold = (float) app(\App\Services\ErpSettingService::class)->get('abc_a_threshold_percent', 80, $companyId) / 100;
        $bThreshold = (float) app(\App\Services\ErpSettingService::class)->get('abc_b_threshold_percent', 95, $companyId) / 100;
        if ($aThreshold <= 0 || $bThreshold <= $aThreshold || $bThreshold > 1) { $aThreshold = 0.80; $bThreshold = 0.95; }
        $total = max((float) $rows->sum($metric), 0.000001); $running = 0;
        return $rows->map(function (array $row) use (&$running, $total, $metric, $abcBasis, $aThreshold, $bThreshold): array { $running += (float) $row[$metric]; $row['abc_basis'] = $abcBasis; $row['abc'] = $running / $total <= $aThreshold ? 'A' : ($running / $total <= $bThreshold ? 'B' : 'C'); return $row; });
    }
}
