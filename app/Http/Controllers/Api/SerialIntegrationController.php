<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventorySerial;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SerialIntegrationController extends Controller
{
    public function stock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:available,reserved,issued,returned,scrapped,quarantine'],
            'product_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for serial stock reporting.');

        if (!empty($data['location_id']) && !InventoryLocation::whereKey($data['location_id'])
            ->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) {
            abort(422, 'Location is not authorized for this company.');
        }

        $serials = InventorySerial::with([
            'product:id,name,sku,company_id,tracking_type',
            'batch:id,product_id,batch_no,lot_no,expiry_date,best_before_date',
            'location:id,code,name',
        ])
            ->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['batch_id'] ?? null, fn ($query, $id) => $query->where('batch_id', $id))
            ->when($data['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');

        return app(IntegrationCursorService::class)->paginate($serials, $request, 'inventory.serials', (int) ($data['per_page'] ?? 50));
    }
}
