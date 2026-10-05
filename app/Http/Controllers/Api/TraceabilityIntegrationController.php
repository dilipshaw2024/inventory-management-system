<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TraceabilityIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'serial_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'movement_type' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'in:all,inbound,outbound'],
            'posted_from' => ['nullable', 'date'],
            'posted_to' => ['nullable', 'date', 'after_or_equal:posted_from'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for inventory traceability.');

        if (!empty($data['product_id']) && !Product::whereKey($data['product_id'])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->exists()) {
            abort(404, 'Product not found.');
        }
        if (!empty($data['location_id']) && !InventoryLocation::whereKey($data['location_id'])
            ->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) {
            abort(422, 'Location is not authorized for this company.');
        }

        $inbound = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $outbound = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        $movements = InventoryMovement::with([
            'product:id,name,sku,company_id',
            'location:id,code,name',
            'creator:id,name,email',
            'batch:id,product_id,batch_no,lot_no,manufacturing_date,expiry_date,best_before_date,warranty_until',
            'serial:id,product_id,batch_id,serial_no,status,warranty_until',
            'allocations.batch:id,product_id,batch_no,lot_no,manufacturing_date,expiry_date,best_before_date,warranty_until',
            'allocations.serial:id,product_id,batch_id,serial_no,status,warranty_until',
            'reference',
        ])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['batch_id'] ?? null, fn ($query, $id) => $query->where(fn ($nested) => $nested->where('batch_id', $id)->orWhereHas('allocations', fn ($allocation) => $allocation->where('batch_id', $id))))
            ->when($data['serial_id'] ?? null, fn ($query, $id) => $query->where(fn ($nested) => $nested->where('serial_id', $id)->orWhereHas('allocations', fn ($allocation) => $allocation->where('serial_id', $id))))
            ->when($data['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($data['movement_type'] ?? null, fn ($query, $type) => $query->where('movement_type', $type))
            ->when($data['direction'] ?? null, function ($query, $direction) use ($inbound, $outbound): void {
                if ($direction === 'inbound') $query->whereIn('movement_type', $inbound);
                if ($direction === 'outbound') $query->whereIn('movement_type', $outbound);
            })
            ->when($data['posted_from'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '>=', $date))
            ->when($data['posted_to'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '<=', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');

        return app(IntegrationCursorService::class)->paginate(
            $movements,
            $request,
            'inventory.traceability',
            (int) ($data['per_page'] ?? 50)
        );
    }
}
