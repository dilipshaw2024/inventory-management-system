@extends('admin.admin_master')
@section('admin')
<div class="page-content">
    <div class="container-fluid">
        <div class="page-title-box">
            <h4>Foreign Currency Revaluation</h4>
            <p class="text-muted">Open AR/AP balances revalued at effective rates as of the selected date.</p>
        </div>
        @if ($errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label>As of date</label>
                        <input type="date" name="as_of" value="{{ $asOf }}" class="form-control" required>
                    </div>
                    <div class="col-md-2"><button class="btn btn-primary">Calculate</button></div>
                </form>
                @if ($rows->isNotEmpty())
                    <form method="POST" action="{{ route('erp.accounting.fx-revaluation.post') }}" class="mt-3" onsubmit="return confirm('Post this revaluation journal for the selected date?')">
                        @csrf
                        <input type="hidden" name="as_of" value="{{ $asOf }}">
                        <button class="btn btn-warning">Post gain/loss journal</button>
                        <small class="text-muted ms-2">Idempotent per company and date; requires FX gain/loss mappings.</small>
                    </form>
                @endif
            </div>
        </div>
        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-bordered">
                    <thead><tr><th>Type</th><th>Document</th><th>Currency</th><th>Outstanding</th><th>Booked rate</th><th>Current rate</th><th>Booked base</th><th>Current base</th><th>Unrealized gain/(loss)</th></tr></thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row['type'] }}</td><td>{{ $row['document'] }}</td><td>{{ $row['currency'] }}</td>
                            <td>{{ number_format($row['outstanding'], 2) }}</td><td>{{ number_format($row['booked_rate'], 6) }}</td><td>{{ number_format($row['current_rate'], 6) }}</td>
                            <td>{{ number_format($row['booked_base'], 2) }}</td><td>{{ number_format($row['current_base'], 2) }}</td>
                            <td class="{{ $row['difference'] >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($row['difference'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center">No open foreign-currency balances with available rates.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
