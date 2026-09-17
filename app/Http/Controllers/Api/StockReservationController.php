<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockReservation;
use App\Models\Branch;
use App\Services\AuditService;
use App\Services\StockReservationService;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:active,released'], 'product_id' => ['nullable', 'integer'], 'batch_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        abort_unless($request->user()?->company_id, 403, 'A company is required for reservations.');
        $reservations = StockReservation::with(['product', 'batch', 'location', 'salesOrderLine.salesOrder'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($data['batch_id'] ?? null, fn ($q, $id) => $q->where('batch_id', $id))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($data['updated_since'] ?? null, fn ($q, $date) => $q->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($reservations, $request, 'inventory.reservations', (int) ($data['per_page'] ?? 50));
    }

    public function reassign(Request $request, int $id): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($companyId);
        $data = $request->validate(['location_id' => ['nullable', 'integer', $locationScope]]);
        if (!empty($data['location_id']) && !\App\Models\InventoryLocation::whereKey($data['location_id'])->exists()) abort(422, 'Location is not authorized for this company.');
        try { $reservation = app(StockReservationService::class)->reassign(StockReservation::findOrFail($id), $data['location_id'] ?? null); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('stock_reservation.reassigned', $reservation, null, ['location_id' => $reservation->location_id]);
        return response()->json(['data' => $reservation, 'status' => 'active']);
    }

    public function release(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0']]);
        try { $reservation = app(StockReservationService::class)->releaseReservation(StockReservation::findOrFail($id), (float) $data['quantity']); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('stock_reservation.released', $reservation, null, ['released_quantity' => $reservation->released_quantity, 'status' => $reservation->status]);
        return response()->json(['data' => $reservation, 'status' => $reservation->status]);
    }
}
