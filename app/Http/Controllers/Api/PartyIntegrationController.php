<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\CustomerContact;
use App\Models\SupplierContact;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartyIntegrationController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $customers = $this->companyScope(Customer::with(['contacts', 'salesPriceList']))
            ->when($data['q'] ?? null, fn ($query, $search) => $query->where(fn ($scope) => $scope->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('mobile_no', 'like', "%{$search}%")->orWhere('external_reference', $search)))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($customers, $request, 'inventory.customers', (int) ($data['per_page'] ?? 50));
    }

    public function customerContacts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'], 'contact_type' => ['nullable', 'in:billing,shipping,contact'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $contacts = CustomerContact::with('customer')
            ->when($data['customer_id'] ?? null, fn ($query, $id) => $query->where('customer_id', $id))
            ->when($data['contact_type'] ?? null, fn ($query, $type) => $query->where('contact_type', $type))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($contacts, $request, 'inventory.customer-contacts', (int) ($data['per_page'] ?? 50));
    }

    public function suppliers(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $suppliers = $this->companyScope(Supplier::with(['purchasePriceList', 'contacts']))
            ->when($data['q'] ?? null, fn ($query, $search) => $query->where(fn ($scope) => $scope->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('mobile_no', 'like', "%{$search}%")->orWhere('external_reference', $search)))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', (bool) $data['is_active']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($suppliers, $request, 'inventory.suppliers', (int) ($data['per_page'] ?? 50));
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $this->assertAccess($request, 'sales:write');
        $companyId = $request->user()?->company_id;
        $data = $this->validateCustomer($request, $companyId);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(Customer::query())->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        $customer = DB::transaction(function () use ($data, $companyId): Customer {
            $customer = Customer::create($data + ['company_id' => $companyId, 'created_by' => auth()->id()]);
            app(AuditService::class)->record('customer.created', $customer, null, $customer->toArray());
            return $customer;
        });
        return response()->json(['data' => $customer, 'status' => 'created'], 201);
    }

    public function updateCustomer(Request $request, int $id): JsonResponse
    {
        $this->assertAccess($request, 'sales:write');
        $customer = $this->companyScope(Customer::query())->findOrFail($id);
        $data = $this->validateCustomer($request, $request->user()?->company_id, $customer->id);
        $before = $customer->only(array_keys($data));
        $customer->update($data + ['updated_by' => auth()->id()]);
        app(AuditService::class)->record('customer.updated', $customer, $before, $customer->fresh()->only(array_keys($data)));
        return response()->json(['data' => $customer->fresh(), 'status' => 'updated']);
    }

    public function storeCustomerContact(Request $request, int $customerId): JsonResponse
    {
        $this->assertAccess($request, 'sales:write');
        $customer = $this->companyScope(Customer::query())->findOrFail($customerId);
        $data = $request->validate($this->contactRules());
        if (!empty($data['external_reference'])) {
            $existing = CustomerContact::where('customer_id', $customer->id)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('customer'), 'status' => 'duplicate_ignored']);
        }
        $contact = DB::transaction(function () use ($data, $customer): CustomerContact {
            if ((bool) ($data['is_default'] ?? false)) {
                CustomerContact::where('customer_id', $customer->id)->where('contact_type', $data['contact_type'])->update(['is_default' => false]);
            }
            $contact = CustomerContact::create($data + ['customer_id' => $customer->id]);
            app(AuditService::class)->record('customer_contact.created', $contact, null, $contact->toArray());
            return $contact;
        });
        return response()->json(['data' => $contact->load('customer'), 'status' => 'created'], 201);
    }

    public function updateCustomerContact(Request $request, int $id): JsonResponse
    {
        $this->assertAccess($request, 'sales:write');
        $contact = CustomerContact::with('customer')->findOrFail($id);
        $customer = $this->companyScope(Customer::query())->findOrFail($contact->customer_id);
        $data = $request->validate($this->contactRules(true));
        if (!empty($data['external_reference'])) {
            $conflict = CustomerContact::where('customer_id', $customer->id)->where('external_reference', $data['external_reference'])->where('id', '<>', $contact->id)->exists();
            if ($conflict) abort(422, 'The external reference is already assigned to another customer contact.');
        }
        $type = $data['contact_type'] ?? $contact->contact_type;
        $isDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : (bool) $contact->is_default;
        DB::transaction(function () use ($data, $contact, $customer, $type, $isDefault): void {
            if ($isDefault) {
                CustomerContact::where('customer_id', $customer->id)->where('contact_type', $type)->where('id', '<>', $contact->id)->update(['is_default' => false]);
            }
            $contact->update($data);
            app(AuditService::class)->record('customer_contact.updated', $contact, null, $contact->fresh()->toArray());
        });
        return response()->json(['data' => $contact->fresh()->load('customer'), 'status' => 'updated']);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $this->assertAccess($request, 'purchasing:write');
        $companyId = $request->user()?->company_id;
        $data = $this->validateSupplier($request, $companyId);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(Supplier::query())->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        $supplier = DB::transaction(function () use ($data, $companyId): Supplier {
            $supplier = Supplier::create($data + ['company_id' => $companyId, 'created_by' => auth()->id()]);
            app(AuditService::class)->record('supplier.created', $supplier, null, $supplier->toArray());
            return $supplier;
        });
        return response()->json(['data' => $supplier, 'status' => 'created'], 201);
    }

    public function updateSupplier(Request $request, int $id): JsonResponse
    {
        $this->assertAccess($request, 'purchasing:write');
        $supplier = $this->companyScope(Supplier::query())->findOrFail($id);
        $data = $this->validateSupplier($request, $request->user()?->company_id, $supplier->id);
        $before = $supplier->only(array_keys($data));
        $supplier->update($data + ['updated_by' => auth()->id()]);
        app(AuditService::class)->record('supplier.updated', $supplier, $before, $supplier->fresh()->only(array_keys($data)));
        return response()->json(['data' => $supplier->fresh(), 'status' => 'updated']);
    }

    public function supplierContacts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'], 'contact_type' => ['nullable', 'in:billing,shipping,contact'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $contacts = SupplierContact::with('supplier')
            ->when($data['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($data['contact_type'] ?? null, fn ($query, $type) => $query->where('contact_type', $type))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($contacts, $request, 'inventory.supplier-contacts', (int) ($data['per_page'] ?? 50));
    }

    public function storeSupplierContact(Request $request, int $supplierId): JsonResponse
    {
        $this->assertAccess($request, 'purchasing:write');
        $supplier = $this->companyScope(Supplier::query())->findOrFail($supplierId);
        $data = $request->validate($this->contactRules());
        if (!empty($data['external_reference']) && SupplierContact::where('supplier_id', $supplier->id)->where('external_reference', $data['external_reference'])->exists()) {
            $existing = SupplierContact::where('supplier_id', $supplier->id)->where('external_reference', $data['external_reference'])->first();
            return response()->json(['data' => $existing->load('supplier'), 'status' => 'duplicate_ignored']);
        }
        $contact = DB::transaction(function () use ($data, $supplier): SupplierContact {
            if ((bool) ($data['is_default'] ?? false)) SupplierContact::where('supplier_id', $supplier->id)->where('contact_type', $data['contact_type'])->update(['is_default' => false]);
            $contact = SupplierContact::create($data + ['company_id' => $supplier->company_id, 'supplier_id' => $supplier->id]);
            app(AuditService::class)->record('supplier_contact.created', $contact, null, $contact->toArray());
            return $contact;
        });
        return response()->json(['data' => $contact->load('supplier'), 'status' => 'created'], 201);
    }

    public function updateSupplierContact(Request $request, int $id): JsonResponse
    {
        $this->assertAccess($request, 'purchasing:write');
        $contact = SupplierContact::with('supplier')->findOrFail($id);
        $supplier = $this->companyScope(Supplier::query())->findOrFail($contact->supplier_id);
        $data = $request->validate($this->contactRules(true));
        if (!empty($data['external_reference']) && SupplierContact::where('supplier_id', $supplier->id)->where('external_reference', $data['external_reference'])->where('id', '<>', $contact->id)->exists()) abort(422, 'The external reference is already assigned to another supplier contact.');
        $type = $data['contact_type'] ?? $contact->contact_type; $isDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : (bool) $contact->is_default;
        DB::transaction(function () use ($data, $contact, $supplier, $type, $isDefault): void {
            if ($isDefault) SupplierContact::where('supplier_id', $supplier->id)->where('contact_type', $type)->where('id', '<>', $contact->id)->update(['is_default' => false]);
            $contact->update($data);
            app(AuditService::class)->record('supplier_contact.updated', $contact, null, $contact->fresh()->toArray());
        });
        return response()->json(['data' => $contact->fresh()->load('supplier'), 'status' => 'updated']);
    }

    private function validateCustomer(Request $request, ?int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate($this->partyRules('customers', $companyId, $ignoreId) + [
            'customer_group' => ['nullable', 'string', 'max:100'], 'sales_channel' => ['nullable', 'string', 'max:50'],
            'currency_code' => ['nullable', 'string', 'size:3'], 'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'credit_hold' => ['nullable', 'boolean'], 'credit_hold_after_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);
    }

    private function validateSupplier(Request $request, ?int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate($this->partyRules('suppliers', $companyId, $ignoreId) + [
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account' => ['nullable', 'string', 'max:100'], 'bank_code' => ['nullable', 'string', 'max:50'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
        ]);
    }

    private function contactRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return [
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:150'], 'contact_type' => [$required, 'in:billing,shipping,contact'], 'label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:150'], 'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'], 'line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'], 'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'], 'postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'], 'is_default' => ['sometimes', 'boolean'],
        ];
    }

    private function partyRules(string $table, ?int $companyId, ?int $ignoreId): array
    {
        return [
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique($table, 'external_reference')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => ['required', 'string', 'max:255'], 'mobile_no' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
            'tax_number' => ['nullable', 'string', 'max:100'], 'tax_jurisdiction' => ['nullable', 'string', 'max:100'], 'tax_exempt' => ['nullable', 'boolean'],
            'tax_exemption_number' => ['required_if:tax_exempt,1', 'nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'], 'status' => ['nullable', 'boolean'],
        ];
    }

    private function assertAccess(Request $request, string $ability): void
    {
        if (!$request->user()?->tokenCan($ability) && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot modify the selected trading partner.');
    }

    private function companyScope($query)
    {
        $companyId = auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
