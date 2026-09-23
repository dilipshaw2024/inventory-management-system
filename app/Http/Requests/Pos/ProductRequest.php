<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $companyId = auth()->user()?->company_id;
        $companyScope = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        return [
            'name' => ['required', 'string', 'max:255'],
            'supplier_id' => ['required', 'integer', $companyScope('suppliers')],
            'unit_id' => ['required', 'integer', $companyScope('units')],
            'category_id' => ['required', 'integer', $companyScope('categories')],
            'brand_id' => ['nullable', 'integer', $companyScope('brands')],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'hsn_sac_code' => ['nullable', 'string', 'max:30'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sales_price' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'gte:min_stock'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_rate_id' => ['nullable', 'integer', $companyScope('tax_rates')],
            'tracking_type' => ['required', 'in:none,batch,serial'],
            'product_type' => ['nullable', 'in:stock,service,consumable,asset,bundle'],
            'lifecycle_status' => ['nullable', 'in:draft,active,discontinued,blocked,archived'],
            'costing_method' => ['nullable', 'in:fifo,weighted_average,moving_average,standard'],
            'standard_cost' => ['nullable', 'numeric', 'min:0'],
            'can_purchase' => ['nullable', 'boolean'],
            'can_sell' => ['nullable', 'boolean'],
            'is_stock_item' => ['nullable', 'boolean'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'length_m' => ['nullable', 'numeric', 'min:0'],
            'width_m' => ['nullable', 'numeric', 'min:0'],
            'height_m' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
