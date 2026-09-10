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

class InvoiceController extends Controller
{
    public function InvoiceAll(){
        $allData = Invoice::orderBy('date','desc')->orderBy('id','desc')->where('status','1')->get();
            return view('backend.invoice.invoice_all',compact('allData'));

    } // End Method


    public function invoiceAdd(){ 


        $category = Category::orderBy('id','desc')->get();
        $costomer = Customer::orderBy('id','desc')->get();
        $invoice_data = Invoice::orderBy('id','desc')->first();
        if ($invoice_data == null) {
           $firstReg = '0';
           $invoice_no = $firstReg+1;
        }else{
            $invoice_data = Invoice::orderBy('id','desc')->first()->invoice_no;
            $invoice_no = $invoice_data+1;
        }
        $date = date('Y-m-d');
        return view('backend.invoice.invoice_add',compact('invoice_no','category','date','costomer'));

    } // End Method


    public function InvoiceStore(InvoiceRequest $request){

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
        $estimatedAmount = $lineTotal - $discount;

        foreach ($request->product_id as $key => $productId) {
            $product = Product::find($productId);
            if (!$product || (int) $product->category_id !== (int) $request->category_id[$key]) {
                return redirect()->back()->withInput()->with(['message' => 'One or more invoice products do not match the selected category.', 'alert-type' => 'error']);
            }
        }

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
    $invoice->invoice_no = $request->invoice_no;
    $invoice->date = date('Y-m-d',strtotime($request->date));
    $invoice->description = $request->description;
    $invoice->status = '0';
    $invoice->created_by = Auth::user()->id; 

    DB::transaction(function() use($request,$invoice,$discount,$estimatedAmount){
        if ($invoice->save()) {
           $count_category = count($request->category_id);
           for ($i=0; $i < $count_category ; $i++) { 

              $invoice_details = new InvoiceDetail();
              $invoice_details->date = date('Y-m-d',strtotime($request->date));
              $invoice_details->invoice_id = $invoice->id;
              $invoice_details->category_id = $request->category_id[$i];
              $invoice_details->product_id = $request->product_id[$i];
              $invoice_details->selling_qty = $request->selling_qty[$i];
              $invoice_details->unit_price = $request->unit_price[$i];
              $invoice_details->selling_price = (float) $request->selling_qty[$i] * (float) $request->unit_price[$i];
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

            $payment = new Payment();
            $payment_details = new PaymentDetail();

            $payment->invoice_id = $invoice->id;
            $payment->customer_id = $customer_id;
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
        $allData = Invoice::orderBy('date','desc')->orderBy('id','desc')->where('status','0')->get();
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
        try {
            DB::transaction(function () use ($id) {
                $invoice = Invoice::lockForUpdate()->with('invoice_details')->findOrFail($id);
                if ((int) $invoice->status === 1) {
                    throw new \RuntimeException('This invoice has already been approved.');
                }

                foreach ($invoice->invoice_details as $invoiceDetail) {
                    $product = Product::lockForUpdate()->findOrFail($invoiceDetail->product_id);
                    if ((float) $product->quantity < (float) $invoiceDetail->selling_qty) {
                        throw new \RuntimeException('Insufficient stock for '.$product->name.'. Available: '.$product->quantity.', requested: '.$invoiceDetail->selling_qty.'.');
                    }
                    $product->quantity = (float) $product->quantity - (float) $invoiceDetail->selling_qty;
                    $product->save();
                    $invoiceDetail->status = 1;
                    $invoiceDetail->save();
                }

                $invoice->updated_by = Auth::user()->id;
                $invoice->status = 1;
                $invoice->save();
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
 
