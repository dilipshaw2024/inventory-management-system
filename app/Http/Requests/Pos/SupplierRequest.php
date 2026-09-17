<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'tax_jurisdiction' => ['nullable', 'string', 'max:100'],
            'tax_exempt' => ['nullable', 'boolean'],
            'tax_exemption_number' => ['required_if:tax_exempt,1', 'nullable', 'string', 'max:100'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:50'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'is_active' => ['nullable', 'boolean'],
            'planning_weekend_days' => ['nullable', 'string', 'max:20', 'regex:/^[0-6](,[0-6])*$/'],
            'planning_holidays' => ['nullable', 'string', 'max:5000', 'regex:/^(\d{4}-\d{2}-\d{2})(,\d{4}-\d{2}-\d{2})*$/'],
        ];
    }
}
