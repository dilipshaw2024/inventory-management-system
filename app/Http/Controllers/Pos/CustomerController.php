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
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    private function companyId(): int
    {
        return (int) Auth::user()->company_id;
    }

    private function companyCustomer($id): Customer
    {
        return Customer::where('company_id', $this->companyId())->findOrFail($id);
    }

    private function companyPayments()
    {
        return Payment::where('company_id', $this->companyId());
    }

    public function CustomerAll(){

         $customers = Customer::where('company_id', $this->companyId())->latest()->get();
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

        Customer::create([
            'name' => $request->name,
            'company_id' => Auth::user()->company_id,
            'mobile_no' => $request->mobile_no,
            'email' => $request->email,
            'address' => $request->address,
            'tax_number' => $request->tax_number,
            'tax_jurisdiction' => $request->tax_jurisdiction,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'tax_exemption_number' => $request->tax_exemption_number,
            'customer_group' => $request->customer_group,
            'sales_channel' => $request->sales_channel,
            'currency_code' => $request->currency_code ? strtoupper($request->currency_code) : null,
            'is_active' => $request->boolean('is_active', true),
            'credit_limit' => $request->credit_limit ?? 0,
            'credit_days' => $request->credit_days ?? 0,
            'credit_hold' => (bool) $request->credit_hold,
            'credit_hold_after_days' => $request->credit_hold_after_days ?? 0,
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

       $customer = $this->companyCustomer($id);
       return view('backend.customer.customer_edit',compact('customer'));

    } // End Method


    public function CustomerUpdate(Request $request){

        $request->validate([
            'id' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $this->companyId())],
            'name' => ['required', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'tax_jurisdiction' => ['nullable', 'string', 'max:100'],
            'tax_exempt' => ['nullable', 'boolean'],
            'tax_exemption_number' => ['required_if:tax_exempt,1', 'nullable', 'string', 'max:100'],
            'customer_group' => ['nullable', 'string', 'max:100'],
            'sales_channel' => ['nullable', 'string', 'max:50'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'credit_hold' => ['nullable', 'boolean'],
            'credit_hold_after_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'customer_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $customer_id = $request->id;
        if ($request->file('customer_image')) {

        $image = $request->file('customer_image');
        $name_gen = hexdec(uniqid()).'.'.$image->getClientOriginalExtension(); // 343434.png
        Image::make($image)->resize(200,200)->save(public_path('upload/customer/'.$name_gen));
        $save_url = 'upload/customer/'.$name_gen;

        $this->companyCustomer($customer_id)->update([
            'name' => $request->name,
            'mobile_no' => $request->mobile_no,
            'email' => $request->email,
            'address' => $request->address,
            'tax_number' => $request->tax_number,
            'tax_jurisdiction' => $request->tax_jurisdiction,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'tax_exemption_number' => $request->tax_exemption_number,
            'customer_group' => $request->customer_group,
            'sales_channel' => $request->sales_channel,
            'currency_code' => $request->currency_code ? strtoupper($request->currency_code) : null,
            'is_active' => $request->boolean('is_active', true),
            'credit_limit' => $request->credit_limit ?? 0,
            'credit_days' => $request->credit_days ?? 0,
            'credit_hold' => (bool) $request->credit_hold,
            'credit_hold_after_days' => $request->credit_hold_after_days ?? 0,
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

          $this->companyCustomer($customer_id)->update([
            'name' => $request->name,
            'mobile_no' => $request->mobile_no,
            'email' => $request->email,
            'address' => $request->address, 
            'tax_number' => $request->tax_number,
            'tax_jurisdiction' => $request->tax_jurisdiction,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'tax_exemption_number' => $request->tax_exemption_number,
            'customer_group' => $request->customer_group,
            'sales_channel' => $request->sales_channel,
            'currency_code' => $request->currency_code ? strtoupper($request->currency_code) : null,
            'is_active' => $request->boolean('is_active', true),
            'credit_limit' => $request->credit_limit ?? 0,
            'credit_days' => $request->credit_days ?? 0,
            'credit_hold' => (bool) $request->credit_hold,
            'credit_hold_after_days' => $request->credit_hold_after_days ?? 0,
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

        $customers = $this->companyCustomer($id);
        if ($customers->payments()->exists()) {
            return redirect()->back()->with(['message' => 'This customer cannot be deleted because payment history exists.', 'alert-type' => 'error']);
        }

        // Keep uploaded media recoverable while the record is soft-deleted.
        $customers->delete();

        $notification = array(
            'message' => 'Customer Deleted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification);

    } // End Method


    public function CreditCustomer(){

        $allData = $this->companyPayments()->where('approval_status', 'approved')->whereIn('paid_status',['full_due','partial_paid'])->orderBy('id','desc')->get();
        return view('backend.customer.customer_credit',compact('allData'));

    } // End Method


    public function CreditCustomerPrintPdf(){

        $allData = $this->companyPayments()->where('approval_status', 'approved')->whereIn('paid_status',['full_due','partial_paid'])->orderBy('id','desc')->get();
        return view('backend.pdf.customer_credit_pdf',compact('allData'));

    }// End Method



    public function CustomerEditInvoice($invoice_id){

        $payment = $this->companyPayments()->where('invoice_id',$invoice_id)->where('approval_status', 'approved')->firstOrFail();
        return view('backend.customer.edit_customer_invoice',compact('payment'));

    }// End Method


    public function CustomerUpdateInvoice(PaymentUpdateRequest $request,$invoice_id){

        $payment = $this->companyPayments()->where('invoice_id',$invoice_id)->where('approval_status', 'approved')->firstOrFail();
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

        $payment = $this->companyPayments()->where('invoice_id',$invoice_id)->where('approval_status', 'approved')->firstOrFail();
        return view('backend.pdf.invoice_details_pdf',compact('payment'));

    }// End Method

    public function PaidCustomer(){
        $allData = $this->companyPayments()->where('approval_status', 'approved')->where('paid_status','!=','full_due')->orderBy('id','desc')->get();
        return view('backend.customer.customer_paid',compact('allData'));
    }// End Method

    public function PaidCustomerPrintPdf(){

        $allData = $this->companyPayments()->where('approval_status', 'approved')->where('paid_status','!=','full_due')->orderBy('id','desc')->get();
        return view('backend.pdf.customer_paid_pdf',compact('allData'));
    }// End Method


    public function CustomerWiseReport(){

        $customers = Customer::where('company_id', $this->companyId())->orderBy('id','desc')->get();
        return view('backend.customer.customer_wise_report',compact('customers'));

    }// End Method


    public function CustomerWiseCreditReport(Request $request){

         $customer = $this->companyCustomer($request->customer_id);
         $allData = $this->companyPayments()->where('customer_id',$customer->id)->where('approval_status', 'approved')->whereIn('paid_status',['full_due','partial_paid'])->orderBy('id','desc')->get();
        return view('backend.pdf.customer_wise_credit_pdf',compact('allData'));
    }// End Method


    public function CustomerWisePaidReport(Request $request){

         $customer = $this->companyCustomer($request->customer_id);
         $allData = $this->companyPayments()->where('customer_id',$customer->id)->where('approval_status', 'approved')->where('paid_status','!=','full_due')->orderBy('id','desc')->get();
        return view('backend.pdf.customer_wise_paid_pdf',compact('allData'));
    }// End Method



    }
 
