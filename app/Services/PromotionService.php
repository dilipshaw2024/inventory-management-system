<?php

namespace App\Services;

use App\Models\Promotion;
use App\Models\Product;
use Illuminate\Support\Carbon;

class PromotionService
{
    /** @param array<int, string> $codes */
    public function applyCodesToLines(array $codes, array $lines, ?int $customerId, string $date): array
    {
        $codes = array_values(array_unique(array_filter(array_map(static fn ($code): string => strtoupper(trim((string) $code)), $codes))));
        $promotions = [];
        foreach ($codes as $code) {
            $result = $this->applyToLines($code, $lines, $customerId, $date);
            $before = (float) array_sum(array_map(static fn (array $line): float => (float) ($line['discount'] ?? 0), $lines));
            $after = (float) array_sum(array_map(static fn (array $line): float => (float) ($line['discount'] ?? 0), $result['lines']));
            if (!$result['promotion'] || $after <= $before + 0.000001) continue;
            if ($promotions && (!$result['promotion']->stackable || collect($promotions)->contains(fn (Promotion $promotion): bool => !$promotion->stackable))) throw new \RuntimeException('One or more promotion codes cannot be stacked.');
            $lines = $result['lines'];
            $promotions[] = $result['promotion'];
        }
        return ['promotion' => $promotions[0] ?? null, 'promotions' => $promotions, 'lines' => $lines];
    }

    /** @param array<int, array{product_id:int, quantity:float, unit_price:float, discount:float}> $lines */
    public function applyToLines(?string $code, array $lines, ?int $customerId, string $date): array
    {
        $result = $this->calculate($code, array_column($lines, 'product_id'), array_column($lines, 'quantity'), array_column($lines, 'unit_price'), $customerId, $date);
        if (!$result['promotion'] || $result['discount'] <= 0) return ['promotion' => $result['promotion'], 'lines' => $lines];
        $promotion = $result['promotion']; $eligible = [];
        foreach ($lines as $index => $line) {
            $product = Product::find($line['product_id']);
            if ($product && (!$promotion->product_id || (int) $promotion->product_id === (int) $product->id) && (!$promotion->category_id || (int) $promotion->category_id === (int) $product->category_id)) $eligible[$index] = (float) $line['quantity'] * (float) $line['unit_price'];
        }
        $eligibleTotal = array_sum($eligible);
        if ($promotion->type === 'bogo') {
            $eligible = [];
            foreach ($lines as $index => $line) {
                $product = Product::find($line['product_id']);
                if (!$product || ($promotion->product_id && (int) $promotion->product_id !== (int) $product->id) || ($promotion->category_id && (int) $promotion->category_id !== (int) $product->category_id)) continue;
                $buy = (float) $promotion->buy_quantity;
                $get = (float) $promotion->get_quantity;
                $free = $buy > 0 && $get > 0 ? floor((float) $line['quantity'] / $buy) * $get : 0;
                $eligible[$index] = min((float) $line['quantity'], $free) * (float) $line['unit_price'];
            }
            $eligibleTotal = array_sum($eligible);
        }
        foreach ($lines as $index => &$line) {
            $share = $eligibleTotal > 0 && isset($eligible[$index]) ? (float) $result['discount'] * $eligible[$index] / $eligibleTotal : 0;
            $line['discount'] = min((float) $line['quantity'] * (float) $line['unit_price'], (float) $line['discount'] + $share);
        }
        unset($line);
        return ['promotion' => $promotion, 'lines' => $lines];
    }

    public function calculate(?string $code, array $productIds, array $quantities, array $prices, ?int $customerId, string $date): array
    {
        if (!$code) return ['promotion' => null, 'discount' => 0.0];
        $promotion = Promotion::where('code', strtoupper(trim($code)))->where('is_active', true)->first();
        if (!$promotion || ($promotion->starts_on && Carbon::parse($date)->lt($promotion->starts_on)) || ($promotion->ends_on && Carbon::parse($date)->gt($promotion->ends_on))) throw new \RuntimeException('Promotion code is invalid or outside its validity period.');
        $this->assertRedeemable($promotion);
        if ($promotion->customer_id && (int) $promotion->customer_id !== (int) $customerId) throw new \RuntimeException('Promotion is not available for this customer.');
        $eligibleTotal = 0.0; $eligibleQuantity = 0.0;
        foreach ($productIds as $index => $productId) {
            $product = Product::find($productId);
            if (!$product || ($promotion->product_id && (int) $promotion->product_id !== (int) $productId) || ($promotion->category_id && (int) $promotion->category_id !== (int) $product->category_id)) continue;
            $eligibleQuantity += (float) ($quantities[$index] ?? 0);
            $eligibleTotal += (float) ($quantities[$index] ?? 0) * (float) ($prices[$index] ?? 0);
        }
        if ($promotion->type !== 'bogo' && $eligibleQuantity < (float) $promotion->minimum_quantity) return ['promotion' => $promotion, 'discount' => 0.0];
        $discount = $promotion->type === 'bogo' ? $eligibleTotal : ($promotion->type === 'percentage' ? $eligibleTotal * ((float) $promotion->discount_value / 100) : (float) $promotion->discount_value);
        return ['promotion' => $promotion, 'discount' => min(max(0, $discount), $eligibleTotal)];
    }

    public function redeem(int $promotionId): Promotion
    {
        $promotion = Promotion::lockForUpdate()->findOrFail($promotionId);
        $this->assertRedeemable($promotion);
        $promotion->increment('usage_count');
        return $promotion->fresh();
    }

    public function assertRedeemable(Promotion $promotion): void
    {
        if (!$promotion->is_active) throw new \RuntimeException('Promotion is inactive.');
        if ($promotion->usage_limit !== null && (int) $promotion->usage_count >= (int) $promotion->usage_limit) throw new \RuntimeException('Promotion usage limit has been reached.');
    }
}
