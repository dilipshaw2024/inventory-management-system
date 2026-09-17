<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\InventoryReturn;
use App\Models\Delivery;
use App\Models\InventoryTransfer;
use App\Models\LandedCost;
use App\Models\SupplierClaim;
use App\Models\SupplierCreditNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AutomaticAccountingService
{
    public function postInventoryMovement(InventoryMovement $movement, float $totalCost, ?Model $source = null): void
    {
        $internalTransfer = $source instanceof InventoryTransfer;
        $salesReturn = $movement->movement_type === 'return_in' && $source instanceof InventoryReturn && $source->return_type === 'sales';
        $purchaseReturn = $movement->movement_type === 'return_out' && $source instanceof InventoryReturn && $source->return_type === 'purchase';
        $operation = $internalTransfer && $movement->movement_type === 'transfer_in'
            ? ['debit' => 'inventory', 'credit' => 'inventory_in_transit']
            : ($internalTransfer && $movement->movement_type === 'transfer_out'
                ? ['debit' => 'inventory_in_transit', 'credit' => 'inventory']
                : match ($movement->movement_type) {
                    'receipt', 'opening', 'transfer_in', 'adjustment_in' => ['debit' => 'inventory', 'credit' => 'grni'],
                    'return_in' => ($salesReturn || $source instanceof Delivery) ? ['debit' => 'inventory', 'credit' => 'cogs'] : ['debit' => 'inventory', 'credit' => 'grni'],
                    'issue', 'transfer_out' => ['debit' => 'cogs', 'credit' => 'inventory'],
                    'adjustment_out', 'scrap' => ['debit' => 'inventory_loss', 'credit' => 'inventory'],
                    'return_out' => $purchaseReturn ? ['debit' => 'accounts_payable', 'credit' => 'inventory'] : ['debit' => 'inventory_loss', 'credit' => 'inventory'],
                    default => null,
                });
        if (!$operation || $totalCost <= 0) return;
        $companyId = $source?->getAttribute('company_id') ?: $movement->getAttribute('company_id') ?: auth()->user()?->company_id;
        $currency = $this->currency($companyId);
        $debit = $this->account($operation['debit'], $companyId);
        $credit = $this->account($operation['credit'], $companyId);
        if (!$debit || !$credit) return;
        app(AccountingService::class)->post([
            'company_id' => $companyId,
            'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))),
            'date' => optional($movement->posted_at)->toDateString() ?? now()->toDateString(),
            'description' => 'Automatic inventory '.$movement->movement_type,
        ], [
            ['account_id' => $debit, 'debit' => $totalCost, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1],
            ['account_id' => $credit, 'debit' => 0, 'credit' => $totalCost, 'currency_code' => $currency, 'exchange_rate' => 1],
        ], $source ?? $movement);
    }

    public function postTransferShortage(InventoryTransfer $transfer): void
    {
        $transfer->loadMissing('lines.product');
        $totalCost = 0.0;

        foreach ($transfer->lines as $line) {
            $shortage = max(0, (float) $line->quantity - (float) ($line->received_quantity ?? 0));
            if ($shortage <= 0) continue;

            $unitCost = $line->unit_cost !== null
                ? (float) $line->unit_cost
                : (float) ($line->product?->purchase_price ?? 0);
            $totalCost += $shortage * $unitCost;
        }

        if ($totalCost <= 0) return;

        $companyId = $transfer->getAttribute('company_id') ?: auth()->user()?->company_id;
        $debit = $this->account('inventory_loss', $companyId);
        $credit = $this->account('inventory_in_transit', $companyId);
        if (!$debit || !$credit) return;

        $currency = $this->currency($companyId);
        app(AccountingService::class)->post([
            'company_id' => $companyId,
            'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))),
            'date' => now()->toDateString(),
            'description' => 'Transfer shortage settlement '.$transfer->transfer_no,
        ], [
            ['account_id' => $debit, 'debit' => $totalCost, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1],
            ['account_id' => $credit, 'debit' => 0, 'credit' => $totalCost, 'currency_code' => $currency, 'exchange_rate' => 1],
        ], $transfer);
    }

    public function postLandedCost(LandedCost $landedCost, ?float $inventoryAmount = null, ?float $consumedAmount = null): void
    {
        $amount = (float) $landedCost->amount;
        if ($amount <= 0) return;

        $inventoryAmount = $inventoryAmount === null ? $amount : max(0, $inventoryAmount);
        $consumedAmount = $consumedAmount === null ? 0 : max(0, $consumedAmount);

        $companyId = $landedCost->getAttribute('company_id') ?: auth()->user()?->company_id;
        $inventory = $this->account('inventory', $companyId);
        $clearing = $this->account('landed_cost_clearing', $companyId);
        if (!$inventory || !$clearing) return;
        $cogs = $consumedAmount > 0 ? $this->account('cogs', $companyId) : null;
        if ($consumedAmount > 0 && !$cogs) return;

        $currency = $this->currency($companyId);
        $lines = [];
        if ($inventoryAmount > 0) $lines[] = ['account_id' => $inventory, 'debit' => $inventoryAmount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1];
        if ($consumedAmount > 0) $lines[] = ['account_id' => $cogs, 'debit' => $consumedAmount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1];
        $lines[] = ['account_id' => $clearing, 'debit' => 0, 'credit' => $amount, 'currency_code' => $currency, 'exchange_rate' => 1];
        app(AccountingService::class)->post([
            'company_id' => $companyId,
            'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))),
            'date' => now()->toDateString(),
            'description' => 'Landed cost '.$landedCost->cost_no,
        ], $lines, $landedCost);
    }

    public function postSalesReturn(InventoryReturn $return): void
    {
        if ($return->return_type !== 'sales') return;
        $subtotal = 0.0; $taxAmount = 0.0;
        foreach ($return->lines as $line) {
            $product = $line->product;
            $lineSubtotal = (float) $line->quantity * (float) ($line->unit_price ?? $product?->sales_price ?? 0);
            $subtotal += $lineSubtotal;
            $taxAmount += $lineSubtotal * ($return->tax_exempt ? 0 : (float) ($line->tax_rate ?? $product?->tax_rate ?? 0)) / 100;
        }
        if ($subtotal <= 0) return;
        $companyId = auth()->user()?->company_id;
        $currency = $this->currency($companyId);
        $returns = $this->account('sales_returns', $companyId) ?? $this->account('sales_revenue', $companyId);
        $receivable = $this->account('accounts_receivable', $companyId);
        $tax = $taxAmount > 0 ? ($this->account('tax_payable', $companyId) ?? $this->account('sales_tax', $companyId)) : null;
        if (!$returns || !$receivable || ($taxAmount > 0 && !$tax)) return;
        $lines = [['account_id' => $returns, 'debit' => $subtotal, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1]];
        if ($taxAmount > 0) $lines[] = ['account_id' => $tax, 'debit' => $taxAmount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1];
        $lines[] = ['account_id' => $receivable, 'debit' => 0, 'credit' => $subtotal + $taxAmount, 'currency_code' => $currency, 'exchange_rate' => 1];
        app(AccountingService::class)->post(['company_id' => $companyId, 'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))), 'date' => $return->date->toDateString(), 'description' => 'Sales return '.$return->return_no], $lines, $return);
    }

    public function postSupplierClaimSettlement(SupplierClaim $claim, float $amount)
    {
        if ($amount <= 0) return null;
        $companyId = $claim->company_id ?: auth()->user()?->company_id;
        $payable = $this->account('accounts_payable', $companyId);
        $recovery = $this->account('supplier_claim_recovery', $companyId) ?? $this->account('inventory_loss', $companyId);
        if (!$payable || !$recovery) return null;
        $currency = $this->currency($companyId);
        return app(AccountingService::class)->post([
            'company_id' => $companyId,
            'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))),
            'date' => $claim->settled_at?->toDateString() ?: now()->toDateString(),
            'description' => 'Supplier claim settlement '.$claim->claim_no,
        ], [
            ['account_id' => $payable, 'debit' => $amount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1],
            ['account_id' => $recovery, 'debit' => 0, 'credit' => $amount, 'currency_code' => $currency, 'exchange_rate' => 1],
        ], $claim);
    }

    public function postSupplierCreditNote(SupplierCreditNote $note, float $amount)
    {
        if ($amount <= 0) return null;
        $companyId = $note->company_id ?: auth()->user()?->company_id;
        $payable = $this->account('accounts_payable', $companyId);
        $recovery = $this->account('supplier_claim_recovery', $companyId) ?? $this->account('inventory_loss', $companyId);
        if (!$payable || !$recovery) return null;
        $currency = $this->currency($companyId);
        return app(AccountingService::class)->post([
            'company_id' => $companyId, 'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))),
            'date' => $note->credit_date?->toDateString() ?: now()->toDateString(),
            'description' => 'Supplier credit note '.$note->credit_no,
        ], [
            ['account_id' => $payable, 'debit' => $amount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1],
            ['account_id' => $recovery, 'debit' => 0, 'credit' => $amount, 'currency_code' => $currency, 'exchange_rate' => 1],
        ], $note);
    }

    public function postSalesInvoice(Invoice $invoice): void
    {
        $companyId = auth()->user()?->company_id;
        $currency = $this->currency($companyId);
        $receivable = $this->account('accounts_receivable', $companyId);
        $revenue = $this->account('sales_revenue', $companyId);
        $tax = $this->account('tax_payable', $companyId) ?? $this->account('sales_tax', $companyId);
        $rate = (float) ($invoice->exchange_rate ?: 1);
        $subtotal = (float) $invoice->subtotal_amount * $rate;
        $taxAmount = (float) $invoice->tax_amount * $rate;
        if (!$receivable || !$revenue || $subtotal <= 0 || $subtotal + $taxAmount <= 0) return;
        $lines = [
            ['account_id' => $receivable, 'debit' => $subtotal + $taxAmount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1],
            ['account_id' => $revenue, 'debit' => 0, 'credit' => $subtotal, 'currency_code' => $currency, 'exchange_rate' => 1],
        ];
        if ($taxAmount > 0 && $tax) $lines[] = ['account_id' => $tax, 'debit' => 0, 'credit' => $taxAmount, 'currency_code' => $currency, 'exchange_rate' => 1];
        if (!$tax && $taxAmount > 0) return;
        app(AccountingService::class)->post(['company_id' => $companyId, 'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))), 'date' => Carbon::parse($invoice->date)->toDateString(), 'description' => 'Sales invoice '.$invoice->invoice_no], $lines, $invoice);
    }

    public function postCustomerPayment(Payment $payment): void
    {
        $amount = (float) ($payment->base_amount ?: ((float) $payment->paid_amount * (float) ($payment->exchange_rate ?: 1)));
        if ($amount <= 0) return;
        $companyId = auth()->user()?->company_id;
        $currency = $this->currency($companyId);
        $cash = $this->account('cash_bank', $companyId);
        $receivable = $this->account('accounts_receivable', $companyId);
        if (!$cash || !$receivable) return;
        app(AccountingService::class)->post(['company_id' => $companyId, 'entry_no' => 'JE-'.strtoupper(bin2hex(random_bytes(6))), 'date' => Carbon::parse($payment->created_at ?? now())->toDateString(), 'description' => 'Customer payment for invoice '.$payment->invoice_id], [
            ['account_id' => $cash, 'debit' => $amount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => 1],
            ['account_id' => $receivable, 'debit' => 0, 'credit' => $amount, 'currency_code' => $currency, 'exchange_rate' => 1],
        ], $payment);
    }

    private function account(string $key, ?int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)->where(function ($query) use ($companyId): void {
            $query->where('company_id', $companyId)->orWhereNull('company_id');
        })->orderByRaw('company_id IS NULL')->value('account_id');
    }

    private function currency(?int $companyId): string
    {
        return $companyId ? (\App\Models\Company::whereKey($companyId)->value('base_currency') ?: 'USD') : 'USD';
    }
}
