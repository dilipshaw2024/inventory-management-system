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
    public function traceability(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['nullable', 'in:all,inbound,outbound'],
            'location_id' => ['nullable', 'integer'],
            'posted_from' => ['nullable', 'date'],
            'posted_to' => ['nullable', 'date', 'after_or_equal:posted_from'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for serial traceability.');
        $serial = InventorySerial::whereKey($id)->whereHas('product', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        if (!empty($data['location_id']) && !InventoryLocation::whereKey($data['location_id'])->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) abort(422, 'Location is not authorized for this company.');
        $inbound = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $outbound = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        $movements = \App\Models\InventoryMovement::with([
            'product:id,name,sku,company_id', 'location:id,code,name', 'creator:id,name,email',
            'batch:id,product_id,batch_no,lot_no,manufacturing_date,expiry_date,best_before_date,warranty_until',
            'serial:id,product_id,batch_id,serial_no,status,warranty_until',
            'allocations.batch:id,product_id,batch_no,lot_no,manufacturing_date,expiry_date,best_before_date,warranty_until',
            'allocations.serial:id,product_id,batch_id,serial_no,status,warranty_until',
        ])->where('product_id', $serial->product_id)
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where(fn ($query) => $query->where('serial_id', $serial->id)->orWhereHas('allocations', fn ($allocation) => $allocation->where('serial_id', $serial->id)))
            ->when($data['location_id'] ?? null, fn ($query, $locationId) => $query->where('location_id', $locationId))
            ->when($data['direction'] ?? null, function ($query, $direction) use ($inbound, $outbound): void {
                if ($direction === 'inbound') $query->whereIn('movement_type', $inbound);
                if ($direction === 'outbound') $query->whereIn('movement_type', $outbound);
            })
            ->when($data['posted_from'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '>=', $date))
            ->when($data['posted_to'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '<=', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($movements, $request, 'inventory.serial.traceability.'.$id, (int) ($data['per_page'] ?? 50));
    }

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
