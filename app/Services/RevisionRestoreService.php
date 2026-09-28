<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DocumentRevision;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RevisionRestoreService
{
    public function restore(DocumentRevision $revision, string $reason, ?int $companyId): Model
    {
        $types = [
            (new Product)->getMorphClass() => Product::class,
            (new Supplier)->getMorphClass() => Supplier::class,
            (new Customer)->getMorphClass() => Customer::class,
            (new Unit)->getMorphClass() => Unit::class,
            (new Category)->getMorphClass() => Category::class,
            (new Brand)->getMorphClass() => Brand::class,
        ];
        $modelClass = $types[$revision->document_type] ?? null;
        abort_unless($modelClass, 422, 'Only supported core master revisions can be restored from this workflow.');
        abort_unless($revision->old_values !== null, 422, 'This revision has no prior state to restore.');
        abort_unless($revision->company_id === null || (int) $revision->company_id === (int) $companyId, 404);

        return DB::transaction(function () use ($revision, $reason, $companyId, $modelClass): Model {
            $record = $modelClass::withTrashed()->lockForUpdate()->findOrFail($revision->document_id);
            abort_unless($record->getAttribute('company_id') === null || (int) $record->getAttribute('company_id') === (int) $companyId, 404);
            $allowed = match ($modelClass) {
                Product::class => ['name', 'sku', 'barcode', 'description', 'category_id', 'brand_id', 'unit_id', 'supplier_id', 'buying_price', 'selling_price', 'tax_rate', 'tax_rate_id', 'minimum_stock', 'maximum_stock', 'reorder_level', 'can_purchase', 'can_sell', 'is_stock_item', 'weight_kg', 'length_m', 'width_m', 'height_m'],
                Supplier::class => ['name', 'code', 'email', 'phone', 'address', 'city', 'state', 'country', 'postal_code', 'tax_number', 'tax_exempt', 'tax_exemption_number', 'tax_jurisdiction', 'payment_terms_days', 'rating', 'bank_name', 'bank_account', 'bank_ifsc', 'purchase_price_list_id', 'is_active', 'planning_calendar'],
                Customer::class => ['name', 'code', 'email', 'phone', 'address', 'city', 'state', 'country', 'postal_code', 'tax_number', 'tax_exempt', 'tax_exemption_number', 'tax_jurisdiction', 'credit_limit', 'credit_days', 'credit_hold', 'credit_hold_after_days', 'sales_price_list_id', 'is_active'],
                Unit::class => ['name', 'code', 'status', 'decimal_places', 'dimension', 'is_base'],
                Category::class => ['name', 'code', 'parent_id', 'tax_rate', 'is_active', 'status', 'required_attribute_ids', 'default_costing_method', 'default_standard_cost'],
                Brand::class => ['name', 'code', 'is_active'],
            };
            $before = $record->only($allowed);
            $restore = array_intersect_key(collect((array) $revision->old_values)->only($allowed)->all(), array_flip(Schema::getColumnListing($record->getTable())));
            if (!$restore) abort(422, 'The revision contains no restorable fields.');
            if (method_exists($record, 'trashed') && $record->trashed()) $record->restore();
            $record->fill($restore);
            $record->save();
            $fresh = $record->fresh();
            app(AuditService::class)->record('document_revision.restored', $fresh, $before, $fresh->only($allowed) + ['revision_id' => $revision->id, 'reason' => $reason]);
            return $fresh;
        });
    }
}
