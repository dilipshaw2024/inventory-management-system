<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Unit;
use App\Http\Requests\Pos\NameRequest;
use Auth;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
     public function UnitAll(){
        
        $units = Unit::latest()->get();
        return view('backend.unit.unit_all',compact('units'));
    } // End Method 


    public function UnitAdd(){
        return view('backend.unit.unit_add');
    } // End Method 



     public function UnitStore(Request $request){

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('units', 'code')->where(fn ($query) => $query->where('company_id', Auth::user()->company_id)->orWhereNull('company_id'))],
            'decimal_places' => ['required', 'integer', 'between:0,8'],
            'dimension' => ['required', 'string', 'max:30', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]*$/'],
            'is_base' => ['nullable', 'boolean'],
        ]);

        Unit::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'decimal_places' => $data['decimal_places'],
            'dimension' => strtolower($data['dimension']),
            'is_base' => (bool) ($data['is_base'] ?? false),
            'company_id' => Auth::user()->company_id,
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(), 

        ]);

         $notification = array(
            'message' => 'Unit Inserted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('unit.all')->with($notification);

    } // End Method 


    public function UnitEdit($id){

          $unit = Unit::findOrFail($id);
        return view('backend.unit.unit_edit',compact('unit'));

    }// End Method 


    public function UnitUpdate(Request $request){

        $request->validate([
            'id' => ['required', 'integer', 'exists:units,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('units', 'code')->ignore($request->id)->where(fn ($query) => $query->where('company_id', Auth::user()->company_id)->orWhereNull('company_id'))],
            'decimal_places' => ['required', 'integer', 'between:0,8'],
            'dimension' => ['required', 'string', 'max:30', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]*$/'],
            'is_base' => ['nullable', 'boolean'],
        ]);

        $unit_id = $request->id;

        Unit::findOrFail($unit_id)->update([
            'name' => $request->name,
            'code' => $request->code ?: null,
            'decimal_places' => (int) $request->decimal_places,
            'dimension' => strtolower($request->dimension),
            'is_base' => $request->boolean('is_base'),
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(), 

        ]);

         $notification = array(
            'message' => 'Unit Updated Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('unit.all')->with($notification);

    }// End Method 


    public function UnitDelete($id){

          $unit = Unit::findOrFail($id);
          if ($unit->products()->exists()) {
              return redirect()->back()->with(['message' => 'This unit cannot be deleted because it is linked to a product.', 'alert-type' => 'error']);
          }

          $unit->delete();
      
       $notification = array(
            'message' => 'Unit Deleted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification);

    } // End Method 
 


}
 
