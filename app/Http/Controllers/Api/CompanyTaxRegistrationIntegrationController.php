<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyTaxRegistration;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompanyTaxRegistrationIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax registrations.');
        $data = $request->validate([
            'jurisdiction' => ['nullable', 'string', 'max:100'],
            'scheme' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = CompanyTaxRegistration::where('company_id', $companyId)
            ->when($data['jurisdiction'] ?? null, fn ($scope, $value) => $scope->where('jurisdiction', $value))
            ->when($data['scheme'] ?? null, fn ($scope, $value) => $scope->where('scheme', $value))
            ->when(array_key_exists('is_active', $data), fn ($scope) => $scope->where('is_active', filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->when($data['updated_since'] ?? null, fn ($scope, $value) => $scope->where('updated_at', '>=', $value))
            ->orderBy('updated_at')->orderBy('id');
        return response()->json($query->paginate((int) ($data['per_page'] ?? 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax registrations.');
        $externalReference = $request->input('external_reference');
        if (is_string($externalReference) && trim($externalReference) !== '') {
            $existing = CompanyTaxRegistration::where('company_id', $companyId)->where('external_reference', trim($externalReference))->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'existing']);
        }
        $data = $this->validated($request, (int) $companyId);
        $registration = DB::transaction(function () use ($data, $companyId, $request): CompanyTaxRegistration {
            if (($data['is_primary'] ?? false) === true) {
                CompanyTaxRegistration::where('company_id', $companyId)->where('is_primary', true)->update(['is_primary' => false]);
            }
            return CompanyTaxRegistration::create($data + ['company_id' => $companyId, 'is_active' => $data['is_active'] ?? true]);
        });
        app(AuditService::class)->record('company_tax_registration.created', $registration, null, $registration->toArray());
        return response()->json(['data' => $registration, 'status' => 'created'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax registrations.');
        $registration = CompanyTaxRegistration::where('company_id', $companyId)->findOrFail($id);
        $data = $this->validated($request, (int) $companyId, $registration->id, true);
        $before = $registration->toArray();
        DB::transaction(function () use ($data, $companyId, $registration): void {
            if (array_key_exists('is_primary', $data) && $data['is_primary'] === true) {
                CompanyTaxRegistration::where('company_id', $companyId)->whereKeyNot($registration->id)->where('is_primary', true)->update(['is_primary' => false]);
            }
            $registration->update($data);
        });
        app(AuditService::class)->record('company_tax_registration.updated', $registration, $before, $registration->fresh()->toArray());
        return response()->json(['data' => $registration->fresh(), 'status' => 'updated']);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for tax registrations.');
        $registration = CompanyTaxRegistration::where('company_id', $companyId)->findOrFail($id);
        $before = $registration->toArray();
        $registration->update(['is_active' => false, 'is_primary' => false]);
        app(AuditService::class)->record('company_tax_registration.deactivated', $registration, $before, $registration->fresh()->toArray());
        return response()->json(['data' => $registration->fresh(), 'status' => 'deactivated']);
    }

    private function validated(Request $request, int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'jurisdiction' => [$required, 'string', 'max:100'],
            'scheme' => [$required, 'string', 'max:40'],
            'registration_number' => [$required, 'string', 'max:100'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('company_tax_registrations', 'external_reference')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId))],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $scheme = $data['scheme'] ?? null;
        $number = $data['registration_number'] ?? null;
        if ($scheme !== null && $number !== null && CompanyTaxRegistration::where('company_id', $companyId)->where('scheme', $scheme)->where('registration_number', $number)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            abort(422, 'A tax registration with this scheme and number already exists.');
        }
        return $data;
    }
}
