<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationCursorController extends Controller
{
    public function acknowledge(Request $request, string $feed): JsonResponse
    {
        $data = $request->validate(['cursor' => ['required', 'string', 'max:2000']]);
        return response()->json(['data' => app(IntegrationCursorService::class)->acknowledge($request, $feed, $data['cursor'])]);
    }
}
