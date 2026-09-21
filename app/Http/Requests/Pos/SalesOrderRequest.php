<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesOrderRequest extends FormRequest
{
    public function authorize(): bool { return auth()->check(); }
    public function rules(): array
    {
        $company = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'));
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($company);
        return ['order_no' => ['nullable', 'string', 'max:100', Rule::unique('sales_orders', 'order_no')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'))], 'customer_id' => ['required', 'integer', $owned('customers')], 'store_id' => ['nullable', 'integer', $owned('stores')], 'location_id' => ['nullable', 'integer', $locationScope], 'date' => ['required', 'date'], 'requested_date' => ['nullable', 'date', 'after_or_equal:date'], 'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'allow_backorders' => ['nullable', 'boolean'], 'description' => ['nullable', 'string', 'max:2000'], 'promotion_code' => ['nullable', 'string', 'max:80'], 'product_id' => ['required', 'array', 'min:1'], 'product_id.*' => ['required', 'integer', $owned('products')], 'batch_id' => ['nullable', 'array'], 'batch_id.*' => ['nullable', 'integer'], 'uom_id' => ['nullable', 'array'], 'uom_id.*' => ['nullable', 'integer', $owned('units')], 'ordered_qty' => ['required', 'array', 'min:1'], 'ordered_qty.*' => ['required', 'numeric', 'gt:0'], 'unit_price' => ['required', 'array', 'min:1'], 'unit_price.*' => ['required', 'numeric', 'min:0'], 'discount_amount' => ['nullable', 'array'], 'discount_amount.*' => ['nullable', 'numeric', 'min:0']];
    }
}
