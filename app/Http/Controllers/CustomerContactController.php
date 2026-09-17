<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class CustomerContactController extends Controller
{
    public function index()
    {
        $contacts = CustomerContact::with('customer')->latest()->paginate(40);
        $customers = Customer::where('status', 1)->orderBy('name')->get();
        return view('backend.customer.contacts', compact('contacts', 'customers'));
    }
    public function store(Request $request)
    {
        $data = $request->validate(['customer_id' => ['required', 'integer'], 'contact_type' => ['required', 'in:billing,shipping,contact'], 'label' => ['nullable', 'string', 'max:80'], 'contact_name' => ['nullable', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:150'], 'line1' => ['nullable', 'string', 'max:255'], 'line2' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', 'string', 'max:100'], 'postal_code' => ['nullable', 'string', 'max:30'], 'country' => ['nullable', 'string', 'size:2'], 'is_default' => ['nullable', 'boolean']]);
        $customer = Customer::findOrFail((int) $data['customer_id']);
        $isDefault = (bool) ($data['is_default'] ?? false);
        $contact = DB::transaction(function () use ($data, $customer, $isDefault): CustomerContact {
            if ($isDefault) {
                CustomerContact::where('customer_id', $customer->id)->where('contact_type', $data['contact_type'])->update(['is_default' => false]);
            }
            return CustomerContact::create($data + ['customer_id' => $customer->id, 'is_default' => $isDefault]);
        });
        app(AuditService::class)->record('customer_contact.created', $contact, null, $contact->toArray());
        return back()->with(['message' => 'Customer contact saved.', 'alert-type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $contact = CustomerContact::findOrFail($id);
        $customer = Customer::findOrFail((int) $contact->customer_id);
        $data = $request->validate([
            'contact_type' => ['required', 'in:billing,shipping,contact'], 'label' => ['nullable', 'string', 'max:80'],
            'contact_name' => ['nullable', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'], 'line1' => ['nullable', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'], 'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2'], 'is_default' => ['nullable', 'boolean'],
        ]);
        $isDefault = (bool) ($data['is_default'] ?? false);
        DB::transaction(function () use ($data, $contact, $customer, $isDefault): void {
            if ($isDefault) CustomerContact::where('customer_id', $customer->id)->where('contact_type', $data['contact_type'])->where('id', '<>', $contact->id)->update(['is_default' => false]);
            $contact->update($data);
        });
        app(AuditService::class)->record('customer_contact.updated', $contact, null, $contact->fresh()->toArray());
        return back()->with(['message' => 'Customer contact updated.', 'alert-type' => 'success']);
    }
}
