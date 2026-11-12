<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\PurchaseInvoice;
use App\Models\SupplierCorrectiveAction;
use App\Services\ProductSpreadsheetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class SupplierPerformanceController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 422, 'A company is required for supplier performance.');
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))]]);
        $performance = $this->performanceRows($filters['from'] ?? null, $filters['to'] ?? null, $filters['supplier_id'] ?? null);
        $suppliers = Supplier::where('status', 1)->where('company_id', $companyId)->orderBy('name')->get();
        return view('backend.purchase.supplier_performance', compact('performance', 'suppliers', 'filters'));
    }

    public function export(Request $request, ProductSpreadsheetService $spreadsheet): Response
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 422, 'A company is required for supplier performance.');
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'format' => ['nullable', 'in:csv,xlsx']]);
        $performance = $this->performanceRows($filters['from'] ?? null, $filters['to'] ?? null, $filters['supplier_id'] ?? null);
        $headers = ['Supplier', 'Score', 'Orders', 'Receipts', 'Ordered quantity', 'Received quantity', 'Fill rate', 'On-time rate', 'Quality pass rate', 'Failed receipts', 'Rejected quantity', 'Corrective actions', 'Open corrective actions', 'Overdue corrective actions', 'Ordered value', 'Invoiced quantity', 'Invoiced value', 'Price variance', 'Price variance amount'];
        $values = $performance->map(fn (array $row): array => [$row['supplier']->name, number_format($row['supplier_score'], 2, '.', ''), $row['orders'], $row['receipts'], number_format($row['ordered'], 6, '.', ''), number_format($row['received'], 6, '.', ''), number_format($row['fill_rate'], 2, '.', ''), number_format($row['on_time_rate'], 2, '.', ''), $row['quality_pass_rate'] === null ? '' : number_format($row['quality_pass_rate'], 2, '.', ''), $row['failed_receipts'], number_format($row['rejected_quantity'], 6, '.', ''), $row['corrective_actions'], $row['open_corrective_actions'], $row['overdue_corrective_actions'], number_format($row['ordered_value'], 6, '.', ''), number_format($row['invoiced_quantity'], 6, '.', ''), number_format($row['invoiced_value'], 6, '.', ''), number_format($row['price_variance'], 6, '.', ''), number_format($row['price_variance_amount'], 6, '.', '')])->all();
        if (($filters['format'] ?? 'csv') === 'xlsx') return response($spreadsheet->write($headers, $values), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="supplier-performance-'.now()->toDateString().'.xlsx"']);
        return response()->streamDownload(function () use ($headers, $values): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers); foreach ($values as $value) fputcsv($output, $value);
            fclose($output);
        }, 'supplier-performance-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    private function performanceRows(?string $from = null, ?string $to = null, ?int $supplierId = null)
    {
        $companyId = auth()->user()?->company_id;
        $from ??= now()->subYear()->toDateString();
        $to ??= now()->toDateString();
        $suppliers = Supplier::where('status', 1)->where('company_id', $companyId)->when($supplierId, fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get();
        $orders = PurchaseOrder::with(['supplier', 'lines', 'receipts.lines', 'receipts.purchaseOrder'])
            ->whereIn('status', ['approved', 'partially_received', 'received'])
            ->where('company_id', $companyId)
            ->whereBetween('date', [$from, $to])
            ->get();
        $actions = SupplierCorrectiveAction::where('company_id', $companyId)->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->when($supplierId, fn ($query, $id) => $query->where('supplier_id', $id))->get();
        $invoices = PurchaseInvoice::with('lines')->where('company_id', $companyId)->where('status', 'approved')->whereIn('purchase_order_id', $orders->pluck('id'))->get()->groupBy('purchase_order_id');
        $performance = $suppliers->map(function (Supplier $supplier) use ($orders, $invoices, $actions): array {
            $supplierOrders = $orders->where('supplier_id', $supplier->id);
            $ordered = (float) $supplierOrders->sum(fn ($order) => $order->lines->sum('ordered_qty'));
            $received = (float) $supplierOrders->sum(fn ($order) => $order->lines->sum('received_qty'));
            $receipts = $supplierOrders->flatMap->receipts;
            $onTime = $receipts->filter(fn ($receipt) => !$receipt->purchaseOrder?->expected_date || $receipt->date <= $receipt->purchaseOrder->expected_date)->count();
            $orderedValue = (float) $supplierOrders->sum(fn ($order) => $order->lines->sum(fn ($line) => (float) $line->ordered_qty * (float) $line->unit_price));
            $supplierInvoices = $supplierOrders->flatMap(fn ($order) => $invoices->get($order->id, collect()));
            $inspectedReceipts = $receipts->filter(fn ($receipt) => in_array($receipt->inspection_status, ['passed', 'failed'], true));
            $failedReceipts = $inspectedReceipts->where('inspection_status', 'failed');
            $rejectedQuantity = (float) $failedReceipts->sum(fn ($receipt) => $receipt->lines->sum('received_qty'));
            $invoicedQty = (float) $supplierInvoices->sum(fn ($invoice) => $invoice->lines->sum('quantity'));
            $invoicedValue = (float) $supplierInvoices->sum(fn ($invoice) => $invoice->lines->sum(fn ($line) => (float) $line->quantity * (float) $line->unit_price));
            $orderedUnitCost = $ordered > 0 ? $orderedValue / $ordered : 0;
            $invoicedUnitCost = $invoicedQty > 0 ? $invoicedValue / $invoicedQty : 0;
            $supplierActions = $actions->where('supplier_id', $supplier->id);
            $openActions = $supplierActions->whereIn('status', ['open', 'in_progress', 'resolved']);
            $overdueActions = $openActions->filter(fn (SupplierCorrectiveAction $action): bool => $action->due_date !== null && $action->due_date->isPast());
            return ['supplier' => $supplier, 'orders' => $supplierOrders->count(), 'ordered' => $ordered, 'received' => $received, 'fill_rate' => $ordered > 0 ? ($received / $ordered) * 100 : 0, 'receipts' => $receipts->count(), 'on_time_rate' => $receipts->count() > 0 ? ($onTime / $receipts->count()) * 100 : 0, 'inspected_receipts' => $inspectedReceipts->count(), 'failed_receipts' => $failedReceipts->count(), 'quality_pass_rate' => $inspectedReceipts->count() > 0 ? (($inspectedReceipts->count() - $failedReceipts->count()) / $inspectedReceipts->count()) * 100 : null, 'rejected_quantity' => $rejectedQuantity, 'quality_rejection_rate' => $received > 0 ? ($rejectedQuantity / $received) * 100 : 0, 'corrective_actions' => $supplierActions->count(), 'open_corrective_actions' => $openActions->count(), 'overdue_corrective_actions' => $overdueActions->count(), 'ordered_value' => $orderedValue, 'invoiced_quantity' => $invoicedQty, 'invoiced_value' => $invoicedValue, 'price_variance' => $orderedUnitCost > 0 && $invoicedQty > 0 ? (($invoicedUnitCost - $orderedUnitCost) / $orderedUnitCost) * 100 : 0, 'price_variance_amount' => $orderedUnitCost > 0 && $invoicedQty > 0 ? ($invoicedUnitCost - $orderedUnitCost) * $invoicedQty : 0];
        })->filter(fn (array $row): bool => $row['orders'] > 0 || $row['corrective_actions'] > 0)->values()->map(function (array $row): array {
            $row['supplier_score'] = app(\App\Services\SupplierPerformanceScoringService::class)->score($row);
            return $row;
        });
        return $performance;
    }
}
