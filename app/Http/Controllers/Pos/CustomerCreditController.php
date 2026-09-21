<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Invoice;
use App\Services\AuditService;
use App\Services\ReceivablesBalanceService;
use App\Services\CustomerCreditService;
use App\Services\CustomerPaymentAllocationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerCreditController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function companyCustomer(int $id): Customer
    {
        return Customer::where('company_id', $this->companyId())->findOrFail($id);
    }

    private function companyPayment(int $id): Payment
    {
        return Payment::where('company_id', $this->companyId())->findOrFail($id);
    }

    private function companyInvoice(int $id): Invoice
    {
        return Invoice::where('company_id', $this->companyId())->findOrFail($id);
    }

    public function index()
    {
        $customers = Customer::where('company_id', $this->companyId())->orderBy('name')->paginate(50);
        $creditService = app(CustomerCreditService::class);
        $customers->getCollection()->each(function (Customer $customer) use ($creditService): void {
            foreach ($creditService->assess($customer) as $key => $value) $customer->setAttribute($key, $value);
        });
        return view('backend.customer.credit_control', compact('customers'));
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['credit_limit' => ['required', 'numeric', 'min:0'], 'credit_days' => ['required', 'integer', 'min:0', 'max:3650'], 'credit_hold' => ['nullable', 'boolean'], 'credit_hold_after_days' => ['required', 'integer', 'min:0', 'max:3650']]);
        $customer = $this->companyCustomer($id); $old = $customer->only(['credit_limit', 'credit_days', 'credit_hold', 'credit_hold_after_days']);
        $customer->update($data + ['credit_hold' => (bool) ($data['credit_hold'] ?? false)]);
        app(AuditService::class)->record('customer.credit_control.updated', $customer, $old, $customer->only(['credit_limit', 'credit_days', 'credit_hold', 'credit_hold_after_days']));
        return back()->with(['message' => 'Customer credit controls updated.', 'alert-type' => 'success']);
    }

    public function allocations()
    {
        $payments = Payment::with(['customer', 'allocations'])->where('company_id', $this->companyId())->where('approval_status', 'approved')->where('paid_amount', '>', 0)->where('is_reversed', false)->latest()->get();
        $invoices = Invoice::with('customer')->where('company_id', $this->companyId())->where('status', 1)->whereNotNull('customer_id')->latest('date')->get();
        return view('backend.customer.payment_allocations', compact('payments', 'invoices'));
    }

    public function allocate(Request $request)
    {
        $companyId = $this->companyId();
        $data = $request->validate(['payment_id' => ['required', 'integer', Rule::exists('payments', 'id')->where('company_id', $companyId)], 'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('company_id', $companyId)], 'amount' => ['required', 'numeric', 'gt:0'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0']]);
        try { $payment = app(CustomerPaymentAllocationService::class)->allocate($this->companyPayment((int) $data['payment_id']), [['invoice_id' => $this->companyInvoice((int) $data['invoice_id'])->id, 'amount' => $data['amount'], 'exchange_rate' => $data['exchange_rate'] ?? null]]); }
        catch (\RuntimeException $exception) { return back()->withInput()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        app(AuditService::class)->record('customer_payment.allocated', $payment, null, ['invoice_id' => $data['invoice_id'], 'amount' => $data['amount']]);
        return back()->with(['message' => 'Customer payment allocated.', 'alert-type' => 'success']);
    }

    public function voidAllocation(Request $request, int $id)
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000']]);
        $allocation = \App\Models\CustomerPaymentAllocation::where('company_id', $this->companyId())->findOrFail($id);
        try { app(CustomerPaymentAllocationService::class)->void($allocation->id, $data['void_reason']); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        app(AuditService::class)->record('customer_payment_allocation.voided', $allocation->fresh(), null, ['void_reason' => $data['void_reason']]);
        return back()->with(['message' => 'Customer payment allocation voided.', 'alert-type' => 'success']);
    }
}
