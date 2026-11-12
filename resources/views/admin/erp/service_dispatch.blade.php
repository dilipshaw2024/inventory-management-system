@extends('admin.admin_master')
@section('admin')
<div class="page-content">
    <div class="container-fluid">
        <div class="page-title-box d-flex justify-content-between align-items-center">
            <div><h4>Maintenance dispatch board</h4><p class="text-muted mb-0">{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</p></div>
            <div class="d-flex gap-2"><form method="GET" class="d-flex gap-2"><input type="date" name="date" value="{{ $center->toDateString() }}" class="form-control"><button class="btn btn-outline-primary">View window</button></form><a href="{{ route('erp.service.create') }}" class="btn btn-primary">Create order</a></div>
        </div>
        <div class="card"><div class="card-body">
            <h5 class="mb-3">Seven-day calendar</h5>
            <div class="row g-2 mb-4">
                @foreach($calendarDays as $day)
                    <div class="col-xl col-md-6">
                        <div class="dispatch-calendar-day border rounded h-100 p-2 {{ $day['date']->isToday() ? 'border-primary bg-light' : '' }}" data-date="{{ $day['date']->toDateString() }}">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong>{{ $day['date']->format('D') }}</strong>
                                <span class="text-muted small">{{ $day['date']->format('d M') }}</span>
                            </div>
                            @forelse($day['orders'] as $calendarOrder)
                                <div class="dispatch-calendar-card border rounded bg-white p-2 mb-2 small {{ in_array($calendarOrder->status, ['completed', 'cancelled'], true) ? '' : 'dispatch-draggable' }}" @if(!in_array($calendarOrder->status, ['completed', 'cancelled'], true)) draggable="true" data-url="{{ route('erp.service.order.schedule', $calendarOrder->id) }}" data-start="{{ optional($calendarOrder->scheduled_start_at)->format('H:i') }}" data-end="{{ optional($calendarOrder->scheduled_end_at)->format('H:i') }}" @endif>
                                    <div class="fw-semibold">{{ optional($calendarOrder->scheduled_start_at)->format('H:i') }}–{{ optional($calendarOrder->scheduled_end_at)->format('H:i') }}</div>
                                    <div>{{ optional($calendarOrder->asset)->asset_no ?: 'No asset' }}</div>
                                    <div class="text-muted">{{ optional($calendarOrder->assignee)->name ?: 'Unassigned' }}</div>
                                    <span class="badge bg-{{ $calendarOrder->status === 'completed' ? 'success' : ($calendarOrder->status === 'cancelled' ? 'secondary' : 'warning text-dark') }}">{{ ucfirst(str_replace('_', ' ', $calendarOrder->status)) }}</span>
                                </div>
                            @empty
                                <div class="text-muted small">No scheduled work</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="text-muted small mb-0"><i class="bx bx-move"></i> Drag an open maintenance card to another day to reschedule it. Server-side technician, leave, overlap, and skill validation still applies.</p>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead><tr><th>Start</th><th>End</th><th>Technician</th><th>Asset</th><th>Skills</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td>{{ optional($order->scheduled_start_at)->format('D, d M Y H:i') }}</td>
                        <td>{{ optional($order->scheduled_end_at)->format('H:i') }}</td>
                        <td>{{ optional($order->assignee)->name ?: 'Unassigned' }}</td>
                        <td>{{ optional($order->asset)->asset_no }} — {{ optional($order->asset)->name }}</td>
                        <td>{{ implode(', ', $order->required_skills ?? []) ?: '—' }}</td>
                        <td><span class="badge bg-{{ $order->status === 'completed' ? 'success' : ($order->status === 'cancelled' ? 'secondary' : 'warning text-dark') }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span>@if(!in_array($order->status, ['completed', 'cancelled'], true))<form method="POST" action="{{ route('erp.service.order.schedule', $order->id) }}" class="mt-2"><input type="hidden" name="_token" value="{{ csrf_token() }}"><input type="datetime-local" name="scheduled_start_at" value="{{ optional($order->scheduled_start_at)->format('Y-m-d\\TH:i') }}" class="form-control form-control-sm mb-1" required><input type="datetime-local" name="scheduled_end_at" value="{{ optional($order->scheduled_end_at)->format('Y-m-d\\TH:i') }}" class="form-control form-control-sm mb-1" required><select name="assigned_to" class="form-select form-select-sm mb-1"><option value="">Unassigned</option>@foreach($technicians as $technician)<option value="{{ $technician->user_id }}" @selected($order->assigned_to === $technician->user_id)>{{ optional($technician->user)->name }}</option>@endforeach</select><input name="required_skills" value="{{ implode(', ', $order->required_skills ?? []) }}" class="form-control form-control-sm mb-1" placeholder="Required skills"><button class="btn btn-sm btn-outline-primary">Save schedule</button></form>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No time-windowed maintenance orders in this window.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div></div>
        <div class="card"><div class="card-body"><h5>Technician capacity profiles</h5><div class="row">
            @forelse($technicians as $technician)
                <div class="col-md-3 mb-2"><div class="border rounded p-2"><strong>{{ optional($technician->user)->name }}</strong><small class="d-block text-muted">{{ $technician->employee_code }}</small>@php($calendar = is_array($technician->working_calendar) ? $technician->working_calendar : [])<small class="d-block">Shift: {{ $calendar['shift_start'] ?? '08:00' }}–{{ $calendar['shift_end'] ?? '17:00' }}</small><small class="d-block">Skills: {{ implode(', ', $technician->skills ?? []) ?: '—' }}</small><a href="{{ route('erp.service.technician.edit', $technician->id) }}" class="btn btn-sm btn-outline-secondary mt-1">Edit profile</a></div></div>
            @empty
                <div class="col-12 text-muted">No technicians configured.</div>
            @endforelse
        </div></div></div>
    </div>
</div>
<script>
document.querySelectorAll('.dispatch-draggable').forEach(function (card) {
    card.addEventListener('dragstart', function (event) {
        event.dataTransfer.setData('text/plain', card.dataset.url);
        card.classList.add('opacity-50');
    });
    card.addEventListener('dragend', function () { card.classList.remove('opacity-50'); });
});
document.querySelectorAll('.dispatch-calendar-day').forEach(function (day) {
    day.addEventListener('dragover', function (event) { event.preventDefault(); day.classList.add('border-primary'); });
    day.addEventListener('dragleave', function () { day.classList.remove('border-primary'); });
    day.addEventListener('drop', function (event) {
        event.preventDefault();
        day.classList.remove('border-primary');
        var url = event.dataTransfer.getData('text/plain');
        var card = document.querySelector('[data-url="' + CSS.escape(url) + '"]');
        if (!url || !card) return;
        var date = day.dataset.date;
        var form = new FormData();
        form.append('_token', '{{ csrf_token() }}');
        form.append('scheduled_start_at', date + 'T' + card.dataset.start);
        form.append('scheduled_end_at', date + 'T' + card.dataset.end);
        fetch(url, { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (response) { if (!response.ok) throw new Error('Schedule update failed'); return response.json().catch(function () { return {}; }); })
            .then(function () { window.location.reload(); })
            .catch(function (error) { window.alert(error.message + '. Review the detailed schedule form for the validation reason.'); });
    });
});
</script>
@endsection
