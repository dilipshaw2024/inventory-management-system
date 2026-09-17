<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Http\Requests\Pos\SupplierRequest;
use Auth;
use Illuminate\Support\Carbon;

class SupplierController extends Controller
{
    public function SupplierAll(){
        // $suppliers = Supplier::all();
        $suppliers = Supplier::latest()->get();
        return view('backend.supplier.supplier_all',compact('suppliers'));
    } // End Method 


    public function SupplierAdd(){
     return view('backend.supplier.supplier_add');
    } // End Method 


    public function SupplierStore(SupplierRequest $request){

        $calendar = $this->planningCalendar($request);

        Supplier::create([
            'name' => $request->name,
            'company_id' => Auth::user()->company_id,
            'mobile_no' => $request->mobile_no,
            'email' => $request->email,
            'address' => $request->address,
            'tax_number' => $request->tax_number,
            'tax_jurisdiction' => $request->tax_jurisdiction,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'tax_exemption_number' => $request->tax_exemption_number,
            'payment_terms_days' => $request->payment_terms_days ?? 0,
            'bank_name' => $request->bank_name,
            'bank_account' => $request->bank_account,
            'bank_code' => $request->bank_code,
            'rating' => $request->rating,
            'is_active' => $request->boolean('is_active', true),
            'planning_calendar' => $calendar,
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(), 

        ]);

         $notification = array(
            'message' => 'Supplier Inserted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('supplier.all')->with($notification);

    } // End Method 


    public function SupplierEdit($id){

        $supplier = Supplier::findOrFail($id);
        return view('backend.supplier.supplier_edit',compact('supplier'));

    } // End Method 

    public function SupplierUpdate(Request $request){

        $request->validate([
            'id' => ['required', 'integer', 'exists:suppliers,id'],
            'name' => ['required', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'tax_jurisdiction' => ['nullable', 'string', 'max:100'],
            'tax_exempt' => ['nullable', 'boolean'],
            'tax_exemption_number' => ['required_if:tax_exempt,1', 'nullable', 'string', 'max:100'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:50'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'is_active' => ['nullable', 'boolean'],
            'planning_weekend_days' => ['nullable', 'string', 'max:20', 'regex:/^[0-6](,[0-6])*$/'],
            'planning_holidays' => ['nullable', 'string', 'max:5000', 'regex:/^(\d{4}-\d{2}-\d{2})(,\d{4}-\d{2}-\d{2})*$/'],
        ]);

        $sullier_id = $request->id;
        $calendar = $this->planningCalendar($request);

        Supplier::findOrFail($sullier_id)->update([
            'name' => $request->name,
            'mobile_no' => $request->mobile_no,
            'email' => $request->email,
            'address' => $request->address,
            'tax_number' => $request->tax_number,
            'tax_jurisdiction' => $request->tax_jurisdiction,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'tax_exemption_number' => $request->tax_exemption_number,
            'payment_terms_days' => $request->payment_terms_days ?? 0,
            'bank_name' => $request->bank_name,
            'bank_account' => $request->bank_account,
            'bank_code' => $request->bank_code,
            'rating' => $request->rating,
            'is_active' => $request->boolean('is_active', true),
            'planning_calendar' => $calendar,
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(), 

        ]);

         $notification = array(
            'message' => 'Supplier Updated Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('supplier.all')->with($notification);

    } // End Method 


    private function planningCalendar(Request $request): ?array
    {
        if (!$request->filled('planning_weekend_days') && !$request->filled('planning_holidays')) return null;
        return [
            'weekend_days' => array_map('intval', explode(',', $request->input('planning_weekend_days', '0,6'))),
            'holidays' => array_values(array_unique(array_filter(explode(',', (string) $request->input('planning_holidays', ''))))),
        ];
    }

    public function SupplierDelete($id){

      $supplier = Supplier::findOrFail($id);
      if ($supplier->products()->exists() || $supplier->purchases()->exists()) {
        return redirect()->back()->with(['message' => 'This supplier cannot be deleted because it is linked to products or purchases.', 'alert-type' => 'error']);
      }

      $supplier->delete();
      
       $notification = array(
            'message' => 'Supplier Deleted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification);

    } // End Method 


}
 
