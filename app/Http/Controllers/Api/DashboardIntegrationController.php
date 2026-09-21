<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['months' => ['nullable', 'integer', 'min:3', 'max:24']]);
        $months = (int) ($data['months'] ?? 6);
        $metrics = app(DashboardMetricsService::class)->forUser($request->user(), $months);
        return response()->json(['data' => $metrics, 'generated_at' => now()->toISOString(), 'meta' => ['months' => $months, 'cached' => (int) config('erp.dashboard_cache_ttl', 60) > 0, 'visibility' => $metrics['visibility']]]);
    }
}
