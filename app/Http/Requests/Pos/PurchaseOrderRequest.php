<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool { return auth()->check(); }
    public function rules(): array
    {
        $company = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'));
        return [
            'po_no' => ['nullable', 'string', 'max:100', Rule::unique('purchase_orders', 'po_no')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'))],
            'supplier_id' => ['required', 'integer', $owned('suppliers')],
            'date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', 'integer', $owned('products')],
            'location_id' => ['nullable', 'array'],
            'location_id.*' => ['nullable', 'integer', $owned('inventory_locations')],
            'uom_id' => ['nullable', 'array'],
            'uom_id.*' => ['nullable', 'integer', $owned('units')],
            'ordered_qty' => ['required', 'array', 'min:1'],
            'ordered_qty.*' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'array', 'min:1'],
            'unit_price.*' => ['required', 'numeric', 'min:0'],
        ];
    }
}
