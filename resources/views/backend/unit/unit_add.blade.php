@extends('admin.admin_master')
@section('admin')
 <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

<div class="page-content">
<div class="container-fluid">

<div class="row">
<div class="col-12">
    <div class="card">
        <div class="card-body">

            <h4 class="card-title">Add Unit Page </h4><br><br>
            
  

            <form method="post" action="{{ route('unit.store') }}" id="myForm" >
                @csrf

            <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Unit Name </label>
                <div class="form-group col-sm-10">
                    <input name="name" class="form-control" type="text"    >
                </div>
            </div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Code</label><div class="form-group col-sm-4"><input name="code" class="form-control" placeholder="e.g. kg"></div><label class="col-sm-2 col-form-label">Decimals</label><div class="form-group col-sm-4"><input name="decimal_places" type="number" min="0" max="8" value="3" class="form-control" required></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Dimension</label><div class="form-group col-sm-4"><input name="dimension" value="unit" class="form-control" pattern="[A-Za-z][A-Za-z0-9_-]*" required></div><div class="form-group col-sm-4 offset-sm-2"><label><input type="checkbox" name="is_base" value="1"> Base unit</label></div></div>
            <!-- end row --> 

        
<input type="submit" class="btn btn-info waves-effect waves-light" value="Add Unit">
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
