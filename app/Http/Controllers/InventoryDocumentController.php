<?php

namespace App\Http\Controllers;

use App\Models\InventoryDocument;
use App\Models\InventoryDocumentLine;
use App\Models\InventoryLocation;
use App\Models\InventoryBatch;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Models\Branch;
use App\Services\AuditService;
use App\Services\ApprovalGuard;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryLedgerService;
use App\Services\SerialLifecycleService;
use App\Services\InventoryDocumentApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryDocumentController extends Controller
{
    public function index()
    {
        $documents = InventoryDocument::with(['location', 'creator'])->latest()->paginate(30);
        return view('backend.stock.inventory_documents', compact('documents'));
    }

    public function create()
    {
        $products = Product::where('status', 1)->orderBy('name')->get(['id', 'name', 'sku', 'quantity', 'purchase_price']);
        $locations = InventoryLocation::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $departments = \App\Models\Department::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
        $costCenters = \App\Models\CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        return view('backend.stock.inventory_document_add', compact('products', 'locations', 'departments', 'costCenters'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $productScope = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($companyId);
        $data = $request->validate([
            'document_no' => ['nullable', 'string', 'max:100'],
            'document_type' => ['required', 'in:receipt,issue'],
            'location_id' => ['nullable', 'integer', $locationScope],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'inspection_required' => ['nullable', 'boolean'],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['nullable', 'integer', $productScope],
            'quantity' => ['required', 'array', 'min:1'],
            'quantity.*' => ['nullable', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'array'],
            'unit_cost.*' => ['nullable', 'numeric', 'min:0'],
            'batch_no' => ['nullable', 'array'], 'batch_no.*' => ['nullable', 'string', 'max:100'],
            'serial_numbers' => ['nullable', 'array'], 'serial_numbers.*' => ['nullable', 'string', 'max:5000'],
            'manufacturing_date' => ['nullable', 'array'], 'manufacturing_date.*' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'array'], 'expiry_date.*' => ['nullable', 'date'],
            'best_before_date' => ['nullable', 'array'], 'best_before_date.*' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'array'], 'warranty_until.*' => ['nullable', 'date'],
            'line_department_id' => ['nullable', 'array'], 'line_department_id.*' => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'line_cost_center_id' => ['nullable', 'array'], 'line_cost_center_id.*' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
        ]);
        $lineData = [];
        foreach ($data['product_id'] as $index => $productId) {
            if (!$productId) continue;
            if (!isset($data['quantity'][$index]) || (float) $data['quantity'][$index] <= 0) {
                throw ValidationException::withMessages(['quantity.'.$index => 'Quantity is required for every selected product.']);
            }
            $lineData[] = ['product_id' => (int) $productId, 'quantity' => $data['quantity'][$index], 'department_id' => $data['line_department_id'][$index] ?? null, 'cost_center_id' => $data['line_cost_center_id'][$index] ?? null, 'unit_cost' => $data['unit_cost'][$index] ?? null, 'batch_no' => $data['batch_no'][$index] ?? null, 'serial_numbers' => $data['serial_numbers'][$index] ?? null, 'manufacturing_date' => $data['manufacturing_date'][$index] ?? null, 'expiry_date' => $data['expiry_date'][$index] ?? null, 'best_before_date' => $data['best_before_date'][$index] ?? null, 'warranty_until' => $data['warranty_until'][$index] ?? null];
        }
        if (!$lineData) throw ValidationException::withMessages(['product_id.0' => 'Select at least one product.']);
        $document = DB::transaction(function () use ($data, $lineData): InventoryDocument {
            $document = InventoryDocument::create([
                'document_no' => $data['document_no'] ?: strtoupper($data['document_type']).'-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'document_type' => $data['document_type'], 'location_id' => $data['location_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'date' => $data['date'], 'description' => $data['description'] ?? null, 'inspection_status' => ($data['document_type'] === 'receipt' && !empty($data['inspection_required'])) ? 'pending' : 'not_required',
                'created_by' => auth()->id(), 'status' => 'pending',
            ]);
            foreach ($lineData as $line) {
                InventoryDocumentLine::create(['inventory_document_id' => $document->id] + $line);
            }
            app(AuditService::class)->record('inventory_document.created', $document, null, $document->toArray());
            return $document;
        });
        return redirect()->route('inventory.documents.index')->with(['message' => 'Inventory document submitted for approval.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(InventoryDocument::class, $id);
        try {
            $document = InventoryDocument::findOrFail($id);
            app(InventoryDocumentApprovalService::class)->approve($document, auth()->user());
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
        return back()->with(['message' => 'Inventory document approved and posted.', 'alert-type' => 'success']);
    }

    public function inspect(Request $request, int $id)
    {
        $data = $request->validate(['inspection_status' => ['required', 'in:passed,failed'], 'inspection_notes' => ['required', 'string', 'max:3000']]);
        try {
            $document = InventoryDocument::findOrFail($id);
            app(InventoryDocumentApprovalService::class)->inspect($document, $data['inspection_status'], $data['inspection_notes'], auth()->user());
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => $data['inspection_status'] === 'passed' ? 'Stock receipt inspection passed.' : 'Stock receipt inspection failed; stock remains unposted.', 'alert-type' => $data['inspection_status'] === 'passed' ? 'success' : 'warning']);
    }

    public function reject(int $id)
    {
        try {
            $document = InventoryDocument::findOrFail($id);
            app(InventoryDocumentApprovalService::class)->reject($document, 'Rejected by an authorized approver.', auth()->user());
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Inventory document rejected.', 'alert-type' => 'info']);
    }
}
