<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Services\AuditService;
use App\Services\PaymentReversalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReversalController extends Controller
{
    public function customer(Request $request, int $id): JsonResponse { return $this->reverse($request, $this->companyScope(Payment::query())->findOrFail($id)); }
    public function supplier(Request $request, int $id): JsonResponse { return $this->reverse($request, $this->companyScope(SupplierPayment::query())->findOrFail($id)); }
    private function reverse(Request $request, $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        try { $reversed = app(PaymentReversalService::class)->reverse($payment, $data['reason']); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        app(AuditService::class)->record('payment.reversed', $reversed, ['is_reversed' => false], ['is_reversed' => true, 'reason' => $data['reason']]);
        return response()->json(['data' => $reversed, 'journal_reversed' => true]);
    }

    private function companyScope($query)
    {
        $companyId = auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
