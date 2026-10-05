<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationCapabilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $abilities = [
            'inventory:read', 'inventory:write',
            'warehouse:read', 'warehouse:write',
            'purchasing:read', 'purchasing:write',
            'sales:read', 'sales:write',
            'manufacturing:read', 'manufacturing:write',
            'planning:read', 'planning:write',
            'service:read', 'service:write',
            'accounting:read', 'accounting:write',
            'integration:read', 'integration:write',
        ];

        $modules = [
            'organization' => ['read_ability' => 'integration:read', 'write_ability' => 'integration:write', 'feed_root' => '/api/integration/organization'],
            'catalog' => ['read_ability' => 'inventory:read', 'write_ability' => 'inventory:write', 'feed_root' => '/api/integration/products'],
            'inventory' => ['read_ability' => 'inventory:read', 'write_ability' => 'inventory:write', 'feed_root' => '/api/inventory'],
            'warehouse' => ['read_ability' => 'warehouse:read', 'write_ability' => 'warehouse:write', 'feed_root' => '/api/integration/warehouse'],
            'purchasing' => ['read_ability' => 'purchasing:read', 'write_ability' => 'purchasing:write', 'feed_root' => '/api/integration'],
            'sales' => ['read_ability' => 'sales:read', 'write_ability' => 'sales:write', 'feed_root' => '/api/integration'],
            'manufacturing' => ['read_ability' => 'manufacturing:read', 'write_ability' => 'manufacturing:write', 'feed_root' => '/api/integration/manufacturing'],
            'planning' => ['read_ability' => 'planning:read', 'write_ability' => 'planning:write', 'feed_root' => '/api/integration/planning'],
            'service' => ['read_ability' => 'service:read', 'write_ability' => 'service:write', 'feed_root' => '/api/service'],
            'accounting' => ['read_ability' => 'accounting:read', 'write_ability' => 'accounting:write', 'feed_root' => '/api/accounting'],
            'security' => ['read_ability' => 'integration:read', 'write_ability' => 'integration:write', 'feed_root' => '/api/integration/security'],
        ];

        $visibleAbilities = array_values(array_filter($abilities, fn (string $ability): bool => $user?->tokenCan($ability) ?? false));
        $modules = collect($modules)->map(function (array $module): array {
            $module['conventions'] = ['cursor_pagination' => true, 'idempotency' => 'external_reference', 'timestamps' => 'updated_at,id'];
            return $module;
        })->all();

        return response()->json([
            'data' => [
                'contract' => 'erp.integration.v1',
                'api_version' => 'v1',
                'modules' => $modules,
                'abilities' => $visibleAbilities,
                'conventions' => [
                    'tenant_scope' => 'token_company',
                    'pagination' => 'signed_cursor_or_updated_since',
                    'idempotency' => 'external_reference',
                    'timestamps' => 'updated_at,id',
                    'errors' => 'json_message_with_http_status',
                ],
            ],
            'meta' => [
                'company_id' => $user?->company_id,
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }
}
