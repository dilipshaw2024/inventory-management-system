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

class DefaultController extends Controller
{
    public function GetCategory(Request $request){

        $supplier_id = $request->validate(['supplier_id' => ['required', 'integer', 'exists:suppliers,id']])['supplier_id'];
        // dd($supplier_id);
        $allCategory = Product::with(['category'])->select('category_id')->where('supplier_id',$supplier_id)->groupBy('category_id')->orderBy('category_id','desc')->get();
        return response()->json($allCategory);
    } // End Mehtod 

    public function GetProduct(Request $request){

        $category_id = $request->validate(['category_id' => ['required', 'integer', 'exists:categories,id']])['category_id'];
        $allProduct = Product::where('category_id',$category_id)->orderBy('id','desc')->get();
        return response()->json($allProduct);
    } // End Mehtod 


    public function GetStock(Request $request){
        $product_id = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id']])['product_id'];
        $stock = Product::where('id',$product_id)->value('quantity');
        return response()->json($stock);

    } // End Mehtod 






}
 
