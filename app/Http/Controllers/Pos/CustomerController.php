<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use Auth;
use Illuminate\Support\Carbon;
use Image; 
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Http\Requests\Pos\CustomerRequest;
use App\Http\Requests\Pos\PaymentUpdateRequest;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function CustomerAll(){

         $customers = Customer::latest()->get();
        return view('backend.customer.customer_all',compact('customers'));

    } // End Method


    public function CustomerAdd(){
     return view('backend.customer.customer_add');
    }    // End Method


    public function CustomerStore(CustomerRequest $request){

        $image = $request->file('customer_image');
        $save_url = 'upload/no_image.jpg';
        if ($image) {
            $name_gen = hexdec(uniqid()).'.'.$image->getClientOriginalExtension();
            Image::make($image)->resize(200,200)->save(public_path('upload/customer/'.$name_gen));
            $save_url = 'upload/customer/'.$name_gen;
        }

        Customer::insert([
            'name' => $request->name,
            'mobile_no' => $request->mobile_no,
            'email' => $request->email,
            'address' => $request->address,
            'customer_image' => $save_url ,
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(),

        ]);

         $notification = array(
            'message' => 'Customer Inserted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('customer.all')->with($notification);

    } // End Method


    public function CustomerEdit($id){

       $customer = Customer::findOrFail($id);
       return view('backend.customer.customer_edit',compact('customer'));

    } // End Method


    public function CustomerUpdate(Request $request){

        $request->validate([
            'id' => ['required', 'integer', 'exists:customers,id'],
            'name' => ['required', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'customer_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $customer_id = $request->id;
        if ($request->file('customer_image')) {

        $image = $request->file('customer_image');
        $name_gen = hexdec(uniqid()).'.'.$image->getClientOriginalExtension(); // 343434.png
        Image::make($image)->resize(200,200)->save(public_path('upload/customer/'.$name_gen));
        $save_url = 'upload/customer/'.$name_gen;

        Customer::findOrFail($customer_id)->update([
            'name' => $request->name,
            'mobile_no' => $request->mobile_no,
            'email' => $request->email,
            'address' => $request->address,
            'customer_image' => $save_url ,
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(),

        ]);

         $notification = array(
            'message' => 'Customer Updated with Image Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('customer.all')->with($notification);
             
        } else{

          Customer::findOrFail($customer_id)->update([
            'name' => $request->name,
            'mobile_no' => $request->mobile_no,
            'email' => $request->email,
            'address' => $request->address, 
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(),

        ]);

         $notification = array(
            'message' => 'Customer Updated without Image Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('customer.all')->with($notification);

        } // end else 

    } // End Method


    public function CustomerDelete($id){

        $customers = Customer::findOrFail($id);
        if ($customers->payments()->exists()) {
            return redirect()->back()->with(['message' => 'This customer cannot be deleted because payment history exists.', 'alert-type' => 'error']);
        }

        $img = $customers->customer_image;
        if ($img && $img !== 'upload/no_image.jpg') {
            $imagePath = public_path($img);
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }

        Customer::findOrFail($id)->delete();

        $notification = array(
            'message' => 'Customer Deleted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification);

    } // End Method


    public function CreditCustomer(){

        $allData = Payment::whereIn('paid_status',['full_due','partial_paid'])->orderBy('id','desc')->get();
        return view('backend.customer.customer_credit',compact('allData'));

    } // End Method


    public function CreditCustomerPrintPdf(){

        $allData = Payment::whereIn('paid_status',['full_due','partial_paid'])->orderBy('id','desc')->get();
        return view('backend.pdf.customer_credit_pdf',compact('allData'));

    }// End Method



    public function CustomerEditInvoice($invoice_id){

        $payment = Payment::where('invoice_id',$invoice_id)->firstOrFail();
        return view('backend.customer.edit_customer_invoice',compact('payment'));

    }// End Method


    public function CustomerUpdateInvoice(PaymentUpdateRequest $request,$invoice_id){

        $payment = Payment::where('invoice_id',$invoice_id)->firstOrFail();
        if ($payment->due_amount <= 0) {
            return redirect()->back()->with(['message' => 'This invoice is already fully paid.', 'alert-type' => 'info']);
        }

        $amount = $request->paid_status === 'full_paid' ? (float) $payment->due_amount : (float) $request->paid_amount;
        if ($amount > (float) $payment->due_amount) {

            $notification = array(
            'message' => 'Sorry You Paid Maximum Value', 
            'alert-type' => 'error'
        );
        return redirect()->back()->with($notification); 
        } else{
            $payment_details = new PaymentDetail();
            $payment->paid_status = $request->paid_status;

            if ($request->paid_status == 'full_paid') {
                 $payment->paid_amount = (float) $payment->paid_amount + $amount;
                 $payment->due_amount = '0';
                 $payment_details->current_paid_amount = $amount;

            } elseif ($request->paid_status == 'partial_paid') {
                $payment->paid_amount = (float) $payment->paid_amount + $amount;
                $payment->due_amount = (float) $payment->due_amount - $amount;
                $payment_details->current_paid_amount = $amount;

            }

            DB::transaction(function () use ($payment, $payment_details, $invoice_id, $request) {
                $payment->save();
                $payment_details->invoice_id = $invoice_id;
                $payment_details->date = date('Y-m-d',strtotime($request->date));
                $payment_details->updated_by = Auth::user()->id;
                $payment_details->save();
            });

              $notification = array(
            'message' => 'Invoice Update Successfully', 
            'alert-type' => 'success'
        );
        return redirect()->route('credit.customer')->with($notification); 


        }

    }// End Method



    public function CustomerInvoiceDetails($invoice_id){

        $payment = Payment::where('invoice_id',$invoice_id)->firstOrFail();
        return view('backend.pdf.invoice_details_pdf',compact('payment'));

    }// End Method

    public function PaidCustomer(){
        $allData = Payment::where('paid_status','!=','full_due')->orderBy('id','desc')->get();
        return view('backend.customer.customer_paid',compact('allData'));
    }// End Method

    public function PaidCustomerPrintPdf(){

        $allData = Payment::where('paid_status','!=','full_due')->orderBy('id','desc')->get();
        return view('backend.pdf.customer_paid_pdf',compact('allData'));
    }// End Method


    public function CustomerWiseReport(){

        $customers = Customer::orderBy('id','desc')->get();
        return view('backend.customer.customer_wise_report',compact('customers'));

    }// End Method


    public function CustomerWiseCreditReport(Request $request){

         $allData = Payment::where('customer_id',$request->customer_id)->whereIn('paid_status',['full_due','partial_paid'])->orderBy('id','desc')->get();
        return view('backend.pdf.customer_wise_credit_pdf',compact('allData'));
    }// End Method


    public function CustomerWisePaidReport(Request $request){

         $allData = Payment::where('customer_id',$request->customer_id)->where('paid_status','!=','full_due')->orderBy('id','desc')->get();
        return view('backend.pdf.customer_wise_paid_pdf',compact('allData'));
    }// End Method



    }
 
