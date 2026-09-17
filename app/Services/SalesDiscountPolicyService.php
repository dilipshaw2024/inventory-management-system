<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;

class SalesDiscountPolicyService
{
    public function assertCanApprove(SalesOrder $order): void
    {
        $gross = (float) $order->lines->sum(fn ($line): float => (float) $line->ordered_qty * (float) $line->unit_price);
        if ($gross <= 0) return;

        $discount = (float) $order->lines->sum('discount_amount');
        $discountPercent = ($discount / $gross) * 100;
        $maximum = (float) app(ErpSettingService::class)->get('max_discount_percent', 100, $order->company_id);
        if ($discountPercent <= $maximum + 0.000001) return;

        if (!auth()->user()?->hasPermission('sales.discount.override')) {
            throw new \RuntimeException('This sales order exceeds the maximum discount policy of '.rtrim(rtrim(number_format($maximum, 4, '.', ''), '0'), '.').'%. Approval requires sales.discount.override permission.');
        }
    }

    public function assertInvoiceCanApprove(Invoice $invoice): void
    {
        $gross = (float) $invoice->invoice_details->sum(fn ($line): float => (float) $line->selling_qty * (float) $line->unit_price);
        if ($gross <= 0) return;

        $discount = (float) ($invoice->payment?->discount_amount ?? 0);
        $this->assertWithinPolicy($discount, $gross, (int) $invoice->company_id);
    }

    public function assertQuotationCanApprove(SalesQuotation $quotation): void
    {
        $gross = (float) $quotation->lines->sum(fn ($line): float => (float) $line->quantity * (float) $line->unit_price);
        if ($gross <= 0) return;

        $discount = (float) $quotation->lines->sum('discount_amount');
        $this->assertWithinPolicy($discount, $gross, (int) $quotation->company_id);
    }

    private function assertWithinPolicy(float $discount, float $gross, int $companyId): void
    {
        $discountPercent = ($discount / $gross) * 100;
        $maximum = (float) app(ErpSettingService::class)->get('max_discount_percent', 100, $companyId);
        if ($discountPercent <= $maximum + 0.000001) return;

        if (!auth()->user()?->hasPermission('sales.discount.override')) {
            throw new \RuntimeException('This sales document exceeds the maximum discount policy of '.rtrim(rtrim(number_format($maximum, 4, '.', ''), '0'), '.').'%. Approval requires sales.discount.override permission.');
        }
    }
}
