<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryTransferRequest extends FormRequest
{
    public function authorize(): bool { return auth()->check(); }

    public function rules(): array
    {
        $company = auth()->user()?->company_id;
        $productScope = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'));
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($company);
        return [
            'transfer_no' => ['nullable', 'string', 'max:100', Rule::unique('inventory_transfers', 'transfer_no')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'))],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'carrier_name' => ['nullable', 'string', 'max:150'],
            'tracking_number' => ['nullable', 'string', 'max:150'],
            'expected_arrival' => ['nullable', 'date', 'after_or_equal:date'],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', 'integer', $productScope],
            'source_location_id' => ['required', 'array', 'min:1'],
            'source_location_id.*' => ['required', 'integer', $locationScope],
            'destination_location_id' => ['required', 'array', 'min:1'],
            'destination_location_id.*' => ['required', 'integer', 'different:source_location_id.*', $locationScope],
            'quantity' => ['required', 'array', 'min:1'],
            'quantity.*' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'array'],
            'unit_cost.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $fields = ['product_id', 'source_location_id', 'destination_location_id', 'quantity'];
            if (collect($fields)->map(fn ($field) => count((array) $this->input($field)))->unique()->count() > 1) {
                $validator->errors()->add('product_id', 'Transfer line data is incomplete.');
            }
            foreach ((array) $this->input('source_location_id') as $index => $source) {
                if ($source && $source === ($this->input('destination_location_id')[$index] ?? null)) {
                    $validator->errors()->add('destination_location_id', 'Source and destination must be different.');
                }
            }
        });
    }
}
