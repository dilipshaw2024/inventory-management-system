<?php

namespace App\Services;

use App\Models\IntegrationSyncCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationCursorService
{
    public function paginate(Builder $query, Request $request, string $feed, int $perPage = 50): JsonResponse
    {
        $perPage = min(100, max(1, $perPage));
        $state = $this->stateFromRequest($request, $feed);
        if ($state === null && !$request->boolean('cursor_mode')) return response()->json($query->paginate($perPage)->withQueryString());

        if ($state) {
            $query->where(function (Builder $scope) use ($state): void {
                $scope->where('updated_at', '>', $state['updated_at'])
                    ->orWhere(fn (Builder $sameTime) => $sameTime->where('updated_at', $state['updated_at'])->where('id', '>', $state['id']));
            });
        }
        $rows = $query->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();
        $companyId = $request->user()?->company_id;
        $principal = $this->principalKey($request);
        $next = $rows->isEmpty() ? null : $this->encode($feed, $rows->last()->updated_at, (int) $rows->last()->id, (int) $companyId, $principal);
        return response()->json(['data' => $rows, 'meta' => ['feed' => $feed, 'has_more' => $hasMore, 'next_cursor' => $hasMore ? $next : null, 'acknowledged_cursor' => $state ? $this->encode($feed, $state['updated_at'], $state['id'], (int) $companyId, $principal) : null]]);
    }

    public function acknowledge(Request $request, string $feed, string $cursor): array
    {
        $companyId = $request->user()?->company_id;
        $principal = $this->principalKey($request);
        $state = $this->decode($cursor, $companyId, $principal);
        if (!$state || $state['feed'] !== $feed) abort(422, 'The cursor is invalid for this feed.');
        if (!$companyId) abort(422, 'A company is required for integration cursors.');
        $acknowledgedAt = now();
        DB::transaction(function () use ($companyId, $principal, $feed, $state, $acknowledgedAt): void {
            $saved = IntegrationSyncCursor::where('company_id', $companyId)
                ->where('principal_key', $principal)
                ->where('feed', $feed)
                ->lockForUpdate()
                ->first();
            $isNewer = !$saved
                || $state['updated_at'] > $saved->cursor_updated_at->format('Y-m-d H:i:s')
                || ($state['updated_at'] === $saved->cursor_updated_at->format('Y-m-d H:i:s') && (int) $state['id'] > (int) $saved->cursor_id);
            if (!$isNewer) return;
            if ($saved) {
                $saved->update(['cursor_updated_at' => $state['updated_at'], 'cursor_id' => $state['id'], 'acknowledged_at' => $acknowledgedAt]);
            } else {
                IntegrationSyncCursor::create(['company_id' => $companyId, 'principal_key' => $principal, 'feed' => $feed, 'cursor_updated_at' => $state['updated_at'], 'cursor_id' => $state['id'], 'acknowledged_at' => $acknowledgedAt]);
            }
        });
        return ['feed' => $feed, 'cursor' => $cursor, 'acknowledged_at' => $acknowledgedAt->toISOString()];
    }

    private function stateFromRequest(Request $request, string $feed): ?array
    {
        if ($request->filled('cursor')) {
            $state = $this->decode((string) $request->input('cursor'), $request->user()?->company_id, $this->principalKey($request));
            if (!$state || $state['feed'] !== $feed) abort(422, 'The cursor is invalid for this feed.');
            return $state;
        }
        if (!$request->boolean('cursor_mode')) return null;
        $companyId = $request->user()?->company_id;
        if (!$companyId) abort(422, 'A company is required for cursor mode.');
        $saved = IntegrationSyncCursor::where('company_id', $companyId)->where('principal_key', $this->principalKey($request))->where('feed', $feed)->first();
        return $saved ? ['feed' => $feed, 'updated_at' => $saved->cursor_updated_at->format('Y-m-d H:i:s'), 'id' => (int) $saved->cursor_id] : null;
    }

    private function principalKey(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        return $token ? 'token:'.$token->getKey() : 'user:'.$request->user()?->getKey();
    }

    private function encode(string $feed, $updatedAt, int $id, int $companyId, string $principal): string
    {
        $payload = json_encode(['feed' => $feed, 'updated_at' => is_object($updatedAt) ? $updatedAt->format('Y-m-d H:i:s') : (string) $updatedAt, 'id' => $id, 'company_id' => $companyId, 'principal_key' => $principal], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'));
        return rtrim(strtr(base64_encode($payload.'.'.$signature), '+/', '-_'), '=');
    }

    private function decode(string $cursor, ?int $companyId = null, ?string $principal = null): ?array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || !str_contains($decoded, '.')) return null;
        [$payload, $signature] = explode('.', $decoded, 2);
        if (!hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $signature)) return null;
        try { $state = json_decode($payload, true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) { return null; }
        if (!is_array($state) || !isset($state['feed'], $state['updated_at'], $state['id'], $state['company_id'], $state['principal_key']) || !is_string($state['feed']) || !is_numeric($state['id']) || (int) $state['company_id'] !== (int) $companyId || (string) $state['principal_key'] !== (string) $principal) return null;
        return ['feed' => $state['feed'], 'updated_at' => (string) $state['updated_at'], 'id' => (int) $state['id']];
    }
}
