<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogChannel;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogChannelIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $channels = CatalogChannel::where('company_id', $companyId)
            ->when($data['code'] ?? null, fn ($query, $code) => $query->where('code', strtolower(trim($code))))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');

        return app(IntegrationCursorService::class)->paginate($channels, $request, 'inventory.catalog-channels', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertWriteAccess($request);
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'name' => ['required', 'string', 'max:150'],
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'locale' => ['nullable', 'string', 'max:35', 'regex:/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{2,8})*$/'],
            'settings' => ['nullable', 'array', 'max:50'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $code = strtolower(trim($data['code']));
        $currency = isset($data['currency_code']) ? strtoupper(trim($data['currency_code'])) : null;
        $locale = isset($data['locale']) ? str_replace('_', '-', strtolower(trim($data['locale']))) : null;
        if (!empty($data['external_reference'])) {
            $existing = CatalogChannel::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored', 'idempotent' => true]);
        }
        if (CatalogChannel::where('company_id', $companyId)->where('code', $code)->exists()) {
            return response()->json(['message' => 'This catalog channel code already exists for the company.'], 422);
        }
        $channel = DB::transaction(function () use ($companyId, $data, $code, $currency, $locale): CatalogChannel {
            $channel = CatalogChannel::create([
                'company_id' => $companyId,
                'code' => $code,
                'name' => trim($data['name']),
                'currency_code' => $currency,
                'locale' => $locale,
                'settings' => $data['settings'] ?? null,
                'external_reference' => $data['external_reference'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            app(AuditService::class)->record('catalog_channel.created', $channel, null, $channel->toArray());
            return $channel;
        });
        return response()->json(['data' => $channel, 'status' => 'created'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $channel = $this->scope($request)->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:35', 'regex:/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{2,8})*$/'],
            'settings' => ['sometimes', 'nullable', 'array', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('name', $data)) $data['name'] = trim($data['name']);
        if (array_key_exists('currency_code', $data)) $data['currency_code'] = $data['currency_code'] === null ? null : strtoupper(trim($data['currency_code']));
        if (array_key_exists('locale', $data)) $data['locale'] = $data['locale'] === null ? null : str_replace('_', '-', strtolower(trim($data['locale'])));
        $before = $channel->only(array_keys($data));
        $channel->update($data);
        app(AuditService::class)->record('catalog_channel.updated', $channel, $before, $channel->fresh()->only(array_keys($data)));
        return response()->json(['data' => $channel->fresh(), 'status' => 'updated']);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $this->assertWriteAccess($request);
        $channel = $this->scope($request)->findOrFail($id);
        $channel->update(['is_active' => false]);
        app(AuditService::class)->record('catalog_channel.deactivated', $channel, ['is_active' => true], ['is_active' => false]);
        return response()->json(['data' => $channel->fresh(), 'status' => 'deactivated']);
    }

    private function scope(Request $request)
    {
        return CatalogChannel::where('company_id', $request->user()?->company_id);
    }

    private function assertWriteAccess(Request $request): void
    {
        if (!$request->user()?->tokenCan('inventory:write') && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify catalog channels.');
    }
}
