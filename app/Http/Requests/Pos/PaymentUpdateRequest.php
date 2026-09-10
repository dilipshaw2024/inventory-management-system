<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class PaymentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'paid_status' => ['required', 'in:full_paid,partial_paid'],
            'paid_amount' => ['nullable', 'numeric', 'gt:0'],
            'date' => ['required', 'date'],
        ];
    }
}
