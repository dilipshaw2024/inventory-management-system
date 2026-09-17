<?php

namespace App\Services;

use App\Models\BillOfMaterial;
use App\Models\Product;
use Carbon\Carbon;

/**
 * Expands a BOM into leaf-material requirements.
 *
 * Quantities returned are stock-unit quantities and include line scrap.
 */
class BomExplosionService
{
    public function leafRequirements(BillOfMaterial $bom, float $outputQuantity, ?int $companyId = null, ?string $asOf = null): array
    {
        $requirements = [];
        $companyId ??= $bom->company_id ?: auth()->user()?->company_id;
        $asOf ??= now()->toDateString();
        $this->expand($bom, $outputQuantity / max((float) $bom->output_quantity, 0.000001), $requirements, [], $companyId, Carbon::parse($asOf)->toDateString());
        return $requirements;
    }

    public function snapshot(BillOfMaterial $bom, ?int $companyId = null, ?string $asOf = null): array
    {
        $companyId ??= $bom->company_id ?: auth()->user()?->company_id;
        return $this->snapshotNode($bom, $companyId, Carbon::parse($asOf ?? now()->toDateString())->toDateString(), []);
    }

    public function leafRequirementsFromSnapshot(array $snapshot, float $outputQuantity): array
    {
        $requirements = [];
        $this->expandSnapshot($snapshot, $outputQuantity / max((float) ($snapshot['output_quantity'] ?? 1), 0.000001), $requirements, [], null);
        return $requirements;
    }

    private function expand(BillOfMaterial $bom, float $factor, array &$requirements, array $path, ?int $companyId, string $asOf): void
    {
        if (in_array($bom->id, $path, true)) {
            throw new \RuntimeException('Circular BOM detected at '.$bom->code.'.');
        }

        if ($companyId !== null) {
            $this->componentForCompany((int) $bom->product_id, $companyId);
        }

        $path[] = $bom->id;
        $bom->loadMissing('lines');
        foreach ($bom->lines as $line) {
            $quantity = (float) $line->quantity * $factor * (1 + ((float) $line->scrap_percent / 100));
            $component = $this->componentForCompany((int) $line->component_product_id, $companyId);
            $child = $this->effectiveChild($line->component_product_id, $companyId, $asOf);

            if ($child) {
                $this->expand($child, $quantity / max((float) $child->output_quantity, 0.000001), $requirements, $path, $companyId, $asOf);
            } else {
                $requirements[$component->id] = ($requirements[$component->id] ?? 0) + $quantity;
            }
        }
    }

    private function snapshotNode(BillOfMaterial $bom, ?int $companyId, string $asOf, array $path): array
    {
        if (in_array($bom->id, $path, true)) throw new \RuntimeException('Circular BOM detected at '.$bom->code.'.');
        $bom->loadMissing(['lines', 'byproducts']);
        if ($companyId !== null) {
            $this->componentForCompany((int) $bom->product_id, $companyId);
            foreach ($bom->byproducts as $byproduct) {
                $this->componentForCompany((int) $byproduct->product_id, $companyId);
            }
        }
        $path[] = $bom->id;
        $lines = [];
        foreach ($bom->lines as $line) {
            if ($companyId !== null) {
                $this->componentForCompany((int) $line->component_product_id, $companyId);
            }
            $child = $this->effectiveChild($line->component_product_id, $companyId, $asOf);
            $lines[] = ['component_product_id' => (int) $line->component_product_id, 'quantity' => (float) $line->quantity, 'scrap_percent' => (float) $line->scrap_percent, 'child' => $child ? $this->snapshotNode($child, $companyId, $asOf, $path) : null];
        }
        return ['id' => (int) $bom->id, 'code' => $bom->code, 'version' => $bom->version ?: '1', 'product_id' => (int) $bom->product_id, 'output_quantity' => (float) $bom->output_quantity, 'lines' => $lines, 'byproducts' => $bom->byproducts->map(fn ($byproduct): array => ['product_id' => (int) $byproduct->product_id, 'quantity' => (float) $byproduct->quantity, 'cost_share_percent' => (float) $byproduct->cost_share_percent])->values()->all()];
    }

    private function expandSnapshot(array $snapshot, float $factor, array &$requirements, array $path, ?int $companyId): void
    {
        $id = (int) ($snapshot['id'] ?? 0);
        if ($id && in_array($id, $path, true)) throw new \RuntimeException('Circular BOM detected in snapshot.');
        $path[] = $id;
        foreach ($snapshot['lines'] ?? [] as $line) {
            $quantity = (float) $line['quantity'] * $factor * (1 + ((float) ($line['scrap_percent'] ?? 0) / 100));
            if ($companyId !== null) $this->componentForCompany((int) $line['component_product_id'], $companyId);
            if (!empty($line['child'])) $this->expandSnapshot($line['child'], $quantity / max((float) ($line['child']['output_quantity'] ?? 1), 0.000001), $requirements, $path, $companyId);
            else $requirements[(int) $line['component_product_id']] = ($requirements[(int) $line['component_product_id']] ?? 0) + $quantity;
        }
    }

    private function effectiveChild(int $productId, ?int $companyId, string $asOf): ?BillOfMaterial
    {
        return BillOfMaterial::withoutGlobalScopes()->where('product_id', $productId)->where('is_active', true)
            ->when($companyId !== null, fn ($query) => $query->whereHas('product', fn ($scope) => $scope->where(fn ($owner) => $owner->where('company_id', $companyId)->orWhereNull('company_id'))))
            ->when($companyId !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $asOf))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->orderByDesc('effective_from')->first();
    }

    private function componentForCompany(int $productId, ?int $companyId): Product
    {
        $component = Product::withoutGlobalScope('company')->find($productId);
        if (!$component || ($companyId !== null && $component->company_id !== null && (int) $component->company_id !== (int) $companyId)) {
            throw new \RuntimeException('BOM component '.$productId.' is not authorized for the planning company.');
        }
        return $component;
    }
}
