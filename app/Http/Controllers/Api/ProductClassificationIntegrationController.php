<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductClassification;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductClassificationIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['scheme' => ['nullable', 'string', 'max:30'], 'jurisdiction' => ['nullable', 'string', 'max:30'], 'is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $classifications = ProductClassification::query()
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($data['scheme'] ?? null, fn ($query, $scheme) => $query->where('scheme', strtolower(trim($scheme))))
            ->when($data['jurisdiction'] ?? null, fn ($query, $jurisdiction) => $query->where('jurisdiction', strtoupper(trim($jurisdiction))))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($classifications, $request, 'inventory.product-classifications', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'scheme' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'jurisdiction' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'description' => ['nullable', 'string', 'max:255'], 'external_reference' => ['nullable', 'string', 'max:150'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $scheme = strtolower(trim($data['scheme'])); $code = strtoupper(trim($data['code'])); $jurisdiction = isset($data['jurisdiction']) ? strtoupper(trim($data['jurisdiction'])) : null;
        if (!empty($data['external_reference']) && ($existing = ProductClassification::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first())) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored', 'idempotent' => true]);
        if (ProductClassification::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->where('scheme', $scheme)->where('code', $code)->where(function ($query) use ($jurisdiction): void { $jurisdiction === null ? $query->whereNull('jurisdiction') : $query->where('jurisdiction', $jurisdiction); })->exists()) return response()->json(['message' => 'This classification code already exists for the scheme and jurisdiction.'], 422);
        $classification = DB::transaction(function () use ($companyId, $data, $scheme, $code, $jurisdiction): ProductClassification {
            $classification = ProductClassification::create(['company_id' => $companyId, 'scheme' => $scheme, 'code' => $code, 'jurisdiction' => $jurisdiction, 'description' => $data['description'] ?? null, 'external_reference' => $data['external_reference'] ?? null, 'is_active' => $data['is_active'] ?? true]);
            app(AuditService::class)->record('product_classification.created', $classification, null, $classification->toArray());
            return $classification;
        });
        return response()->json(['data' => $classification, 'status' => 'created'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $classification = $this->scope()->findOrFail($id);
        $data = $request->validate(['description' => ['sometimes', 'nullable', 'string', 'max:255'], 'jurisdiction' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_.-]+$/'], 'is_active' => ['sometimes', 'boolean']]);
        if (array_key_exists('jurisdiction', $data)) $data['jurisdiction'] = $data['jurisdiction'] === null ? null : strtoupper(trim($data['jurisdiction']));
        $before = $classification->only(array_keys($data)); $classification->update($data); app(AuditService::class)->record('product_classification.updated', $classification, $before, $classification->fresh()->only(array_keys($data)));
        return response()->json(['data' => $classification->fresh(), 'status' => 'updated']);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request); $classification = $this->scope()->findOrFail($id); $classification->update(['is_active' => false]);
        app(AuditService::class)->record('product_classification.deactivated', $classification, ['is_active' => true], ['is_active' => false]);
        return response()->json(['data' => $classification->fresh(), 'status' => 'deactivated']);
    }

    private function scope() { $companyId = auth()->user()?->company_id; return ProductClassification::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id')); }
    private function assertWriteAccess(Request $request): void { if (!$request->user()?->tokenCan('inventory:write') && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify product classifications.'); }
}
