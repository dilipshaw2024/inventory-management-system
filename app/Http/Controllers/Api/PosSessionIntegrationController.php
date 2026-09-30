<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Store;
use App\Services\AuditService;
use App\Services\StorePosSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosSessionIntegrationController extends Controller
{
    public function registers(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for POS registers.');
        $registers = PosRegister::with(['store', 'openSession'])
            ->where('company_id', $companyId)
            ->when($this->allowedStoreId($request), fn ($query, $storeId) => $query->where('store_id', $storeId))
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->when($request->input('store_id'), fn ($query, $storeId) => $query->where('store_id', $storeId))
            ->orderBy('code')->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => $registers->getCollection(),
            'meta' => [
                'current_page' => $registers->currentPage(),
                'per_page' => $registers->perPage(),
                'total' => $registers->total(),
                'last_page' => $registers->lastPage(),
            ],
        ]);
    }

    public function storeRegister(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for POS registers.');
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);

        if ($this->allowedStoreId($request) !== null && $this->allowedStoreId($request) !== (int) $data['store_id']) abort(403, 'This user is assigned to a different store.');

        $store = Store::whereKey($data['store_id'])
            ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))
            ->firstOrFail();

        if (!empty($data['external_reference'])) {
            $existing = PosRegister::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) {
                return response()->json(['data' => $existing->load('store', 'openSession'), 'status' => 'duplicate_ignored']);
            }
        }
        if (PosRegister::where('company_id', $companyId)->where('code', $data['code'])->exists()) {
            return response()->json(['message' => 'A POS register with this code already exists in the company.'], 422);
        }

        $register = PosRegister::create([
            'company_id' => $companyId,
            'store_id' => $store->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
            'external_reference' => $data['external_reference'] ?? null,
        ]);
        app(AuditService::class)->record('pos.register.created', $register, null, $register->toArray());

        return response()->json(['data' => $register->load('store'), 'status' => 'created'], 201);
    }

    public function openSession(Request $request, int $registerId): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for POS sessions.');
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $session = DB::transaction(function () use ($request, $companyId, $registerId, $data): PosSession {
            $registerQuery = PosRegister::where('company_id', $companyId);
            if ($this->allowedStoreId($request) !== null) $registerQuery->where('store_id', $this->allowedStoreId($request));
            $register = $registerQuery->lockForUpdate()->findOrFail($registerId);
            if (!$register->is_active) {
                throw new \RuntimeException('The POS register is inactive.');
            }
            if ($register->openSession()->lockForUpdate()->exists()) {
                throw new \RuntimeException('This POS register already has an open session.');
            }
            if (!empty($data['external_reference'])) {
                $existing = PosSession::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
                if ($existing) return $existing->load('register.store');
            }
            $session = PosSession::create([
                'company_id' => $companyId,
                'register_id' => $register->id,
                'opened_by' => $request->user()?->id,
                'status' => 'open',
                'opened_at' => now(),
                'opening_cash' => $data['opening_cash'],
                'external_reference' => $data['external_reference'] ?? null,
            ]);
            app(AuditService::class)->record('pos.session.opened', $session, null, $session->toArray());
            return $session->load('register.store');
        });

        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $session, 'status' => $session->wasRecentlyCreated ? 'opened' : 'duplicate_ignored'], $session->wasRecentlyCreated ? 201 : 200);
    }

    public function sessions(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for POS sessions.');
        $sessions = PosSession::with(['register.store', 'opener', 'closer'])
            ->where('company_id', $companyId)
            ->when($this->allowedStoreId($request), fn ($query, $storeId) => $query->whereHas('register', fn ($register) => $register->where('store_id', $storeId)))
            ->when($request->input('register_id'), fn ($query, $id) => $query->where('register_id', $id))
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest('opened_at')->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => $sessions->getCollection()->map(fn (PosSession $session): array => $this->sessionPayload($session))->values(),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }

    public function sessionSummary(Request $request, int $sessionId): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for POS session reports.');

        $sessionQuery = PosSession::with(['register.store', 'opener', 'closer', 'cashMovements', 'varianceJournal'])
            ->where('company_id', $companyId);
        if ($this->allowedStoreId($request) !== null) $sessionQuery->whereHas('register', fn ($register) => $register->where('store_id', $this->allowedStoreId($request)));
        $session = $sessionQuery->findOrFail($sessionId);

        $payments = $session->payments()
            ->where('is_reversed', false)
            ->whereHas('invoice', fn ($query) => $query->where('status', 1))
            ->where(fn ($query) => $query->whereNull('approval_status')->orWhere('approval_status', 'approved'))
            ->get(['id', 'invoice_id', 'method', 'paid_amount', 'currency_code']);

        $tenderTotals = $payments
            ->groupBy(fn ($payment) => (string) ($payment->method ?: 'cash'))
            ->map(fn ($rows) => round((float) $rows->sum('paid_amount'), 6))
            ->all();

        $movementTotals = $session->cashMovements
            ->groupBy('type')
            ->map(fn ($rows) => round((float) $rows->sum('amount'), 6))
            ->all();

        $movementNet = (float) $session->cashMovements->sum(
            fn (PosCashMovement $movement): float => in_array($movement->type, ['cash_in', 'float_adjustment'], true)
                ? (float) $movement->amount
                : -(float) $movement->amount
        );
        $salesCash = (float) ($tenderTotals['cash'] ?? 0);
        $expected = (float) $session->opening_cash + $movementNet + $salesCash;

        return response()->json([
            'data' => [
                'session' => $this->sessionPayload($session),
                'register' => $session->register,
                'store' => $session->register?->store,
                'sales' => [
                    'invoice_count' => $payments->pluck('invoice_id')->filter()->unique()->count(),
                    'payment_count' => $payments->count(),
                    'payment_total' => round((float) $payments->sum('paid_amount'), 6),
                    'tender_totals' => $tenderTotals,
                    'cash_total' => round($salesCash, 6),
                    'currency_codes' => $payments->pluck('currency_code')->filter()->unique()->values()->all(),
                ],
                'cash_movements' => [
                    'by_type' => $movementTotals,
                    'net' => round($movementNet, 6),
                ],
                'cash_reconciliation' => [
                    'opening_cash' => (float) $session->opening_cash,
                    'expected_cash' => $session->expected_cash !== null ? (float) $session->expected_cash : round($expected, 6),
                    'closing_cash' => $session->closing_cash !== null ? (float) $session->closing_cash : null,
                    'variance' => $session->variance !== null ? (float) $session->variance : null,
                    'variance_accounting_status' => $session->variance_accounting_status,
                    'variance_journal_id' => $session->variance_journal_id ? (int) $session->variance_journal_id : null,
                ],
                'meta' => [
                    'read_only' => true,
                    'generated_at' => now()->toISOString(),
                ],
            ],
        ]);
    }

    public function cashMovement(Request $request, int $sessionId): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for POS cash movements.');
        $data = $request->validate([
            'type' => ['required', 'in:cash_in,cash_out,float_adjustment,refund'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $movement = DB::transaction(function () use ($request, $companyId, $sessionId, $data): PosCashMovement {
            $sessionQuery = PosSession::where('company_id', $companyId);
            if ($this->allowedStoreId($request) !== null) $sessionQuery->whereHas('register', fn ($register) => $register->where('store_id', $this->allowedStoreId($request)));
            $session = $sessionQuery->lockForUpdate()->findOrFail($sessionId);
            if ($session->status !== 'open') {
                throw new \RuntimeException('Cash movements can only be recorded on an open POS session.');
            }
            if (!empty($data['external_reference'])) {
                $existing = PosCashMovement::where('session_id', $session->id)->where('external_reference', $data['external_reference'])->first();
                if ($existing) return $existing;
            }
            $movement = PosCashMovement::create([
                'company_id' => $companyId,
                'session_id' => $session->id,
                'created_by' => $request->user()?->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'currency_code' => strtoupper($data['currency_code'] ?? ($request->user()?->company?->base_currency ?? 'USD')),
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'external_reference' => $data['external_reference'] ?? null,
            ]);
            app(AuditService::class)->record('pos.cash_movement.created', $movement, null, $movement->toArray());
            return $movement;
        });

        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $movement->load('session'), 'status' => $movement->wasRecentlyCreated ? 'created' : 'duplicate_ignored'], $movement->wasRecentlyCreated ? 201 : 200);
    }

    public function closeSession(Request $request, int $sessionId): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for POS sessions.');
        $data = $request->validate([
            'closing_cash' => ['required', 'numeric', 'min:0'],
            'closing_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $session = DB::transaction(function () use ($request, $companyId, $sessionId, $data): PosSession {
            $sessionQuery = PosSession::where('company_id', $companyId);
            if ($this->allowedStoreId($request) !== null) $sessionQuery->whereHas('register', fn ($register) => $register->where('store_id', $this->allowedStoreId($request)));
            $session = $sessionQuery->lockForUpdate()->findOrFail($sessionId);
            if ($session->status !== 'open') {
                throw new \RuntimeException('Only open POS sessions can be closed.');
            }
            $movementNet = (float) $session->cashMovements()->get()->sum(
                fn (PosCashMovement $movement): float => in_array($movement->type, ['cash_in', 'float_adjustment'], true)
                    ? (float) $movement->amount
                    : -(float) $movement->amount
            );
            $salesCash = (float) $session->payments()
                ->where('is_reversed', false)
                ->where('method', 'cash')
                ->whereHas('invoice', fn ($query) => $query->where('status', 1))
                ->where(fn ($query) => $query->whereNull('approval_status')->orWhere('approval_status', 'approved'))
                ->sum('paid_amount');
            $expected = (float) $session->opening_cash + $movementNet + $salesCash;
            $variance = (float) $data['closing_cash'] - $expected;
            $session->load('register.store');
            $tolerance = (float) (app(StorePosSettingsService::class)->normalize($session->register->store->pos_settings)['cash_variance_tolerance'] ?? 0);
            $session->update([
                'status' => 'closed',
                'closed_by' => $request->user()?->id,
                'closed_at' => now(),
                'expected_cash' => $expected,
                'closing_cash' => $data['closing_cash'],
                'variance' => $variance,
                'closing_note' => $data['closing_note'] ?? null,
            ]);
            $session->refresh();
            if (abs($variance) <= $tolerance + 0.000001) {
                $session->update(['variance_accounting_status' => 'not_required', 'variance_accounting_message' => $tolerance > 0 ? 'Cash variance is within the configured store tolerance.' : null]);
            } else {
            app(\App\Services\PosCashVarianceAccountingService::class)->post($session);
            }
            app(AuditService::class)->record('pos.session.closed', $session, ['status' => 'open'], $session->fresh()->toArray());
            return $session->fresh()->load('register.store', 'opener', 'closer', 'cashMovements', 'varianceJournal');
        });

        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->sessionPayload($session), 'status' => 'closed']);
    }

    private function allowedStoreId(Request $request): ?int
    {
        return $request->user()?->store_id ? (int) $request->user()->store_id : null;
    }

    private function tenderTotals(PosSession $session): array
    {
        return $session->payments()
            ->where('is_reversed', false)
            ->whereHas('invoice', fn ($query) => $query->where('status', 1))
            ->where(fn ($query) => $query->whereNull('approval_status')->orWhere('approval_status', 'approved'))
            ->selectRaw("COALESCE(method, 'cash') AS tender, COALESCE(SUM(paid_amount), 0) AS amount")
            ->groupBy('method')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->tender => round((float) $row->amount, 6)])
            ->all();
    }

    private function sessionPayload(PosSession $session): array
    {
        $movementNet = (float) $session->cashMovements()->get()->sum(
            fn (PosCashMovement $movement): float => in_array($movement->type, ['cash_in', 'float_adjustment'], true)
                ? (float) $movement->amount
                : -(float) $movement->amount
        );
        $salesCash = (float) $session->payments()
            ->where('is_reversed', false)
            ->where('method', 'cash')
            ->whereHas('invoice', fn ($query) => $query->where('status', 1))
            ->where(fn ($query) => $query->whereNull('approval_status')->orWhere('approval_status', 'approved'))
            ->sum('paid_amount');
        $expected = (float) $session->opening_cash + $movementNet + $salesCash;
        return [
            'id' => (int) $session->id,
            'register_id' => (int) $session->register_id,
            'register' => $session->register,
            'store' => $session->register?->store,
            'status' => $session->status,
            'opened_at' => optional($session->opened_at)->toISOString(),
            'closed_at' => optional($session->closed_at)->toISOString(),
            'opening_cash' => (float) $session->opening_cash,
            'sales_cash' => round($salesCash, 6),
            'tender_totals' => $this->tenderTotals($session),
            'cash_movement_net' => round($movementNet, 6),
            'expected_cash' => $session->expected_cash !== null ? (float) $session->expected_cash : round($expected, 6),
            'closing_cash' => $session->closing_cash !== null ? (float) $session->closing_cash : null,
            'variance' => $session->variance !== null ? (float) $session->variance : null,
            'variance_accounting_status' => $session->variance_accounting_status,
            'variance_journal_id' => $session->variance_journal_id ? (int) $session->variance_journal_id : null,
            'variance_accounting_message' => $session->variance_accounting_message,
            'cash_variance_tolerance' => (float) (app(StorePosSettingsService::class)->normalize($session->register?->store?->pos_settings)['cash_variance_tolerance'] ?? 0),
            'cash_movements' => $session->relationLoaded('cashMovements') ? $session->cashMovements : null,
        ];
    }
}
