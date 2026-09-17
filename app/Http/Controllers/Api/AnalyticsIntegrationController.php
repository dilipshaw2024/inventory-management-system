<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryAnalyticsService;
use App\Models\InvoiceDetail;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\InventoryReturn;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsIntegrationController extends Controller
{
    public function salesPerformance(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'product_id' => ['nullable', 'integer'], 'customer_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id; abort_unless($companyId, 403, 'A company is required for sales performance.');
        $from = $data['from'] ?? now()->startOfMonth()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        if (!empty($data['product_id']) && !Product::whereKey($data['product_id'])->exists()) abort(422, 'Product is not authorized for this company.');
        if (!empty($data['customer_id']) && !Customer::whereKey($data['customer_id'])->exists()) abort(422, 'Customer is not authorized for this company.');
        $lines = InvoiceDetail::with(['product', 'invoice.customer'])->whereBetween('date', [$from, $to])->whereHas('invoice', fn ($q) => $q->whereIn('status', [1, 'approved'])->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))->when($data['customer_id'] ?? null, fn ($q, $id) => $q->whereHas('invoice', fn ($invoice) => $invoice->where('customer_id', $id)))->get();
        $issueCosts = InventoryMovement::where('movement_type', 'issue')->where('reference_type', (new Invoice())->getMorphClass())->whereIn('reference_id', $lines->pluck('invoice_id')->filter()->unique())->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->get()->groupBy(fn ($movement): string => $movement->product_id.'|'.$movement->reference_id)->map(fn ($movements): float => (float) $movements->sum(fn ($movement): float => (float) $movement->quantity * (float) $movement->unit_cost));
        $returns = InventoryReturn::with(['lines.product', 'customer'])->where('return_type', 'sales')->where('status', 'approved')->whereBetween('date', [$from, $to])->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->when($data['product_id'] ?? null, fn ($q, $id) => $q->whereHas('lines', fn ($linesQuery) => $linesQuery->where('product_id', $id)))->when($data['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))->get();
        $returnCosts = InventoryMovement::where('movement_type', 'return_in')->where('reference_type', (new InventoryReturn())->getMorphClass())->whereIn('reference_id', $returns->pluck('id')->unique())->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->get()->groupBy(fn ($movement): string => $movement->product_id.'|'.$movement->reference_id)->map(fn ($movements): float => (float) $movements->sum(fn ($movement): float => (float) $movement->quantity * (float) $movement->unit_cost));
        $rows = $lines->groupBy(fn ($line): string => $line->product_id.'|'.($line->invoice?->customer_id ?: 0))->map(function ($group) use ($issueCosts): array {
            $first = $group->first(); $quantity = (float) $group->sum('selling_qty'); $revenue = (float) $group->sum('selling_price'); $tax = (float) $group->sum('tax_amount');
            $quantitiesByInvoiceProduct = $group->groupBy(fn ($line): string => $line->product_id.'|'.$line->invoice_id)->map(fn ($lines): float => (float) $lines->sum('selling_qty'));
            $cogs = (float) $group->sum(function ($line) use ($issueCosts, $quantitiesByInvoiceProduct): float {
                $key = $line->product_id.'|'.$line->invoice_id;
                if ($issueCosts->has($key)) return (float) $issueCosts->get($key) * ((float) $line->selling_qty / max(0.000001, (float) $quantitiesByInvoiceProduct->get($key, 0)));
                return (float) $line->selling_qty * (float) ($line->product?->purchase_price ?? 0);
            });
            $grossProfit = $revenue - $cogs;
            return ['product_id' => (int) $first->product_id, 'product' => $first->product, 'customer_id' => $first->invoice?->customer_id, 'customer' => $first->invoice?->customer, 'documents' => $group->pluck('invoice_id')->unique()->count(), 'quantity' => $quantity, 'revenue' => $revenue, 'tax' => $tax, 'cogs' => $cogs, 'gross_profit' => $grossProfit, 'margin_percent' => $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0];
        })->sortByDesc('revenue')->values();
        $returns->flatMap(fn (InventoryReturn $return) => $return->lines->map(fn ($line) => ['return' => $return, 'line' => $line]))->groupBy(fn (array $item): string => $item['line']->product_id.'|'.($item['return']->customer_id ?: 0))->each(function ($group) use (&$rows, $returnCosts): void {
            $first = $group->first(); $return = $first['return']; $quantityByReturnProduct = $group->groupBy(fn (array $item): string => $item['line']->product_id.'|'.$item['return']->id)->map(fn ($items): float => (float) $items->sum(fn (array $item): float => (float) $item['line']->quantity));
            $quantity = (float) $group->sum(fn (array $item): float => (float) $item['line']->quantity); $revenue = (float) $group->sum(fn (array $item): float => (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0)); $tax = $return->tax_exempt ? 0.0 : (float) $group->sum(fn (array $item): float => (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0) * (float) ($item['line']->tax_rate ?? 0) / 100);
            $cogs = (float) $group->sum(function (array $item) use ($returnCosts, $quantityByReturnProduct): float { $key = $item['line']->product_id.'|'.$item['return']->id; if ($returnCosts->has($key)) return (float) $returnCosts->get($key) * ((float) $item['line']->quantity / max(0.000001, (float) $quantityByReturnProduct->get($key, 0))); return (float) $item['line']->quantity * (float) ($item['line']->product?->purchase_price ?? 0); });
            $key = $first['line']->product_id.'|'.($return->customer_id ?: 0); $existing = $rows->search(fn (array $row): bool => $row['product_id'].'|'.($row['customer_id'] ?: 0) === $key);
            $row = $existing === false ? ['product_id' => (int) $first['line']->product_id, 'product' => $first['line']->product, 'customer_id' => $return->customer_id, 'customer' => $return->customer, 'documents' => 0, 'return_documents' => 0, 'quantity' => 0.0, 'revenue' => 0.0, 'tax' => 0.0, 'cogs' => 0.0, 'gross_profit' => 0.0, 'margin_percent' => 0.0] : $rows->get($existing);
            $row['return_documents'] = (int) ($row['return_documents'] ?? 0) + $group->pluck('return.id')->unique()->count(); $row['quantity'] -= $quantity; $row['revenue'] -= $revenue; $row['tax'] -= $tax; $row['cogs'] -= $cogs; $row['gross_profit'] = $row['revenue'] - $row['cogs']; $row['margin_percent'] = $row['revenue'] > 0 ? ($row['gross_profit'] / $row['revenue']) * 100 : 0;
            if ($existing === false) $rows->push($row); else $rows->put($existing, $row);
        });
        $rows = $rows->sortByDesc('revenue')->values();
        $page = max(1, $request->integer('page', 1)); $perPage = (int) ($data['per_page'] ?? 50);
        return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'summary' => ['documents' => $lines->pluck('invoice_id')->unique()->count(), 'return_documents' => $returns->count(), 'quantity' => (float) $rows->sum('quantity'), 'revenue' => (float) $rows->sum('revenue'), 'tax' => (float) $rows->sum('tax'), 'cogs' => (float) $rows->sum('cogs'), 'gross_profit' => (float) $rows->sum('gross_profit')], 'meta' => ['from' => $from, 'to' => $to, 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'product_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'], 'abc_basis' => ['nullable', 'in:revenue,movement,value'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id; abort_unless($companyId, 403, 'A company is required for inventory analytics.');
        $from = $data['from'] ?? now()->subYear()->toDateString(); $to = $data['to'] ?? now()->toDateString(); $abcBasis = $data['abc_basis'] ?? 'revenue'; $rows = app(InventoryAnalyticsService::class)->rowsForCompany((int) $companyId, $from, $to, $data['product_id'] ?? null, $abcBasis, $data['category_id'] ?? null);
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, $request->integer('page', 1)); $paged = $rows->forPage($page, $perPage)->values();
        $summaryQuantity = (float) $rows->sum('quantity_sold'); $summaryAverageStock = (float) $rows->sum('average_stock'); $periodDays = max(1, \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1);
        $summary = ['revenue' => (float) $rows->sum('revenue'), 'cogs' => (float) $rows->sum('cogs'), 'gross_profit' => (float) $rows->sum('gross_profit'), 'quantity_sold' => $summaryQuantity, 'inventory_value' => (float) $rows->sum('inventory_value'), 'turnover' => $summaryAverageStock > 0 ? $summaryQuantity / $summaryAverageStock : 0, 'days_of_inventory' => $summaryQuantity > 0 ? $summaryAverageStock / ($summaryQuantity / $periodDays) : null];
        $categorySummary = $rows->groupBy(fn (array $row) => $row['product']->category_id ?: 0)->map(function ($categoryRows, $categoryId) use ($periodDays): array {
            $category = $categoryRows->first()['product']->category;
            $quantity = (float) $categoryRows->sum('quantity_sold'); $averageStock = (float) $categoryRows->sum('average_stock');
            return ['category_id' => (int) $categoryId, 'category_name' => $category?->name ?: 'Uncategorized', 'revenue' => (float) $categoryRows->sum('revenue'), 'cogs' => (float) $categoryRows->sum('cogs'), 'gross_profit' => (float) $categoryRows->sum('gross_profit'), 'quantity_sold' => $quantity, 'inventory_value' => (float) $categoryRows->sum('inventory_value'), 'turnover' => $averageStock > 0 ? $quantity / $averageStock : 0, 'days_of_inventory' => $quantity > 0 ? $averageStock / ($quantity / $periodDays) : null];
        })->sortByDesc('revenue')->values();
        $aThreshold = (float) app(\App\Services\ErpSettingService::class)->get('abc_a_threshold_percent', 80, (int) $companyId);
        $bThreshold = (float) app(\App\Services\ErpSettingService::class)->get('abc_b_threshold_percent', 95, (int) $companyId);
        return response()->json(['data' => $paged, 'summary' => $summary, 'category_summary' => $categorySummary, 'meta' => ['from' => $from, 'to' => $to, 'abc_basis' => $abcBasis, 'abc_a_threshold_percent' => $aThreshold, 'abc_b_threshold_percent' => $bThreshold, 'category_id' => $data['category_id'] ?? null, 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }
}
