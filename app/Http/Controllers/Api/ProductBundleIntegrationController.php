<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\ProductBundleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductBundleIntegrationController extends Controller
{
    public function show(Request $request, int $productId): JsonResponse
    {
        $bundle = $this->ownedBundle($request, $productId);
        return response()->json(['data' => ['product_id' => $bundle->id, 'sku' => $bundle->sku, 'name' => $bundle->name, 'components' => app(ProductBundleService::class)->components($bundle)->map(fn ($row): array => ['product_id' => $row->component_product_id, 'sku' => $row->componentProduct?->sku, 'name' => $row->componentProduct?->name, 'quantity' => (float) $row->quantity, 'sort_order' => $row->sort_order])->values()] ]);
    }

    public function replace(Request $request, int $productId): JsonResponse
    {
        $bundle = $this->ownedBundle($request, $productId);
        $data = $request->validate(['components' => ['required', 'array', 'min:1'], 'components.*.product_id' => ['required', 'integer', 'distinct'], 'components.*.quantity' => ['required', 'numeric', 'gt:0'], 'components.*.sort_order' => ['sometimes', 'integer', 'min:0']]);
        try {
            $components = DB::transaction(function () use ($bundle, $data, $request): mixed {
                $components = app(ProductBundleService::class)->replace($bundle, $data['components'], (int) $request->user()->company_id);
                app(AuditService::class)->record('product_bundle.components_replaced', $bundle, null, ['components' => $components->map(fn ($row): array => ['product_id' => $row->component_product_id, 'quantity' => (float) $row->quantity])->all()]);
                return $components;
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return $this->show($request, $productId)->setStatusCode(200);
    }

    private function ownedBundle(Request $request, int $productId): Product
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for bundle management.');
        $bundle = Product::withoutGlobalScopes()->whereKey($productId)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->firstOrFail();
        abort_unless(($bundle->product_type ?: 'stock') === 'bundle', 422, 'The selected product is not a bundle.');
        return $bundle;
    }
}
