@extends('admin.admin_master')
@section('admin')


 <div class="page-content">
                    <div class="container-fluid">

                        <!-- start page title -->
                        <div class="row">
                            <div class="col-12">
                                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                                    <h4 class="mb-sm-0">Stock Report All</h4>

                                     

                                </div>
                            </div>
                        </div>
                        <!-- end page title -->
                        
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">

                    <a href="{{ route('stock.report.pdf', request()->query()) }}" target="_blank" class="btn btn-dark btn-rounded waves-effect waves-light" style="float:right;"><i class="fa fa-print"> Stock Report Print </i></a> <br>  <br>
                    <form method="GET" action="{{ route('stock.report') }}" class="row g-2 mb-3"><div class="col-md-3"><select name="product_id" class="form-select"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>@endforeach</select></div><div class="col-md-3"><select name="category_id" class="form-select"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select></div><div class="col-md-3"><select name="location_id" class="form-select"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected($locationId == $location->id)>{{ $location->code }}</option>@endforeach</select></div><div class="col-md-3"><button class="btn btn-primary">Filter</button> <a href="{{ route('stock.report') }}" class="btn btn-light">Reset</a></div></form>

                    <h4 class="card-title">Stock Report </h4>
                    

                    <table id="datatable" class="table table-bordered dt-responsive nowrap" style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                        <thead>
                        <tr>
                            <th>Sl</th>
                            <th>Supplier Name </th>
                            <th>Unit</th>
                            <th>Category</th> 
                            <th>Product Name</th> 
                            <th>In Qty</th> 
                            <th>Out Qty </th>  
                            <th>On hand</th>
                            <th>Reserved</th>
                            <th>Quality hold</th>
                            <th>Available</th>
                            
                        </thead>


                        <tbody>
                        	 
                        	@foreach($allData as $key => $item)
@php
$buying_total = $item->report_inbound_quantity;

$selling_total = $item->report_outbound_quantity;
@endphp

    <tr>
        <td> {{ $key+1}} </td> 
        <td> {{ data_get($item, 'supplier.name', 'N/A') }} </td> 
        <td> {{ data_get($item, 'unit.name', 'N/A') }} </td> 
        <td> {{ data_get($item, 'category.name', 'N/A') }} </td> 
        <td> {{ $item->name }} </td> 
        <td> <span class="btn btn-success"> {{ number_format((float) $buying_total, $decimalPrecision) }}</span>  </td>
        <td> <span class="btn btn-info"> {{ number_format((float) $selling_total, $decimalPrecision) }}</span> </td>
        <td> <span class="btn btn-danger"> {{ number_format((float) $item->report_on_hand_quantity, $decimalPrecision) }}</span> </td>
        <td> {{ number_format((float) $item->reserved_quantity, $decimalPrecision) }} </td>
        <td> {{ number_format((float) $item->quality_hold_quantity, $decimalPrecision) }} </td>
        <td> <span class="btn btn-success"> {{ number_format((float) $item->available_quantity, $decimalPrecision) }}</span> </td>
        
       
    </tr>
    @endforeach
                        
                        </tbody>
                    </table>
        
                                    </div>
                                </div>
                            </div> <!-- end col -->
                        </div> <!-- end row -->
        
                     
                        
                    </div> <!-- container-fluid -->
                </div>
 

@endsection
