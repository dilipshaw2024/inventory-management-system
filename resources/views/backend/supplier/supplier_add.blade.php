@extends('admin.admin_master')
@section('admin')
 <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

<div class="page-content">
<div class="container-fluid">

<div class="row">
<div class="col-12">
    <div class="card">
        <div class="card-body">

            <h4 class="card-title">Add Supplier Page </h4><br><br>
            
  

            <form method="post" action="{{ route('supplier.store') }}" id="myForm" >
                @csrf

            <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Supplier Name </label>
                <div class="form-group col-sm-10">
                    <input name="name" class="form-control" type="text"    >
                </div>
            </div>
            <!-- end row -->

            <div class="row mb-3"><label class="col-sm-2 col-form-label">Tax jurisdiction</label><div class="form-group col-sm-10"><input name="tax_jurisdiction" class="form-control" maxlength="100" placeholder="Domestic, state, export..."></div></div>


              <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Supplier Mobile </label>
                <div class="form-group col-sm-10">
                    <input name="mobile_no" class="form-control" type="text"    >
                </div>
            </div>
            <!-- end row -->


  <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Supplier Email </label>
                <div class="form-group col-sm-10">
                    <input name="email" class="form-control" type="email"  >
                </div>
            </div>
            <!-- end row -->


  <div class="row mb-3">
                <label for="example-text-input" class="col-sm-2 col-form-label">Supplier Address </label>
                <div class="form-group col-sm-10">
                    <input name="address" class="form-control" type="text"  >
                </div>
            </div>
            <!-- end row -->

            <div class="row mb-3"><label class="col-sm-2 col-form-label">Tax number</label><div class="form-group col-sm-10"><input name="tax_number" class="form-control" maxlength="100"></div></div><div class="row mb-3"><label class="col-sm-2 col-form-label">Tax exemption</label><div class="form-group col-sm-10"><input name="tax_exempt" value="1" type="checkbox" class="form-check-input"> Exempt <input name="tax_exemption_number" class="form-control mt-2" maxlength="100" placeholder="Exemption certificate/reference"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Payment terms (days)</label><div class="form-group col-sm-10"><input name="payment_terms_days" type="number" min="0" max="3650" class="form-control" value="0"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Bank details</label><div class="form-group col-sm-10"><div class="row g-2"><div class="col-md-4"><input name="bank_name" class="form-control" placeholder="Bank name"></div><div class="col-md-4"><input name="bank_account" class="form-control" placeholder="Account number"></div><div class="col-md-4"><input name="bank_code" class="form-control" placeholder="IFSC / routing code"></div></div></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Rating (0–5)</label><div class="form-group col-sm-10"><input name="rating" type="number" min="0" max="5" step="0.01" class="form-control"></div></div>
            <div class="row mb-3"><label class="col-sm-2 col-form-label">Working days</label><div class="form-group col-sm-10"><input name="planning_weekend_days" class="form-control" value="0,6" placeholder="0,6"><small class="text-muted">Weekend day numbers: 0 Sunday through 6 Saturday. Leave holiday list empty to use the company calendar.</small><input name="planning_holidays" class="form-control mt-2" placeholder="2026-12-25,2027-01-01"><small class="text-muted">Optional supplier holidays, YYYY-MM-DD comma-separated.</small></div></div>
 
 


        
<input type="submit" class="btn btn-info waves-effect waves-light" value="Add Supplier">
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
