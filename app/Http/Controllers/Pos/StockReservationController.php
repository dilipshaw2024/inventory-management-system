<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\StockReservation;
use App\Services\AuditService;
use App\Services\StockReservationService;
use Illuminate\Http\Request;

class StockReservationController extends Controller
{
    public function index()
    {
        $reservations = StockReservation::with(['product', 'batch', 'location', 'salesOrderLine.salesOrder', 'creator'])->where('status', 'active')->latest()->paginate(30);
        $locations = InventoryLocation::where('is_active', true)->orderBy('code')->get();
        return view('backend.stock.reservations', compact('reservations', 'locations'));
    }

    public function reassign(Request $request, int $id)
    {
        $data = $request->validate(['location_id' => ['nullable', 'integer', 'exists:inventory_locations,id']]);
        try { $reservation = app(StockReservationService::class)->reassign(StockReservation::findOrFail($id), $data['location_id'] ?? null); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        app(AuditService::class)->record('stock_reservation.reassigned', $reservation, null, ['location_id' => $reservation->location_id]);
        return back()->with(['message' => 'Reservation location updated.', 'alert-type' => 'success']);
    }

    public function release(Request $request, int $id)
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0']]);
        try { $reservation = app(StockReservationService::class)->releaseReservation(StockReservation::findOrFail($id), (float) $data['quantity']); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        app(AuditService::class)->record('stock_reservation.released', $reservation, null, ['released_quantity' => $reservation->released_quantity, 'status' => $reservation->status]);
        return back()->with(['message' => 'Reservation quantity released.', 'alert-type' => 'success']);
    }
}
