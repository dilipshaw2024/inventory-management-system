<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceivablesController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    public function aging(Request $request)
    {
        $asOf = $this->asOf($request);
        return view('backend.customer.receivables_aging', ['rows' => $this->rows($asOf), 'asOf' => $asOf]);
    }

    public function export(Request $request): StreamedResponse
    {
        $asOf = $this->asOf($request);
        $rows = $this->rows($asOf);
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Customer', 'Invoice', 'Invoice date', 'Due date', 'Days overdue', 'Bucket', 'Collection status', 'Outstanding']);
            foreach ($rows as $row) fputcsv($output, [$row['customer']?->name ?: 'N/A', $row['invoice']?->invoice_no ?: ($row['invoice']?->id ?: 'N/A'), optional($row['invoice']?->date)->format('Y-m-d') ?: '', $row['due_date'] ?? '', $row['days_overdue'], $row['bucket'], $row['collection_status'] ?? 'current', number_format((float) $row['outstanding'], 2, '.', '')]);
            fclose($output);
        }, 'receivables-aging-'.$asOf.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function rows(string $asOf)
    {
        $invoices = Invoice::where('company_id', $this->companyId())->with('customer')->where('status', 1)->whereNotNull('customer_id')->where(function ($query) use ($asOf): void { $query->whereDate('date', '<=', $asOf)->orWhere(function ($fallback) use ($asOf): void { $fallback->whereNull('date')->whereDate('created_at', '<=', $asOf); }); })->latest('date')->get();
        $invoiceIds = $invoices->pluck('id');
        $paid = $invoiceIds->isEmpty() ? collect() : Payment::where('company_id', $this->companyId())->whereIn('invoice_id', $invoiceIds)->where('approval_status', 'approved')->where('is_reversed', false)->whereDate('created_at', '<=', $asOf)->selectRaw('invoice_id, COALESCE(SUM(paid_amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $allocated = $invoiceIds->isEmpty() ? collect() : CustomerPaymentAllocation::where('company_id', $this->companyId())->whereIn('invoice_id', $invoiceIds)->whereNull('voided_at')->whereDate('allocated_at', '<=', $asOf)->whereHas('payment', fn ($query) => $query->where('company_id', $this->companyId())->where('approval_status', 'approved')->where('is_reversed', false))->selectRaw('invoice_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $rows = $invoices->map(function (Invoice $invoice) use ($paid, $allocated, $asOf): ?array {
            $outstanding = max(0, (float) $invoice->total_amount - (float) ($paid[$invoice->id] ?? 0) - (float) ($allocated[$invoice->id] ?? 0));
            if ($outstanding <= 0.000001) return null;
            $dueDate = CarbonImmutable::parse($invoice->date ?? $invoice->created_at)->addDays((int) ($invoice->customer?->credit_days ?? 0));
            $days = max(0, $dueDate->diffInDays(CarbonImmutable::parse($asOf), false));
            return ['customer' => $invoice->customer, 'invoice' => $invoice, 'due_date' => $dueDate->toDateString(), 'days_overdue' => $days, 'bucket' => $days <= 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'))), 'outstanding' => $outstanding, 'collection_status' => app(\App\Services\CustomerCreditService::class)->collectionStatus($invoice->customer ?: new \App\Models\Customer(), $outstanding, $days)];
        })->filter()->values();
        $legacyInvoiceIds = $invoiceIds->all() ?: [-1];
        $legacyRows = Payment::where('company_id', $this->companyId())->with(['customer', 'invoice'])->where('approval_status', 'approved')->where('due_amount', '>', 0)->where('is_reversed', false)->whereDate('created_at', '<=', $asOf)->whereNotIn('invoice_id', $legacyInvoiceIds)->latest()->get()->map(function (Payment $payment) use ($asOf): array {
            $dueDate = CarbonImmutable::parse($payment->invoice?->date ?? $payment->created_at)->addDays((int) ($payment->customer?->credit_days ?? 0));
            $days = max(0, $dueDate->diffInDays(CarbonImmutable::parse($asOf), false));
            return ['customer' => $payment->customer, 'invoice' => $payment->invoice, 'due_date' => $dueDate->toDateString(), 'days_overdue' => $days, 'bucket' => $days <= 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'))), 'outstanding' => (float) $payment->due_amount, 'collection_status' => app(\App\Services\CustomerCreditService::class)->collectionStatus($payment->customer ?: new \App\Models\Customer(), (float) $payment->due_amount, $days)];
        });
        return $rows->concat($legacyRows);
    }

    private function asOf(Request $request): string
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);
        return (string) ($validated['as_of'] ?? now()->toDateString());
    }
}
