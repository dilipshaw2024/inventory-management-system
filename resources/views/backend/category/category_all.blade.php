@extends('admin.admin_master')
@section('admin')


 <div class="page-content">
                    <div class="container-fluid">

                        <!-- start page title -->
                        <div class="row">
                            <div class="col-12">
                                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                                    <h4 class="mb-sm-0">Category All</h4>

                                     

                                </div>
                            </div>
                        </div>
                        <!-- end page title -->
                        
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">

    <a href="{{ route('category.add') }}" class="btn btn-dark btn-rounded waves-effect waves-light" style="float:right;"><i class="fas fa-plus-circle"> Add Category </i></a> <br>  <br>               

                    <h4 class="card-title">Category All Data </h4>
                    

                    <table id="datatable" class="table table-bordered dt-responsive nowrap" style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                        <thead>
                        <tr>
                            <th width="5%">Sl</th>
                            <th>Name</th> 
                            <th>Code</th>
                            <th>Parent</th>
                            <th>Tax</th>
                            <th>Status</th>
                            <th width="20%">Action</th>
                            
                        </thead>


                        <tbody>
                        	 
                        	@foreach($categoris as $key => $item)
                        <tr>
                            <td> {{ $key+1}} </td>
                            <td> {{ $item->name }} </td>  
                            <td> {{ $item->code ?: '—' }} </td>
                            <td> {{ $item->parent->name ?? 'Top level' }} </td>
                            <td> {{ $item->tax_rate ?? '—' }}% </td>
                            <td> {{ $item->is_active === false ? 'Inactive' : 'Active' }} </td>
                            <td>
   <a href="{{ route('category.edit',$item->id) }}" class="btn btn-info sm" title="Edit Data">  <i class="fas fa-edit"></i> </a>

     <form method="POST" action="{{ route('category.delete',$item->id) }}" class="d-inline" onsubmit="return confirm('Delete this category?');"> @csrf <button type="submit" class="btn btn-danger sm" title="Delete Data"><i class="fas fa-trash-alt"></i></button> </form>

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
