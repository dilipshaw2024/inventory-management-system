@extends('admin.admin_master')
@section('admin')


 <div class="page-content">
                    <div class="container-fluid">

                        <!-- start page title -->
                        <div class="row">
                            <div class="col-12">
                                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                                    <h4 class="mb-sm-0">Product All</h4>

                                     

                                </div>
                            </div>
                        </div>
                        <!-- end page title -->
                        
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">

                    <a href="{{ route('product.export') }}" class="btn btn-outline-success btn-sm float-end ms-2">Export XLSX</a><form method="POST" action="{{ route('product.import') }}" enctype="multipart/form-data" class="float-end d-flex gap-1">@csrf<input type="file" name="file" accept=".csv,.txt,.xlsx" class="form-control form-control-sm" required><label class="form-check-label mt-1"><input type="checkbox" name="dry_run" value="1" class="form-check-input"> Validate only</label><button class="btn btn-outline-primary btn-sm">Import</button></form><a href="{{ route('product.add') }}" class="btn btn-dark btn-rounded waves-effect waves-light float-end"><i class="fas fa-plus-circle"> Add Product </i></a> <br>  <br>

                    <h4 class="card-title">Product All Data </h4>
                    

                    <table id="datatable" class="table table-bordered dt-responsive nowrap" style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                        <thead>
                        <tr>
                            <th>Sl</th>
                            <th>Image</th>
                            <th>Name</th>
                            <th>SKU / Barcode</th>
                            <th>Supplier Name </th>
                            <th>Unit</th>
                            <th>Category</th> 
                            <th>Action</th>
                            
                        </thead>


                        <tbody>
                        	 
                        	@foreach($product as $key => $item)
                        <tr>
                            <td> {{ $key+1}} </td>
                            <td>@php($primaryImage = $item->attachments->first())@if($primaryImage)<img src="{{ route('erp.attachments.preview', $primaryImage->id) }}" alt="{{ $item->name }}" style="max-width:48px;max-height:48px;object-fit:contain">@else — @endif</td>
                            <td> {{ $item->name }} </td>
                            <td>{{ $item->sku ?? '—' }}<br><small>{{ $item->barcode ?? '—' }}</small></td>
                            <td> {{ data_get($item, 'supplier.name', 'N/A') }} </td> 
                            <td> {{ data_get($item, 'unit.name', 'N/A') }} </td> 
                            <td> {{ data_get($item, 'category.name', 'N/A') }} </td> 
                            <td>
   <a href="{{ route('product.edit',$item->id) }}" class="btn btn-info sm" title="Edit Data">  <i class="fas fa-edit"></i> </a>

     <form method="POST" action="{{ route('product.delete',$item->id) }}" class="d-inline" onsubmit="return confirm('Delete this product?');"> @csrf <button type="submit" class="btn btn-danger sm" title="Delete Data"><i class="fas fa-trash-alt"></i></button> </form>

                            </td>
                           
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
