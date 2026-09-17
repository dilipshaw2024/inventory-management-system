<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryReturnRequest extends FormRequest
{
    public function authorize(): bool { return auth()->check(); }
    public function rules(): array
    {
        $company = auth()->user()?->company_id;
        $owned = static fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'));
        return ['return_no' => ['nullable', 'string', 'max:100', 'unique:inventory_returns,return_no'], 'return_type' => ['required', 'in:sales,purchase'], 'customer_id' => ['nullable', 'integer', $owned('customers')], 'source_invoice_id' => ['nullable', 'integer', $owned('invoices')], 'source_goods_receipt_id' => ['nullable', 'integer', $owned('goods_receipts')], 'supplier_id' => ['nullable', 'integer', $owned('suppliers')], 'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($company)], 'date' => ['required', 'date'], 'reason_code' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'], 'product_id' => ['required', 'array', 'min:1'], 'product_id.*' => ['required', 'integer', $owned('products')], 'batch_id' => ['nullable', 'array'], 'batch_id.*' => ['nullable', 'integer'], 'quantity' => ['required', 'array', 'min:1'], 'quantity.*' => ['required', 'numeric', 'gt:0'], 'unit_cost' => ['nullable', 'array'], 'unit_cost.*' => ['nullable', 'numeric', 'min:0'], 'unit_price' => ['nullable', 'array'], 'unit_price.*' => ['nullable', 'numeric', 'min:0'], 'tax_rate' => ['nullable', 'array'], 'tax_rate.*' => ['nullable', 'numeric', 'min:0', 'max:100'], 'serial_numbers' => ['nullable', 'array'], 'serial_numbers.*' => ['nullable', 'string', 'max:300'], 'component_serial_numbers' => ['nullable', 'array']];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('return_type') === 'sales' && $this->filled('source_goods_receipt_id')) {
                $validator->errors()->add('source_goods_receipt_id', 'Sales returns must reference a sales invoice, not a goods receipt.');
            }
            if ($this->input('return_type') === 'purchase' && $this->filled('source_invoice_id')) {
                $validator->errors()->add('source_invoice_id', 'Purchase returns must reference a goods receipt, not a sales invoice.');
            }
        });
    }
}
