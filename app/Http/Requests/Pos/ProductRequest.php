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
        return [
            'name' => ['required', 'string', 'max:255'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
        ];
    }
}
