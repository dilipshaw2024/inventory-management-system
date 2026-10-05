<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InventoryIntercompanyReceipt;
use App\Models\InventoryIntercompanyTransfer;
use App\Models\InventoryIntercompanyTransferAllocation;
use App\Models\InventoryIntercompanyTransferLine;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementAllocation;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\ApprovalGuard;
use App\Services\IntegrationCursorService;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryLedgerService;
use App\Services\SerialLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntercompanyTransferIntegrationController extends Controller
{
    private function companyId(Request $request): int
    {
        abort_unless($request->user()?->company_id, 403, 'A company is required for intercompany transfers.');
        return (int) $request->user()->company_id;
    }

    private function allowedCompanyPair(int $sourceId, int $destinationId): bool
    {
        if ($sourceId === $destinationId) return false;
        $source = Company::findOrFail($sourceId);
        $destination = Company::findOrFail($destinationId);
        return (int) $source->parent_company_id === $destinationId
            || (int) $destination->parent_company_id === $sourceId
            || ($source->parent_company_id && $source->parent_company_id === $destination->parent_company_id);
    }

    private function load(InventoryIntercompanyTransfer $transfer): InventoryIntercompanyTransfer
    {
        return $transfer->load([
            'sourceCompany:id,name,code', 'destinationCompany:id,name,code',
            'lines.product:id,name,sku,company_id,tracking_type',
            'lines.sourceLocation:id,code,name', 'lines.destinationLocation:id,code,name',
            'lines.allocations.batch:id,product_id,batch_no,lot_no,expiry_date,best_before_date',
            'lines.allocations.serial:id,product_id,batch_id,serial_no,status,location_id',
            'receipts:id,transfer_id,company_id,receipt_no,external_reference,date,received_by,created_at',
        ]);
    }

    private function locationsForCompany(array $ids, int $companyId): array
    {
        $locations = InventoryLocation::withoutGlobalScopes()
            ->whereIn('id', array_values(array_unique(array_map('intval', $ids))))
            ->whereHas('warehouse', fn ($warehouse) => $warehouse->withoutGlobalScopes()
                ->whereHas('branch', fn ($branch) => $branch->withoutGlobalScopes()->where('company_id', $companyId)))
            ->get()->keyBy('id');
        if ($locations->count() !== count(array_unique(array_map('intval', $ids)))) abort(422, 'Every location must belong to its declared company.');
        return $locations->all();
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(['status' => ['nullable', 'string', 'max:30'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = InventoryIntercompanyTransfer::with(['sourceCompany:id,name,code', 'destinationCompany:id,name,code'])
            ->where(fn ($scope) => $scope->where('source_company_id', $companyId)->orWhere('destination_company_id', $companyId))
            ->when($data['status'] ?? null, fn ($scope, $status) => $scope->where('status', $status))
            ->latest('id');
        return app(IntegrationCursorService::class)->paginate($query, $request, 'inventory.intercompany_transfers', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $sourceCompanyId = $this->companyId($request);
        $data = $request->validate([
            'destination_company_id' => ['required', 'integer', 'exists:companies,id'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'date' => ['required', 'date'], 'expected_arrival' => ['nullable', 'date', 'after_or_equal:date'],
            'description' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'], 'lines.*.source_location_id' => ['required', 'integer'],
            'lines.*.destination_location_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $destinationCompanyId = (int) $data['destination_company_id'];
        abort_unless($this->allowedCompanyPair($sourceCompanyId, $destinationCompanyId), 422, 'Only affiliated companies can exchange inventory.');
        if (!empty($data['external_reference'])) {
            $existing = InventoryIntercompanyTransfer::where('source_company_id', $sourceCompanyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $this->load($existing), 'status' => 'duplicate_ignored']);
        }
        $sourceLocationIds = collect($data['lines'])->pluck('source_location_id')->all();
        $destinationLocationIds = collect($data['lines'])->pluck('destination_location_id')->all();
        $this->locationsForCompany($sourceLocationIds, $sourceCompanyId);
        $this->locationsForCompany($destinationLocationIds, $destinationCompanyId);
        foreach ($data['lines'] as $line) {
            if ((int) $line['source_location_id'] === (int) $line['destination_location_id']) abort(422, 'Intercompany transfer locations must be different.');
            $product = Product::withoutGlobalScopes()->whereKey($line['product_id'])->firstOrFail();
            if ($product->company_id !== null) abort(422, 'Intercompany transfers require a shared product master with no company owner.');
            if (!$product->is_stock_item) abort(422, 'Only stock products can be transferred between companies.');
        }
        $transfer = DB::transaction(function () use ($request, $data, $sourceCompanyId, $destinationCompanyId): InventoryIntercompanyTransfer {
            $transfer = InventoryIntercompanyTransfer::create([
                'transfer_no' => 'IC-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'source_company_id' => $sourceCompanyId, 'destination_company_id' => $destinationCompanyId,
                'external_reference' => $data['external_reference'] ?? null, 'date' => $data['date'],
                'expected_arrival' => $data['expected_arrival'] ?? null, 'description' => $data['description'] ?? null,
                'status' => 'pending', 'created_by' => $request->user()?->id,
            ]);
            foreach ($data['lines'] as $line) InventoryIntercompanyTransferLine::create([
                'transfer_id' => $transfer->id, 'product_id' => $line['product_id'], 'source_location_id' => $line['source_location_id'],
                'destination_location_id' => $line['destination_location_id'], 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'] ?? null,
            ]);
            app(AuditService::class)->record('intercompany_transfer.created', $transfer, null, $transfer->toArray());
            return $transfer;
        });
        return response()->json(['data' => $this->load($transfer), 'status' => 'pending_approval'], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        try {
            $transfer = DB::transaction(function () use ($companyId, $id): InventoryIntercompanyTransfer {
                $transfer = InventoryIntercompanyTransfer::where('source_company_id', $companyId)->lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'pending') throw new \RuntimeException('Only pending intercompany transfers can be approved.');
                app(ApprovalGuard::class)->assertDifferent($transfer);
                $transfer->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                app(AuditService::class)->record('intercompany_transfer.approved', $transfer, ['status' => 'pending'], ['status' => 'approved']);
                return $transfer->fresh();
            });
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $this->load($transfer), 'status' => $transfer->status]);
    }

    public function dispatchTransfer(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        try {
            $transfer = DB::transaction(function () use ($companyId, $id): InventoryIntercompanyTransfer {
                $transfer = InventoryIntercompanyTransfer::with('lines')->where('source_company_id', $companyId)->lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'approved') throw new \RuntimeException('Only approved intercompany transfers can be dispatched.');
                app(ApprovalGuard::class)->assertDifferent($transfer);
                foreach ($transfer->lines as $line) {
                    $product = Product::withoutGlobalScopes()->whereKey($line->product_id)->lockForUpdate()->firstOrFail();
                    if (app(InventoryAvailabilityService::class)->available($product, false, $line->source_location_id, $companyId) + 0.000001 < (float) $line->quantity) throw new \RuntimeException('Insufficient source stock for '.$product->name.'.');
                    $serials = app(SerialLifecycleService::class)->reserveForTransfer($product, (float) $line->quantity, (int) $line->source_location_id);
                    $movements = collect();
                    if ($serials->isNotEmpty()) {
                        foreach ($serials as $serial) $movements->push(app(InventoryLedgerService::class)->post($line->product_id, 'transfer_out', 1, $line->unit_cost !== null ? (float) $line->unit_cost : null, $line->source_location_id, $transfer, 'Intercompany transfer dispatch', null, $serial->batch_id, $serial->id));
                    } else {
                        $movements->push(app(InventoryLedgerService::class)->post($line->product_id, 'transfer_out', (float) $line->quantity, $line->unit_cost !== null ? (float) $line->unit_cost : null, $line->source_location_id, $transfer, 'Intercompany transfer dispatch'));
                    }
                    foreach ($movements as $movement) {
                        $allocations = InventoryMovementAllocation::where('movement_id', $movement->id)->get();
                        if ($allocations->isEmpty()) $allocations = collect([(object) ['batch_id' => null, 'serial_id' => null, 'quantity' => (float) $movement->quantity, 'unit_cost' => (float) $movement->unit_cost]]);
                        foreach ($allocations as $allocation) InventoryIntercompanyTransferAllocation::create(['transfer_line_id' => $line->id, 'batch_id' => $allocation->batch_id, 'serial_id' => $allocation->serial_id, 'quantity' => $allocation->quantity, 'unit_cost' => $allocation->unit_cost]);
                    }
                }
                $transfer->update(['status' => 'in_transit', 'dispatched_by' => auth()->id(), 'dispatched_at' => now()]);
                app(AuditService::class)->record('intercompany_transfer.dispatched', $transfer, ['status' => 'approved'], ['status' => 'in_transit']);
                return $transfer->fresh();
            });
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $this->load($transfer), 'status' => $transfer->status]);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $destinationCompanyId = $this->companyId($request);
        $data = $request->validate(['external_reference' => ['nullable', 'string', 'max:150'], 'received_quantities' => ['nullable', 'array'], 'received_quantities.*' => ['numeric', 'min:0'], 'receiving_note' => ['nullable', 'string', 'max:2000']]);
        $existingReceipt = !empty($data['external_reference']) ? InventoryIntercompanyReceipt::where('company_id', $destinationCompanyId)->where('external_reference', $data['external_reference'])->first() : null;
        if ($existingReceipt) return response()->json(['data' => $this->load($existingReceipt->transfer), 'receipt' => $existingReceipt, 'status' => 'duplicate_ignored']);
        try {
            [$transfer, $receipt] = DB::transaction(function () use ($destinationCompanyId, $id, $data, $request): array {
                $transfer = InventoryIntercompanyTransfer::with(['lines.allocations'])->where('destination_company_id', $destinationCompanyId)->lockForUpdate()->findOrFail($id);
                if (!in_array($transfer->status, ['in_transit', 'partially_received'], true)) throw new \RuntimeException('Only in-transit intercompany transfers can be received.');
                app(ApprovalGuard::class)->assertDifferent($transfer);
                $receipt = InventoryIntercompanyReceipt::create(['transfer_id' => $transfer->id, 'company_id' => $destinationCompanyId, 'receipt_no' => 'ICR-'.now()->format('YmdHis').'-'.random_int(100, 999), 'external_reference' => $data['external_reference'] ?? null, 'date' => now()->toDateString(), 'received_by' => $request->user()?->id]);
                $receivedQuantities = $data['received_quantities'] ?? [];
                $allReceived = true;
                foreach ($transfer->lines as $line) {
                    $already = (float) $line->received_quantity; $remaining = max(0, (float) $line->quantity - $already);
                    $received = array_key_exists((string) $line->id, $receivedQuantities) ? (float) $receivedQuantities[(string) $line->id] : (array_key_exists($line->id, $receivedQuantities) ? (float) $receivedQuantities[$line->id] : $remaining);
                    if ($received > $remaining + 0.000001) throw new \RuntimeException('Received quantity exceeds the remaining intercompany quantity.');
                    $unreceived = $line->allocations->filter(fn ($allocation): bool => (float) $allocation->received_quantity < (float) $allocation->quantity)->values();
                    $remainingReceipt = $received;
                    foreach ($unreceived as $allocation) {
                        if ($remainingReceipt <= 0.000001) break;
                        $open = (float) $allocation->quantity - (float) $allocation->received_quantity;
                        $quantity = min($remainingReceipt, $open);
                        if ($allocation->serial_id !== null && abs($quantity - round($quantity)) > 0.000001) throw new \RuntimeException('Serialized intercompany receipts must use whole quantities.');
                        if ($allocation->serial_id !== null) {
                            for ($unit = 0; $unit < (int) round($quantity); $unit++) {
                                $serial = InventorySerial::lockForUpdate()->findOrFail($allocation->serial_id);
                                if ($serial->status !== 'reserved') throw new \RuntimeException('A transferred serial is no longer reserved.');
                                app(InventoryLedgerService::class)->post($line->product_id, 'transfer_in', 1, (float) $allocation->unit_cost, $line->destination_location_id, $receipt, 'Intercompany transfer receipt', null, $allocation->batch_id, $serial->id);
                                $serial->update(['status' => 'available', 'location_id' => $line->destination_location_id]);
                            }
                        } else {
                            app(InventoryLedgerService::class)->post($line->product_id, 'transfer_in', $quantity, (float) $allocation->unit_cost, $line->destination_location_id, $receipt, 'Intercompany transfer receipt', null, $allocation->batch_id);
                        }
                        $allocation->update(['received_quantity' => (float) $allocation->received_quantity + $quantity]);
                        $remainingReceipt -= $quantity;
                    }
                    if ($remainingReceipt > 0.000001) throw new \RuntimeException('Intercompany transfer allocation does not cover the received quantity.');
                    $line->update(['received_quantity' => $already + $received]);
                    if ($already + $received < (float) $line->quantity - 0.000001) $allReceived = false;
                }
                $transfer->update(['status' => $allReceived ? 'received' : 'partially_received', 'received_by' => $request->user()?->id, 'received_at' => now(), 'receiving_note' => $data['receiving_note'] ?? null]);
                app(AuditService::class)->record('intercompany_transfer.received', $receipt, null, ['status' => $transfer->status, 'transfer_id' => $transfer->id]);
                return [$transfer->fresh(), $receipt];
            });
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $this->load($transfer), 'receipt' => $receipt, 'status' => $transfer->status]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $transfer = InventoryIntercompanyTransfer::where('source_company_id', $companyId)->lockForUpdate()->findOrFail($id);
        if (!in_array($transfer->status, ['pending', 'approved'], true)) return response()->json(['message' => 'Only pending or approved intercompany transfers can be cancelled.'], 422);
        $transfer->update(['status' => 'cancelled']);
        app(AuditService::class)->record('intercompany_transfer.cancelled', $transfer, null, ['status' => 'cancelled']);
        return response()->json(['data' => $this->load($transfer), 'status' => $transfer->status]);
    }
}
