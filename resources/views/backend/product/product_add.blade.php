@extends('admin.admin_master')
@section('admin')
 <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

<div class="page-content">
<div class="container-fluid">

<div class="row">
<div class="col-12">
    <div class="card">
        <div class="card-body">

            <h4 class="card-title">Add Product Page </h4><br><br>
            
  

 <form method="post" action="{{ route('product.store') }}" id="myForm" >
                @csrf

            <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Product Name </label>
                <div class="form-group col-sm-10">
                    <input name="name" class="form-control" type="text"    >
                </div>
            </div>
            <!-- end row -->


            <div class="row mb-3">
        <label class="col-sm-2 col-form-label">Supplier Name </label>
        <div class="col-sm-10">
            <select name="supplier_id" class="form-select" aria-label="Default select example">
                <option selected="">Open this select menu</option>
                @foreach($supplier as $supp)
                <option value="{{ $supp->id }}">{{ $supp->name }}</option>
               @endforeach
                </select>
        </div>
    </div>
  <!-- end row -->

      <div class="row mb-3">
        <label class="col-sm-2 col-form-label">Unit Name </label>
        <div class="col-sm-10">
            <select name="unit_id" class="form-select" aria-label="Default select example">
                <option selected="">Open this select menu</option>
                @foreach($unit as $uni)
                <option value="{{ $uni->id }}">{{ $uni->name }}</option>
               @endforeach
                </select>
        </div>
    </div> 
  <!-- end row -->



      <div class="row mb-3">
        <label class="col-sm-2 col-form-label">Category Name </label>
        <div class="col-sm-10">
            <select name="category_id" class="form-select" aria-label="Default select example">
                <option selected="">Open this select menu</option>
                @foreach($category as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
               @endforeach
                </select>
        </div>
    </div>
  <!-- end row -->

            <div class="row mb-3"><label class="col-sm-2 col-form-label">Brand</label><div class="col-sm-10"><select name="brand_id" class="form-select"><option value="">No brand</option>@foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">SKU</label><div class="col-sm-10"><input name="sku" class="form-control" maxlength="100"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Barcode</label><div class="col-sm-10"><input name="barcode" class="form-control" maxlength="100"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">HSN/SAC</label><div class="col-sm-10"><input name="hsn_sac_code" class="form-control" maxlength="30"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Purchase price</label><div class="col-sm-10"><input name="purchase_price" type="number" step="0.000001" min="0" class="form-control"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Sales price</label><div class="col-sm-10"><input name="sales_price" type="number" step="0.000001" min="0" class="form-control"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Inventory costing</label><div class="col-sm-10 row g-2"><div class="col-md-6"><select name="costing_method" class="form-select"><option value="fifo" @selected(($defaultCostingMethod ?? 'fifo') === 'fifo')>FIFO</option><option value="weighted_average" @selected(($defaultCostingMethod ?? 'fifo') === 'weighted_average')>Weighted average</option><option value="moving_average" @selected(($defaultCostingMethod ?? 'fifo') === 'moving_average')>Moving average</option><option value="standard" @selected(($defaultCostingMethod ?? 'fifo') === 'standard')>Standard cost</option></select></div><div class="col-md-6"><input name="standard_cost" type="number" step="0.000001" min="0" value="{{ $defaultStandardCost ?? '' }}" placeholder="Standard cost (optional)" class="form-control"></div></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Physical dimensions</label><div class="col-sm-10 row g-2"><div class="col-md-3"><input name="weight_kg" type="number" step="0.000001" min="0" placeholder="Weight (kg)" class="form-control"></div><div class="col-md-3"><input name="length_m" type="number" step="0.000001" min="0" placeholder="Length (m)" class="form-control"></div><div class="col-md-3"><input name="width_m" type="number" step="0.000001" min="0" placeholder="Width (m)" class="form-control"></div><div class="col-md-3"><input name="height_m" type="number" step="0.000001" min="0" placeholder="Height (m)" class="form-control"></div></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Tax rate (%)</label><div class="col-sm-10"><input name="tax_rate" type="number" step="0.0001" min="0" max="100" value="" placeholder="Use category default" class="form-control"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Configured tax rate</label><div class="col-sm-10"><select name="tax_rate_id" class="form-select"><option value="">Use numeric/category rate</option>@foreach($taxRates as $taxRate)<option value="{{ $taxRate->id }}">{{ $taxRate->code }} — {{ $taxRate->name }} ({{ $taxRate->rate }}%)</option>@endforeach</select></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Tracking</label><div class="col-sm-10"><select name="tracking_type" class="form-select"><option value="none">None</option><option value="batch">Batch</option><option value="serial">Serial</option></select></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Lifecycle</label><div class="col-sm-10 row g-2"><div class="col-md-4"><select name="product_type" class="form-select"><option value="stock">Stock item</option><option value="service">Service</option><option value="consumable">Consumable</option><option value="asset">Asset</option><option value="bundle">Bundle</option></select></div><div class="col-md-4"><select name="lifecycle_status" class="form-select"><option value="active">Active</option><option value="draft">Draft</option><option value="discontinued">Discontinued</option><option value="blocked">Blocked</option><option value="archived">Archived</option></select></div><div class="col-md-4"><label class="form-check"><input type="hidden" name="can_purchase" value="0"><input class="form-check-input" type="checkbox" name="can_purchase" value="1" checked> Purchasable</label><label class="form-check"><input type="hidden" name="can_sell" value="0"><input class="form-check-input" type="checkbox" name="can_sell" value="1" checked> Sellable</label><label class="form-check"><input type="hidden" name="is_stock_item" value="0"><input class="form-check-input" type="checkbox" name="is_stock_item" value="1" checked> Stock-managed</label></div></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Stock levels</label><div class="col-sm-10 row g-2"><div class="col-md-4"><input name="min_stock" type="number" step="0.000001" min="0" placeholder="Minimum" class="form-control"></div><div class="col-md-4"><input name="reorder_level" type="number" step="0.000001" min="0" placeholder="Reorder level" class="form-control"></div><div class="col-md-4"><input name="max_stock" type="number" step="0.000001" min="0" placeholder="Maximum" class="form-control"></div></div></div>

<input type="submit" class="btn btn-info waves-effect waves-light" value="Add Product">
            </form>
             
           
           
        </div>
    </div>
</div> <!-- end col -->
</div>
 


</div>
</div>

<script type="text/javascript">
    $(document).ready(function (){
        $('#myForm').validate({
            rules: {
                name: {
                    required : true,
                }, 
                 supplier_id: {
                    required : true,
                },
                 unit_id: {
                    required : true,
                },
                 category_id: {
                    required : true,
                },
            },
            messages :{
                name: {
                    required : 'Please Enter Your Product Name',
                },
                supplier_id: {
                    required : 'Please Select One Supplier',
                },
                unit_id: {
                    required : 'Please Select One Unit',
                },
                category_id: {
                    required : 'Please Select One Category',
                },
            },
            errorElement : 'span', 
            errorPlacement: function (error,element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight : function(element, errorClass, validClass){
                $(element).addClass('is-invalid');
            },
            unhighlight : function(element, errorClass, validClass){
                $(element).removeClass('is-invalid');
            },
        });
    });
    
</script>


 
@endsection 
