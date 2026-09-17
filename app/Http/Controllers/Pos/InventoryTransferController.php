<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\InventoryTransferRequest;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\InventoryLedgerService;
use App\Services\NumberingSequenceService;
use App\Services\InventoryAvailabilityService;
use App\Services\SerialLifecycleService;
use App\Models\InventoryTransferSerial;
use App\Models\InventoryTransferAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class InventoryTransferController extends Controller
{
    public function index()
    {
        $transfers = InventoryTransfer::with(['creator', 'approver', 'lines.product'])->latest()->paginate(30);
        return view('backend.stock.transfer_all', compact('transfers'));
    }

    public function create()
    {
        $products = Product::orderBy('name')->get();
        $locations = InventoryLocation::where('is_active', true)->orderBy('code')->get();
        return view('backend.stock.transfer_add', compact('products', 'locations'));
    }

    public function store(InventoryTransferRequest $request)
    {
        DB::transaction(function () use ($request): void {
            $transfer = InventoryTransfer::create([
                'transfer_no' => $request->transfer_no ?: app(NumberingSequenceService::class)->nextOrFallback('inventory_transfer', 'TRF-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id),
                'date' => $request->date,
                'description' => $request->description,
                'carrier_name' => $request->carrier_name,
                'tracking_number' => $request->tracking_number,
                'expected_arrival' => $request->expected_arrival,
                'created_by' => auth()->id(),
            ]);
            foreach ($request->product_id as $index => $productId) {
                InventoryTransferLine::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $productId,
                    'source_location_id' => $request->source_location_id[$index],
                    'destination_location_id' => $request->destination_location_id[$index],
                    'quantity' => $request->quantity[$index],
                    'unit_cost' => $request->unit_cost[$index] ?? null,
                ]);
            }
            app(AuditService::class)->record('inventory_transfer.created', $transfer, null, $transfer->toArray());
        });

        return redirect()->route('inventory.transfers')->with(['message' => 'Transfer submitted for approval.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryTransfer::class, $id);
        try {
            DB::transaction(function () use ($id): void {
                $transfer = InventoryTransfer::with('lines')->lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'pending') {
                    throw new \RuntimeException('This transfer has already been processed.');
                }
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);

                $transfer->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                app(AuditService::class)->record('inventory_transfer.approved', $transfer, ['status' => 'pending'], ['status' => 'approved']);
            });
        } catch (\RuntimeException $exception) {
            return redirect()->back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }

        return redirect()->route('inventory.transfers')->with(['message' => 'Transfer approved and ready for dispatch.', 'alert-type' => 'success']);
    }

    public function dispatchTransfer(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryTransfer::class, $id);
        try {
            DB::transaction(function () use ($id): void {
                $transfer = InventoryTransfer::with('lines')->lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'approved') throw new \RuntimeException('Only approved transfers can be dispatched.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
                foreach ($transfer->lines as $line) {
                    $product = Product::lockForUpdate()->findOrFail($line->product_id);
                    if (app(InventoryAvailabilityService::class)->available($product, false, $line->source_location_id, $transfer->company_id) < (float) $line->quantity) throw new \RuntimeException('Insufficient stock at the selected source location for '.$product->name.'.');
                    $reservedSerials = app(SerialLifecycleService::class)->reserveForTransfer($product, (float) $line->quantity, (int) $line->source_location_id);
                    if ($reservedSerials->isNotEmpty()) {
                        foreach ($reservedSerials as $serial) { InventoryTransferSerial::create(['transfer_line_id' => $line->id, 'serial_id' => $serial->id]); $movement = app(InventoryLedgerService::class)->post($line->product_id, 'transfer_out', 1, $line->unit_cost ? (float) $line->unit_cost : null, $line->source_location_id, $transfer, 'Warehouse transfer dispatch', null, $serial->batch_id, $serial->id); foreach ($movement->allocations as $allocation) InventoryTransferAllocation::create(['transfer_line_id' => $line->id, 'batch_id' => $allocation->batch_id, 'serial_id' => $allocation->serial_id, 'quantity' => $allocation->quantity]); }
                    } else {
                        $movement = app(InventoryLedgerService::class)->post($line->product_id, 'transfer_out', (float) $line->quantity, $line->unit_cost ? (float) $line->unit_cost : null, $line->source_location_id, $transfer, 'Warehouse transfer dispatch');
                        foreach ($movement->allocations as $allocation) InventoryTransferAllocation::create(['transfer_line_id' => $line->id, 'batch_id' => $allocation->batch_id, 'serial_id' => $allocation->serial_id, 'quantity' => $allocation->quantity]);
                    }
                }
                $transfer->update(['status' => 'in_transit', 'dispatched_by' => auth()->id(), 'dispatched_at' => now()]);
                app(AuditService::class)->record('inventory_transfer.dispatched', $transfer, ['status' => 'approved'], ['status' => 'in_transit']);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Transfer dispatched and marked in transit.', 'alert-type' => 'success']);
    }

    public function receive(Request $request, int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryTransfer::class, $id);
        $request->validate(['received_quantity' => ['nullable', 'array'], 'received_quantity.*' => ['nullable', 'numeric', 'min:0'], 'receiving_note' => ['nullable', 'string', 'max:2000']]);
        try {
            DB::transaction(function () use ($id, $request): void {
                $transfer = InventoryTransfer::with(['lines.transferSerials.serial'])->lockForUpdate()->findOrFail($id);
                if (!in_array($transfer->status, ['in_transit', 'partially_received'], true)) throw new \RuntimeException('Only in-transit transfers can be received.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
                $receivedQuantities = $request->input('received_quantity', []);
                $allReceived = true;
                foreach ($transfer->lines as $line) {
                    $alreadyReceived = (float) ($line->received_quantity ?? 0);
                    $remaining = max(0, (float) $line->quantity - $alreadyReceived);
                    $received = array_key_exists($line->id, $receivedQuantities) ? (float) $receivedQuantities[$line->id] : $remaining;
                    if ($received < 0 || $received > $remaining) throw new \RuntimeException('Received quantity must be between zero and the remaining transfer quantity.');
                    $receivedSerialRows = collect();
                    if ($received > 0 && $line->transferSerials->isNotEmpty()) {
                        $serialRows = $line->transferSerials->whereNull('received_at')->take((int) round($received));
                        if ($serialRows->count() !== (int) round($received)) throw new \RuntimeException('Received serial quantity does not match the transferred serial allocation.');
                        foreach ($serialRows as $serialRow) { $serialRow->serial->update(['status' => 'available', 'location_id' => $line->destination_location_id]); $serialRow->update(['received_at' => now()]); $receivedSerialRows->push($serialRow); }
                    }
                    if ($receivedSerialRows->isNotEmpty()) {
                        foreach ($receivedSerialRows as $serialRow) {
                            app(InventoryLedgerService::class)->post($line->product_id, 'transfer_in', 1, $line->unit_cost ? (float) $line->unit_cost : null, $line->destination_location_id, $transfer, 'Warehouse transfer receipt', null, $serialRow->serial->batch_id, $serialRow->serial_id);
                            $allocation = InventoryTransferAllocation::where('transfer_line_id', $line->id)->where('serial_id', $serialRow->serial_id)->lockForUpdate()->first();
                            if ($allocation) { $allocation->received_quantity = min((float) $allocation->quantity, (float) $allocation->received_quantity + 1); $allocation->save(); }
                        }
                    } elseif ($received > 0) {
                        $remainingReceipt = $received;
                        $allocations = InventoryTransferAllocation::where('transfer_line_id', $line->id)->whereColumn('received_quantity', '<', 'quantity')->orderBy('id')->lockForUpdate()->get();
                        foreach ($allocations as $allocation) {
                            if ($remainingReceipt <= 0.000001) break;
                            $open = (float) $allocation->quantity - (float) $allocation->received_quantity;
                            $quantity = min($remainingReceipt, $open);
                            if ($quantity <= 0.000001) continue;
                            app(InventoryLedgerService::class)->post($line->product_id, 'transfer_in', $quantity, $line->unit_cost ? (float) $line->unit_cost : null, $line->destination_location_id, $transfer, 'Warehouse transfer receipt', null, $allocation->batch_id);
                            $allocation->received_quantity = (float) $allocation->received_quantity + $quantity;
                            $allocation->save();
                            $remainingReceipt -= $quantity;
                        }
                        if ($remainingReceipt > 0.000001) app(InventoryLedgerService::class)->post($line->product_id, 'transfer_in', $remainingReceipt, $line->unit_cost ? (float) $line->unit_cost : null, $line->destination_location_id, $transfer, 'Warehouse transfer receipt');
                    }
                    $line->received_quantity = $alreadyReceived + $received;
                    $line->save();
                    if ((float) $line->received_quantity < (float) $line->quantity) $allReceived = false;
                }
                $transfer->update(['status' => $allReceived ? 'received' : 'partially_received', 'received_by' => auth()->id(), 'received_at' => now(), 'receiving_note' => $request->input('receiving_note'), 'variance_status' => $allReceived && $transfer->lines->contains(fn ($line): bool => (float) $line->received_quantity < (float) $line->quantity) ? 'pending' : ($allReceived ? null : $transfer->variance_status)]);
                app(AuditService::class)->record('inventory_transfer.received', $transfer, ['status' => 'in_transit'], ['status' => $transfer->status, 'receiving_note' => $transfer->receiving_note]);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Transfer received into the destination location.', 'alert-type' => 'success']);
    }

    public function resolveVariance(Request $request, int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryTransfer::class, $id);
        $data = $request->validate([
            'resolution' => ['required', 'in:accepted,waived'],
            'variance_reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($id, $data): void {
                $transfer = InventoryTransfer::with('lines')->lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'received' || $transfer->variance_status !== 'pending') {
                    throw new \RuntimeException('Only a fully received transfer with a pending variance can be resolved.');
                }
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
                $before = $transfer->only(['variance_status', 'variance_reason', 'variance_resolved_by', 'variance_resolved_at']);
                $transfer->update(['variance_status' => $data['resolution'], 'variance_reason' => $data['variance_reason'], 'variance_resolved_by' => auth()->id(), 'variance_resolved_at' => now()]);
                app(\App\Services\AutomaticAccountingService::class)->postTransferShortage($transfer);
                app(AuditService::class)->record('inventory_transfer.variance_resolved', $transfer, $before, $transfer->only(['variance_status', 'variance_reason', 'variance_resolved_by', 'variance_resolved_at']));
            });
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }

        return back()->with(['message' => 'Transfer variance resolved and recorded.', 'alert-type' => 'success']);
    }

    public function closeShortage(Request $request, int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryTransfer::class, $id);
        $data = $request->validate(['shortage_reason' => ['required', 'string', 'max:2000']]);

        try {
            DB::transaction(function () use ($id, $data): void {
                $transfer = InventoryTransfer::with('lines')->lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'partially_received' || !$transfer->lines->contains(fn ($line): bool => (float) $line->received_quantity < (float) $line->quantity)) {
                    throw new \RuntimeException('Only partially received transfers with an outstanding shortage can be closed.');
                }
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
                $before = $transfer->only(['status', 'variance_status', 'variance_reason']);
                $transfer->update(['status' => 'received', 'variance_status' => 'pending', 'variance_reason' => $data['shortage_reason'], 'received_at' => $transfer->received_at ?: now()]);
                app(AuditService::class)->record('inventory_transfer.shortage_closed', $transfer, $before, $transfer->only(['status', 'variance_status', 'variance_reason']));
            });
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }

        return back()->with(['message' => 'Transfer shortage closed and submitted for variance resolution.', 'alert-type' => 'success']);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $transfer = InventoryTransfer::findOrFail($id);
        if ($transfer->status !== 'pending') {
            return redirect()->back()->with(['message' => 'This transfer has already been processed.', 'alert-type' => 'error']);
        }
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $transfer->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $transfer->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('inventory_transfer.rejected', $transfer, $before, $transfer->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return redirect()->route('inventory.transfers')->with(['message' => 'Transfer rejected.', 'alert-type' => 'success']);
    }
}
