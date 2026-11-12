<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FiscalYear;
use App\Models\NumberingSequence;
use App\Models\TaxRate;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FinanceConfigurationController extends Controller
{
    public function index()
    {
        $companyId = auth()->user()?->company_id; $currencies = Currency::orderBy('code')->get(); $rates = ExchangeRate::with(['fromCurrency', 'toCurrency'])->latest('effective_date')->limit(50)->get(); $taxRates = TaxRate::with('components')->orderBy('name')->get(); $companies = Company::where('is_active', true)->when($companyId, fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get(); $fiscalYears = FiscalYear::with('company')->latest('starts_on')->get(); $sequences = NumberingSequence::with(['company', 'branch'])->orderBy('document_type')->get(); $branches = Branch::where('is_active', true)->when($companyId, fn ($query, $id) => $query->where('company_id', $id))->orderBy('name')->get();
        return view('admin.erp.finance_configuration', compact('currencies', 'rates', 'taxRates', 'companies', 'fiscalYears', 'sequences', 'branches'));
    }

    public function currency(Request $request) { $data = $request->validate(['code' => ['required', 'string', 'size:3', 'unique:currencies,code'], 'name' => ['required', 'string', 'max:100'], 'symbol' => ['nullable', 'string', 'max:8'], 'decimal_places' => ['required', 'integer', 'min:0', 'max:8'], 'is_base' => ['nullable', 'boolean']]); $currency = Currency::create(array_merge($data, ['code' => strtoupper($data['code']), 'is_active' => true, 'is_base' => (bool) ($data['is_base'] ?? false)])); app(AuditService::class)->record('currency.created', $currency, null, $currency->toArray()); return back()->with(['message' => 'Currency created.', 'alert-type' => 'success']); }
    public function rate(Request $request) { $data = $request->validate(['from_currency_id' => ['required', 'exists:currencies,id'], 'to_currency_id' => ['required', 'exists:currencies,id', 'different:from_currency_id'], 'rate' => ['required', 'numeric', 'gt:0'], 'effective_date' => ['required', 'date'], 'source_type' => ['nullable', 'in:manual,provider,import'], 'source_reference' => ['nullable', 'string', 'max:150']]); $data['source_type'] = $data['source_type'] ?? 'manual'; if ($data['source_type'] !== 'manual' && empty($data['source_reference'])) return back()->withErrors(['source_reference' => 'A provider or import rate requires a source reference.'])->withInput(); $rate = ExchangeRate::create($data + ['retrieved_at' => now(), 'is_active' => true]); app(AuditService::class)->record('exchange_rate.created', $rate, null, $rate->toArray()); return back()->with(['message' => 'Exchange rate created.', 'alert-type' => 'success']); }
    public function tax(Request $request) {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('tax_rates', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->where('jurisdiction', $request->input('jurisdiction')))], 'rate' => ['required', 'numeric', 'min:0', 'max:100'], 'calculation' => ['required', 'in:exclusive,inclusive'], 'jurisdiction' => ['nullable', 'string', 'max:100'], 'components_json' => ['nullable', 'string', 'max:20000']]);
        $components = null;
        if (trim((string) ($data['components_json'] ?? '')) !== '') {
            try { $components = json_decode($data['components_json'], true, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return back()->withErrors(['components_json' => 'Tax components must be valid JSON.'])->withInput(); }
            if (!is_array($components) || array_is_list($components) === false) return back()->withErrors(['components_json' => 'Tax components must be a JSON array.'])->withInput();
            $validator = Validator::make($components, ['*.code' => ['required', 'string', 'max:40', 'alpha_dash'], '*.name' => ['required', 'string', 'max:120'], '*.rate' => ['required', 'numeric', 'min:0', 'max:100'], '*.component_type' => ['nullable', 'string', 'max:40'], '*.sequence' => ['nullable', 'integer', 'min:1', 'max:65535'], '*.is_compound' => ['nullable', 'boolean']]);
            if ($validator->fails()) return back()->withErrors(['components_json' => $validator->errors()->first()])->withInput();
            if (round((float) collect($components)->sum(fn (array $component): float => (float) $component['rate']), 4) !== round((float) $data['rate'], 4)) return back()->withErrors(['components_json' => 'Tax component rates must equal the aggregate tax rate.'])->withInput();
        }
        unset($data['components_json']);
        $tax = DB::transaction(function () use ($data, $components, $companyId): TaxRate {
            $tax = TaxRate::create($data + ['company_id' => $companyId, 'is_active' => true]);
            if ($components !== null) foreach ($components as $index => $component) $tax->components()->create(['company_id' => $companyId, 'code' => $component['code'], 'name' => $component['name'], 'rate' => $component['rate'], 'component_type' => $component['component_type'] ?? 'other', 'sequence' => $component['sequence'] ?? ($index + 1), 'is_compound' => (bool) ($component['is_compound'] ?? false), 'is_active' => true]);
            app(AuditService::class)->record('tax_rate.created', $tax, null, $tax->fresh('components')->toArray());
            return $tax;
        });
        return back()->with(['message' => 'Tax rate created.', 'alert-type' => 'success']);
    }
    public function updateTax(Request $request, int $id) {
        $companyId = auth()->user()?->company_id;
        $tax = TaxRate::where('company_id', $companyId)->findOrFail($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('tax_rates', 'code')->ignore($tax->id)->where(fn ($query) => $query->where('company_id', $companyId)->where('jurisdiction', $request->input('jurisdiction')))], 'rate' => ['required', 'numeric', 'min:0', 'max:100'], 'calculation' => ['required', 'in:exclusive,inclusive'], 'jurisdiction' => ['nullable', 'string', 'max:100'], 'components_json' => ['nullable', 'string', 'max:20000']]);
        try { $components = $this->decodeTaxComponents($data['components_json'] ?? null, (float) $data['rate']); }
        catch (\InvalidArgumentException $exception) { return back()->withErrors(['components_json' => $exception->getMessage()])->withInput(); }
        unset($data['components_json']);
        $before = $tax->fresh('components')->toArray();
        DB::transaction(function () use ($tax, $data, $components, $companyId, $before): void {
            $tax->update($data);
            $tax->components()->delete();
            if ($components !== null) foreach ($components as $index => $component) $tax->components()->create(['company_id' => $companyId, 'code' => $component['code'], 'name' => $component['name'], 'rate' => $component['rate'], 'component_type' => $component['component_type'] ?? 'other', 'sequence' => $component['sequence'] ?? ($index + 1), 'is_compound' => (bool) ($component['is_compound'] ?? false), 'is_active' => true]);
            app(AuditService::class)->record('tax_rate.updated', $tax, $before, $tax->fresh('components')->toArray());
        });
        return back()->with(['message' => 'Tax rate updated.', 'alert-type' => 'success']);
    }

    private function decodeTaxComponents(?string $json, float $rate): ?array
    {
        if (trim((string) $json) === '') return null;
        try { $components = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('Tax components must be valid JSON.'); }
        if (!is_array($components) || array_is_list($components) === false) throw new \InvalidArgumentException('Tax components must be a JSON array.');
        $validator = Validator::make($components, ['*.code' => ['required', 'string', 'max:40', 'alpha_dash'], '*.name' => ['required', 'string', 'max:120'], '*.rate' => ['required', 'numeric', 'min:0', 'max:100'], '*.component_type' => ['nullable', 'string', 'max:40'], '*.sequence' => ['nullable', 'integer', 'min:1', 'max:65535'], '*.is_compound' => ['nullable', 'boolean']]);
        if ($validator->fails()) throw new \InvalidArgumentException($validator->errors()->first());
        if (round((float) collect($components)->sum(fn (array $component): float => (float) $component['rate']), 4) !== round($rate, 4)) throw new \InvalidArgumentException('Tax component rates must equal the aggregate tax rate.');
        return $components;
    }
    public function fiscalYear(Request $request) { $data = $request->validate(['company_id' => ['required', 'exists:companies,id'], 'name' => ['required', 'string', 'max:100'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on']]); $companyId = auth()->user()?->company_id ?: (int) $data['company_id']; if (auth()->user()?->company_id && (int) $data['company_id'] !== (int) $companyId) abort(403); if (FiscalYear::where('company_id', $companyId)->where(function ($query) use ($data): void { $query->whereBetween('starts_on', [$data['starts_on'], $data['ends_on']])->orWhereBetween('ends_on', [$data['starts_on'], $data['ends_on']])->orWhere(function ($nested) use ($data): void { $nested->where('starts_on', '<=', $data['starts_on'])->where('ends_on', '>=', $data['ends_on']); }); })->exists()) return back()->withErrors(['starts_on' => 'The fiscal-year period overlaps an existing period.'])->withInput(); $year = FiscalYear::create(array_merge($data, ['company_id' => $companyId, 'status' => 'open'])); app(AuditService::class)->record('fiscal_year.created', $year, null, $year->toArray()); return back()->with(['message' => 'Fiscal year created.', 'alert-type' => 'success']); }
    public function closeFiscalYear(int $id) { $year = FiscalYear::findOrFail($id); if ($year->status !== 'open') return back()->withErrors(['fiscal_year' => 'Fiscal year is already closed.']); $pending = collect([
            'purchase orders awaiting approval' => [\App\Models\PurchaseOrder::class, 'submitted'],
            'sales orders awaiting approval' => [\App\Models\SalesOrder::class, 'submitted'],
            'goods receipts awaiting approval' => [\App\Models\GoodsReceipt::class, 'pending'],
            'deliveries awaiting approval' => [\App\Models\Delivery::class, 'pending'],
            'inventory adjustments awaiting approval' => [\App\Models\InventoryAdjustment::class, 'pending'],
            'inventory transfers awaiting approval' => [\App\Models\InventoryTransfer::class, 'pending'],
            'inventory returns awaiting approval' => [\App\Models\InventoryReturn::class, 'pending'],
            'stock counts awaiting approval' => [\App\Models\StockCount::class, 'submitted'],
            'status transfers awaiting approval' => [\App\Models\InventoryStatusTransfer::class, 'pending'],
            'purchase invoices awaiting approval' => [\App\Models\PurchaseInvoice::class, 'pending'],
            'supplier payments awaiting approval' => [\App\Models\SupplierPayment::class, 'pending'],
            'customer refunds awaiting approval' => [\App\Models\CustomerRefund::class, 'pending'],
            'manual journals awaiting approval' => [\App\Models\JournalEntry::class, 'draft'],
        ])->mapWithKeys(fn (array $check, string $label): array => [$label => $check[0]::where('status', $check[1])->where(fn ($scope) => $scope->where('company_id', $year->company_id)->orWhereNull('company_id'))->count()])->filter(fn (int $count): bool => $count > 0);
        try { app(\App\Services\FiscalPeriodService::class)->assertBankReconciliationsClosed((int) $year->company_id, $year->ends_on->toDateString()); } catch (\RuntimeException $exception) { return back()->withErrors(['fiscal_year' => $exception->getMessage()]); }
        if ($pending->isNotEmpty()) return back()->withErrors(['fiscal_year' => 'Close checklist failed: '.$pending->map(fn (int $count, string $label): string => $count.' '.$label)->implode('; ').'.']); $snapshot = app(\App\Services\InventorySnapshotService::class)->capture((int) $year->company_id, $year->ends_on, auth()->id()); if ($snapshot->status === 'variance') return back()->withErrors(['fiscal_year' => 'Close checklist failed: inventory snapshot #'.$snapshot->id.' contains negative-balance variances. Resolve the inventory reconciliation before closing.']); $old = $year->toArray(); $year->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id(), 'inventory_snapshot_id' => $snapshot->id]); app(AuditService::class)->record('fiscal_year.closed', $year, $old, $year->toArray() + ['inventory_snapshot_id' => $snapshot->id]); return back()->with(['message' => 'Fiscal year closed with inventory snapshot #'.$snapshot->id.'. Journals and financial postings are blocked for this period.', 'alert-type' => 'success']); }
    public function reopenFiscalYear(Request $request, int $id) { $data = $request->validate(['reopen_reason' => ['required', 'string', 'max:2000']]); $year = FiscalYear::findOrFail($id); if ($year->status !== 'closed') return back()->withErrors(['fiscal_year' => 'Fiscal year is already open.']); $old = $year->toArray(); $year->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]); app(AuditService::class)->record('fiscal_year.reopened', $year, $old, array_merge($year->toArray(), ['reopen_reason' => $data['reopen_reason']])); return back()->with(['message' => 'Fiscal year reopened.', 'alert-type' => 'success']); }
    public function sequence(Request $request) { $userCompanyId = auth()->user()?->company_id; $data = $request->validate(['company_id' => ['nullable', 'exists:companies,id'], 'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where(function ($query) use ($userCompanyId): void { if ($userCompanyId) $query->where('company_id', $userCompanyId); })], 'document_type' => ['required', 'string', 'max:80'], 'prefix' => ['nullable', 'string', 'max:20'], 'next_number' => ['required', 'integer', 'min:1'], 'padding' => ['required', 'integer', 'min:1', 'max:12'], 'reset_period' => ['required', 'in:never,year,month']]); if ($userCompanyId && isset($data['company_id']) && (int) $data['company_id'] !== (int) $userCompanyId) abort(403, 'A sequence can only be created for the current company.'); if (!empty($data['branch_id']) && !empty($data['company_id']) && (int) Branch::whereKey($data['branch_id'])->value('company_id') !== (int) $data['company_id']) abort(422, 'The selected branch does not belong to the selected company.'); $sequence = NumberingSequence::create($data); app(AuditService::class)->record('numbering_sequence.created', $sequence, null, $sequence->toArray()); return back()->with(['message' => 'Numbering sequence created.', 'alert-type' => 'success']); }
}
