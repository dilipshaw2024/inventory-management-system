<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditIntegrationController extends Controller
{
    public function logs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:150'], 'user_id' => ['nullable', 'integer'],
            'auditable_type' => ['nullable', 'string', 'max:255'], 'auditable_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        if (!empty($data['user_id']) && !\App\Models\User::whereKey($data['user_id'])->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->exists()) abort(422, 'User is not authorized for this company.');
        $logs = AuditLog::with('user:id,name,email')
            ->when($data['action'] ?? null, fn ($query, $action) => $query->where('action', 'like', '%'.$action.'%'))
            ->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($data['auditable_type'] ?? null, fn ($query, $type) => $query->where('auditable_type', $type))
            ->when($data['auditable_id'] ?? null, fn ($query, $id) => $query->where('auditable_id', $id))
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($logs, $request, 'security.audit-logs', (int) ($data['per_page'] ?? 50));
    }
}
