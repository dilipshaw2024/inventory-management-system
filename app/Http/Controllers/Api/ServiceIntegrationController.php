<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceOrder;
use App\Models\ServiceAsset;
use App\Models\ServiceRequest;
use App\Models\ServiceTechnician;
use App\Models\AssetSparePart;
use App\Models\Product;
use App\Models\MaintenancePart;
use App\Models\ServiceMaintenanceSchedule;
use App\Models\WarrantyClaim;
use App\Services\ProductLifecycleService;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryLedgerService;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use App\Services\IntegrationCursorService;
use App\Services\AssetValuationService;
use Carbon\Carbon;
use App\Services\AssetDepreciationPostingService;
use App\Models\AssetOwnershipTransfer;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\ServiceContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\TaxCalculationService;
use App\Services\CurrencyConversionService;

class ServiceIntegrationController extends Controller
{
    private function companyUnique(string $table, string $column)
    {
        $companyId = auth()->user()?->company_id;
        return Rule::unique($table, $column)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    private function companyExists(string $table)
    {
        $companyId = auth()->user()?->company_id;
        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    public function assets(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:active,under_service,retired'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $assets = $this->companyScope(ServiceAsset::with(['product', 'customer']))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($assets, $request, 'service.assets', (int) ($data['per_page'] ?? 50));
    }

    public function storeAsset(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'asset_no' => ['nullable', 'string', 'max:80', $this->companyUnique('service_assets', 'asset_no')],
            'external_reference' => ['nullable', 'string', 'max:150', $this->companyUnique('service_assets', 'external_reference')],
            'name' => ['required', 'string', 'max:255'], 'manufacturer' => ['nullable', 'string', 'max:150'], 'model_no' => ['nullable', 'string', 'max:150'],
            'installation_date' => ['nullable', 'date'], 'condition' => ['nullable', 'in:operational,needs_repair,out_of_service'], 'meter_value' => ['nullable', 'numeric', 'min:0'],
            'product_id' => ['nullable', 'integer', $this->companyExists('products')],
            'customer_id' => ['nullable', 'integer', $this->companyExists('customers')],
            'serial_no' => ['nullable', 'string', 'max:100'], 'location' => ['nullable', 'string', 'max:500'],
            'warranty_until' => ['nullable', 'date'], 'status' => ['nullable', 'in:active,retired'],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0'], 'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['nullable', 'integer', 'min:1'], 'depreciation_method' => ['nullable', 'in:straight_line,declining_balance,units_of_production'],
            'depreciation_units_total' => ['nullable', 'numeric', 'gt:0'], 'depreciation_units_used' => ['nullable', 'numeric', 'min:0'],
            'in_service_date' => ['nullable', 'date'], 'accumulated_depreciation' => ['nullable', 'numeric', 'min:0'],
        ]);
        if ((float) ($data['salvage_value'] ?? 0) > (float) ($data['acquisition_cost'] ?? 0)) abort(422, 'Salvage value cannot exceed acquisition cost.');
        if ((float) ($data['accumulated_depreciation'] ?? 0) > max(0, (float) ($data['acquisition_cost'] ?? 0) - (float) ($data['salvage_value'] ?? 0))) abort(422, 'Accumulated depreciation cannot exceed the depreciable base.');
        if (isset($data['depreciation_units_total'], $data['depreciation_units_used']) && (float) $data['depreciation_units_used'] > (float) $data['depreciation_units_total']) abort(422, 'Used depreciation units cannot exceed total units.');
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(ServiceAsset::query())->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['product', 'customer']), 'status' => 'duplicate_ignored']);
        }
        $asset = DB::transaction(function () use ($data, $companyId): ServiceAsset {
            $attributes = array_merge($data, ['company_id' => $companyId, 'status' => $data['status'] ?? 'active']);
            if (empty($attributes['asset_no'])) $attributes['asset_no'] = app(NumberingSequenceService::class)->nextOrFallback('service_asset', 'AST-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId);
            $asset = ServiceAsset::create($attributes);
            app(AuditService::class)->record('service_asset.created', $asset, null, $asset->toArray() + ['api' => true]);
            return $asset;
        });
        return response()->json(['data' => $asset->load(['product', 'customer']), 'status' => 'created'], 201);
    }

    public function updateAsset(Request $request, int $id): JsonResponse
    {
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($id);
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'asset_no' => ['sometimes', 'string', 'max:80', Rule::unique('service_assets', 'asset_no')->ignore($asset->id)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('service_assets', 'external_reference')->ignore($asset->id)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'name' => ['sometimes', 'string', 'max:255'], 'manufacturer' => ['sometimes', 'nullable', 'string', 'max:150'], 'model_no' => ['sometimes', 'nullable', 'string', 'max:150'], 'installation_date' => ['sometimes', 'nullable', 'date'], 'condition' => ['sometimes', 'in:operational,needs_repair,out_of_service'], 'meter_value' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'product_id' => ['sometimes', 'nullable', 'integer', $this->companyExists('products')],
            'customer_id' => ['sometimes', 'nullable', 'integer', $this->companyExists('customers')], 'serial_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'], 'warranty_until' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'in:active,retired'],
            'acquisition_cost' => ['sometimes', 'numeric', 'min:0'], 'salvage_value' => ['sometimes', 'numeric', 'min:0'],
            'useful_life_months' => ['sometimes', 'nullable', 'integer', 'min:1'], 'depreciation_method' => ['sometimes', 'in:straight_line,declining_balance,units_of_production'],
            'depreciation_units_total' => ['sometimes', 'nullable', 'numeric', 'gt:0'], 'depreciation_units_used' => ['sometimes', 'numeric', 'min:0'],
            'in_service_date' => ['sometimes', 'nullable', 'date'], 'accumulated_depreciation' => ['sometimes', 'numeric', 'min:0'],
        ]);
        if (($data['status'] ?? null) === 'active' && $asset->status === 'retired') abort(422, 'Retired assets cannot be reactivated.');
        $cost = (float) ($data['acquisition_cost'] ?? $asset->acquisition_cost ?? 0);
        $salvage = (float) ($data['salvage_value'] ?? $asset->salvage_value ?? 0);
        $accumulated = (float) ($data['accumulated_depreciation'] ?? $asset->accumulated_depreciation ?? 0);
        $unitsTotal = (float) ($data['depreciation_units_total'] ?? $asset->depreciation_units_total ?? 0);
        $unitsUsed = (float) ($data['depreciation_units_used'] ?? $asset->depreciation_units_used ?? 0);
        if ($salvage > $cost) abort(422, 'Salvage value cannot exceed acquisition cost.');
        if ($accumulated > max(0, $cost - $salvage)) abort(422, 'Accumulated depreciation cannot exceed the depreciable base.');
        if ($unitsTotal > 0 && $unitsUsed > $unitsTotal) abort(422, 'Used depreciation units cannot exceed total units.');
        $before = $asset->only(array_keys($data));
        $asset->update($data);
        app(AuditService::class)->record('service_asset.updated', $asset, $before, $asset->fresh()->only(array_keys($data)) + ['api' => true]);
        return response()->json(['data' => $asset->fresh()->load(['product', 'customer']), 'status' => 'updated']);
    }

    public function valuation(Request $request, int $id): JsonResponse
    {
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($id);
        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        return response()->json(['data' => app(AssetValuationService::class)->snapshot($asset, isset($data['as_of']) ? Carbon::parse($data['as_of']) : null)]);
    }

    public function postDepreciation(Request $request, int $id): JsonResponse
    {
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($id);
        $data = $request->validate(['date' => ['required', 'date']]);
        $entry = app(AssetDepreciationPostingService::class)->post($asset, $data['date']);
        app(AuditService::class)->record('service_asset.depreciation_posted', $entry, null, $entry->toArray() + ['asset_id' => $asset->id, 'api' => true]);
        return response()->json(['data' => $entry->load('journal.lines'), 'status' => 'posted'], 201);
    }

    public function ownershipHistory(int $id): JsonResponse
    {
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($id);
        return response()->json(['data' => $asset->ownershipTransfers()->with(['previousCustomer', 'newCustomer', 'creator'])->get(), 'asset_id' => $asset->id]);
    }

    public function assetHistory(int $id): JsonResponse
    {
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($id);
        return response()->json(['data' => [
            'asset' => $asset->load(['product', 'customer']),
            'requests' => $asset->requests()->with(['customer', 'assignee', 'contract'])->latest()->get(),
            'maintenance_orders' => $asset->maintenanceOrders()->with(['assignee', 'serviceRequest', 'serviceInvoice', 'laborJournal'])->latest()->get(),
            'warranty_claims' => $asset->warrantyClaims()->with(['customer', 'product', 'creator'])->latest()->get(),
            'ownership_transfers' => $asset->ownershipTransfers()->with(['previousCustomer', 'newCustomer', 'creator'])->get(),
            'depreciation_entries' => $asset->depreciationEntries()->with('journal')->latest('depreciation_date')->get(),
        ], 'asset_id' => $asset->id]);
    }

    public function transferOwnership(Request $request, int $id): JsonResponse
    {
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($id);
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'new_customer_id' => ['nullable', 'integer', $this->companyExists('customers')],
            'effective_date' => ['required', 'date'], 'external_reference' => ['nullable', 'string', 'max:150', $this->companyUnique('asset_ownership_transfers', 'external_reference')],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        if ((int) ($data['new_customer_id'] ?? 0) === (int) ($asset->customer_id ?? 0)) abort(422, 'The new owner is already the current owner.');
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(AssetOwnershipTransfer::query())->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['asset', 'previousCustomer', 'newCustomer']), 'status' => 'duplicate_ignored']);
        }
        $transfer = DB::transaction(function () use ($asset, $data, $companyId): AssetOwnershipTransfer {
            $locked = ServiceAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $transfer = AssetOwnershipTransfer::create([
                'company_id' => $companyId, 'asset_id' => $locked->id,
                'previous_customer_id' => $locked->customer_id, 'new_customer_id' => $data['new_customer_id'] ?? null,
                'effective_date' => $data['effective_date'], 'external_reference' => $data['external_reference'] ?? null,
                'reason' => $data['reason'], 'created_by' => auth()->id(),
            ]);
            $locked->update(['customer_id' => $data['new_customer_id'] ?? null]);
            app(AuditService::class)->record('service_asset.ownership_transferred', $transfer, null, $transfer->toArray() + ['asset_id' => $locked->id, 'api' => true]);
            return $transfer;
        });
        return response()->json(['data' => $transfer->load(['asset', 'previousCustomer', 'newCustomer']), 'status' => 'created'], 201);
    }

    public function spareParts(int $id): JsonResponse
    {
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($id);
        return response()->json(['data' => $asset->spareParts()->with('product')->orderBy('id')->get(), 'asset_id' => $asset->id]);
    }

    public function storeSparePart(Request $request, int $id): JsonResponse
    {
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($id);
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'product_id' => ['required', 'integer', $this->companyExists('products')],
            'quantity_per_service' => ['required', 'numeric', 'gt:0'], 'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock' => ['nullable', 'numeric', 'gte:minimum_stock'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $product = $this->companyScope(Product::query())->findOrFail($data['product_id']);
        app(ProductLifecycleService::class)->assertStockManaged($product);
        if ($asset->spareParts()->where('product_id', $product->id)->exists()) abort(422, 'This spare part is already linked to the asset.');
        $part = DB::transaction(function () use ($data, $asset, $companyId): AssetSparePart {
            $part = AssetSparePart::create($data + ['asset_id' => $asset->id, 'company_id' => $companyId]);
            app(AuditService::class)->record('asset_spare_part.created', $part, null, $part->toArray() + ['api' => true]);
            return $part;
        });
        return response()->json(['data' => $part->load('product'), 'status' => 'created'], 201);
    }

    public function updateSparePart(Request $request, int $id, int $partId): JsonResponse
    {
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($id);
        $part = $asset->spareParts()->whereKey($partId)->firstOrFail();
        $data = $request->validate(['quantity_per_service' => ['sometimes', 'numeric', 'gt:0'], 'minimum_stock' => ['sometimes', 'numeric', 'min:0'], 'maximum_stock' => ['sometimes', 'nullable', 'numeric'], 'notes' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        if (array_key_exists('maximum_stock', $data) && $data['maximum_stock'] !== null && (float) $data['maximum_stock'] < (float) ($data['minimum_stock'] ?? $part->minimum_stock)) abort(422, 'Maximum stock must be greater than or equal to minimum stock.');
        $before = $part->only(array_keys($data));
        $part->update($data);
        app(AuditService::class)->record('asset_spare_part.updated', $part, $before, $part->fresh()->only(array_keys($data)) + ['api' => true]);
        return response()->json(['data' => $part->fresh()->load('product'), 'status' => 'updated']);
    }

    public function requests(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:open,assigned,in_progress,resolved,cancelled'], 'priority' => ['nullable', 'in:low,normal,high,urgent'], 'sla_status' => ['nullable', 'in:not_tracked,due,met,breached,closed'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = $this->companyScope(ServiceRequest::with(['asset', 'customer', 'contract', 'assignee']))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($data['sla_status'] ?? null, function ($query, $sla): void {
                $now = now();
                match ($sla) {
                    'not_tracked' => $query->whereNull('response_due_at'),
                    'closed' => $query->whereIn('status', ['resolved', 'cancelled'])->whereNotNull('response_due_at'),
                    'met' => $query->whereNotNull('response_due_at')->whereNotNull('assigned_at')->whereColumn('assigned_at', '<=', 'response_due_at'),
                    'due' => $query->whereNotNull('response_due_at')->whereNull('assigned_at')->where('response_due_at', '>=', $now)->whereNotIn('status', ['resolved', 'cancelled']),
                    'breached' => $query->whereNotNull('response_due_at')->whereNotIn('status', ['resolved', 'cancelled'])->where(function ($nested) use ($now): void { $nested->where(function ($q): void { $q->whereNull('assigned_at')->where('response_due_at', '<', now()); })->orWhereColumn('assigned_at', '>', 'response_due_at'); }),
                };
            })
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($rows, $request, 'service.requests', (int) ($data['per_page'] ?? 50));
    }

    public function contracts(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:active,expired,cancelled'], 'customer_id' => ['nullable', 'integer', $this->companyExists('customers')], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = $this->companyScope(ServiceContract::with(['customer', 'asset']))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($rows, $request, 'service.contracts', (int) ($data['per_page'] ?? 50));
    }

    public function storeContract(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'contract_no' => ['nullable', 'string', 'max:80', $this->companyUnique('service_contracts', 'contract_no')],
            'external_reference' => ['nullable', 'string', 'max:150', $this->companyUnique('service_contracts', 'external_reference')],
            'customer_id' => ['required', 'integer', $this->companyExists('customers')], 'asset_id' => ['nullable', 'integer', $this->companyExists('service_assets')],
            'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'coverage_type' => ['required', 'in:full,parts,labor,preventive'], 'response_hours' => ['nullable', 'integer', 'min:1'],
            'contract_value' => ['nullable', 'numeric', 'min:0'], 'currency_code' => ['nullable', 'string', 'size:3'], 'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        if (!empty($data['asset_id'])) {
            $asset = $this->companyScope(ServiceAsset::query())->findOrFail($data['asset_id']);
            if ($asset->customer_id && (int) $asset->customer_id !== (int) $data['customer_id']) abort(422, 'The asset belongs to a different customer.');
        }
        if ($existing = !empty($data['external_reference']) ? $this->companyScope(ServiceContract::query())->where('external_reference', $data['external_reference'])->first() : null) return response()->json(['data' => $existing->load(['customer', 'asset']), 'status' => 'duplicate_ignored']);
        $contract = ServiceContract::create([
            'company_id' => $companyId, 'contract_no' => $data['contract_no'] ?? app(NumberingSequenceService::class)->nextOrFallback('service_contract', 'SC-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId),
            'external_reference' => $data['external_reference'] ?? null, 'customer_id' => $data['customer_id'], 'asset_id' => $data['asset_id'] ?? null,
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'coverage_type' => $data['coverage_type'], 'response_hours' => $data['response_hours'] ?? null,
            'contract_value' => $data['contract_value'] ?? 0, 'currency_code' => strtoupper($data['currency_code'] ?? ($request->user()?->company?->base_currency ?? 'USD')), 'notes' => $data['notes'] ?? null,
        ]);
        app(AuditService::class)->record('service_contract.created', $contract, null, $contract->toArray() + ['api' => true]);
        return response()->json(['data' => $contract->load(['customer', 'asset']), 'status' => 'created'], 201);
    }

    public function updateContract(Request $request, int $id): JsonResponse
    {
        $contract = $this->companyScope(ServiceContract::query())->findOrFail($id);
        $data = $request->validate(['status' => ['sometimes', 'in:active,expired,cancelled'], 'ends_on' => ['sometimes', 'date'], 'response_hours' => ['sometimes', 'nullable', 'integer', 'min:1'], 'contract_value' => ['sometimes', 'numeric', 'min:0'], 'notes' => ['sometimes', 'nullable', 'string', 'max:3000']]);
        if (isset($data['ends_on']) && $data['ends_on'] < $contract->starts_on->toDateString()) abort(422, 'Contract end date cannot precede its start date.');
        $before = $contract->only(array_keys($data));
        $contract->update($data);
        app(AuditService::class)->record('service_contract.updated', $contract, $before, $contract->fresh()->only(array_keys($data)) + ['api' => true]);
        return response()->json(['data' => $contract->fresh()->load(['customer', 'asset']), 'status' => 'updated']);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $data = $request->validate(['request_no' => ['required', 'string', 'max:80', $this->companyUnique('service_requests', 'request_no')], 'external_reference' => ['nullable', 'string', 'max:150'], 'asset_id' => ['nullable', 'integer', $this->companyExists('service_assets')], 'customer_id' => ['nullable', 'integer', $this->companyExists('customers')], 'contract_id' => ['nullable', 'integer', $this->companyExists('service_contracts')], 'priority' => ['required', 'in:low,normal,high,urgent'], 'description' => ['required', 'string', 'max:3000']]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(ServiceRequest::query())->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['asset', 'customer', 'assignee']), 'status' => 'duplicate_ignored']);
        }
        if (!empty($data['asset_id'])) {
            $asset = $this->companyScope(ServiceAsset::query())->findOrFail($data['asset_id']);
            if (!empty($data['customer_id']) && $asset->customer_id && (int) $asset->customer_id !== (int) $data['customer_id']) return response()->json(['message' => 'The selected asset belongs to a different customer.'], 422);
        }
        if (!empty($data['customer_id'])) $this->companyScope(\App\Models\Customer::query())->findOrFail($data['customer_id']);
        $contract = null;
        if (!empty($data['contract_id'])) {
            $contract = $this->companyScope(ServiceContract::query())->findOrFail($data['contract_id']);
            if ($contract->status !== 'active' || $contract->starts_on->isFuture() || $contract->ends_on->isPast()) abort(422, 'The selected service contract is not active for today.');
            if ($contract->customer_id && !empty($data['customer_id']) && (int) $contract->customer_id !== (int) $data['customer_id']) abort(422, 'The service contract belongs to a different customer.');
            if ($contract->asset_id && (!empty($data['asset_id']) && (int) $contract->asset_id !== (int) $data['asset_id'])) abort(422, 'The service contract belongs to a different asset.');
            $data['customer_id'] = $data['customer_id'] ?? $contract->customer_id;
            $data['asset_id'] = $data['asset_id'] ?? $contract->asset_id;
            $data['response_due_at'] = $contract->response_hours ? now()->addHours((int) $contract->response_hours) : null;
        }
        $serviceRequest = ServiceRequest::create($data + ['company_id' => auth()->user()?->company_id, 'created_by' => auth()->id()]);
        app(AuditService::class)->record('service_request.created', $serviceRequest, null, $serviceRequest->toArray());
        return response()->json(['data' => $serviceRequest->load(['asset', 'customer']), 'status' => 'open'], 201);
    }

    public function assignRequest(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['technician_id' => ['required', 'integer', $this->companyExists('service_technicians')]]);
        try {
            $serviceRequest = DB::transaction(function () use ($data, $id): ServiceRequest {
                $serviceRequest = $this->companyScope(ServiceRequest::query())->lockForUpdate()->findOrFail($id);
                if (!in_array($serviceRequest->status, ['open', 'assigned'], true)) throw new \RuntimeException('Only open or assigned service requests can be assigned.');
                $technician = $this->companyScope(ServiceTechnician::query())->whereKey($data['technician_id'])->lockForUpdate()->firstOrFail();
                if (!$technician->is_available) throw new \RuntimeException('The selected technician is not available.');
                $before = $serviceRequest->only(['status', 'assigned_to', 'assigned_at', 'assigned_by']);
                $serviceRequest->update(['status' => 'assigned', 'assigned_to' => $technician->user_id, 'assigned_at' => now(), 'assigned_by' => auth()->id()]);
                app(AuditService::class)->record('service_request.assigned', $serviceRequest, $before, $serviceRequest->fresh()->only(['status', 'assigned_to', 'assigned_at', 'assigned_by']) + ['technician_id' => $technician->id, 'api' => true]);
                return $serviceRequest->fresh()->load(['asset', 'customer', 'assignee']);
            });
            return response()->json(['data' => $serviceRequest, 'status' => 'assigned']);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function orders(Request $request): JsonResponse
    {
        $rows = $this->companyScope(MaintenanceOrder::with(['asset', 'serviceRequest', 'assignee', 'serviceInvoice.invoice_details.product', 'parts.product', 'parts.location', 'parts.batch', 'parts.returns']))
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->has('updated_since'), fn ($query) => $query->where('updated_at', '>=', $request->date('updated_since')))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($rows, $request, 'service.orders', (int) $request->input('per_page', 50));
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate(['order_no' => ['nullable', 'string', 'max:80', $this->companyUnique('maintenance_orders', 'order_no')], 'external_reference' => ['nullable', 'string', 'max:150'], 'asset_id' => ['required', 'integer', $this->companyExists('service_assets')], 'service_request_id' => ['nullable', 'integer', $this->companyExists('service_requests')], 'maintenance_type' => ['required', 'in:preventive,corrective,inspection'], 'scheduled_date' => ['nullable', 'date'], 'assigned_to' => ['nullable', 'integer', $this->companyExists('users')], 'notes' => ['nullable', 'string', 'max:3000']]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(MaintenanceOrder::query())->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['asset', 'serviceRequest', 'assignee']), 'status' => 'duplicate_ignored']);
        }
        try {
            $order = DB::transaction(function () use ($data): MaintenanceOrder {
                $asset = $this->companyScope(ServiceAsset::query())->findOrFail($data['asset_id']);
                $serviceRequest = !empty($data['service_request_id']) ? $this->companyScope(ServiceRequest::query())->lockForUpdate()->findOrFail($data['service_request_id']) : null;
                if ($serviceRequest && ((int) $serviceRequest->asset_id !== (int) $asset->id || in_array($serviceRequest->status, ['resolved', 'cancelled'], true))) throw new \RuntimeException('The linked service request is invalid for this asset or already closed.');
                if (!empty($data['assigned_to']) && !$this->companyScope(ServiceTechnician::query())->where('user_id', $data['assigned_to'])->where('is_available', true)->exists()) throw new \RuntimeException('The assigned user must be an available service technician.');
                $order = MaintenanceOrder::create(($data + ['company_id' => auth()->user()?->company_id, 'created_by' => auth()->id()]) + ['order_no' => $data['order_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('maintenance_order', 'MO-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, null)]);
                if ($serviceRequest) $serviceRequest->update(['status' => 'in_progress', 'assigned_to' => $data['assigned_to'] ?? $serviceRequest->assigned_to]);
                app(AuditService::class)->record('maintenance_order.created', $order, null, $order->toArray() + ['api' => true]);
                return $order->load(['asset', 'serviceRequest', 'assignee']);
            });
            return response()->json(['data' => $order, 'status' => 'planned'], 201);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function updateOrderStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:in_progress,completed,cancelled'], 'actual_hours' => ['nullable', 'numeric', 'min:0'], 'labor_cost' => ['nullable', 'numeric', 'min:0'], 'outcome' => ['nullable', 'string', 'max:3000']]);
        try {
            $order = DB::transaction(function () use ($data, $id): MaintenanceOrder {
                $order = $this->companyScope(MaintenanceOrder::with(['asset', 'serviceRequest']))->lockForUpdate()->findOrFail($id);
                $oldStatus = $order->status;
                if (in_array($oldStatus, ['completed', 'cancelled'], true)) throw new \RuntimeException('A closed maintenance order cannot be changed.');
                if ($data['status'] === 'completed' && empty($data['outcome'])) throw new \RuntimeException('An outcome is required when completing maintenance.');
                if ($data['status'] === 'in_progress' && $oldStatus !== 'planned') throw new \RuntimeException('Only planned maintenance orders can be started.');
                if ($data['status'] === 'completed' && !in_array($oldStatus, ['planned', 'in_progress'], true)) throw new \RuntimeException('Only planned or in-progress orders can be completed.');
                $updates = ['status' => $data['status'], 'actual_hours' => $data['actual_hours'] ?? $order->actual_hours, 'labor_cost' => $data['labor_cost'] ?? $order->labor_cost, 'outcome' => $data['outcome'] ?? $order->outcome];
                if ($data['status'] === 'in_progress') $updates['started_at'] = $order->started_at ?: now();
                if (in_array($data['status'], ['completed', 'cancelled'], true)) $updates += ['completed_at' => now(), 'completed_by' => auth()->id()];
                $order->update($updates);
                if ($order->asset) $order->asset->update(['status' => $data['status'] === 'in_progress' ? 'under_service' : 'active']);
                if ($data['status'] === 'completed' && $order->serviceRequest) $order->serviceRequest->update(['status' => 'resolved']);
                if ($data['status'] === 'completed') app(\App\Services\MaintenanceLaborAccountingService::class)->post($order);
                app(AuditService::class)->record('maintenance_order.status_updated', $order, ['status' => $oldStatus], $order->only(['status', 'actual_hours', 'labor_cost', 'outcome', 'started_at', 'completed_at', 'completed_by']) + ['api' => true]);
                return $order->fresh()->load(['asset', 'serviceRequest', 'assignee']);
            });
            return response()->json(['data' => $order, 'status' => $order->status]);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function createServiceInvoice(Request $request, int $id): JsonResponse
    {
        $order = $this->companyScope(MaintenanceOrder::with('asset'))->findOrFail($id);
        if ($order->status !== 'completed') abort(422, 'Only completed maintenance orders can be invoiced.');
        if (!$order->asset?->customer_id) abort(422, 'The maintenance asset must have a customer before billing.');
        if ($existing = Invoice::where('maintenance_order_id', $order->id)->first()) {
            return response()->json(['data' => $existing->load('customer', 'invoice_details.product'), 'status' => 'duplicate_ignored']);
        }
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'service_product_id' => ['required', 'integer', $this->companyExists('products')],
            'amount' => ['required', 'numeric', 'gt:0'], 'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'external_reference' => ['nullable', 'string', 'max:150', $this->companyUnique('invoices', 'external_reference')],
            'description' => ['nullable', 'string', 'max:2000'], 'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'tax_mode' => ['nullable', 'in:exclusive,inclusive'],
        ]);
        $customer = $this->companyScope(\App\Models\Customer::query())->findOrFail($order->asset->customer_id);
        $product = $this->companyScope(Product::query())->findOrFail($data['service_product_id']);
        if ($product->is_stock_item || $product->product_type !== 'service') abort(422, 'The billing product must be a non-stock service product.');
        app(ProductLifecycleService::class)->assertSellable($product);
        $currency = strtoupper($data['currency_code'] ?? ($request->user()?->company?->base_currency ?? 'USD'));
        $exchangeRate = $data['exchange_rate'] ?? app(CurrencyConversionService::class)->rate($currency, strtoupper($request->user()?->company?->base_currency ?? 'USD'), $data['date']);
        $taxMode = $data['tax_mode'] ?? app(\App\Services\ErpSettingService::class)->get('default_tax_mode', 'exclusive');
        $taxRate = $customer->tax_exempt ? 0.0 : (float) ($product->tax_rate ?? 0);
        $taxResult = $taxMode === 'inclusive'
            ? app(TaxCalculationService::class)->inclusive((float) $data['amount'], $taxRate)
            : ['net' => (float) $data['amount'], 'tax' => app(TaxCalculationService::class)->exclusive((float) $data['amount'], $taxRate)];
        $invoice = DB::transaction(function () use ($data, $order, $customer, $product, $companyId, $currency, $exchangeRate, $taxMode, $taxRate, $taxResult, $request): Invoice {
            $invoice = Invoice::create([
                'company_id' => $companyId, 'invoice_type' => 'service', 'maintenance_order_id' => $order->id,
                'external_reference' => $data['external_reference'] ?? null,
                'invoice_no' => app(NumberingSequenceService::class)->nextOrFallback('sales_invoice', 'INV-SVC-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'customer_id' => $customer->id, 'date' => $data['date'],
                'due_date' => $data['due_date'] ?? \Carbon\CarbonImmutable::parse($data['date'])->addDays((int) ($customer->credit_days ?? 0))->toDateString(),
                'currency_code' => $currency, 'exchange_rate' => $exchangeRate, 'tax_mode' => $taxMode,
                'tax_exempt' => (bool) $customer->tax_exempt, 'tax_exemption_number' => $customer->tax_exempt ? $customer->tax_exemption_number : null,
                'description' => $data['description'] ?? 'Maintenance service for '.$order->order_no, 'status' => 0, 'created_by' => $request->user()?->id,
            ]);
            InvoiceDetail::create([
                'date' => $data['date'], 'invoice_id' => $invoice->id, 'category_id' => $product->category_id, 'product_id' => $product->id,
                'selling_qty' => 1, 'unit_price' => (float) $data['amount'], 'selling_price' => (float) $data['amount'],
                'tax_rate' => $taxRate, 'tax_amount' => (float) $taxResult['tax'], 'status' => 0,
            ]);
            $total = $taxMode === 'inclusive' ? (float) $data['amount'] : (float) $taxResult['net'] + (float) $taxResult['tax'];
            $invoice->update(['subtotal_amount' => (float) $taxResult['net'], 'tax_amount' => (float) $taxResult['tax'], 'total_amount' => $total]);
            app(AuditService::class)->record('service_invoice.created', $invoice, null, $invoice->toArray() + ['maintenance_order_id' => $order->id, 'api' => true]);
            return $invoice;
        });
        return response()->json(['data' => $invoice->load('customer', 'invoice_details.product'), 'status' => 'pending_approval'], 201);
    }

    public function approveServiceInvoice(int $id): JsonResponse
    {
        $order = $this->companyScope(MaintenanceOrder::query())->findOrFail($id);
        $invoice = Invoice::where('maintenance_order_id', $order->id)->firstOrFail();
        return app(\App\Http\Controllers\Api\InventoryIntegrationController::class)->approveSalesInvoice($invoice->id);
    }

    public function rejectServiceInvoice(Request $request, int $id): JsonResponse
    {
        $order = $this->companyScope(MaintenanceOrder::query())->findOrFail($id);
        $invoice = Invoice::where('maintenance_order_id', $order->id)->firstOrFail();
        return app(\App\Http\Controllers\Api\InventoryIntegrationController::class)->rejectSalesInvoice($request, $invoice->id);
    }

    public function consumePart(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'product_id' => ['required', 'integer', $this->companyExists('products')],
            'quantity' => ['required', 'numeric', 'gt:0'], 'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)],
            'batch_id' => ['nullable', 'integer'], 'serial_numbers' => ['nullable', 'string', 'max:5000'],
        ]);
        try {
            $order = DB::transaction(function () use ($data, $id, $companyId): MaintenanceOrder {
                $order = $this->companyScope(MaintenanceOrder::query())->lockForUpdate()->findOrFail($id);
                if (in_array($order->status, ['completed', 'cancelled'], true)) throw new \RuntimeException('Closed maintenance orders cannot consume spare parts.');
                $product = $this->companyScope(Product::query())->lockForUpdate()->findOrFail($data['product_id']);
                $quantity = (float) $data['quantity'];
                app(\App\Services\MaintenancePartConsumptionService::class)->consume($order, $product, $quantity, isset($data['unit_cost']) ? (float) $data['unit_cost'] : null, $data['location_id'] ?? null, $data['batch_id'] ?? null, !empty($data['serial_numbers']) ? preg_split('/[,\\r\\n]+/', $data['serial_numbers']) : []);
                app(AuditService::class)->record('maintenance_part.consumed', $order, null, ['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $data['unit_cost'] ?? $product->purchase_price ?? 0, 'location_id' => $data['location_id'] ?? null, 'batch_id' => $data['batch_id'] ?? null, 'api' => true]);
                return $order->fresh()->load(['asset', 'serviceRequest', 'assignee', 'parts.product', 'parts.location', 'parts.batch']);
            });
            return response()->json(['data' => $order, 'status' => 'consumed']);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function returnPart(Request $request, int $id, int $partId): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:2000'], 'serial_numbers' => ['nullable', 'string', 'max:5000']]);
        try {
            $order = DB::transaction(function () use ($data, $id, $partId): MaintenanceOrder {
                $order = $this->companyScope(MaintenanceOrder::query())->lockForUpdate()->findOrFail($id);
                $part = MaintenancePart::where('maintenance_order_id', $order->id)->lockForUpdate()->findOrFail($partId);
                app(\App\Services\MaintenancePartReturnService::class)->returnToStock($order, $part, (float) $data['quantity'], $data['reason'], !empty($data['serial_numbers']) ? preg_split('/[,\\r\\n]+/', $data['serial_numbers']) : []);
                return $order->fresh()->load(['asset', 'serviceRequest', 'assignee', 'parts.product', 'parts.location', 'parts.batch', 'parts.returns']);
            });
            return response()->json(['data' => $order, 'status' => 'returned']);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function schedules(Request $request): JsonResponse
    {
        $data = $request->validate(['is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = $this->companyScope(ServiceMaintenanceSchedule::with(['asset', 'assignee']))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($rows, $request, 'service.schedules', (int) ($data['per_page'] ?? 50));
    }

    public function technicians(Request $request): JsonResponse
    {
        $data = $request->validate(['is_available' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = $this->companyScope(ServiceTechnician::with('user:id,name,email'))
            ->when($request->has('is_available'), fn ($query) => $query->where('is_available', $request->boolean('is_available')))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($rows, $request, 'service.technicians', (int) ($data['per_page'] ?? 50));
    }

    public function warrantyClaims(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:submitted,under_review,approved,rejected,resolved'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = $this->companyScope(WarrantyClaim::with(['asset', 'customer', 'product', 'creator']))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($rows, $request, 'service.warranty-claims', (int) ($data['per_page'] ?? 50));
    }

    public function storeWarrantyClaim(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['claim_no' => ['nullable', 'string', 'max:80', $this->companyUnique('warranty_claims', 'claim_no')], 'external_reference' => ['nullable', 'string', 'max:150', $this->companyUnique('warranty_claims', 'external_reference')], 'asset_id' => ['required', 'integer', $this->companyExists('service_assets')], 'received_at' => ['required', 'date'], 'issue' => ['required', 'string', 'max:3000']]);
        if (!empty($data['external_reference']) && ($existing = $this->companyScope(WarrantyClaim::query())->where('external_reference', $data['external_reference'])->first())) return response()->json(['data' => $existing->load(['asset', 'customer', 'product']), 'status' => 'duplicate_ignored']);
        $asset = $this->companyScope(ServiceAsset::query())->findOrFail($data['asset_id']);
        $data['coverage_status'] = !$asset->warranty_until ? 'unknown' : ($asset->warranty_until->isBefore($data['received_at']) ? 'expired' : 'in_warranty');
        if ($data['coverage_status'] === 'expired') $data['decision_notes'] = 'Submitted after recorded warranty expiry; review required.';
        $attributes = array_merge($data, ['company_id' => $companyId, 'customer_id' => $asset->customer_id, 'product_id' => $asset->product_id, 'created_by' => auth()->id()]);
        if (empty($attributes['claim_no'])) $attributes['claim_no'] = app(NumberingSequenceService::class)->nextOrFallback('warranty_claim', 'WC-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId);
        $claim = WarrantyClaim::create($attributes);
        app(AuditService::class)->record('warranty_claim.created', $claim, null, $claim->toArray() + ['api' => true]);
        return response()->json(['data' => $claim->load(['asset', 'customer', 'product']), 'status' => 'submitted'], 201);
    }

    public function updateWarrantyClaim(Request $request, int $id): JsonResponse
    {
        $claim = $this->companyScope(WarrantyClaim::query())->findOrFail($id);
        $data = $request->validate(['status' => ['required', 'in:under_review,approved,rejected,resolved'], 'covered' => ['nullable', 'boolean'], 'decision_notes' => ['nullable', 'string', 'max:3000']]);
        if ($claim->status === 'resolved' || $claim->status === 'rejected') abort(422, 'Closed warranty claims cannot be changed.');
        if ($data['status'] === 'rejected' && empty($data['decision_notes'])) abort(422, 'Decision notes are required when rejecting a warranty claim.');
        if (in_array($data['status'], ['approved', 'rejected'], true) && !array_key_exists('covered', $data)) abort(422, 'A coverage decision is required when approving or rejecting a warranty claim.');
        $before = $claim->only(['status', 'covered', 'decision_notes', 'resolved_at']);
        $updates = $data;
        if ($data['status'] === 'resolved') $updates['resolved_at'] = now();
        $claim->update($updates);
        app(AuditService::class)->record('warranty_claim.updated', $claim, $before, $claim->fresh()->only(['status', 'covered', 'decision_notes', 'resolved_at']) + ['api' => true]);
        return response()->json(['data' => $claim->fresh()->load(['asset', 'customer', 'product']), 'status' => $claim->status]);
    }

    public function storeTechnician(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', $this->companyUnique('service_technicians', 'external_reference')],
            'user_id' => ['required', 'integer', $this->companyExists('users'), Rule::unique('service_technicians', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'employee_code' => ['required', 'string', 'max:50', $this->companyUnique('service_technicians', 'employee_code')],
            'skills' => ['nullable', 'array'], 'skills.*' => ['string', 'max:100'], 'hourly_rate' => ['nullable', 'numeric', 'min:0'], 'phone' => ['nullable', 'string', 'max:30'], 'is_available' => ['nullable', 'boolean'],
        ]);
        if (!empty($data['external_reference']) && ($existing = $this->companyScope(ServiceTechnician::query())->where('external_reference', $data['external_reference'])->first())) return response()->json(['data' => $existing->load('user:id,name,email'), 'status' => 'duplicate_ignored']);
        $technician = ServiceTechnician::create($data + ['company_id' => $companyId, 'skills' => $data['skills'] ?? [], 'hourly_rate' => $data['hourly_rate'] ?? 0, 'is_available' => $data['is_available'] ?? true]);
        app(AuditService::class)->record('service_technician.created', $technician, null, $technician->toArray() + ['api' => true]);
        return response()->json(['data' => $technician->load('user:id,name,email'), 'status' => 'created'], 201);
    }

    public function updateTechnician(Request $request, int $id): JsonResponse
    {
        $technician = $this->companyScope(ServiceTechnician::query())->findOrFail($id);
        $data = $request->validate(['external_reference' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('service_technicians', 'external_reference')->ignore($technician->id)->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))], 'employee_code' => ['sometimes', 'string', 'max:50', Rule::unique('service_technicians', 'employee_code')->ignore($technician->id)->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))], 'skills' => ['sometimes', 'array'], 'skills.*' => ['string', 'max:100'], 'hourly_rate' => ['sometimes', 'numeric', 'min:0'], 'phone' => ['sometimes', 'nullable', 'string', 'max:30'], 'is_available' => ['sometimes', 'boolean']]);
        $before = $technician->only(array_keys($data));
        $technician->update($data);
        app(AuditService::class)->record('service_technician.updated', $technician, $before, $technician->fresh()->only(array_keys($data)) + ['api' => true]);
        return response()->json(['data' => $technician->fresh()->load('user:id,name,email'), 'status' => 'updated']);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', $this->companyUnique('service_maintenance_schedules', 'external_reference')],
            'asset_id' => ['required', 'integer', $this->companyExists('service_assets')], 'name' => ['required', 'string', 'max:150'],
            'frequency_days' => ['required', 'integer', 'min:1'], 'next_due' => ['required', 'date'], 'assigned_to' => ['nullable', 'integer', $this->companyExists('users')],
            'meter_interval' => ['nullable', 'numeric', 'gt:0'], 'next_meter_due' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'], 'is_active' => ['nullable', 'boolean'],
        ]);
        if (!empty($data['external_reference']) && ($existing = $this->companyScope(ServiceMaintenanceSchedule::query())->where('external_reference', $data['external_reference'])->first())) return response()->json(['data' => $existing->load(['asset', 'assignee']), 'status' => 'duplicate_ignored']);
        $schedule = ServiceMaintenanceSchedule::create($data + ['company_id' => $companyId, 'is_active' => $data['is_active'] ?? true, 'created_by' => auth()->id()]);
        app(AuditService::class)->record('maintenance_schedule.created', $schedule, null, $schedule->toArray() + ['api' => true]);
        return response()->json(['data' => $schedule->load(['asset', 'assignee']), 'status' => 'created'], 201);
    }

    public function updateSchedule(Request $request, int $id): JsonResponse
    {
        $schedule = $this->companyScope(ServiceMaintenanceSchedule::query())->findOrFail($id);
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'], 'frequency_days' => ['sometimes', 'integer', 'min:1'], 'next_due' => ['sometimes', 'date'], 'meter_interval' => ['sometimes', 'nullable', 'numeric', 'gt:0'], 'next_meter_due' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'assigned_to' => ['sometimes', 'nullable', 'integer', $this->companyExists('users')], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000'], 'is_active' => ['sometimes', 'boolean'], 'external_reference' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('service_maintenance_schedules', 'external_reference')->ignore($schedule->id)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))]]);
        $before = $schedule->only(array_keys($data));
        $schedule->update($data);
        app(AuditService::class)->record('maintenance_schedule.updated', $schedule, $before, $schedule->fresh()->only(array_keys($data)) + ['api' => true]);
        return response()->json(['data' => $schedule->fresh()->load(['asset', 'assignee']), 'status' => 'updated']);
    }

    public function generateSchedule(int $id): JsonResponse
    {
        try {
            $order = DB::transaction(function () use ($id): MaintenanceOrder {
                $schedule = $this->companyScope(ServiceMaintenanceSchedule::query())->lockForUpdate()->findOrFail($id);
                $calendarDue = $schedule->next_due && !$schedule->next_due->isFuture();
                $meterDue = $schedule->meter_interval !== null && $schedule->next_meter_due !== null && $schedule->asset?->meter_value !== null && (float) $schedule->asset->meter_value >= (float) $schedule->next_meter_due;
                if (!$schedule->is_active || (!$calendarDue && !$meterDue)) throw new \RuntimeException('Schedule is inactive or not yet due.');
                $scheduledDate = $calendarDue ? $schedule->next_due : Carbon::today();
                $order = MaintenanceOrder::create(['company_id' => $schedule->company_id ?: auth()->user()?->company_id, 'order_no' => app(NumberingSequenceService::class)->nextOrFallback('maintenance_order', 'MO-'.now()->format('YmdHis').'-'.random_int(100, 999), $schedule->company_id, auth()->user()?->branch_id), 'asset_id' => $schedule->asset_id, 'maintenance_type' => 'preventive', 'scheduled_date' => $scheduledDate, 'assigned_to' => $schedule->assigned_to, 'notes' => $schedule->notes, 'created_by' => auth()->id()]);
                $updates = ['last_generated_at' => now()];
                if ($calendarDue) $updates['next_due'] = $schedule->next_due->copy()->addDays($schedule->frequency_days);
                if ($meterDue) $updates['next_meter_due'] = (float) $schedule->next_meter_due + (float) $schedule->meter_interval;
                $schedule->update($updates);
                app(AuditService::class)->record('maintenance_schedule.generated', $schedule, null, $schedule->only(['next_due', 'next_meter_due']) + ['maintenance_order_id' => $order->id, 'api' => true]);
                return $order->load(['asset', 'serviceRequest', 'assignee']);
            });
            return response()->json(['data' => $order, 'status' => 'generated'], 201);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    private function companyScope($query)
    {
        $companyId = auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
