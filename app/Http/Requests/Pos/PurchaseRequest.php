<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'array', 'min:1'],
            'date.*' => ['required', 'date'],
            'purchase_no' => ['required', 'array', 'min:1'],
            'purchase_no.*' => ['required', 'string', 'max:255'],
            'supplier_id' => ['required', 'array', 'min:1'],
            'supplier_id.*' => ['required', 'integer', 'exists:suppliers,id'],
            'category_id' => ['required', 'array', 'min:1'],
            'category_id.*' => ['required', 'integer', 'exists:categories,id'],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', 'integer', 'exists:products,id'],
            'buying_qty' => ['required', 'array', 'min:1'],
            'buying_qty.*' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'array', 'min:1'],
            'unit_price.*' => ['required', 'numeric', 'min:0'],
            'buying_price' => ['required', 'array', 'min:1'],
            'buying_price.*' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'array'],
            'description.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $fields = ['date', 'purchase_no', 'supplier_id', 'category_id', 'product_id', 'buying_qty', 'unit_price', 'buying_price'];
            $counts = collect($fields)->map(fn ($field) => count((array) $this->input($field)))->unique();
            if ($counts->count() > 1) {
                $validator->errors()->add('category_id', 'Purchase line data is incomplete. Please remove the incomplete row and add it again.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Please add at least one purchase item.',
            'product_id.*.exists' => 'One selected product is no longer available.',
            'buying_qty.*.gt' => 'Purchase quantity must be greater than zero.',
            'unit_price.*.min' => 'Purchase unit price cannot be negative.',
        ];
    }
}
