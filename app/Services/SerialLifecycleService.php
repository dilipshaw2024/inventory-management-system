<?php

namespace App\Services;

use App\Models\InventorySerial;
use App\Models\Product;
use Illuminate\Support\Collection;

class SerialLifecycleService
{
    public function receive(Product $product, string $serialNumber, ?int $locationId = null, ?int $batchId = null, $warrantyUntil = null): InventorySerial
    {
        if ($product->tracking_type !== 'serial') throw new \RuntimeException('Serial numbers can only be received for serial-tracked products.');
        $serialNumber = trim($serialNumber);
        if ($serialNumber === '') throw new \RuntimeException('Serial number cannot be empty.');
        $existing = InventorySerial::where('product_id', $product->id)->where('serial_no', $serialNumber)->lockForUpdate()->first();
        if ($existing) throw new \RuntimeException('Serial number '.$serialNumber.' already exists for '.$product->name.'.');
        return InventorySerial::create([
            'product_id' => $product->id,
            'serial_no' => $serialNumber,
            'location_id' => $locationId,
            'batch_id' => $batchId,
            'warranty_until' => $warrantyUntil,
            'status' => 'available',
        ]);
    }

    public function issue(Product $product, float $quantity, ?int $locationId = null, ?int $batchId = null): Collection
    {
        if ($product->tracking_type !== 'serial') return collect();
        if (abs($quantity - round($quantity)) > 0.000001) throw new \RuntimeException('Serial-tracked quantity must be a whole number for '.$product->name.'.');
        $serials = InventorySerial::where('product_id', $product->id)->whereIn('status', ['available', 'returned'])->when($batchId !== null, fn ($query) => $query->where('batch_id', $batchId))->when($locationId !== null, fn ($query) => $query->where(function ($nested) use ($locationId): void { $nested->whereNull('location_id')->orWhere('location_id', $locationId); }))->orderBy('id')->lockForUpdate()->limit((int) round($quantity))->get();
        if ($serials->count() !== (int) round($quantity)) throw new \RuntimeException('Insufficient available serial numbers for '.$product->name.'.');
        $serials->each(fn (InventorySerial $serial): bool => (bool) $serial->update(['status' => 'issued', 'location_id' => $locationId ?: $serial->location_id]));
        return $serials;
    }

    public function issueSpecific(Product $product, array $serialNumbers, ?int $locationId = null, ?int $batchId = null): Collection
    {
        if ($product->tracking_type !== 'serial') return collect();
        $serialNumbers = array_values(array_unique(array_filter(array_map('trim', $serialNumbers))));
        $serials = InventorySerial::where('product_id', $product->id)->whereIn('serial_no', $serialNumbers)->whereIn('status', ['available', 'returned'])->when($batchId !== null, fn ($query) => $query->where('batch_id', $batchId))->when($locationId !== null, fn ($query) => $query->where(function ($nested) use ($locationId): void { $nested->whereNull('location_id')->orWhere('location_id', $locationId); }))->lockForUpdate()->get();
        if ($serials->count() !== count($serialNumbers)) throw new \RuntimeException('One or more selected serial numbers are unavailable for '.$product->name.'.');
        $serials->each(fn (InventorySerial $serial): bool => (bool) $serial->update(['status' => 'issued', 'location_id' => $locationId ?: $serial->location_id]));
        return $serials;
    }

    public function returnToStock(Product $product, array $serialNumbers, ?int $locationId = null, ?int $batchId = null): Collection
    {
        if ($product->tracking_type !== 'serial') return collect();
        $serials = InventorySerial::where('product_id', $product->id)->whereIn('serial_no', $serialNumbers)->where('status', 'issued')->when($batchId !== null, fn ($query) => $query->where('batch_id', $batchId))->lockForUpdate()->get();
        if ($serials->count() !== count($serialNumbers)) throw new \RuntimeException('One or more serial numbers do not belong to '.$product->name.'.');
        $serials->each(fn (InventorySerial $serial): bool => (bool) $serial->update(['status' => 'returned', 'location_id' => $locationId ?: $serial->location_id]));
        return $serials;
    }

    public function reserveForTransfer(Product $product, float $quantity, int $sourceLocationId): Collection
    {
        if ($product->tracking_type !== 'serial') return collect();
        if (abs($quantity - round($quantity)) > 0.000001) throw new \RuntimeException('Serial-tracked transfer quantity must be a whole number for '.$product->name.'.');
        $serials = InventorySerial::where('product_id', $product->id)->whereIn('status', ['available', 'returned'])->where(function ($query) use ($sourceLocationId): void { $query->whereNull('location_id')->orWhere('location_id', $sourceLocationId); })->orderBy('id')->lockForUpdate()->limit((int) round($quantity))->get();
        if ($serials->count() !== (int) round($quantity)) throw new \RuntimeException('Insufficient available serial numbers at the source location for '.$product->name.'.');
        $serials->each(fn (InventorySerial $serial): bool => (bool) $serial->update(['status' => 'reserved', 'location_id' => $sourceLocationId]));
        return $serials;
    }
}
