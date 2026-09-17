<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\PurchaseOrder;

class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool { return auth()->check(); }
    public function rules(): array
    {
        $company = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'));
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($company);
        $ownedPurchaseOrderIds = PurchaseOrder::withoutGlobalScopes()->where('company_id', $company)->orWhereNull('company_id')->select('id');
        return [
            'grn_no' => ['nullable', 'string', 'max:100', Rule::unique('goods_receipts', 'grn_no')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'))],
            'purchase_order_id' => ['required', 'integer', $owned('purchase_orders')],
            'location_id' => ['nullable', 'integer', $locationScope],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'inspection_required' => ['nullable', 'boolean'],
            'line_id' => ['required', 'array', 'min:1'],
            'line_id.*' => ['required', 'integer', Rule::exists('purchase_order_lines', 'id')->where(fn ($query) => $query->whereIn('purchase_order_id', $ownedPurchaseOrderIds))],
            'received_qty' => ['required', 'array', 'min:1'], 'uom_id' => ['nullable', 'array'], 'uom_id.*' => ['nullable', 'integer', $owned('units')],
            'received_qty.*' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'array', 'min:1'],
            'unit_cost.*' => ['required', 'numeric', 'min:0'],
            'batch_no' => ['nullable', 'array'],
            'batch_no.*' => ['nullable', 'string', 'max:100'],
            'serial_numbers' => ['nullable', 'array'],
            'serial_numbers.*' => ['nullable', 'string', 'max:5000'],
            'manufacturing_date' => ['nullable', 'array'],
            'manufacturing_date.*' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'array'],
            'expiry_date.*' => ['nullable', 'date', 'after_or_equal:manufacturing_date.*'],
            'best_before_date' => ['nullable', 'array'],
            'best_before_date.*' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'array'],
            'warranty_until.*' => ['nullable', 'date'],
        ];
    }
}
