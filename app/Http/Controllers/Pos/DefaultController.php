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
use Illuminate\Validation\Rule;

class DefaultController extends Controller
{
    private function companyId(): int
    {
        return (int) Auth::user()->company_id;
    }

    private function visibleProducts()
    {
        return Product::where(fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'));
    }

    public function GetCategory(Request $request){

        $companyId = $this->companyId();
        $supplier_id = $request->validate(['supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))]])['supplier_id'];
        // dd($supplier_id);
        $allCategory = $this->visibleProducts()->with(['category'])->select('category_id')->where('supplier_id',$supplier_id)->groupBy('category_id')->orderBy('category_id','desc')->get();
        return response()->json($allCategory);
    } // End Mehtod 

    public function GetProduct(Request $request){

        $companyId = $this->companyId();
        $category_id = $request->validate(['category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))]])['category_id'];
        $allProduct = $this->visibleProducts()->where('category_id',$category_id)->orderBy('id','desc')->get();
        return response()->json($allProduct);
    } // End Mehtod 


    public function GetStock(Request $request){
        $product_id = $request->validate(['product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'))]])['product_id'];
        $stock = $this->visibleProducts()->where('id',$product_id)->value('quantity');
        return response()->json($stock);

    } // End Mehtod 






}
 
