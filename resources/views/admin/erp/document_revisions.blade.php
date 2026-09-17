@extends('admin.admin_master')
@section('admin')
<div class="page-content"><div class="container-fluid">
    <div class="page-title-box"><h4>Document Revisions</h4><p class="text-muted">Before-and-after snapshots generated from audited master and transaction changes.</p></div>
    <div class="card mb-3"><div class="card-body"><form class="row g-2"><div class="col-md-4"><input name="document_type" value="{{ request('document_type') }}" class="form-control" placeholder="Document type"></div><div class="col-md-3"><input name="document_id" value="{{ request('document_id') }}" class="form-control" placeholder="Document ID"></div><div class="col-md-2"><button class="btn btn-primary">Filter</button></div></form></div></div>
    <div class="card"><div class="card-body table-responsive"><table class="table table-bordered"><thead><tr><th>Changed</th><th>Document</th><th>Version</th><th>User</th><th>Snapshot</th><th>Diff</th></tr></thead><tbody>
    @forelse($revisions as $revision)<tr><td>{{ optional($revision->changed_at)->format('Y-m-d H:i:s') }}</td><td>{{ class_basename($revision->document_type) }} #{{ $revision->document_id }}</td><td>v{{ $revision->version }}</td><td>{{ data_get($revision, 'user.name', 'System') }}</td><td><details><summary>View</summary><small>Old: {{ json_encode($revision->old_values) }}<br>New: {{ json_encode($revision->new_values) }}</small></details></td><td><a class="btn btn-sm btn-outline-primary" target="_blank" href="{{ route('erp.security.audit.revisions.diff', $revision->id) }}">JSON diff</a></td></tr>@empty
    <tr><td colspan="6" class="text-center">No revisions found.</td></tr>@endforelse
    </tbody></table>{{ $revisions->links() }}</div></div>
</div></div>
@endsection
