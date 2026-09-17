<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Services\AuditService;
use App\Services\PaymentReversalService;
use Illuminate\Http\Request;

class PaymentReversalController extends Controller
{
    public function customer(Request $request, int $id)
    {
        return $this->reverse($request, Payment::findOrFail($id));
    }

    public function supplier(Request $request, int $id)
    {
        return $this->reverse($request, SupplierPayment::findOrFail($id));
    }

    private function reverse(Request $request, $payment)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        try { $reversed = app(PaymentReversalService::class)->reverse($payment, $data['reason']); }
        catch (\RuntimeException $exception) { return back()->withErrors(['payment' => $exception->getMessage()]); }
        app(AuditService::class)->record('payment.reversed', $reversed, ['is_reversed' => false], ['is_reversed' => true, 'reason' => $data['reason']]);
        return back()->with(['message' => 'Payment reversed and linked journal counter-posted.', 'alert-type' => 'success']);
    }
}
