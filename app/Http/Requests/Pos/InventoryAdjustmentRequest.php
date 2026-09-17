<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $company = auth()->user()?->company_id;
        $productScope = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'));
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($company);
        return [
            'adjustment_no' => ['nullable', 'string', 'max:100', Rule::unique('inventory_adjustments', 'adjustment_no')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'))],
            'date' => ['required', 'date'],
            'reason_code' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', 'integer', $productScope],
            'direction' => ['required', 'array', 'min:1'],
            'direction.*' => ['required', 'in:in,out'],
            'quantity' => ['required', 'array', 'min:1'],
            'quantity.*' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'array'],
            'unit_cost.*' => ['nullable', 'numeric', 'min:0'],
            'batch_no' => ['nullable', 'array'],
            'batch_no.*' => ['nullable', 'string', 'max:100'],
            'serial_numbers' => ['nullable', 'array'],
            'serial_numbers.*' => ['nullable', 'string', 'max:5000'],
            'manufacturing_date' => ['nullable', 'array'],
            'manufacturing_date.*' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'array'],
            'expiry_date.*' => ['nullable', 'date'],
            'best_before_date' => ['nullable', 'array'],
            'best_before_date.*' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'array'],
            'warranty_until.*' => ['nullable', 'date'],
            'location_id' => ['nullable', 'array'],
            'location_id.*' => ['nullable', 'integer', $locationScope],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $fields = ['product_id', 'direction', 'quantity'];
            $counts = collect($fields)->map(fn ($field) => count((array) $this->input($field)))->unique();
            if ($counts->count() > 1) {
                $validator->errors()->add('product_id', 'Adjustment line data is incomplete.');
            }
        });
    }
}
