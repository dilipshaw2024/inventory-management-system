<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InvoiceDetail;
use App\Models\InventoryReturn;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductSpreadsheetService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesReportController extends Controller
{
    public function index(Request $request)
    {
        [$rows, $from, $to, $counts] = $this->reportRows($request);
        $summary = ['quantity' => (float) $rows->sum('quantity'), 'revenue' => (float) $rows->sum('revenue'), 'tax' => (float) $rows->sum('tax'), 'cogs' => (float) $rows->sum('cogs'), 'gross_profit' => (float) $rows->sum('gross_profit'), 'documents' => $counts['documents'], 'return_documents' => $counts['return_documents']];
        $comparison = null;
        if ($request->filled('compare_from') && $request->filled('compare_to')) {
            $comparisonRequest = $request->duplicate();
            $comparisonRequest->query->set('from', $request->input('compare_from'));
            $comparisonRequest->query->set('to', $request->input('compare_to'));
            [$comparisonRows, $comparisonFrom, $comparisonTo, $comparisonCounts] = $this->reportRows($comparisonRequest);
            $comparisonSummary = ['quantity' => (float) $comparisonRows->sum('quantity'), 'revenue' => (float) $comparisonRows->sum('revenue'), 'tax' => (float) $comparisonRows->sum('tax'), 'cogs' => (float) $comparisonRows->sum('cogs'), 'gross_profit' => (float) $comparisonRows->sum('gross_profit'), 'documents' => $comparisonCounts['documents'], 'return_documents' => $comparisonCounts['return_documents']];
            $comparison = ['from' => $comparisonFrom, 'to' => $comparisonTo, 'summary' => $comparisonSummary, 'variance' => collect(['quantity', 'revenue', 'tax', 'cogs', 'gross_profit'])->mapWithKeys(fn (string $metric): array => [$metric => ['amount' => $summary[$metric] - $comparisonSummary[$metric], 'percent' => abs($comparisonSummary[$metric]) > 0.000001 ? (($summary[$metric] - $comparisonSummary[$metric]) / abs($comparisonSummary[$metric])) * 100 : null]])->all()];
        }
        return view('backend.sales.sales_report', ['rows' => $rows, 'summary' => $summary, 'comparison' => $comparison, 'products' => Product::where('status', 1)->orderBy('name')->get(), 'customers' => Customer::orderBy('name')->get(), 'salespeople' => User::where('company_id', $request->user()?->company_id)->orderBy('name')->get(), 'from' => $from, 'to' => $to]);
    }

    public function export(Request $request, ProductSpreadsheetService $spreadsheet): Response
    {
        [$rows, $from, $to] = $this->reportRows($request);
        $headers = ['Product', 'Customer', 'Sales channel', 'Salesperson', 'Delivery method', 'Documents', 'Return documents', 'Quantity', 'Revenue', 'Tax', 'COGS', 'Gross profit', 'Margin percent'];
        $values = $rows->map(fn (array $row): array => [$row['product']?->name ?? 'Unknown', $row['customer']?->name ?? 'Walk-in', $row['sales_channel'] ?: 'Unspecified', $row['salesperson']?->name ?? 'Unassigned', $row['delivery_method'] ?: 'Unspecified', $row['documents'], $row['return_documents'], number_format($row['quantity'], 3, '.', ''), number_format($row['revenue'], 2, '.', ''), number_format($row['tax'], 2, '.', ''), number_format($row['cogs'], 2, '.', ''), number_format($row['gross_profit'], 2, '.', ''), number_format($row['revenue'] > 0 ? $row['gross_profit'] / $row['revenue'] * 100 : 0, 2, '.', '')])->all();
        if ($request->input('format') === 'xlsx') {
            return response($spreadsheet->write($headers, $values), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="sales-report-'.$from.'-to-'.$to.'.xlsx"']);
        }
        return response()->streamDownload(function () use ($headers, $values): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);
            foreach ($values as $value) fputcsv($output, $value);
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
            'sales_channel' => ['nullable', 'string', 'max:50'],
            'salesperson_id' => ['nullable', 'integer'],
            'delivery_method' => ['nullable', 'string', 'max:30'],
            'compare_from' => ['nullable', 'date'],
            'compare_to' => ['nullable', 'date', 'after_or_equal:compare_from'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $storeId = (int) ($request->user()?->store_id ?? 0);

        $query = InvoiceDetail::with(['product', 'invoice.customer', 'invoice.creator', 'invoice.salesperson'])
            ->whereBetween('date', [$from, $to])
            ->whereHas('invoice', function ($invoice) use ($companyId, $storeId): void {
                $invoice->whereIn('status', [1, 'approved'])->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
                if ($storeId > 0) $invoice->where('store_id', $storeId);
            });
        if (!empty($data['product_id']) && !Product::where(fn ($product) => $product->where('company_id', $companyId)->orWhereNull('company_id'))->whereKey($data['product_id'])->doesntExist()) abort(422, 'Product is not authorized for this company.');
        if (!empty($data['customer_id']) && !Customer::where(fn ($customer) => $customer->where('company_id', $companyId)->orWhereNull('company_id'))->whereKey($data['customer_id'])->doesntExist()) abort(422, 'Customer is not authorized for this company.');
        if (!empty($data['salesperson_id']) && !User::where('company_id', $companyId)->whereKey($data['salesperson_id'])->exists()) abort(422, 'Salesperson is not authorized for this company.');
        if (!empty($data['product_id'])) $query->where('product_id', $data['product_id']);
        if (!empty($data['customer_id'])) $query->whereHas('invoice', fn ($invoice) => $invoice->where('customer_id', $data['customer_id']));
        if (!empty($data['sales_channel'])) $query->whereHas('invoice', fn ($invoice) => $invoice->where('sales_channel', $data['sales_channel'])->orWhere(function ($fallback) use ($data): void { $fallback->whereNull('sales_channel')->whereHas('customer', fn ($customer) => $customer->where('sales_channel', $data['sales_channel'])); }));
        if (!empty($data['salesperson_id'])) $query->whereHas('invoice', fn ($invoice) => $invoice->where('salesperson_id', $data['salesperson_id'])->orWhere(function ($fallback) use ($data): void { $fallback->whereNull('salesperson_id')->where('created_by', $data['salesperson_id']); }));
        if (!empty($data['delivery_method'])) $query->whereHas('invoice', fn ($invoice) => $invoice->where('delivery_method', $data['delivery_method']));

        $lines = $query->get();
        $returns = InventoryReturn::with(['lines.product', 'customer', 'sourceInvoice.customer', 'sourceInvoice.salesperson', 'sourceInvoice.creator'])
            ->where('return_type', 'sales')
            ->where('status', 'approved')
            ->whereBetween('date', [$from, $to])
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($storeId > 0, fn ($q) => $q->whereHas('sourceInvoice', fn ($invoice) => $invoice->where('store_id', $storeId)))
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->whereHas('lines', fn ($lineQuery) => $lineQuery->where('product_id', $id)))
            ->when($data['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->get()
            ->filter(function (InventoryReturn $return) use ($data): bool {
                $invoice = $return->sourceInvoice;
                $channel = $invoice?->sales_channel ?: $invoice?->customer?->sales_channel ?: $return->customer?->sales_channel;
                $salespersonId = $invoice?->salesperson_id ?: $invoice?->created_by;
                if (!empty($data['sales_channel']) && $channel !== $data['sales_channel']) return false;
                if (!empty($data['salesperson_id']) && (int) $salespersonId !== (int) $data['salesperson_id']) return false;
                if (!empty($data['delivery_method']) && $invoice?->delivery_method !== $data['delivery_method']) return false;
                return true;
            })->values();
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
        $rows = $lines->groupBy(fn ($line): string => $line->product_id.'|'.($line->invoice?->customer_id ?: 0).'|'.($line->invoice?->sales_channel ?: $line->invoice?->customer?->sales_channel ?: '').'|'.($line->invoice?->salesperson_id ?: $line->invoice?->created_by ?: 0).'|'.($line->invoice?->delivery_method ?: ''))
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
                    'sales_channel' => $first->invoice?->sales_channel ?: $first->invoice?->customer?->sales_channel,
                    'salesperson' => $first->invoice?->salesperson ?: $first->invoice?->creator,
                    'delivery_method' => $first->invoice?->delivery_method,
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
            ->groupBy(function (array $item): string {
                $return = $item['return'];
                $invoice = $return->sourceInvoice;
                $channel = $invoice?->sales_channel ?: $invoice?->customer?->sales_channel ?: $return->customer?->sales_channel ?: '';
                $salespersonId = $invoice?->salesperson_id ?: $invoice?->created_by ?: 0;
                return $item['line']->product_id.'|'.($return->customer_id ?: 0).'|'.$channel.'|'.$salespersonId.'|'.($invoice?->delivery_method ?: '');
            })
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
                $invoice = $return->sourceInvoice;
                $channel = $invoice?->sales_channel ?: $invoice?->customer?->sales_channel ?: $return->customer?->sales_channel ?: '';
                $salespersonId = $invoice?->salesperson_id ?: $invoice?->created_by ?: 0;
                $deliveryMethod = $invoice?->delivery_method ?: '';
                $key = $first['line']->product_id.'|'.($return->customer_id ?: 0).'|'.$channel.'|'.$salespersonId.'|'.$deliveryMethod;
                $existing = $rows->search(fn (array $row): bool => $row['product']?->id.'|'.($row['customer']?->id ?: 0).'|'.($row['sales_channel'] ?: '').'|'.($row['salesperson']?->id ?: 0).'|'.($row['delivery_method'] ?: '') === $key);
                $row = $existing === false
                    ? ['product' => $first['line']->product, 'customer' => $return->customer, 'sales_channel' => $channel ?: null, 'salesperson' => $invoice?->salesperson ?: $invoice?->creator, 'delivery_method' => $invoice?->delivery_method, 'quantity' => 0.0, 'revenue' => 0.0, 'tax' => 0.0, 'cogs' => 0.0, 'gross_profit' => 0.0, 'documents' => 0, 'return_documents' => 0]
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
