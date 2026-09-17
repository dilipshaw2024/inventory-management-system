<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\SalesOrder;

class DeliveryRequest extends FormRequest
{
    public function authorize(): bool { return auth()->check(); }
    public function rules(): array
    {
        $company = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'));
        $ownedSalesOrderIds = SalesOrder::withoutGlobalScopes()->where('company_id', $company)->orWhereNull('company_id')->select('id');
        return ['delivery_no' => ['nullable', 'string', 'max:100', Rule::unique('deliveries', 'delivery_no')->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'))], 'sales_order_id' => ['required', 'integer', $owned('sales_orders')], 'date' => ['required', 'date'], 'delivery_address' => ['nullable', 'string', 'max:2000'], 'carrier' => ['nullable', 'string', 'max:255'], 'tracking_no' => ['nullable', 'string', 'max:255'], 'package_count' => ['nullable', 'integer', 'min:1'], 'total_weight' => ['nullable', 'numeric', 'min:0'], 'weight_unit' => ['nullable', 'string', 'max:12'], 'length' => ['nullable', 'numeric', 'gt:0'], 'width' => ['nullable', 'numeric', 'gt:0'], 'height' => ['nullable', 'numeric', 'gt:0'], 'proof_of_delivery' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'line_id' => ['required', 'array', 'min:1'], 'line_id.*' => ['required', 'integer', Rule::exists('sales_order_lines', 'id')->where(fn ($query) => $query->whereIn('sales_order_id', $ownedSalesOrderIds))], 'uom_id' => ['nullable', 'array'], 'uom_id.*' => ['nullable', 'integer', $owned('units')], 'delivered_qty' => ['required', 'array', 'min:1'], 'delivered_qty.*' => ['required', 'numeric', 'gt:0'], 'unit_price' => ['required', 'array', 'min:1'], 'unit_price.*' => ['required', 'numeric', 'min:0'], 'batch_no' => ['nullable', 'array'], 'batch_no.*' => ['nullable', 'string', 'max:100'], 'serial_numbers' => ['nullable', 'array'], 'serial_numbers.*' => ['nullable', 'string', 'max:5000']];
    }
}
