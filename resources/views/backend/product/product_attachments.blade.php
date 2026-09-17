<div class="card mt-4"><div class="card-body">
    <h5>Product media and documents</h5>
    <form method="POST" action="{{ route('erp.attachments.store') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
        @csrf
        <input type="hidden" name="attachable_type" value="product">
        <input type="hidden" name="attachable_id" value="{{ $product->id }}">
        <div class="col-md-2"><label class="small">Type</label><select name="attachment_type" class="form-select" required><option value="image">Image</option><option value="document">Document</option></select></div>
        <div class="col-md-2"><label class="small">Title</label><input name="title" class="form-control" placeholder="Front image"></div>
        <div class="col-md-1"><label class="small">Version</label><input name="version" type="number" min="1" placeholder="Auto" class="form-control"></div>
        <div class="col-md-3"><label class="small">File</label><input name="file" type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.csv,.xls,.xlsx,.doc,.docx" required></div>
        <div class="col-md-2 form-check"><input name="is_primary" value="1" type="checkbox" class="form-check-input" id="product-primary-media"><label class="form-check-label" for="product-primary-media">Primary media</label></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Upload</button></div>
    </form>
    <div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Preview</th><th>Type</th><th>Title</th><th>File</th><th>Version</th><th>Primary</th><th></th></tr></thead><tbody>@forelse($attachments as $attachment)<tr><td>@if($attachment->attachment_type === 'image')<img src="{{ route('erp.attachments.preview', $attachment->id) }}" alt="{{ $attachment->title ?: $attachment->original_name }}" style="max-width:64px;max-height:64px;object-fit:contain">@else — @endif</td><td>{{ ucfirst($attachment->attachment_type) }}</td><td>{{ $attachment->title ?: '—' }}</td><td>{{ $attachment->original_name }}</td><td>{{ $attachment->version }}</td><td>{{ $attachment->is_primary ? 'Yes' : 'No' }}</td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('erp.attachments.download', $attachment->id) }}">Download</a></td></tr>@empty<tr><td colspan="7" class="text-muted">No product media or documents uploaded.</td></tr>@endforelse</tbody></table></div>
</div></div>
