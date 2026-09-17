<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\ProductAttribute;
use Auth;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;


class CategoryController extends Controller
{
    public function CategoryAll(){

        $categoris = Category::latest()->get();
        return view('backend.category.category_all',compact('categoris'));

    } // End Mehtod 

    public function CategoryAdd(){
     $categories = Category::orderBy('name')->get(['id', 'name']);
     $attributes = $this->availableAttributes();
     return view('backend.category.category_add', compact('categories', 'attributes'));
    } // End Mehtod 


    public function CategoryStore(Request $request){
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:50', Rule::unique('categories', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'is_active' => ['nullable', 'boolean'], 'required_attribute_ids' => ['nullable', 'array', 'max:50'], 'required_attribute_ids.*' => ['integer', Rule::exists('product_attributes', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))]]);
        $requiredAttributeIds = $this->validatedRequiredAttributeIds($data['required_attribute_ids'] ?? []);

        Category::create([
            'name' => $data['name'], 'code' => $data['code'] ?? 'CAT-'.strtoupper(bin2hex(random_bytes(4))), 'parent_id' => $data['parent_id'] ?? null, 'tax_rate' => $data['tax_rate'] ?? null, 'is_active' => $request->boolean('is_active', true), 'required_attribute_ids' => $requiredAttributeIds,
            'company_id' => Auth::user()->company_id,
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(), 

        ]);

         $notification = array(
            'message' => 'Category Inserted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('category.all')->with($notification);

    } // End Method 

     public function CategoryEdit($id){

          $category = Category::findOrFail($id);
          $categories = Category::where('id', '<>', $id)->orderBy('name')->get(['id', 'name']);
          $attributes = $this->availableAttributes();
        return view('backend.category.category_edit',compact('category', 'categories', 'attributes'));

    }// End Method 


     public function CategoryUpdate(Request $request){

        $request->validate([
            'id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:50', Rule::unique('categories', 'code')->ignore($request->id)->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))], 'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id')), 'not_in:'.$request->id], 'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'is_active' => ['nullable', 'boolean'], 'required_attribute_ids' => ['nullable', 'array', 'max:50'], 'required_attribute_ids.*' => ['integer', Rule::exists('product_attributes', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))],
        ]);
        $requiredAttributeIds = $this->validatedRequiredAttributeIds($request->input('required_attribute_ids', []));

        $category_id = $request->id;
        if ($request->parent_id && $this->createsCategoryCycle($category_id, (int) $request->parent_id)) {
            return back()->withErrors(['parent_id' => 'A category cannot be nested under one of its descendants.'])->withInput();
        }

        Category::findOrFail($category_id)->update([
            'name' => $request->name, 'code' => $request->code ?: 'CAT-'.strtoupper(bin2hex(random_bytes(4))), 'parent_id' => $request->parent_id, 'tax_rate' => $request->tax_rate, 'is_active' => $request->boolean('is_active', false), 'required_attribute_ids' => $requiredAttributeIds,
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(), 

        ]);

         $notification = array(
            'message' => 'Category Updated Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('category.all')->with($notification);

    }// End Method 


    public function CategoryDelete($id){

          $category = Category::findOrFail($id);
          if ($category->products()->exists() || $category->purchases()->exists() || $category->invoiceDetails()->exists()) {
              return redirect()->back()->with(['message' => 'This category cannot be deleted because it is linked to products or transactions.', 'alert-type' => 'error']);
    }

          $category->delete();
      
       $notification = array(
            'message' => 'Category Deleted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification);

    } // End Method 

    private function createsCategoryCycle(int $categoryId, int $parentId): bool
    {
        $visited = [];
        while ($parentId && !in_array($parentId, $visited, true)) {
            if ($parentId === $categoryId) return true;
            $visited[] = $parentId;
            $parentId = (int) (Category::whereKey($parentId)->value('parent_id') ?? 0);
        }
        return false;
    }

    private function availableAttributes()
    {
        return ProductAttribute::where('is_active', true)->with('values')->orderBy('name')->get();
    }

    private function validatedRequiredAttributeIds(array $attributeIds): array
    {
        $attributeIds = array_values(array_unique(array_map('intval', $attributeIds)));
        if (!$attributeIds) return [];

        $activeCount = ProductAttribute::whereIn('id', $attributeIds)->where('is_active', true)->count();
        if ($activeCount !== count($attributeIds)) {
            abort(422, 'All required attributes must be active and authorized for this company.');
        }
        return $attributeIds;
    }


}
 
