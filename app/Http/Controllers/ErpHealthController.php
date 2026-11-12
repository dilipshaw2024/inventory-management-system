<?php

namespace App\Http\Controllers;

use App\Services\ErpHealthService;
use Illuminate\Http\JsonResponse;

class ErpHealthController extends Controller
{
    public function __invoke(ErpHealthService $health): JsonResponse
    {
        $result = $health->check();

        return response()->json([
            'status' => $result['status'],
            'checked_at' => $result['checked_at'],
        ], $result['status'] === 'healthy' ? 200 : 503);
    }
}
