<?php

namespace App\Services;

use App\Models\ProductionOperation;
use App\Models\ProductionOrder;
use App\Models\Routing;
use Illuminate\Support\Facades\DB;

class ProductionOperationService
{
    public function assertReadyForCompletion(ProductionOrder $order): void
    {
        $operations = $order->relationLoaded('operations')
            ? $order->operations
            : $order->load('operations')->operations;
        $incomplete = $operations->first(fn (ProductionOperation $operation): bool => !in_array($operation->status, ['completed', 'skipped'], true));
        if ($incomplete) throw new \RuntimeException('All production routing operations must be completed before receiving finished goods.');
    }

    public function initialize(ProductionOrder $order): void
    {
        $routing = Routing::with('operations')->where('bom_id', $order->bom_id)->where('is_active', true)->first();
        if (!$routing) return;
        foreach ($routing->operations as $operation) ProductionOperation::firstOrCreate(['production_order_id' => $order->id, 'sequence' => $operation->sequence], ['company_id' => $order->company_id, 'routing_operation_id' => $operation->id, 'work_center_id' => $operation->work_center_id, 'operation' => $operation->operation, 'planned_quantity' => $order->planned_quantity]);
    }

    public function start(int $id): ProductionOperation
    {
        return DB::transaction(function () use ($id): ProductionOperation {
            $operation = $this->companyScope(ProductionOperation::query())->lockForUpdate()->findOrFail($id);
            $order = $this->companyScope(ProductionOrder::query(), $operation->company_id)->lockForUpdate()->findOrFail($operation->production_order_id);
            if (!in_array($order->status, ['released', 'in_progress'], true)) throw new \RuntimeException('Only released production orders can start operations.');
            if ($operation->status !== 'pending') throw new \RuntimeException('Only pending operations can be started.');
            if (ProductionOperation::where('production_order_id', $order->id)->where('sequence', '<', $operation->sequence)->whereNotIn('status', ['completed', 'skipped'])->exists()) throw new \RuntimeException('Complete the previous production operation first.');
            $operation->update(['status' => 'in_progress', 'started_by' => auth()->id(), 'started_at' => now()]);
            if ($order->status === 'released') $order->update(['status' => 'in_progress']);
            app(AuditService::class)->record('production_operation.started', $operation, ['status' => 'pending'], ['status' => 'in_progress']);
            return $operation->fresh();
        });
    }

    public function complete(int $id, float $quantity, ?float $setupMinutes = null, ?float $runMinutes = null, ?string $notes = null): ProductionOperation
    {
        return DB::transaction(function () use ($id, $quantity, $setupMinutes, $runMinutes, $notes): ProductionOperation {
            $operation = $this->companyScope(ProductionOperation::query())->lockForUpdate()->findOrFail($id);
            $order = $this->companyScope(ProductionOrder::query(), $operation->company_id)->lockForUpdate()->findOrFail($operation->production_order_id);
            if (!in_array($order->status, ['released', 'in_progress'], true)) throw new \RuntimeException('Only released or in-progress production orders can complete operations.');
            if ($operation->status !== 'in_progress') throw new \RuntimeException('Only in-progress operations can be completed.');
            if ($quantity <= 0 || $quantity > (float) $operation->planned_quantity + 0.000001) throw new \RuntimeException('Operation quantity must be positive and no more than planned quantity.');
            $operation->update(['status' => 'completed', 'completed_quantity' => $quantity, 'actual_setup_minutes' => $setupMinutes, 'actual_run_minutes' => $runMinutes, 'completed_by' => auth()->id(), 'completed_at' => now(), 'notes' => $notes]);
            app(AuditService::class)->record('production_operation.completed', $operation, ['status' => 'in_progress'], $operation->fresh()->only(['status', 'completed_quantity', 'actual_setup_minutes', 'actual_run_minutes', 'notes']));
            return $operation->fresh();
        });
    }

    private function companyScope($query, ?int $companyId = null)
    {
        $companyId ??= auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
