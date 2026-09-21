<?php

namespace App\Http\Controllers;

use App\Models\DocumentAttachment;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentAttachmentController extends Controller
{
    private array $types = [
        'product' => \App\Models\Product::class,
        'brand' => \App\Models\Brand::class,
        'purchase_requisition' => \App\Models\PurchaseRequisition::class,
        'purchase_rfq' => \App\Models\PurchaseRfq::class,
        'purchase_order' => \App\Models\PurchaseOrder::class,
        'goods_receipt' => \App\Models\GoodsReceipt::class,
        'purchase_invoice' => \App\Models\PurchaseInvoice::class,
        'supplier_claim' => \App\Models\SupplierClaim::class,
        'supplier_credit_note' => \App\Models\SupplierCreditNote::class,
        'sales_quotation' => \App\Models\SalesQuotation::class,
        'sales_order' => \App\Models\SalesOrder::class,
        'delivery' => \App\Models\Delivery::class,
        'invoice' => \App\Models\Invoice::class,
        'inventory_return' => \App\Models\InventoryReturn::class,
        'inventory_adjustment' => \App\Models\InventoryAdjustment::class,
        'inventory_transfer' => \App\Models\InventoryTransfer::class,
        'inventory_document' => \App\Models\InventoryDocument::class,
        'stock_count' => \App\Models\StockCount::class,
        'landed_cost' => \App\Models\LandedCost::class,
        'production_order' => \App\Models\ProductionOrder::class,
        'service_asset' => \App\Models\ServiceAsset::class,
        'service_request' => \App\Models\ServiceRequest::class,
        'maintenance_order' => \App\Models\MaintenanceOrder::class,
    ];

    public function create()
    {
        return view('admin.erp.attachment_add', ['attachableTypes' => array_keys($this->types)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['attachable_type' => ['required', \Illuminate\Validation\Rule::in(array_keys($this->types))], 'attachable_id' => ['required', 'integer'], 'attachment_type' => ['required', 'in:image,document'], 'title' => ['nullable', 'string', 'max:255'], 'version' => ['nullable', 'integer', 'min:1'], 'is_primary' => ['nullable', 'boolean'], 'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx,doc,docx']]);
        $this->assertTypePermission($data['attachable_type']);
        $model = $this->types[$data['attachable_type']]::findOrFail($data['attachable_id']);
        $this->assertAttachableOwnership($model);
        $file = $request->file('file');
        if ($data['attachment_type'] === 'image' && !str_starts_with((string) $file->getMimeType(), 'image/')) return back()->withErrors(['file' => 'Image attachments must be image files.'])->withInput();
        if (!empty($data['is_primary']) && $data['attachment_type'] !== 'image') return back()->withErrors(['is_primary' => 'Only image attachments can be marked as primary media.'])->withInput();
        $version = $data['version'] ?? 1;
        if (!$request->filled('version')) {
            // Keep document revisions monotonic for every supported attachable,
            // not only product media. This makes replacement files auditable
            // across purchasing, sales, returns, and service records too.
            $version = ((int) DocumentAttachment::where('attachable_type', $model->getMorphClass())->where('attachable_id', $model->getKey())->where('attachment_type', $data['attachment_type'])->max('version')) + 1;
        }
        $path = $file->store('erp-attachments', 'local');
        if (!empty($data['is_primary'])) DocumentAttachment::where('attachable_type', $model->getMorphClass())->where('attachable_id', $model->getKey())->where('attachment_type', $data['attachment_type'])->update(['is_primary' => false]);
        $attachment = DocumentAttachment::create(['company_id' => $model->company_id ?: auth()->user()?->company_id, 'attachable_type' => $model->getMorphClass(), 'attachable_id' => $model->getKey(), 'original_name' => $file->getClientOriginalName(), 'stored_path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(), 'attachment_type' => $data['attachment_type'], 'title' => $data['title'] ?? null, 'version' => $version, 'is_primary' => (bool) ($data['is_primary'] ?? false), 'uploaded_by' => auth()->id()]);
        app(AuditService::class)->record('document_attachment.created', $attachment, null, $attachment->toArray());
        return back()->with(['message' => 'Document attached securely.', 'alert-type' => 'success']);
    }

    public function download(int $id)
    {
        $attachment = DocumentAttachment::findOrFail($id);
        $this->assertAttachmentOwnership($attachment);
        $type = array_search($attachment->attachable_type, $this->types, true);
        abort_unless($type !== false, 404);
        $this->assertTypePermission($type);
        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404);
        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_name, ['Content-Type' => $attachment->mime_type]);
    }

    public function preview(int $id)
    {
        $attachment = DocumentAttachment::findOrFail($id);
        $this->assertAttachmentOwnership($attachment);
        abort_unless($attachment->attachment_type === 'image', 404);
        $type = array_search($attachment->attachable_type, $this->types, true);
        abort_unless($type !== false, 404);
        $this->assertTypePermission($type);
        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404);
        return response()->file(Storage::disk('local')->path($attachment->stored_path), ['Content-Type' => $attachment->mime_type]);
    }

    private function assertTypePermission(string $type): void
    {
        $permission = match ($type) {
            'purchase_requisition', 'purchase_rfq', 'purchase_order', 'goods_receipt', 'purchase_invoice' => 'purchasing.manage',
            'supplier_claim', 'supplier_credit_note', 'landed_cost' => 'accounting.manage',
            'sales_quotation', 'sales_order', 'delivery', 'invoice' => 'sales.manage',
            'production_order' => 'manufacturing.manage',
            'service_asset', 'service_request', 'maintenance_order' => 'service.manage',
            default => 'inventory.view',
        };
        abort_unless(auth()->user()?->hasPermission($permission), 403, 'You are not authorized to access this attachment type.');
    }

    private function assertAttachableOwnership(object $model): void
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for attachment access.');
        if (!empty($model->company_id) && (int) $model->company_id !== (int) $companyId) abort(404);
    }

    private function assertAttachmentOwnership(DocumentAttachment $attachment): void
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for attachment access.');
        if (!empty($attachment->company_id) && (int) $attachment->company_id !== (int) $companyId) abort(404);
        $attachable = $attachment->attachable;
        if (!$attachable || (!empty($attachable->company_id) && (int) $attachable->company_id !== (int) $companyId)) abort(404);
    }
}
