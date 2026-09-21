<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MrpPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MrpIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for MRP planning.');
        $result = app(MrpPlanningService::class)->proposalsForCompany((int) $companyId); $proposals = $result['proposals'];
        $perPage = (int) ($data['per_page'] ?? 50); $page = max(1, (int) $request->input('page', 1));
        return response()->json(['data' => $proposals->forPage($page, $perPage)->values(), 'errors' => $result['errors'], 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $proposals->count(), 'last_page' => max(1, (int) ceil($proposals->count() / $perPage)), 'generated_at' => now()->toISOString()]]);
    }
}
