<?php

namespace App\Http\Controllers;

use App\Models\TaxFiling;
use App\Models\TaxFilingProviderSetting;
use App\Models\CompanyTaxRegistration;
use App\Services\AuditService;
use App\Http\Controllers\Api\FinanceIntegrationController;
use App\Services\TaxFilingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\Integrations\TaxFilingProviderRegistry;

class TaxFilingController extends Controller
{
    private function companyId(): int
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        abort_unless($companyId, 422, 'A company is required for tax filings.');
        return $companyId;
    }

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', 'in:draft,submitted,accepted,rejected'], 'jurisdiction' => ['nullable', 'string', 'max:100']]);
        $providers = TaxFilingProviderSetting::where('company_id', $this->companyId())->orderBy('provider')->get();
        $registrations = CompanyTaxRegistration::where('company_id', $this->companyId())->where('is_active', true)->orderByDesc('is_primary')->orderBy('jurisdiction')->get();
        $filings = TaxFiling::where('company_id', $this->companyId())
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['jurisdiction'] ?? null, fn ($query, $jurisdiction) => $query->where('jurisdiction', $jurisdiction))
            ->latest('period_from')->latest('id')->paginate(25);
        return view('admin.erp.tax_filings', compact('filings', 'providers', 'registrations'));
    }

    public function storeProvider(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(app(TaxFilingProviderRegistry::class)->keys())], 'endpoint' => ['nullable', 'url', 'max:2000'],
            'token' => ['nullable', 'string', 'max:2000'], 'timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'retries' => ['nullable', 'integer', 'min:0', 'max:5'], 'retry_sleep' => ['nullable', 'integer', 'min:0', 'max:60000'],
        ]);
        $setting = TaxFilingProviderSetting::updateOrCreate(
            ['company_id' => $this->companyId(), 'provider' => strtolower($data['provider'])],
            ['connection_config' => collect($data)->except('provider')->filter(fn ($value): bool => $value !== null && $value !== '')->all(), 'is_active' => true]
        );
        app(AuditService::class)->record('tax_filing_provider.updated_from_browser', $setting, null, ['provider' => $setting->provider, 'is_active' => true]);
        return back()->with(['message' => 'Tax filing provider configured.', 'alert-type' => 'success']);
    }

    public function deactivateProvider(int $id)
    {
        $setting = TaxFilingProviderSetting::where('company_id', $this->companyId())->findOrFail($id);
        $setting->update(['is_active' => false]);
        app(AuditService::class)->record('tax_filing_provider.deactivated_from_browser', $setting, ['is_active' => true], ['is_active' => false]);
        return back()->with(['message' => 'Tax filing provider deactivated.', 'alert-type' => 'success']);
    }

    public function storeRegistration(Request $request)
    {
        $companyId = $this->companyId();
        $data = $this->registrationData($request, $companyId);
        if (CompanyTaxRegistration::where('company_id', $companyId)->where('scheme', $data['scheme'])->where('registration_number', $data['registration_number'])->exists()) {
            return back()->withErrors(['registration_number' => 'This tax registration already exists.'])->withInput();
        }
        $registration = DB::transaction(function () use ($data, $companyId): CompanyTaxRegistration {
            if (!empty($data['is_primary'])) CompanyTaxRegistration::where('company_id', $companyId)->where('is_primary', true)->update(['is_primary' => false]);
            return CompanyTaxRegistration::create($data + ['company_id' => $companyId, 'is_active' => true]);
        });
        app(AuditService::class)->record('company_tax_registration.created_from_browser', $registration, null, $registration->toArray());
        return back()->with(['message' => 'Tax registration created.', 'alert-type' => 'success']);
    }

    public function updateRegistration(Request $request, int $id)
    {
        $companyId = $this->companyId();
        $registration = CompanyTaxRegistration::where('company_id', $companyId)->findOrFail($id);
        $data = $this->registrationData($request, $companyId, $registration->id, true);
        if (array_key_exists('scheme', $data) && array_key_exists('registration_number', $data) && CompanyTaxRegistration::where('company_id', $companyId)->where('scheme', $data['scheme'])->where('registration_number', $data['registration_number'])->whereKeyNot($registration->id)->exists()) {
            return back()->withErrors(['registration_number' => 'This tax registration already exists.'])->withInput();
        }
        $before = $registration->toArray();
        DB::transaction(function () use ($data, $companyId, $registration): void {
            if (($data['is_primary'] ?? false) === true) CompanyTaxRegistration::where('company_id', $companyId)->whereKeyNot($registration->id)->where('is_primary', true)->update(['is_primary' => false]);
            $registration->update($data);
        });
        app(AuditService::class)->record('company_tax_registration.updated_from_browser', $registration, $before, $registration->fresh()->toArray());
        return back()->with(['message' => 'Tax registration updated.', 'alert-type' => 'success']);
    }

    public function deactivateRegistration(int $id)
    {
        $registration = CompanyTaxRegistration::where('company_id', $this->companyId())->findOrFail($id);
        $before = $registration->toArray();
        $registration->update(['is_active' => false, 'is_primary' => false]);
        app(AuditService::class)->record('company_tax_registration.deactivated_from_browser', $registration, $before, $registration->fresh()->toArray());
        return back()->with(['message' => 'Tax registration deactivated.', 'alert-type' => 'success']);
    }

    private function registrationData(Request $request, int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'jurisdiction' => [$required, 'string', 'max:100'], 'scheme' => [$required, 'string', 'max:40'], 'registration_number' => [$required, 'string', 'max:100'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'effective_from' => ['sometimes', 'nullable', 'date'], 'effective_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('company_tax_registrations', 'external_reference')->ignore($ignoreId)->where(fn ($query) => $query->where('company_id', $companyId))],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId();
        $data = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'jurisdiction' => ['nullable', 'string', 'max:100'], 'external_reference' => ['nullable', 'string', 'max:180'],
            'tax_registration_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('company_tax_registrations', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))], 'return_type' => ['nullable', 'string', 'max:40'],
        ]);
        $reportRequest = Request::create('/api/accounting/tax-report', 'GET', [
            'from' => $data['from'], 'to' => $data['to'], 'jurisdiction' => $data['jurisdiction'] ?? null,
        ]);
        $reportRequest->setUserResolver(fn () => $request->user());
        $reportResponse = app(FinanceIntegrationController::class)->taxReport($reportRequest);
        $report = json_decode($reportResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $existing = TaxFiling::where('company_id', $companyId)->where('external_reference', $data['external_reference'] ?? ('tax-filing:'.$companyId.':'.$data['from'].':'.$data['to'].':'.($data['jurisdiction'] ?? 'all').':'.($data['tax_registration_id'] ?? 'none')))->exists();
        try { $filing = app(TaxFilingService::class)->createSnapshot($companyId, $data['from'], $data['to'], $data['jurisdiction'] ?? null, $report, $data['external_reference'] ?? null, $data['return_type'] ?? 'indirect_tax', isset($data['tax_registration_id']) ? (int) $data['tax_registration_id'] : null); }
        catch (\RuntimeException $exception) { return back()->withErrors(['tax_registration_id' => $exception->getMessage()])->withInput(); }
        app(AuditService::class)->record('tax_filing.created_from_browser', $filing, null, $filing->toArray());
        return back()->with(['message' => $existing ? 'Existing tax filing snapshot reused.' : 'Tax filing snapshot created.', 'alert-type' => 'success']);
    }

    public function verify(int $id)
    {
        $filing = TaxFiling::where('company_id', $this->companyId())->findOrFail($id);
        $verified = app(TaxFilingService::class)->verifySnapshot($filing);
        return back()->with(['message' => $verified ? 'Filing snapshot integrity verified.' : 'Filing snapshot integrity check failed.', 'alert-type' => $verified ? 'success' : 'error']);
    }

    public function export(int $id)
    {
        $filing = TaxFiling::where('company_id', $this->companyId())->findOrFail($id);
        abort_unless(app(TaxFilingService::class)->verifySnapshot($filing), 409, 'Tax filing snapshot integrity verification failed.');
        $snapshot = $filing->snapshot_payload ?? [];
        $rows = $snapshot['report']['data'] ?? [];
        $summary = $snapshot['report']['summary'] ?? [];
        return response()->streamDownload(function () use ($filing, $rows, $summary): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['filing_no', 'period_from', 'period_to', 'jurisdiction', 'rate', 'sales_tax', 'purchase_tax', 'net_tax']);
            foreach ($rows as $row) fputcsv($handle, [$filing->filing_no, $filing->period_from?->toDateString(), $filing->period_to?->toDateString(), $row['jurisdiction'] ?? $filing->jurisdiction, $row['rate'] ?? null, $row['sales']['tax'] ?? 0, $row['purchases']['tax'] ?? 0, ((float) ($row['sales']['tax'] ?? 0)) - ((float) ($row['purchases']['tax'] ?? 0))]);
            fputcsv($handle, ['SUMMARY', '', '', '', '', $summary['sales_tax'] ?? 0, $summary['purchase_tax'] ?? 0, $summary['net_tax'] ?? 0]);
            fclose($handle);
        }, $filing->filing_no.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function submit(Request $request, int $id)
    {
        $data = $request->validate(['filing_reference' => ['nullable', 'string', 'max:180'], 'provider' => ['nullable', Rule::in(app(TaxFilingProviderRegistry::class)->keys())]]);
        if (empty($data['filing_reference']) && empty($data['provider'])) return back()->withErrors(['submission' => 'A filing reference or provider is required.'])->withInput();
        $filing = TaxFiling::where('company_id', $this->companyId())->findOrFail($id);
        $before = $filing->toArray();
        try {
            $updated = app(TaxFilingService::class)->submit($filing, $data['filing_reference'] ?? null, $data['provider'] ?? null);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['submission' => $exception->getMessage()])->withInput();
        }
        app(AuditService::class)->record('tax_filing.submitted_from_browser', $updated, $before, $updated->toArray());
        return back()->with(['message' => 'Tax filing submitted.', 'alert-type' => 'success']);
    }

    public function decide(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', 'in:accepted,rejected'], 'rejection_reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:3000']]);
        $filing = TaxFiling::where('company_id', $this->companyId())->findOrFail($id);
        try {
            $updated = app(TaxFilingService::class)->decide($filing, $data['status'], $data['rejection_reason'] ?? null);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()])->withInput();
        }
        app(AuditService::class)->record('tax_filing.'.$data['status'].'_from_browser', $updated, ['status' => 'submitted'], $updated->toArray());
        return back()->with(['message' => 'Tax filing marked '.$data['status'].'.', 'alert-type' => 'success']);
    }
}
