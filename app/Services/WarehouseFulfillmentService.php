<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryOperation;
use Illuminate\Support\Facades\DB;

class WarehouseFulfillmentService
{
    public function assertReadyForDispatch(Delivery $delivery): void
    {
        $operations = $delivery->relationLoaded('operations')
            ? $delivery->operations
            : $delivery->load('operations')->operations;

        foreach (['pick' => 'Picking', 'pack' => 'Packing'] as $type => $label) {
            if ($operations->firstWhere('operation_type', $type)?->status !== 'completed') {
                throw new \RuntimeException($label.' must be completed before dispatch approval.');
            }
        }
    }

    public function complete(Delivery $delivery, string $type, ?array $confirmedQuantities = null): DeliveryOperation
    {
        if (!in_array($type, ['pick', 'pack'], true)) throw new \InvalidArgumentException('Only picking and packing are completed before dispatch approval.');
        if ($delivery->status !== 'pending' || ($delivery->fulfillment_status ?: 'pending') !== 'pending') throw new \RuntimeException('Only pending deliveries can be picked or packed.');
        return DB::transaction(function () use ($delivery, $type, $confirmedQuantities): DeliveryOperation {
            $delivery->load('operations');
            $operation = $delivery->operations->firstWhere('operation_type', $type) ?? DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => $type]);
            if ($operation->status === 'completed') return $operation;
            if ($type === 'pack' && ($delivery->operations->firstWhere('operation_type', 'pick')?->status !== 'completed')) throw new \RuntimeException('Picking must be completed before packing.');
            $attributes = ['status' => 'completed', 'performed_by' => auth()->id(), 'completed_at' => now()];
            if (in_array($type, ['pick', 'pack'], true)) {
                $delivery->loadMissing('lines');
                $expected = $delivery->lines->mapWithKeys(fn ($line): array => [(string) $line->id => (float) $line->delivered_qty])->all();
                $confirmed = $confirmedQuantities === null || $confirmedQuantities === []
                    ? $expected
                    : collect($confirmedQuantities)->mapWithKeys(fn ($quantity, $lineId): array => [(string) $lineId => (float) $quantity])->all();
                foreach ($expected as $lineId => $quantity) {
                    if (!array_key_exists($lineId, $confirmed) || abs($confirmed[$lineId] - $quantity) > 0.000001) throw new \RuntimeException(ucfirst($type).' quantity must match the delivery quantity for every line.');
                }
                foreach ($confirmed as $lineId => $quantity) {
                    if (!array_key_exists($lineId, $expected) || $quantity < 0) throw new \RuntimeException('Picked quantity contains an invalid delivery line.');
                }
                $attributes['confirmed_quantities'] = $confirmed;
            }
            $operation->update($attributes);
            if ($type === 'pick') DeliveryOperation::firstOrCreate(['delivery_id' => $delivery->id, 'operation_type' => 'pack']);
            return $operation;
        });
    }

    public function markDispatched(Delivery $delivery): void
    {
        if ($delivery->status !== 'approved') throw new \RuntimeException('Only approved deliveries can be dispatched.');
        $this->assertReadyForDispatch($delivery);
        DeliveryOperation::updateOrCreate(['delivery_id' => $delivery->id, 'operation_type' => 'dispatch'], ['status' => 'completed', 'performed_by' => auth()->id(), 'completed_at' => now()]);
        $delivery->update(['fulfillment_status' => 'dispatched']);
    }
}
