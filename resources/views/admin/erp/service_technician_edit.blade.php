@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid"><div class="page-title-box"><h4>Edit technician profile</h4><p class="text-muted">{{ optional($technician->user)->name }} — {{ $technician->employee_code }}</p></div>
<div class="card"><div class="card-body"><form method="POST" action="{{ route('erp.service.technician.update', $technician->id) }}" class="row g-3">@csrf @method('PATCH')
    <div class="col-md-3"><label>Employee code</label><input name="employee_code" value="{{ old('employee_code', $technician->employee_code) }}" class="form-control" required></div>
    <div class="col-md-4"><label>Skills (comma separated)</label><input name="skills" value="{{ old('skills', implode(', ', $technician->skills ?? [])) }}" class="form-control"></div>
    <div class="col-md-8"><label>Skill certifications (one per line: skill | certificate no | expiry YYYY-MM-DD)</label><textarea name="skill_certifications" rows="2" class="form-control">{{ old('skill_certifications', collect($technician->skill_certifications ?? [])->map(fn ($certification) => ($certification['skill'] ?? '').' | '.($certification['certificate_no'] ?? '').' | '.($certification['expires_on'] ?? ''))->implode("\n")) }}</textarea></div>
    @php($calendar = is_array($technician->working_calendar) ? $technician->working_calendar : [])
    <div class="col-md-3"><label>Weekend days</label><select name="weekend_days[]" class="form-select" multiple>@foreach([0=>'Sunday',1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday'] as $day => $label)<option value="{{ $day }}" @selected(in_array($day, $calendar['weekend_days'] ?? [0, 6], true))>{{ $label }}</option>@endforeach</select></div>
    <div class="col-md-6"><label>Holidays (YYYY-MM-DD, comma separated)</label><input name="holidays" value="{{ old('holidays', implode(', ', $calendar['holidays'] ?? [])) }}" class="form-control"></div>
    <div class="col-md-2"><label>Shift start</label><input name="shift_start" type="time" value="{{ old('shift_start', $calendar['shift_start'] ?? '08:00') }}" class="form-control"></div>
    <div class="col-md-2"><label>Shift end</label><input name="shift_end" type="time" value="{{ old('shift_end', $calendar['shift_end'] ?? '17:00') }}" class="form-control"></div>
    <div class="col-md-2"><label>Hourly rate</label><input name="hourly_rate" type="number" min="0" step="0.000001" value="{{ old('hourly_rate', $technician->hourly_rate) }}" class="form-control"></div>
    <div class="col-md-3"><label>Phone</label><input name="phone" value="{{ old('phone', $technician->phone) }}" class="form-control"></div>
    <div class="col-md-3 form-check mt-4 ms-2"><input name="is_available" value="1" type="checkbox" class="form-check-input" @checked(old('is_available', $technician->is_available))><label class="form-check-label">Available for assignment</label></div>
    <div class="col-12"><button class="btn btn-primary">Save profile</button> <a href="{{ route('erp.service.dispatch') }}" class="btn btn-light">Back to dispatch</a></div>
</form></div></div></div></div>
@endsection
