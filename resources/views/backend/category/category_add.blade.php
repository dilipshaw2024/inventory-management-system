@extends('admin.admin_master')
@section('admin')
 <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

<div class="page-content">
<div class="container-fluid">

<div class="row">
<div class="col-12">
    <div class="card">
        <div class="card-body">

            <h4 class="card-title">Add Category Page </h4><br><br>
            
  

            <form method="post" action="{{ route('category.store') }}" id="myForm" >
                @csrf

            <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Category Name </label>
                <div class="form-group col-sm-10">
                    <input name="name" class="form-control" type="text"    >
                </div>
            </div>
            <!-- end row --> 

            <div class="row mb-3"><label class="col-sm-2 col-form-label">Category code</label><div class="form-group col-sm-10"><input name="code" class="form-control" maxlength="50"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Parent category</label><div class="form-group col-sm-10"><select name="parent_id" class="form-select"><option value="">Top level</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Default tax rate</label><div class="form-group col-sm-10"><input name="tax_rate" type="number" min="0" max="100" step="0.0001" class="form-control" value="0"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Required variant attributes</label><div class="form-group col-sm-10"><select name="required_attribute_ids[]" class="form-select" multiple size="4">@foreach($attributes as $attribute)<option value="{{ $attribute->id }}">{{ $attribute->name }}</option>@endforeach</select><small class="text-muted">Optional. Selected attributes must be supplied when creating variants in this category.</small></div></div>

        
<input type="submit" class="btn btn-info waves-effect waves-light" value="Add Category">
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
                 
            },
            messages :{
                name: {
                    required : 'Please Enter Your Name',
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
