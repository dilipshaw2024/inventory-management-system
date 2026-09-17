@extends('admin.admin_master')
@section('admin')
 <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

<div class="page-content">
<div class="container-fluid">

<div class="row">
<div class="col-12">
    <div class="card">
        <div class="card-body">

            <h4 class="card-title">Add Customer Page </h4><br><br>
            
  

    <form method="post" action="{{ route('customer.store') }}" id="myForm" enctype="multipart/form-data" >
                @csrf

            <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Customer Name </label>
                <div class="form-group col-sm-10">
                    <input name="name" class="form-control" type="text"    >
                </div>
            </div>
            <!-- end row -->

            <div class="row mb-3"><label class="col-sm-2 col-form-label">Tax jurisdiction</label><div class="form-group col-sm-10"><input name="tax_jurisdiction" class="form-control" maxlength="100" placeholder="Domestic, state, export..."></div></div>


              <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Customer Mobile </label>
                <div class="form-group col-sm-10">
                    <input name="mobile_no" class="form-control" type="text"    >
                </div>
            </div>
            <!-- end row -->


  <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Customer Email </label>
                <div class="form-group col-sm-10">
                    <input name="email" class="form-control" type="email"  >
                </div>
            </div>
            <!-- end row -->


  <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Customer Address </label>
                <div class="form-group col-sm-10">
                    <input name="address" class="form-control" type="text"  >
                </div>
            </div>
            <!-- end row -->

            <div class="row mb-3"><label class="col-sm-2 col-form-label">Tax number</label><div class="form-group col-sm-10"><input name="tax_number" class="form-control" maxlength="100"></div></div><div class="row mb-3"><label class="col-sm-2 col-form-label">Tax exemption</label><div class="form-group col-sm-10"><input name="tax_exempt" value="1" type="checkbox" class="form-check-input"> Exempt <input name="tax_exemption_number" class="form-control mt-2" maxlength="100" placeholder="Exemption certificate/reference"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Customer group</label><div class="form-group col-sm-10"><input name="customer_group" class="form-control" maxlength="100" placeholder="Retail, wholesale, VIP..."></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Sales channel</label><div class="form-group col-sm-10"><input name="sales_channel" class="form-control" maxlength="50" placeholder="Store, online, partner..."></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Preferred currency</label><div class="form-group col-sm-10"><input name="currency_code" class="form-control" maxlength="3" placeholder="USD"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Credit limit</label><div class="form-group col-sm-10"><input name="credit_limit" type="number" min="0" step="0.000001" class="form-control" value="0"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Credit days</label><div class="form-group col-sm-10"><input name="credit_days" type="number" min="0" max="3650" class="form-control" value="0"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Hold after overdue days</label><div class="form-group col-sm-10"><input name="credit_hold_after_days" type="number" min="0" max="3650" class="form-control" value="0"><small class="text-muted">Zero disables automatic overdue blocking.</small></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Credit hold</label><div class="form-group col-sm-10"><input name="credit_hold" value="1" type="checkbox" class="form-check-input"></div></div>

              <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Customer Image </label>
                <div class="form-group col-sm-10">
       <input name="customer_image" class="form-control" type="file"  id="image">
                </div>
            </div>
            <!-- end row -->

              <div class="row mb-3">
                 <label for="example-text-input" class="col-sm-2 col-form-label">  </label>
                <div class="col-sm-10">
   <img id="showImage" class="rounded avatar-lg" src="{{  url('upload/no_image.jpg') }}" alt="Card image cap">
                </div>
            </div>
            <!-- end row -->
 
 


        
<input type="submit" class="btn btn-info waves-effect waves-light" value="Add Customer">
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
                 mobile_no: {
                    required : true,
                },
                 email: {
                    required : true,
                },
                 address: {
                    required : true,
                },
                 customer_image: {
                    required : true,
                },
            },
            messages :{
                name: {
                    required : 'Please Enter Your Name',
                },
                mobile_no: {
                    required : 'Please Enter Your Mobile Number',
                },
                email: {
                    required : 'Please Enter Your Email',
                },
                address: {
                    required : 'Please Enter Your Address',
                },
                 customer_image: {
                    required : 'Please Select one Image',
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


<script type="text/javascript">
    
    $(document).ready(function(){
        $('#image').change(function(e){
            var reader = new FileReader();
            reader.onload = function(e){
                $('#showImage').attr('src',e.target.result);
            }
            reader.readAsDataURL(e.target.files['0']);
        });
    });

</script>


 
@endsection 
