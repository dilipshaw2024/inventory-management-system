<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\InventoryDocument;
use App\Models\InventoryReturn;
use Illuminate\Database\Eloquent\Model;

class InventoryMovementSourceContextService
{
    public function resolve(?Model $source): ?array
    {
        if (!$source) return null;
        $context = ['document_type' => $source->getMorphClass(), 'document_id' => (int) $source->getKey()];
        if ($source instanceof InventoryReturn) {
            $sales = $source->return_type === 'sales';
            $party = $sales ? $source->customer : $source->supplier;
            return $context + ['document_no' => $source->return_no, 'party_type' => $sales ? 'customer' : 'supplier', 'party_id' => $party?->id, 'party_name' => $party?->name];
        }
        if ($source instanceof Delivery) {
            $customer = $source->salesOrder?->customer;
            return $context + ['document_no' => $source->delivery_no, 'party_type' => 'customer', 'party_id' => $customer?->id, 'party_name' => $customer?->name];
        }
        if ($source instanceof GoodsReceipt) {
            $supplier = $source->purchaseOrder?->supplier;
            return $context + ['document_no' => $source->grn_no, 'party_type' => 'supplier', 'party_id' => $supplier?->id, 'party_name' => $supplier?->name];
        }
        if ($source instanceof InventoryDocument) return $context + ['document_no' => $source->document_no, 'document_kind' => $source->document_type];
        return $context;
    }
}
