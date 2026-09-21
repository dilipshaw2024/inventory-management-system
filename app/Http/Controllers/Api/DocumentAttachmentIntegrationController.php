<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentAttachment;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentAttachmentIntegrationController extends Controller
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

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['nullable', Rule::in(array_keys($this->types))],
            'attachable_id' => ['nullable', 'integer', 'min:1'],
            'attachment_type' => ['nullable', 'in:image,document'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for attachment synchronization.');
        $query = DocumentAttachment::query()
            ->where('company_id', $companyId)
            ->when($data['attachable_type'] ?? null, fn ($query, $type) => $query->where('attachable_type', $this->types[$type]))
            ->when($data['attachable_id'] ?? null, fn ($query, $id) => $query->where('attachable_id', $id))
            ->when($data['attachment_type'] ?? null, fn ($query, $type) => $query->where('attachment_type', $type))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        $query->select(['id', 'company_id', 'attachable_type', 'attachable_id', 'original_name', 'mime_type', 'size_bytes', 'attachment_type', 'title', 'version', 'is_primary', 'uploaded_by', 'created_at', 'updated_at']);
        return app(IntegrationCursorService::class)->paginate($query, $request, 'inventory.attachments', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys($this->types))],
            'attachable_id' => ['required', 'integer', 'min:1'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'attachment_type' => ['required', 'in:image,document'],
            'title' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx,doc,docx'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for attachment synchronization.');
        $record = $this->types[$data['attachable_type']]::withoutGlobalScopes()->whereKey($data['attachable_id'])
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        if (!empty($data['external_reference'])) {
            $existing = DocumentAttachment::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored', 'idempotent' => true]);
        }
        $file = $request->file('file');
        if ($data['attachment_type'] === 'image' && !str_starts_with((string) $file->getMimeType(), 'image/')) abort(422, 'Image attachments must be image files.');
        if (!empty($data['is_primary']) && $data['attachment_type'] !== 'image') abort(422, 'Only image attachments can be marked as primary media.');
        $morphType = $record->getMorphClass();
        $version = ((int) DocumentAttachment::where('attachable_type', $morphType)->where('attachable_id', $record->getKey())->where('attachment_type', $data['attachment_type'])->max('version')) + 1;
        $path = $file->store('erp-attachments', 'local');
        if (!empty($data['is_primary'])) DocumentAttachment::where('attachable_type', $morphType)->where('attachable_id', $record->getKey())->where('attachment_type', $data['attachment_type'])->update(['is_primary' => false]);
        $attachment = DocumentAttachment::create([
            'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
            'attachable_type' => $morphType, 'attachable_id' => $record->getKey(), 'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(),
            'attachment_type' => $data['attachment_type'], 'title' => $data['title'] ?? null, 'version' => $version,
            'is_primary' => (bool) ($data['is_primary'] ?? false), 'uploaded_by' => $request->user()?->id,
        ]);
        app(AuditService::class)->record('document_attachment.created', $attachment, null, $attachment->toArray() + ['source' => 'integration']);
        return response()->json(['data' => $attachment, 'status' => 'created'], 201);
    }

    public function download(Request $request, int $id)
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for attachment access.');
        $attachment = DocumentAttachment::where('company_id', $companyId)->findOrFail($id);
        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404);
        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_name, ['Content-Type' => $attachment->mime_type]);
    }
}
