<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InvoiceDetail;
use App\Models\InventoryReturn;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    public function index(Request $request)
    {
        [$rows, $from, $to, $counts] = $this->reportRows($request);
        $summary = ['quantity' => (float) $rows->sum('quantity'), 'revenue' => (float) $rows->sum('revenue'), 'tax' => (float) $rows->sum('tax'), 'cogs' => (float) $rows->sum('cogs'), 'gross_profit' => (float) $rows->sum('gross_profit'), 'documents' => $counts['documents'], 'return_documents' => $counts['return_documents']];
        return view('backend.sales.sales_report', ['rows' => $rows, 'summary' => $summary, 'products' => Product::where('status', 1)->orderBy('name')->get(), 'customers' => Customer::orderBy('name')->get(), 'from' => $from, 'to' => $to]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$rows, $from, $to] = $this->reportRows($request);
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Product', 'Customer', 'Documents', 'Return documents', 'Quantity', 'Revenue', 'Tax', 'COGS', 'Gross profit', 'Margin percent']);
            foreach ($rows as $row) fputcsv($output, [$row['product']?->name ?? 'Unknown', $row['customer']?->name ?? 'Walk-in', $row['documents'], $row['return_documents'], number_format($row['quantity'], 3, '.', ''), number_format($row['revenue'], 2, '.', ''), number_format($row['tax'], 2, '.', ''), number_format($row['cogs'], 2, '.', ''), number_format($row['gross_profit'], 2, '.', ''), number_format($row['revenue'] > 0 ? $row['gross_profit'] / $row['revenue'] * 100 : 0, 2, '.', '')]);
            fclose($output);
        }, 'sales-report-'.$from.'-to-'.$to.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function reportRows(Request $request): array
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for sales reporting.');
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
        ]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $query = InvoiceDetail::with(['product', 'invoice.customer'])
            ->whereBetween('date', [$from, $to])
            ->whereHas('invoice', fn ($invoice) => $invoice->whereIn('status', [1, 'approved'])->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')));
        if (!empty($data['product_id']) && !Product::whereKey($data['product_id'])->exists()) abort(422, 'Product is not authorized for this company.');
        if (!empty($data['customer_id']) && !Customer::whereKey($data['customer_id'])->exists()) abort(422, 'Customer is not authorized for this company.');
        if (!empty($data['product_id'])) $query->where('product_id', $data['product_id']);
        if (!empty($data['customer_id'])) $query->whereHas('invoice', fn ($invoice) => $invoice->where('customer_id', $data['customer_id']));

        $lines = $query->get();
        $returns = InventoryReturn::with(['lines.product', 'customer'])
            ->where('return_type', 'sales')
            ->where('status', 'approved')
            ->whereBetween('date', [$from, $to])
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->whereHas('lines', fn ($lineQuery) => $lineQuery->where('product_id', $id)))
            ->when($data['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->get();
        $issueCosts = InventoryMovement::where('movement_type', 'issue')
            ->where('reference_type', (new Invoice())->getMorphClass())
            ->whereIn('reference_id', $lines->pluck('invoice_id')->filter()->unique())
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->get()
            ->groupBy(fn ($movement): string => $movement->product_id.'|'.$movement->reference_id)
            ->map(fn ($movements): float => (float) $movements->sum(fn ($movement): float => (float) $movement->quantity * (float) $movement->unit_cost));
        $returnCosts = InventoryMovement::where('movement_type', 'return_in')
            ->where('reference_type', (new InventoryReturn())->getMorphClass())
            ->whereIn('reference_id', $returns->pluck('id')->unique())
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->get()
            ->groupBy(fn ($movement): string => $movement->product_id.'|'.$movement->reference_id)
            ->map(fn ($movements): float => (float) $movements->sum(fn ($movement): float => (float) $movement->quantity * (float) $movement->unit_cost));
        $rows = $lines->groupBy(fn ($line): string => $line->product_id.'|'.($line->invoice?->customer_id ?: 0))
            ->map(function ($group) use ($issueCosts): array {
                $first = $group->first();
                $revenue = (float) $group->sum('selling_price');
                $tax = (float) $group->sum('tax_amount');
                $quantity = (float) $group->sum('selling_qty');
                $quantitiesByInvoiceProduct = $group->groupBy(fn ($line): string => $line->product_id.'|'.$line->invoice_id)->map(fn ($lines): float => (float) $lines->sum('selling_qty'));
                $cost = $group->sum(function ($line) use ($issueCosts, $quantitiesByInvoiceProduct): float {
                    $key = $line->product_id.'|'.$line->invoice_id;
                    if ($issueCosts->has($key)) return (float) $issueCosts->get($key) * ((float) $line->selling_qty / max(0.000001, (float) $quantitiesByInvoiceProduct->get($key, 0)));
                    return (float) $line->selling_qty * (float) ($line->product?->purchase_price ?? 0);
                });
                return [
                    'product' => $first->product,
                    'customer' => $first->invoice?->customer,
                    'quantity' => $quantity,
                    'revenue' => $revenue,
                    'tax' => $tax,
                    'cogs' => $cost,
                    'gross_profit' => $revenue - $cost,
                    'documents' => $group->pluck('invoice_id')->unique()->count(),
                    'return_documents' => 0,
                ];
            })->sortByDesc('revenue')->values();

        $returns->flatMap(fn (InventoryReturn $return) => $return->lines->map(fn ($line) => ['return' => $return, 'line' => $line]))
            ->groupBy(fn (array $item): string => $item['line']->product_id.'|'.($item['return']->customer_id ?: 0))
            ->each(function ($group) use (&$rows, $returnCosts): void {
                $first = $group->first();
                $return = $first['return'];
                $quantity = (float) $group->sum(fn (array $item): float => (float) $item['line']->quantity);
                $revenue = (float) $group->sum(fn (array $item): float => (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0));
                $tax = $return->tax_exempt ? 0.0 : (float) $group->sum(fn (array $item): float => (float) $item['line']->quantity * (float) ($item['line']->unit_price ?? $item['line']->product?->sales_price ?? 0) * (float) ($item['line']->tax_rate ?? 0) / 100);
                $quantitiesByReturnProduct = $group->groupBy(fn (array $item): string => $item['line']->product_id.'|'.$item['return']->id)->map(fn ($items): float => (float) $items->sum(fn (array $item): float => (float) $item['line']->quantity));
                $cogs = (float) $group->sum(function (array $item) use ($returnCosts, $quantitiesByReturnProduct): float {
                    $key = $item['line']->product_id.'|'.$item['return']->id;
                    if ($returnCosts->has($key)) return (float) $returnCosts->get($key) * ((float) $item['line']->quantity / max(0.000001, (float) $quantitiesByReturnProduct->get($key, 0)));
                    return (float) $item['line']->quantity * (float) ($item['line']->product?->purchase_price ?? 0);
                });
                $key = $first['line']->product_id.'|'.($return->customer_id ?: 0);
                $existing = $rows->search(fn (array $row): bool => $row['product']?->id.'|'.($row['customer']?->id ?: 0) === $key);
                $row = $existing === false
                    ? ['product' => $first['line']->product, 'customer' => $return->customer, 'quantity' => 0.0, 'revenue' => 0.0, 'tax' => 0.0, 'cogs' => 0.0, 'gross_profit' => 0.0, 'documents' => 0, 'return_documents' => 0]
                    : $rows->get($existing);
                $row['return_documents'] += $group->pluck('return.id')->unique()->count();
                $row['quantity'] -= $quantity;
                $row['revenue'] -= $revenue;
                $row['tax'] -= $tax;
                $row['cogs'] -= $cogs;
                $row['gross_profit'] = $row['revenue'] - $row['cogs'];
                if ($existing === false) $rows->push($row); else $rows->put($existing, $row);
            });
        $rows = $rows->sortByDesc('revenue')->values();

        $summary = [
            'quantity' => (float) $rows->sum('quantity'),
            'revenue' => (float) $rows->sum('revenue'),
            'tax' => (float) $rows->sum('tax'),
            'cogs' => (float) $rows->sum('cogs'),
            'gross_profit' => (float) $rows->sum('gross_profit'),
            'documents' => $lines->pluck('invoice_id')->unique()->count(),
            'return_documents' => $returns->count(),
        ];
        return [$rows, $from, $to, ['documents' => $lines->pluck('invoice_id')->filter()->unique()->count(), 'return_documents' => $returns->count()]];
    }
}
