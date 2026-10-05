<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\InventoryLocation;
use App\Models\InventorySerial;
use App\Models\InventoryStatusTransfer;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryReturnLine;
use App\Models\Product;
use App\Models\QualityInspection;
use App\Models\QualityInspectionPlan;
use App\Models\QualityInspectionPlanLine;
use App\Models\QualityInspectionResult;
use App\Services\AuditService;
use App\Services\InventoryStatusService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QualityInspectionIntegrationController extends Controller
{
    public function plans(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for quality plans.');
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'inspection_type' => ['nullable', 'in:receiving,in_process,final,return'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $plans = QualityInspectionPlan::with(['product:id,name,sku', 'lines'])
            ->where('company_id', $companyId)
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['inspection_type'] ?? null, fn ($query, $type) => $query->where('inspection_type', $type))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->latest('id');

        return response()->json($plans->paginate((int) ($data['per_page'] ?? 50)));
    }

    public function storePlan(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for quality plans.');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:180'],
            'inspection_type' => ['required', 'in:receiving,in_process,final,return'],
            'sampling_percent' => ['nullable', 'numeric', 'gt:0', 'lte:100'],
            'product_id' => ['nullable', 'integer'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sequence' => ['required', 'integer', 'min:1'],
            'lines.*.characteristic' => ['required', 'string', 'max:180'],
            'lines.*.data_type' => ['required', 'in:numeric,text,boolean'],
            'lines.*.unit' => ['nullable', 'string', 'max:40'],
            'lines.*.target_value' => ['nullable', 'numeric'],
            'lines.*.minimum_value' => ['nullable', 'numeric'],
            'lines.*.maximum_value' => ['nullable', 'numeric', 'gte:lines.*.minimum_value'],
            'lines.*.is_required' => ['nullable', 'boolean'],
        ]);

        if (!empty($data['product_id'])) {
            Product::withoutGlobalScopes()
                ->whereKey($data['product_id'])
                ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                ->firstOrFail();
        }
        if (!empty($data['external_reference'])) {
            $existing = QualityInspectionPlan::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('external_reference', $data['external_reference'])
                ->with(['product', 'lines'])
                ->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }

        $lines = $data['lines'];
        unset($data['lines']);
        $plan = DB::transaction(function () use ($data, $lines, $companyId, $request): QualityInspectionPlan {
            $plan = QualityInspectionPlan::create($data + [
                'company_id' => $companyId,
                'sampling_percent' => $data['sampling_percent'] ?? 100,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $request->user()?->id,
            ]);
            foreach ($lines as $line) $plan->lines()->create($line);
            app(AuditService::class)->record('quality.plan.created', $plan, null, $plan->fresh()->load('lines')->toArray());
            return $plan->fresh()->load(['product', 'lines']);
        });

        return response()->json(['data' => $plan, 'status' => 'created'], 201);
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for quality plans.');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'sampling_percent' => ['sometimes', 'numeric', 'gt:0', 'lte:100'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $plan = QualityInspectionPlan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('lines')
            ->findOrFail($id);
        $before = $plan->toArray();
        $samplingChanged = array_key_exists('sampling_percent', $data)
            && (float) $data['sampling_percent'] !== (float) $plan->sampling_percent;
        if ($samplingChanged && $plan->inspections()->whereIn('status', ['pending', 'in_progress'])->exists()) {
            abort(422, 'Sampling rules cannot change while this plan has open inspections.');
        }
        if (array_key_exists('external_reference', $data) && $data['external_reference'] !== $plan->external_reference && $data['external_reference'] !== null) {
            $duplicate = QualityInspectionPlan::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('external_reference', $data['external_reference'])
                ->where('id', '<>', $plan->id)
                ->exists();
            if ($duplicate) abort(422, 'The external reference is already used by another quality plan.');
        }

        $plan->update($data);
        app(AuditService::class)->record('quality.plan.updated', $plan, $before, $plan->fresh()->load('lines')->toArray());
        return response()->json(['data' => $plan->fresh()->load(['product', 'lines']), 'status' => 'updated']);
    }

    public function inspections(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for quality inspections.');
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,in_progress,passed,failed,conditionally_accepted'],
            'product_id' => ['nullable', 'integer'],
            'inspection_type' => ['nullable', 'in:receiving,in_process,final,return'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = QualityInspection::with(['plan:id,code,name,inspection_type', 'product:id,name,sku', 'batch:id,batch_no', 'serial:id,serial_no', 'results'])
            ->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($data['inspection_type'] ?? null, fn ($q, $type) => $q->whereHas('plan', fn ($plan) => $plan->where('inspection_type', $type)))
            ->latest('id');
        return response()->json($query->paginate((int) ($data['per_page'] ?? 50)));
    }

    public function storeInspection(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for quality inspections.');
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'serial_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'integer'],
            'inspection_no' => ['nullable', 'string', 'max:100'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $plan = QualityInspectionPlan::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->with('lines')->findOrFail($data['plan_id']);
        $product = Product::withoutGlobalScopes()->whereKey($data['product_id'])->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        if ($plan->product_id && (int) $plan->product_id !== (int) $product->id) abort(422, 'The inspection plan is not valid for this product.');

        if (!empty($data['batch_id'])) InventoryBatch::withoutGlobalScopes()->whereKey($data['batch_id'])->where('product_id', $product->id)->firstOrFail();
        if (!empty($data['serial_id'])) InventorySerial::withoutGlobalScopes()->whereKey($data['serial_id'])->where('product_id', $product->id)->firstOrFail();
        if (!empty($data['location_id'])) $this->authorizedLocation($request, (int) $data['location_id'], $companyId);

        if (!empty($data['external_reference'])) {
            $existing = QualityInspection::withoutGlobalScopes()->where('company_id', $companyId)->where('external_reference', $data['external_reference'])->with(['plan.lines', 'product', 'results'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }

        $inspection = QualityInspection::create($data + [
            'company_id' => $companyId,
            'inspection_no' => $data['inspection_no'] ?? ('QI-'.now()->format('YmdHisv').'-'.random_int(100, 999)),
            'status' => 'pending',
            'sample_quantity' => $this->sampleQuantity((float) $data['quantity'], (float) ($plan->sampling_percent ?: 100)),
            'inspected_by' => null,
        ]);
        app(AuditService::class)->record('quality.inspection.created', $inspection, null, $inspection->toArray());
        return response()->json(['data' => $inspection->fresh()->load(['plan.lines', 'product', 'results']), 'status' => 'created'], 201);
    }

    public function recordResults(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['results' => ['required', 'array', 'min:1', 'max:100'], 'results.*.plan_line_id' => ['required', 'integer'], 'results.*.value_numeric' => ['nullable', 'numeric'], 'results.*.value_text' => ['nullable', 'string', 'max:3000'], 'results.*.value_boolean' => ['nullable', 'boolean'], 'results.*.notes' => ['nullable', 'string', 'max:2000']]);
        $inspection = QualityInspection::withoutGlobalScopes()->where('company_id', $companyId)->with(['plan.lines', 'results'])->findOrFail($id);
        if (in_array($inspection->status, ['passed', 'failed', 'conditionally_accepted'], true)) abort(422, 'Completed inspections cannot be changed.');
        $lines = $inspection->plan->lines->keyBy('id');

        DB::transaction(function () use ($data, $inspection, $lines, $request): void {
            foreach ($data['results'] as $item) {
                $line = $lines->get((int) $item['plan_line_id']);
                if (!$line) abort(422, 'The result line does not belong to the inspection plan.');
                $result = $this->evaluateResult($line, $item);
                QualityInspectionResult::updateOrCreate(
                    ['inspection_id' => $inspection->id, 'plan_line_id' => $line->id],
                    $result + ['notes' => $item['notes'] ?? null]
                );
            }
            $inspection->update(['status' => 'in_progress', 'started_at' => $inspection->started_at ?? now()]);
            app(AuditService::class)->record('quality.inspection.results_recorded', $inspection, null, ['result_count' => count($data['results']), 'actor_id' => $request->user()?->id]);
        });
        return response()->json(['data' => $inspection->fresh()->load(['plan.lines', 'results']), 'status' => 'in_progress']);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['disposition' => ['required', 'in:release,quarantine,rework,scrap'], 'notes' => ['nullable', 'string', 'max:3000']]);
        $inspection = QualityInspection::withoutGlobalScopes()->where('company_id', $companyId)->with(['plan.lines', 'results'])->findOrFail($id);
        if (in_array($inspection->status, ['passed', 'failed', 'conditionally_accepted'], true)) return response()->json(['data' => $inspection, 'status' => 'already_completed']);
        $required = $inspection->plan->lines->where('is_required', true)->pluck('id');
        $resultLines = $inspection->results->pluck('plan_line_id');
        if ($required->diff($resultLines)->isNotEmpty()) abort(422, 'All required inspection characteristics must have results.');
        $failed = $inspection->results->contains(fn ($result) => $result->status === 'failed');
        if ($failed && $data['disposition'] === 'release') abort(422, 'A failed inspection cannot be released.');
        $status = $failed ? 'failed' : 'passed';
        $before = $inspection->toArray();
        $inspection->update(['status' => $status, 'disposition' => $data['disposition'], 'notes' => $data['notes'] ?? $inspection->notes, 'inspected_by' => $request->user()?->id, 'completed_at' => now(), 'started_at' => $inspection->started_at ?? now()]);
        $this->syncGoodsReceiptInspection($inspection);
        $this->syncInventoryReturnInspection($inspection);
        app(AuditService::class)->record('quality.inspection.completed', $inspection, $before, $inspection->fresh()->toArray());
        return response()->json(['data' => $inspection->fresh()->load(['plan.lines', 'results']), 'status' => $status]);
    }

    public function applyDisposition(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for quality dispositions.');
        $inspection = QualityInspection::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['product', 'plan', 'inventoryStatusTransfer'])
            ->findOrFail($id);
        if (!in_array($inspection->status, ['passed', 'failed', 'conditionally_accepted'], true)) {
            abort(422, 'Only completed quality inspections can apply a disposition.');
        }
        if ($inspection->location_id) $this->authorizedLocation($request, (int) $inspection->location_id, $companyId);
        if ($inspection->disposition_applied_at) {
            return response()->json(['data' => $inspection, 'status' => 'already_applied']);
        }

        if ($inspection->disposition === 'release') {
            $inspection->update(['disposition_applied_at' => now()]);
            app(AuditService::class)->record('quality.inspection.disposition_applied', $inspection, null, ['disposition' => 'release', 'inventory_status_transfer_id' => null]);
            return response()->json(['data' => $inspection->fresh()->load(['product', 'plan', 'inventoryStatusTransfer']), 'status' => 'applied']);
        }

        $toStatus = $inspection->disposition === 'scrap' ? 'scrap' : 'quarantine';
        try {
            $inspection = DB::transaction(function () use ($inspection, $companyId, $request, $toStatus): QualityInspection {
                $locked = QualityInspection::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->findOrFail($inspection->id);
                if ($locked->disposition_applied_at) return $locked->fresh()->load(['product', 'plan', 'inventoryStatusTransfer']);
                $transfer = InventoryStatusTransfer::create([
                    'company_id' => $companyId,
                    'external_reference' => 'QUALITY-'.$locked->id.'-DISPOSITION',
                    'transfer_no' => app(NumberingSequenceService::class)->nextOrFallback('inventory_status_transfer', 'ST-QI-'.$locked->id.'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                    'product_id' => $locked->product_id,
                    'batch_id' => $locked->batch_id,
                    'serial_id' => $locked->serial_id,
                    'location_id' => $locked->location_id,
                    'from_status' => 'available',
                    'to_status' => $toStatus,
                    'quantity' => $locked->quantity,
                    'reason' => 'Quality inspection '.$locked->inspection_no.' disposition: '.$locked->disposition,
                    'inspection_required' => false,
                    'inspection_status' => 'not_required',
                    'status' => 'pending',
                    'created_by' => $request->user()?->id,
                ]);
                app(InventoryStatusService::class)->apply($transfer);
                $transfer->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now()]);
                $locked->update(['inventory_status_transfer_id' => $transfer->id, 'disposition_applied_at' => now()]);
                app(AuditService::class)->record('quality.inspection.disposition_applied', $locked, null, ['disposition' => $locked->disposition, 'inventory_status_transfer_id' => $transfer->id]);
                return $locked->fresh()->load(['product', 'plan', 'inventoryStatusTransfer']);
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $inspection, 'status' => 'applied']);
    }

    private function syncInventoryReturnInspection(QualityInspection $inspection): void
    {
        if ($inspection->source_type !== 'inventory_return_line' || !$inspection->source_id) return;
        $line = InventoryReturnLine::with('inventoryReturn')->find($inspection->source_id);
        $return = $line?->inventoryReturn;
        if (!$return) return;
        $inspections = $return->lines()->whereNotNull('quality_inspection_id')->with('qualityInspection')->get()->pluck('qualityInspection')->filter();
        $hasFailed = $inspections->contains(fn ($item) => $item->status === 'failed');
        $allPassed = $inspections->isNotEmpty() && $inspections->every(fn ($item) => $item->status === 'passed');
        $return->update(['inspection_status' => $hasFailed ? 'failed' : ($allPassed ? 'passed' : 'pending')]);
    }

    private function syncGoodsReceiptInspection(QualityInspection $inspection): void
    {
        if ($inspection->source_type !== 'goods_receipt_line' || !$inspection->source_id) return;
        $line = GoodsReceiptLine::with('goodsReceipt')->find($inspection->source_id);
        $receipt = $line?->goodsReceipt;
        if (!$receipt) return;
        $inspections = $receipt->lines()->whereNotNull('quality_inspection_id')->with('qualityInspection')->get()->pluck('qualityInspection')->filter();
        $hasFailed = $inspections->contains(fn ($item) => in_array($item->status, ['failed'], true) || ($item->status === 'passed' && $item->disposition !== 'release'));
        $allReleased = $inspections->isNotEmpty() && $inspections->every(fn ($item) => $item->status === 'passed' && $item->disposition === 'release');
        $receipt->update(['inspection_status' => $hasFailed ? 'failed' : ($allReleased ? 'passed' : 'pending')]);
    }

    private function authorizedLocation(Request $request, int $locationId, int $companyId): InventoryLocation
    {
        $user = $request->user();
        $location = InventoryLocation::withoutGlobalScopes()
            ->whereKey($locationId)
            ->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))
            ->when($user?->warehouse_id, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->first();

        abort_unless($location, 404, 'The inventory location is not available to this user.');
        return $location;
    }

    private function evaluateResult(QualityInspectionPlanLine $line, array $item): array
    {
        if ($line->data_type === 'numeric') {
            if (!array_key_exists('value_numeric', $item)) abort(422, 'A numeric result is required.');
            $value = (float) $item['value_numeric'];
            $passed = ($line->minimum_value === null || $value >= (float) $line->minimum_value)
                && ($line->maximum_value === null || $value <= (float) $line->maximum_value);
            return ['value_numeric' => $value, 'value_text' => null, 'value_boolean' => null, 'status' => $passed ? 'passed' : 'failed'];
        }
        if ($line->data_type === 'boolean') {
            if (!array_key_exists('value_boolean', $item)) abort(422, 'A boolean result is required.');
            return ['value_numeric' => null, 'value_text' => null, 'value_boolean' => (bool) $item['value_boolean'], 'status' => $item['value_boolean'] ? 'passed' : 'failed'];
        }
        if (!array_key_exists('value_text', $item) || trim((string) $item['value_text']) === '') abort(422, 'A text result is required.');
        return ['value_numeric' => null, 'value_text' => (string) $item['value_text'], 'value_boolean' => null, 'status' => 'passed'];
    }

    private function sampleQuantity(float $quantity, float $samplingPercent): float
    {
        if ($quantity <= 0) return 0.0;
        $percent = max(0.0001, min(100.0, $samplingPercent));
        return min($quantity, max(1.0, ceil($quantity * $percent / 100)));
    }
}
