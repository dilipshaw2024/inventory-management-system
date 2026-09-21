@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid"><div class="page-title-box"><h4>Stock Status Transfer</h4><p class="text-muted">Move stock to blocked, quarantine, damaged, scrap, or release it back to available stock with approval.</p></div><div class="card"><div class="card-body"><form method="POST" action="{{ route('inventory.status.store') }}">@csrf<div class="row g-3"><div class="col-md-3"><label>Transfer no</label><input name="transfer_no" class="form-control" value="STS-{{ now()->format('YmdHis') }}" required></div><div class="col-md-3"><label>Product</label><select name="product_id" class="form-select" required><option value="">Select product</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} ({{ $product->quantity }})</option>@endforeach</select></div><div class="col-md-3"><label>Location</label><select name="location_id" class="form-select"><option value="">Unassigned</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->code }}</option>@endforeach</select></div><div class="col-md-3"><label>Quantity</label><input name="quantity" type="number" min="0.000001" step="0.000001" class="form-control" required></div><div class="col-md-4"><label>From status</label><select name="from_status" class="form-select" required><option value="available">Available</option><option value="blocked">Blocked / Hold</option><option value="quarantine">Quarantine</option><option value="damaged">Damaged</option></select></div><div class="col-md-4"><label>To status</label><select name="to_status" class="form-select" required><option value="available">Available (release)</option><option value="blocked">Blocked / Hold</option><option value="quarantine">Quarantine</option><option value="damaged">Damaged</option><option value="scrap">Scrap</option></select></div><div class="col-md-4"><label>Reason</label><input name="reason" class="form-control" required></div><div class="col-12"><button class="btn btn-primary">Submit for approval</button><a href="{{ route('inventory.status') }}" class="btn btn-light ms-2">Cancel</a></div></div></form></div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[action*="inventory.status.store"]');
    const target = form?.querySelector('[name="to_status"]');
    const fields = form ? form.querySelectorAll('.recovery-fields') : [];
    const sync = () => fields.forEach((field) => { field.style.display = target?.value === 'scrap' ? '' : 'none'; });
    target?.addEventListener('change', sync); sync();
});
document.addEventListener('DOMContentLoaded', function () {
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[action*="inventory.status.store"]');
    const submit = form?.querySelector('button.btn-primary');
    if (!form || !submit) return;
    const recovery = document.createElement('div');
    recovery.className = 'row g-3 recovery-fields';
    recovery.innerHTML = '<div class="col-md-4"><label>Recovery product (optional)</label><select name="recovery_product_id" class="form-select"><option value="">None</option>@foreach($products as $recoveryProduct)<option value="{{ $recoveryProduct->id }}">{{ $recoveryProduct->name }}</option>@endforeach</select></div><div class="col-md-4"><label>Recovery quantity</label><input name="recovery_quantity" type="number" min="0.000001" step="0.000001" class="form-control"></div><div class="col-md-4"><label>Recovery unit value</label><input name="recovery_unit_cost" type="number" min="0" step="0.000001" class="form-control"></div>';
    submit.parentElement.before(recovery);
    const wrapper = document.createElement('div');
    wrapper.className = 'form-check mb-3';
    wrapper.innerHTML = '<input name="inspection_required" value="1" type="checkbox" class="form-check-input" id="status-inspection"><label class="form-check-label" for="status-inspection">Require quality inspection before approval</label>';
    submit.parentElement.before(wrapper);
});
</script>
<script>document.addEventListener('DOMContentLoaded',function(){const form=document.querySelector('form[action*="inventory.status.store"]');const submit=form?.querySelector('button[type="submit"],button.btn-primary');if(form&&submit){const wrapper=document.createElement('div');wrapper.className='form-check mb-3';wrapper.innerHTML='<input name="inspection_required" value="1" type="checkbox" class="form-check-input" id="status-inspection"><label class="form-check-label" for="status-inspection">Require quality inspection before approval</label>';submit.parentNode.parentNode.insertBefore(wrapper,submit.parentNode);}});</script>
@endsection
