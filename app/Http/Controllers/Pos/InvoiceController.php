<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Category;
use Auth;
use Illuminate\Support\Carbon;

use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Customer;
use DB;
use App\Http\Requests\Pos\InvoiceRequest;
use App\Services\InventoryLedgerService;
use App\Services\AuditService;
use App\Services\TaxCalculationService;
use App\Services\TaxRateResolver;
use App\Services\NumberingSequenceService;
use App\Services\AutomaticAccountingService;
use App\Services\SerialLifecycleService;
use App\Services\PromotionService;
use App\Models\InventoryBatch;
use App\Models\Store;

class InvoiceController extends Controller
{
    public function InvoiceAll(){
        $allData = Invoice::orderBy('date','desc')->orderBy('id','desc')->where('status','1')->get();
            return view('backend.invoice.invoice_all',compact('allData'));

    } // End Method


    public function invoiceAdd(){ 


        $category = Category::orderBy('id','desc')->get();
        $costomer = Customer::orderBy('id','desc')->get();
        $stores = Store::where('is_active', true)->with('branch')->orderBy('name')->get();
        $invoice_data = Invoice::orderBy('id','desc')->first();
        if ($invoice_data == null) {
           $firstReg = '0';
           $invoice_no = $firstReg+1;
        }else{
            $invoice_data = Invoice::orderBy('id','desc')->first()->invoice_no;
            $invoice_no = $invoice_data+1;
        }
        $invoice_no = app(NumberingSequenceService::class)->previewOrFallback('sales_invoice', (string) $invoice_no, auth()->user()?->company_id, auth()->user()?->branch_id);
        $date = date('Y-m-d');
        $defaultTaxMode = app(\App\Services\ErpSettingService::class)->get('default_tax_mode', 'exclusive');
        return view('backend.invoice.invoice_add',compact('invoice_no','category','date','costomer','stores','defaultTaxMode'));

    } // End Method


    public function InvoiceStore(InvoiceRequest $request){

    if ($request->filled('store_id') && !Store::whereKey($request->integer('store_id'))->exists()) abort(403);

    if ($request->category_id == null) {

       $notification = array(
        'message' => 'Sorry You do not select any item', 
        'alert-type' => 'error'
    );
    return redirect()->back()->with($notification);

    } else{
        $discount = (float) ($request->discount_amount ?? 0);
        $lineTotal = collect($request->selling_qty)->sum(function ($qty, $key) use ($request) {
            return (float) $qty * (float) $request->unit_price[$key];
        });
        $taxTotal = 0;
        $netTotal = 0;
        $lineTaxes = [];
        $taxMode = $request->input('tax_mode') ?: app(\App\Services\ErpSettingService::class)->get('default_tax_mode', 'exclusive');
        $customer = $request->integer('customer_id') > 0 ? Customer::find($request->integer('customer_id')) : null;
        $taxExempt = (bool) ($customer?->tax_exempt);
        $taxExemptionNumber = $customer?->tax_exemption_number;

        foreach ($request->product_id as $key => $productId) {
            $product = Product::find($productId);
            if (!$product || (int) $product->category_id !== (int) $request->category_id[$key]) {
                return redirect()->back()->withInput()->with(['message' => 'One or more invoice products do not match the selected category.', 'alert-type' => 'error']);
            }
            $lineSubtotal = (float) $request->selling_qty[$key] * (float) $request->unit_price[$key];
            $taxRate = $taxExempt ? 0 : app(TaxRateResolver::class)->rateFor($product, $request->date, $customer?->tax_jurisdiction);
            $lineTaxes[$key] = $taxMode === 'inclusive' ? app(TaxCalculationService::class)->inclusive($lineSubtotal, $taxRate)['tax'] : app(TaxCalculationService::class)->exclusive($lineSubtotal, $taxRate);
            $netTotal += $taxMode === 'inclusive' ? app(TaxCalculationService::class)->inclusive($lineSubtotal, $taxRate)['net'] : $lineSubtotal;
            $taxTotal += $lineTaxes[$key];
        }

        try {
            $promotionResult = app(PromotionService::class)->calculate($request->promotion_code, $request->product_id, $request->selling_qty, $request->unit_price, (string) $request->customer_id !== '0' ? (int) $request->customer_id : null, date('Y-m-d', strtotime($request->date)));
        } catch (\RuntimeException $exception) {
            return redirect()->back()->withInput()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
        $promotion = $promotionResult['promotion'];
        $discount += $promotionResult['discount'];

        $estimatedAmount = $taxMode === 'inclusive' ? $lineTotal - $discount : $lineTotal - $discount + $taxTotal;

        if ($estimatedAmount < 0) {
           $notification = array('message' => 'Discount cannot be greater than the invoice subtotal.', 'alert-type' => 'error');
           return redirect()->back()->withInput()->with($notification);
        }

        if ($request->paid_status === 'partial_paid' && (float) $request->paid_amount > $estimatedAmount) {

           $notification = array(
        'message' => 'Sorry Paid Amount is Maximum the total price', 
        'alert-type' => 'error'
    );
    return redirect()->back()->with($notification);

        } else {

    $invoice = new Invoice();
    $invoice->invoice_no = app(NumberingSequenceService::class)->nextOrFallback('sales_invoice', (string) $request->invoice_no, auth()->user()?->company_id, auth()->user()?->branch_id);
    $invoice->date = date('Y-m-d',strtotime($request->date));
    $invoice->due_date = $request->filled('due_date') ? date('Y-m-d', strtotime($request->due_date)) : null;
    $invoice->store_id = $request->input('store_id');
    $invoice->description = $request->description;
    $invoice->currency_code = strtoupper($request->currency_code ?: (auth()->user()?->company?->base_currency ?? 'USD'));
    $invoice->tax_mode = $taxMode;
    $invoice->tax_exempt = $taxExempt;
    $invoice->tax_exemption_number = $taxExempt ? $taxExemptionNumber : null;
    $invoice->tax_jurisdiction = $customer?->tax_jurisdiction;
    $baseCurrency = strtoupper(auth()->user()?->company?->base_currency ?? 'USD');
    try {
        $invoice->exchange_rate = $request->filled('exchange_rate') ? (float) $request->exchange_rate : app(\App\Services\CurrencyConversionService::class)->rate($invoice->currency_code, $baseCurrency, $invoice->date);
    } catch (\RuntimeException $exception) {
        return redirect()->back()->withInput()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
    }
    $invoice->status = '0';
    $invoice->created_by = Auth::user()->id; 

    DB::transaction(function() use($request,$invoice,$discount,$promotion,$estimatedAmount,$netTotal,$taxTotal,$lineTaxes,$taxExempt){
        if ($invoice->save()) {
           $invoice->subtotal_amount = $netTotal;
           $invoice->tax_amount = $taxTotal;
           $invoice->total_amount = $estimatedAmount;
           $invoice->promotion_id = $promotion?->id;
           $invoice->save();
           $count_category = count($request->category_id);
           for ($i=0; $i < $count_category ; $i++) { 

              $invoice_details = new InvoiceDetail();
              $invoice_details->date = date('Y-m-d',strtotime($request->date));
              $invoice_details->invoice_id = $invoice->id;
              $invoice_details->category_id = $request->category_id[$i];
              $invoice_details->product_id = $request->product_id[$i];
              $invoice_details->batch_no = $request->batch_no[$i] ?? null;
              $invoice_details->serial_numbers = $request->serial_numbers[$i] ?? null;
              $invoice_details->selling_qty = $request->selling_qty[$i];
              $invoice_details->unit_price = $request->unit_price[$i];
              $invoice_details->selling_price = (float) $request->selling_qty[$i] * (float) $request->unit_price[$i];
              $invoice_details->tax_rate = $taxExempt ? 0 : ($request->product_id[$i] ? (float) Product::whereKey($request->product_id[$i])->value('tax_rate') : 0);
              $invoice_details->tax_amount = $lineTaxes[$i] ?? 0;
              $invoice_details->status = '0'; 
              $invoice_details->save(); 
           }

            if ($request->customer_id == '0') {
                $customer = new Customer();
                $customer->name = $request->name;
                $customer->mobile_no = $request->mobile_no;
                $customer->email = $request->email;
                $customer->created_by = Auth::user()->id;
                $customer->save();
                $customer_id = $customer->id;
            } else{
                $customer_id = $request->customer_id;
            } 
            $customer = Customer::findOrFail($customer_id);
            $invoice->due_date = $invoice->due_date ?: Carbon::parse($invoice->date)->addDays((int) ($customer->credit_days ?? 0))->toDateString();
            $invoice->billing_address = $customer->address;
            $invoice->shipping_address = $customer->address;
            $invoice->customer_id = $customer_id;
            $invoice->save();

            $payment = new Payment();
            $payment_details = new PaymentDetail();

            $payment->invoice_id = $invoice->id;
            $payment->customer_id = $customer_id;
            $payment->currency_code = $invoice->currency_code;
            $payment->exchange_rate = $invoice->exchange_rate ?: 1;
            $payment->paid_status = $request->paid_status;
            $payment->discount_amount = $discount;
            $payment->total_amount = $estimatedAmount;

            if ($request->paid_status == 'full_paid') {
                $payment->paid_amount = $estimatedAmount;
                $payment->due_amount = '0';
                $payment_details->current_paid_amount = $estimatedAmount;
            } elseif ($request->paid_status == 'full_due') {
                $payment->paid_amount = '0';
                $payment->due_amount = $estimatedAmount;
                $payment_details->current_paid_amount = '0';
            }elseif ($request->paid_status == 'partial_paid') {
                $payment->paid_amount = $request->paid_amount;
                $payment->due_amount = $estimatedAmount - $request->paid_amount;
                $payment_details->current_paid_amount = $request->paid_amount;
            }
            $payment->base_amount = (float) $payment->paid_amount * (float) ($payment->exchange_rate ?: 1);
            $payment->save();

            $payment_details->invoice_id = $invoice->id; 
            $payment_details->date = date('Y-m-d',strtotime($request->date));
            $payment_details->save(); 
        } 

            }); 

        } // end else 
    }

     $notification = array(
        'message' => 'Invoice Data Inserted Successfully', 
        'alert-type' => 'success'
    );
    return redirect()->route('invoice.pending.list')->with($notification);  
    } // End Method


    public function PendingList(){
        $allData = Invoice::orderBy('date','desc')->orderBy('id','desc')->whereIn('status',[0, 2])->get();
            return view('backend.invoice.invoice_pending_list',compact('allData'));
    } // End Method



    public function InvoiceDelete($id){

        $invoice = Invoice::findOrFail($id);
        if ((int) $invoice->status === 1) {
            return redirect()->back()->with(['message' => 'Approved invoices cannot be deleted because they affect stock and payment history.', 'alert-type' => 'error']);
        }
        DB::transaction(function () use ($invoice) {
            InvoiceDetail::where('invoice_id',$invoice->id)->delete();
            Payment::where('invoice_id',$invoice->id)->delete();
            PaymentDetail::where('invoice_id',$invoice->id)->delete();
            $invoice->delete();
        });

         $notification = array(
        'message' => 'Invoice Deleted Successfully', 
        'alert-type' => 'success'
    );
    return redirect()->back()->with($notification); 

    }// End Method



    public function InvoiceApprove($id){

        $invoice = Invoice::with('invoice_details')->findOrFail($id);
        return view('backend.invoice.invoice_approve',compact('invoice'));

    }// End Method


    public function ApprovalStore(Request $request, $id){
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(Invoice::class, (int) $id);
        try {
            DB::transaction(function () use ($id) {
                $invoice = Invoice::lockForUpdate()->with(['invoice_details', 'payment'])->findOrFail($id);
                if ((int) $invoice->status !== 0) {
                    throw new \RuntimeException('Only pending invoices can be approved.');
                }

                app(\App\Services\SalesDiscountPolicyService::class)->assertInvoiceCanApprove($invoice);

                foreach ($invoice->invoice_details as $invoiceDetail) {
                    $product = Product::lockForUpdate()->findOrFail($invoiceDetail->product_id);
                    app(\App\Services\ProductLifecycleService::class)->assertSellable($product);
                    $available = app(\App\Services\InventoryAvailabilityService::class)->available($product, true, null, $invoice->company_id);
                    if ($available < (float) $invoiceDetail->selling_qty) {
                        throw new \RuntimeException('Insufficient stock for '.$product->name.'. Available: '.$available.', requested: '.$invoiceDetail->selling_qty.'.');
                    }
                    $batch = null;
                    if ($invoiceDetail->batch_no) {
                        $batch = InventoryBatch::where('product_id', $product->id)->where('batch_no', $invoiceDetail->batch_no)->lockForUpdate()->first();
                        if (!$batch) throw new \RuntimeException('Batch '.$invoiceDetail->batch_no.' was not found for '.$product->name.'.');
                    }
                    $issuedSerials = collect();
                    if ($product->tracking_type === 'serial') {
                        $serialNumbers = $invoiceDetail->serial_numbers ? array_filter(array_map('trim', preg_split('/[,\r\n]+/', $invoiceDetail->serial_numbers))) : [];
                        if ($serialNumbers) {
                            if (count($serialNumbers) !== (int) round((float) $invoiceDetail->selling_qty)) throw new \RuntimeException('Serial count must equal quantity for '.$product->name.'.');
                            $issuedSerials = app(SerialLifecycleService::class)->issueSpecific($product, $serialNumbers, null, $batch?->id);
                        } else {
                            $issuedSerials = app(SerialLifecycleService::class)->issue($product, (float) $invoiceDetail->selling_qty, null, $batch?->id);
                        }
                        if ($batch && $issuedSerials->contains(fn ($serial) => (int) $serial->batch_id !== (int) $batch->id)) throw new \RuntimeException('One or more selected serials do not belong to batch '.$batch->batch_no.'.');
                    }
                    $product->quantity = (float) $product->quantity - (float) $invoiceDetail->selling_qty;
                    $product->save();
                    if ($issuedSerials->isNotEmpty()) {
                        foreach ($issuedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, 'issue', 1, (float) $invoiceDetail->unit_price, null, $invoice, 'Approved sales issue', null, $batch?->id, $serial->id);
                    } else {
                        app(InventoryLedgerService::class)->post($product->id, 'issue', (float) $invoiceDetail->selling_qty, (float) $invoiceDetail->unit_price, null, $invoice, 'Approved sales issue', null, $batch?->id);
                    }
                    $invoiceDetail->batch_id = $batch?->id;
                    $invoiceDetail->status = 1;
                    $invoiceDetail->save();
                }

                $invoice->updated_by = Auth::user()->id;
                $invoice->status = 1;
                $invoice->save();
                if ($invoice->promotion_id) {
                    $redeemed = app(PromotionService::class)->redeem((int) $invoice->promotion_id);
                    app(AuditService::class)->record('promotion.redeemed', $redeemed, ['usage_count' => max(0, (int) $redeemed->usage_count - 1)], ['usage_count' => $redeemed->usage_count, 'invoice_id' => $invoice->id]);
                }
                app(AutomaticAccountingService::class)->postSalesInvoice($invoice);
                $payment = Payment::where('invoice_id', $invoice->id)->first();
                if ($payment) app(AutomaticAccountingService::class)->postCustomerPayment($payment);
                app(AuditService::class)->record('invoice.approved', $invoice, ['status' => 0], ['status' => 1]);
            });
        } catch (\RuntimeException $exception) {
            return redirect()->back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }

    $notification = array(
        'message' => 'Invoice Approve Successfully', 
        'alert-type' => 'success'
    );
    return redirect()->route('invoice.pending.list')->with($notification);  

    } // End Method

    public function Reject(Request $request, $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);

        try {
            DB::transaction(function () use ($id, $data): void {
                $invoice = Invoice::lockForUpdate()->findOrFail($id);
                if ((int) $invoice->status !== 0) throw new \RuntimeException('Only pending invoices can be rejected.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($invoice);
                $before = $invoice->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
                $invoice->update(['status' => 2, 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => Auth::id(), 'rejected_at' => now()]);
                app(AuditService::class)->record('invoice.rejected', $invoice, $before, $invoice->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            });
        } catch (\RuntimeException $exception) {
            return redirect()->back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }

        return redirect()->route('invoice.pending.list')->with(['message' => 'Sales invoice rejected without posting stock or accounting entries.', 'alert-type' => 'success']);
    }

    // End rejection workflow


    public function PrintInvoiceList(){

    $allData = Invoice::orderBy('date','desc')->orderBy('id','desc')->where('status','1')->get();
       return view('backend.invoice.print_invoice_list',compact('allData'));
    } // End Method


    public function PrintInvoice($id){
        $invoice = Invoice::with('invoice_details')->findOrFail($id);
        return view('backend.pdf.invoice_pdf',compact('invoice'));

    } // End Method


    public function DailyInvoiceReport(){
        return view('backend.invoice.daily_invoice_report');
    } // End Method


    public function DailyInvoicePdf(Request $request){

        $sdate = date('Y-m-d',strtotime($request->start_date));
        $edate = date('Y-m-d',strtotime($request->end_date));
        $allData = Invoice::whereBetween('date',[$sdate,$edate])->where('status','1')->orderBy('date','desc')->orderBy('id','desc')->get();


        $start_date = date('Y-m-d',strtotime($request->start_date));
        $end_date = date('Y-m-d',strtotime($request->end_date));
        return view('backend.pdf.daily_invoice_report_pdf',compact('allData','start_date','end_date'));
    } // End Method


}
 
