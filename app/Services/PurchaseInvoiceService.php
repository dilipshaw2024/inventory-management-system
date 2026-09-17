<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    public function approve(PurchaseInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            foreach ($invoice->lines as $line) {
                $poLine = $line->purchaseOrderLine()->lockForUpdate()->firstOrFail();
                $receiptLine = null;
                if ($line->goods_receipt_line_id) {
                    $receiptLine = GoodsReceiptLine::with('goodsReceipt')->lockForUpdate()->findOrFail($line->goods_receipt_line_id);
                    if ((int) $receiptLine->purchase_order_line_id !== (int) $poLine->id || (int) $receiptLine->product_id !== (int) $line->product_id || $receiptLine->goodsReceipt?->status !== 'approved') {
                        throw new \RuntimeException('Invoice receipt reference does not match an approved receipt for '.$line->product->name.'.');
                    }
                }
                $tolerance = (float) app(ErpSettingService::class)->get('purchase_price_variance_percent', 0);
                $baselinePrice = $receiptLine ? (float) $receiptLine->unit_cost : (float) $poLine->unit_price;
                if ($tolerance > 0 && $baselinePrice > 0) {
                    $variance = abs((float) $line->unit_price - $baselinePrice) / $baselinePrice * 100;
                    if ($variance > $tolerance + 0.000001) throw new \RuntimeException('Invoice price variance exceeds the configured '.$tolerance.'% tolerance against the '.($receiptLine ? 'receipt' : 'purchase-order').' price for '.$line->product->name.'.');
                }
                $alreadyBilled = (float) $invoice->lines()->where('purchase_order_line_id', $line->purchase_order_line_id)->where('id', '<>', $line->id)->sum('quantity');
                $approvedOther = (float) DB::table('purchase_invoice_lines')->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_lines.purchase_invoice_id')->where('purchase_invoice_lines.purchase_order_line_id', $line->purchase_order_line_id)->where('purchase_invoices.status', 'approved')->sum('purchase_invoice_lines.quantity');
                if ($alreadyBilled + $approvedOther + (float) $line->quantity > (float) $poLine->received_qty + 0.000001) throw new \RuntimeException('Invoice quantity exceeds received quantity for '.$line->product->name.'.');
            }
            $companyId = auth()->user()?->company_id;
            $currency = $companyId ? (\App\Models\Company::whereKey($companyId)->value('base_currency') ?: 'USD') : 'USD';
            $convertedTotal = (float) $invoice->total_amount * (float) ($invoice->exchange_rate ?: 1);
            $debit = $this->account('grni', $companyId);
            $credit = $this->account('accounts_payable', $companyId);
            if ($debit && $credit) {
                app(AccountingService::class)->post(['company_id' => $companyId, 'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))), 'date' => $invoice->invoice_date->toDateString(), 'description' => 'Purchase invoice '.$invoice->invoice_no], [['account_id' => $debit, 'debit' => $convertedTotal, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1], ['account_id' => $credit, 'debit' => 0, 'credit' => $convertedTotal, 'currency_code' => $currency, 'exchange_rate' => 1]], $invoice);
            }
            $invoice->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    private function account(string $key, ?int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->orderByRaw('company_id IS NULL')->value('account_id');
    }
}
