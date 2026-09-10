<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\Unit;
use App\Http\Requests\Pos\ProductRequest;
use Auth;
use Illuminate\Support\Carbon;
  
class ProductController extends Controller
{
    public function ProductAll(){

        $product = Product::latest()->get();
        return view('backend.product.product_all',compact('product'));

    } // End Method 


    public function ProductAdd(){

        $supplier = Supplier::orderBy('id','desc')->get();
        $category = Category::orderBy('id','desc')->get();
        $unit = Unit::orderBy('id','desc')->get();
        return view('backend.product.product_add',compact('supplier','category','unit'));
    } // End Method 


    public function ProductStore(ProductRequest $request){

        Product::insert([

            'name' => $request->name,
            'supplier_id' => $request->supplier_id,
            'unit_id' => $request->unit_id,
            'category_id' => $request->category_id,
            'quantity' => '0',
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(), 
        ]);

        $notification = array(
            'message' => 'Product Inserted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('product.all')->with($notification); 

    } // End Method 



    public function ProductEdit($id){

        $supplier = Supplier::orderBy('id','desc')->get();
        $category = Category::orderBy('id','desc')->get();
        $unit = Unit::orderBy('id','desc')->get();
        $product = Product::findOrFail($id);
        return view('backend.product.product_edit',compact('product','supplier','category','unit'));
    } // End Method 



    public function ProductUpdate(Request $request){

        $request->validate([
            'id' => ['required', 'integer', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
        ]);

        $product_id = $request->id;

         Product::findOrFail($product_id)->update([

            'name' => $request->name,
            'supplier_id' => $request->supplier_id,
            'unit_id' => $request->unit_id,
            'category_id' => $request->category_id, 
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(), 
        ]);

        $notification = array(
            'message' => 'Product Updated Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('product.all')->with($notification); 


    } // End Method 


    public function ProductDelete($id){
       $product = Product::findOrFail($id);
       if ($product->purchases()->exists() || $product->invoiceDetails()->exists() || $product->quantity > 0) {
            return redirect()->back()->with(['message' => 'This product cannot be deleted because it has stock or transaction history.', 'alert-type' => 'error']);
       }
       $product->delete();
            $notification = array(
            'message' => 'Product Deleted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification); 

    } // End Method 



}
 
