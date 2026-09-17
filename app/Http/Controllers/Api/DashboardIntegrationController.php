<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardMetricsService;
use Illuminate\Http\JsonResponse;

class DashboardIntegrationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => app(DashboardMetricsService::class)->forCurrentCompany(), 'generated_at' => now()->toISOString()]);
    }
}
