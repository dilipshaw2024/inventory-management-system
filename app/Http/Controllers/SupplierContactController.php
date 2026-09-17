<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierContactController extends Controller
{
    public function index()
    {
        $contacts = SupplierContact::with('supplier')->latest()->paginate(40);
        $suppliers = Supplier::where('status', 1)->orderBy('name')->get();
        return view('backend.supplier.contacts', compact('contacts', 'suppliers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->contactRules());
        $supplier = Supplier::findOrFail((int) $data['supplier_id']);
        $isDefault = (bool) ($data['is_default'] ?? false);
        $contact = DB::transaction(function () use ($data, $supplier, $isDefault): SupplierContact {
            if ($isDefault) SupplierContact::where('supplier_id', $supplier->id)->where('contact_type', $data['contact_type'])->update(['is_default' => false]);
            return SupplierContact::create($data + ['company_id' => $supplier->company_id, 'supplier_id' => $supplier->id, 'is_default' => $isDefault]);
        });
        app(AuditService::class)->record('supplier_contact.created', $contact, null, $contact->toArray());
        return back()->with(['message' => 'Supplier contact saved.', 'alert-type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $contact = SupplierContact::with('supplier')->findOrFail($id);
        $data = $request->validate($this->contactRules(true));
        unset($data['supplier_id']);
        $supplier = Supplier::findOrFail((int) $contact->supplier_id);
        $type = $data['contact_type'] ?? $contact->contact_type;
        $isDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : (bool) $contact->is_default;
        DB::transaction(function () use ($data, $contact, $supplier, $type, $isDefault): void {
            if ($isDefault) SupplierContact::where('supplier_id', $supplier->id)->where('contact_type', $type)->where('id', '<>', $contact->id)->update(['is_default' => false]);
            $contact->update($data);
            app(AuditService::class)->record('supplier_contact.updated', $contact, null, $contact->fresh()->toArray());
        });
        return back()->with(['message' => 'Supplier contact updated.', 'alert-type' => 'success']);
    }

    private function contactRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return [
            'supplier_id' => [$partial ? 'sometimes' : 'required', 'integer'], 'contact_type' => [$required, 'in:billing,shipping,contact'],
            'label' => ['sometimes', 'nullable', 'string', 'max:80'], 'contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'], 'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'line1' => ['sometimes', 'nullable', 'string', 'max:255'], 'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'], 'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:30'], 'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
