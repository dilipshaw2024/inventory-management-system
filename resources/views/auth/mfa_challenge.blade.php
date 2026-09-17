@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid"><div class="row justify-content-center"><div class="col-md-5"><div class="card"><div class="card-body"><h4>Verify your identity</h4><p class="text-muted">Enter your authenticator code or one unused recovery code.</p><form method="POST" action="{{ route('mfa.challenge.verify') }}">@csrf<input name="code" class="form-control mb-3" inputmode="numeric" maxlength="20" autofocus required><button class="btn btn-primary w-100">Continue</button></form></div></div></div></div></div></div>
@endsection
