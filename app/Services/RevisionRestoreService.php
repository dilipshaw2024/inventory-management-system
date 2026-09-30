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
    public function preview(DocumentRevision $revision, ?int $companyId): array
    {
        $modelClass = $this->modelClassFor($revision);
        abort_unless($revision->old_values !== null, 422, 'This revision has no prior state to restore.');
        abort_unless($revision->company_id === null || (int) $revision->company_id === (int) $companyId, 404);

        $record = $modelClass::withTrashed()->findOrFail($revision->document_id);
        abort_unless($record->getAttribute('company_id') === null || (int) $record->getAttribute('company_id') === (int) $companyId, 404);
        $allowed = $this->allowedFieldsFor($modelClass);
        $columns = Schema::getColumnListing($record->getTable());
        $restorable = array_intersect_key(collect((array) $revision->old_values)->only($allowed)->all(), array_flip($columns));
        $dependencies = [];
        foreach ($this->dependencyDefinitions($modelClass) as $definition) {
            if (!Schema::hasTable($definition['table']) || !Schema::hasColumn($definition['table'], $definition['foreign_key'])) continue;
            $query = DB::table($definition['table'])->where($definition['foreign_key'], $record->getKey());
            if (Schema::hasColumn($definition['table'], 'company_id') && $companyId !== null) {
                $query->where('company_id', $companyId);
            }
            $dependencies[] = [
                'table' => $definition['table'],
                'relation' => $definition['relation'],
                'count' => (int) $query->count(),
                'review_required' => (int) $query->count() > 0,
            ];
        }
        $conflicts = [];
        foreach ($this->uniqueFieldsFor($modelClass) as $field) {
            if (!array_key_exists($field, $restorable) || $restorable[$field] === null || $restorable[$field] === '') continue;
            if (!Schema::hasColumn($record->getTable(), $field)) continue;
            $query = $modelClass::withTrashed()->where($field, $restorable[$field])->whereKeyNot($record->getKey());
            if (Schema::hasColumn($record->getTable(), 'company_id') && $companyId !== null) {
                $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
            }
            $conflicts[] = ['field' => $field, 'value' => $restorable[$field], 'conflicting_record_ids' => $query->pluck($record->getKeyName())->map(fn ($id): int => (int) $id)->all()];
        }
        $conflicts = array_values(array_filter($conflicts, fn (array $conflict): bool => $conflict['conflicting_record_ids'] !== []));
        return [
            'revision_id' => (int) $revision->id,
            'document_type' => $revision->document_type,
            'document_id' => (int) $revision->document_id,
            'version' => (int) $revision->version,
            'record_exists' => true,
            'record_deleted' => method_exists($record, 'trashed') && $record->trashed(),
            'restorable_fields' => $restorable,
            'dependencies' => $dependencies,
            'conflicts' => $conflicts,
            'safe_to_restore' => $conflicts === [] && $restorable !== [],
            'requires_review' => $conflicts !== [] || collect($dependencies)->contains('review_required', true),
        ];
    }

    public function restore(DocumentRevision $revision, string $reason, ?int $companyId): Model
    {
        $modelClass = $this->modelClassFor($revision);
        abort_unless($revision->old_values !== null, 422, 'This revision has no prior state to restore.');
        abort_unless($revision->company_id === null || (int) $revision->company_id === (int) $companyId, 404);

        return DB::transaction(function () use ($revision, $reason, $companyId, $modelClass): Model {
            $record = $modelClass::withTrashed()->lockForUpdate()->findOrFail($revision->document_id);
            abort_unless($record->getAttribute('company_id') === null || (int) $record->getAttribute('company_id') === (int) $companyId, 404);
            $allowed = $this->allowedFieldsFor($modelClass);
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

    private function modelClassFor(DocumentRevision $revision): string
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
        return $modelClass;
    }

    private function allowedFieldsFor(string $modelClass): array
    {
        return match ($modelClass) {
            Product::class => ['name', 'sku', 'barcode', 'description', 'category_id', 'brand_id', 'unit_id', 'supplier_id', 'buying_price', 'selling_price', 'purchase_price', 'sales_price', 'tax_rate', 'tax_rate_id', 'hsn_sac_code', 'classification_id', 'min_stock', 'max_stock', 'minimum_stock', 'maximum_stock', 'reorder_level', 'status', 'tracking_type', 'product_type', 'lifecycle_status', 'can_purchase', 'can_sell', 'is_stock_item', 'parent_product_id', 'is_variant', 'costing_method', 'standard_cost', 'weight_kg', 'length_m', 'width_m', 'height_m'],
            Supplier::class => ['name', 'code', 'email', 'phone', 'address', 'city', 'state', 'country', 'postal_code', 'tax_number', 'tax_exempt', 'tax_exemption_number', 'tax_jurisdiction', 'payment_terms_days', 'rating', 'bank_name', 'bank_account', 'bank_ifsc', 'purchase_price_list_id', 'is_active', 'planning_calendar'],
            Customer::class => ['name', 'code', 'email', 'phone', 'address', 'city', 'state', 'country', 'postal_code', 'tax_number', 'tax_exempt', 'tax_exemption_number', 'tax_jurisdiction', 'credit_limit', 'credit_days', 'credit_hold', 'credit_hold_after_days', 'sales_price_list_id', 'is_active'],
            Unit::class => ['name', 'code', 'status', 'decimal_places', 'dimension', 'is_base'],
            Category::class => ['name', 'code', 'parent_id', 'tax_rate', 'is_active', 'status', 'required_attribute_ids', 'default_costing_method', 'default_standard_cost'],
            Brand::class => ['name', 'code', 'is_active'],
        };
    }

    private function uniqueFieldsFor(string $modelClass): array
    {
        return match ($modelClass) {
            Product::class, Supplier::class, Customer::class, Unit::class, Category::class, Brand::class => ['sku', 'barcode', 'code'],
            default => [],
        };
    }

    private function dependencyDefinitions(string $modelClass): array
    {
        return match ($modelClass) {
            Product::class => [
                ['table' => 'inventory_movements', 'foreign_key' => 'product_id', 'relation' => 'inventory movements'],
                ['table' => 'purchase_order_lines', 'foreign_key' => 'product_id', 'relation' => 'purchase order lines'],
                ['table' => 'sales_order_lines', 'foreign_key' => 'product_id', 'relation' => 'sales order lines'],
                ['table' => 'bom_components', 'foreign_key' => 'component_product_id', 'relation' => 'BOM component lines'],
            ],
            Supplier::class => [
                ['table' => 'purchase_orders', 'foreign_key' => 'supplier_id', 'relation' => 'purchase orders'],
                ['table' => 'purchase_invoices', 'foreign_key' => 'supplier_id', 'relation' => 'purchase invoices'],
                ['table' => 'supplier_claims', 'foreign_key' => 'supplier_id', 'relation' => 'supplier claims'],
            ],
            Customer::class => [
                ['table' => 'sales_orders', 'foreign_key' => 'customer_id', 'relation' => 'sales orders'],
                ['table' => 'sales_invoices', 'foreign_key' => 'customer_id', 'relation' => 'sales invoices'],
                ['table' => 'service_requests', 'foreign_key' => 'customer_id', 'relation' => 'service requests'],
            ],
            Unit::class => [['table' => 'products', 'foreign_key' => 'unit_id', 'relation' => 'products using unit']],
            Category::class => [
                ['table' => 'products', 'foreign_key' => 'category_id', 'relation' => 'products in category'],
                ['table' => 'categories', 'foreign_key' => 'parent_id', 'relation' => 'child categories'],
            ],
            Brand::class => [['table' => 'products', 'foreign_key' => 'brand_id', 'relation' => 'products using brand']],
            default => [],
        };
    }
}
