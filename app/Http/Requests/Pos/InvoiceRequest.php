<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Branch;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $companyId = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $storeScope = Rule::exists('stores', 'id')->where(fn ($query) => $query->whereIn('branch_id', Branch::where('company_id', $companyId)->orWhereNull('company_id')->select('id')));
        return [
            'invoice_no' => ['required', 'string', 'max:255', Rule::unique('invoices', 'invoice_no')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'store_id' => ['nullable', 'integer', $storeScope],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'category_id' => ['required', 'array', 'min:1'],
            'category_id.*' => ['required', 'integer', $owned('categories')],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', 'integer', $owned('products')],
            'selling_qty' => ['required', 'array', 'min:1'],
            'selling_qty.*' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'array', 'min:1'],
            'unit_price.*' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'array', 'min:1'],
            'selling_price.*' => ['required', 'numeric', 'min:0'],
            'batch_no' => ['nullable', 'array'],
            'batch_no.*' => ['nullable', 'string', 'max:100'],
            'serial_numbers' => ['nullable', 'array'],
            'serial_numbers.*' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'tax_mode' => ['nullable', 'in:exclusive,inclusive'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'promotion_code' => ['nullable', 'string', 'max:80'],
            'estimated_amount' => ['required', 'numeric', 'min:0'],
            'paid_status' => ['required', 'in:full_paid,full_due,partial_paid'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['required'],
            'name' => ['required_if:customer_id,0', 'nullable', 'string', 'max:255'],
            'mobile_no' => ['required_if:customer_id,0', 'nullable', 'string', 'max:30'],
            'email' => ['required_if:customer_id,0', 'nullable', 'email', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $fields = ['category_id', 'product_id', 'selling_qty', 'unit_price', 'selling_price'];
            $counts = collect($fields)->map(fn ($field) => count((array) $this->input($field)))->unique();
            if ($counts->count() > 1) {
                $validator->errors()->add('category_id', 'Invoice line data is incomplete. Please remove the incomplete row and add it again.');
            }
        });

        $companyId = auth()->user()?->company_id;
        $customerScope = Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $validator->sometimes('customer_id', ['integer', $customerScope], function ($input) {
            return (string) $input->customer_id !== '0';
        });

        $validator->sometimes('paid_amount', ['required', 'numeric', 'min:0'], function ($input) {
            return $input->paid_status === 'partial_paid';
        });
    }

    public function messages(): array
    {
        return [
            'invoice_no.unique' => 'This invoice number already exists. Please refresh and use the next number.',
            'category_id.required' => 'Please add at least one invoice item.',
            'product_id.*.exists' => 'One selected product is no longer available.',
            'selling_qty.*.gt' => 'Selling quantity must be greater than zero.',
            'paid_status.required' => 'Please select a payment status.',
            'paid_amount.required' => 'Please enter the partial payment amount.',
        ];
    }
}
