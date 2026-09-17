<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\AuditService;

class SoftDeleteRestoreController extends Controller
{
    private const MODELS = [
        'branch' => Branch::class, 'brand' => Brand::class, 'category' => Category::class,
        'customer' => Customer::class, 'department' => Department::class, 'invoice' => Invoice::class,
        'inventory-location' => InventoryLocation::class, 'payment' => Payment::class, 'product' => Product::class,
        'purchase' => Purchase::class, 'store' => Store::class, 'supplier' => Supplier::class,
        'unit' => Unit::class, 'warehouse' => Warehouse::class,
    ];

    public function restore(string $type, int $id)
    {
        $modelClass = self::MODELS[$type] ?? null;
        if (!$modelClass) abort(404, 'This record type cannot be restored.');
        $model = $modelClass::withTrashed()->findOrFail($id);
        $this->assertOwnership($model);
        if (!$model->trashed()) return back()->with(['message' => 'The record is already active.', 'alert-type' => 'info']);
        $before = ['deleted_at' => $model->deleted_at];
        $model->restore();
        app(AuditService::class)->record('soft_delete.restored', $model, $before, ['deleted_at' => null, 'record_type' => $type]);
        return back()->with(['message' => ucfirst(str_replace('-', ' ', $type)).' restored.', 'alert-type' => 'success']);
    }

    private function assertOwnership(object $model): void
    {
        $companyId = auth()->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required to restore records.');
        $ownerCompanyId = $model->company_id ?? null;
        if ($model instanceof Warehouse || $model instanceof Store) {
            $ownerCompanyId = $model->loadMissing('branch')->branch?->company_id;
        } elseif ($model instanceof InventoryLocation) {
            $ownerCompanyId = $model->loadMissing('warehouse.branch')->warehouse?->branch?->company_id;
        }
        if ($ownerCompanyId !== null && (int) $ownerCompanyId !== (int) $companyId) abort(404);
    }
}
