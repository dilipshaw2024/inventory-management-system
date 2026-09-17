<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockCountSchedule;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use App\Services\InventoryAvailabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateDueStockCounts extends Command
{
    protected $signature = 'erp:inventory:generate-counts {--company= : Limit generation to a company ID}';
    protected $description = 'Generate draft stock-count documents for due recurring schedules.';

    public function handle(): int
    {
        $query = StockCountSchedule::query()->where('is_active', true)->whereDate('next_due', '<=', Carbon::today());
        if ($this->option('company')) $query->where('company_id', (int) $this->option('company'));
        $generated = 0;
        foreach ($query->pluck('id') as $scheduleId) {
            try {
                if (DB::transaction(function () use ($scheduleId): bool {
                    $schedule = StockCountSchedule::lockForUpdate()->find($scheduleId);
                    if (!$schedule || !$schedule->is_active || !$schedule->next_due || $schedule->next_due->isFuture()) return false;
                    $count = StockCount::create([
                        'company_id' => $schedule->company_id,
                        'count_no' => app(NumberingSequenceService::class)->nextOrFallback('stock_count', 'CNT-'.now()->format('YmdHis').'-'.random_int(100, 999), $schedule->company_id, null),
                        'count_date' => $schedule->next_due,
                        'location_id' => $schedule->location_id,
                        'description' => trim('Automated count from '.$schedule->schedule_no.'. '.($schedule->description ?: '')),
                        'status' => 'draft',
                        'created_by' => null,
                    ]);
                    $products = Product::where('status', 1)->when($schedule->company_id !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('company_id', $schedule->company_id)->orWhereNull('company_id')))->get();
                    $availability = app(InventoryAvailabilityService::class)->availableMany($products, false, $schedule->location_id, $schedule->company_id);
                    foreach ($products as $product) {
                        $systemQuantity = (float) ($availability[$product->id] ?? 0);
                        StockCountLine::create(['stock_count_id' => $count->id, 'product_id' => $product->id, 'system_quantity' => $systemQuantity, 'counted_quantity' => $systemQuantity, 'variance_quantity' => 0, 'unit_cost' => $product->purchase_price]);
                    }
                    $oldDue = $schedule->next_due->copy();
                    $nextDue = $oldDue->copy()->addDays($schedule->frequency_days);
                    $schedule->update(['next_due' => $nextDue, 'last_generated_at' => now()]);
                    app(AuditService::class)->record('stock_count_schedule.generated', $schedule, ['next_due' => $oldDue->toDateString()], ['next_due' => $nextDue->toDateString(), 'stock_count_id' => $count->id, 'automated' => true]);
                    return true;
                })) $generated++;
            } catch (\Throwable $exception) {
                $this->warn('Schedule '.$scheduleId.' skipped: '.$exception->getMessage());
            }
        }
        $this->info("Generated {$generated} stock count(s).");
        return self::SUCCESS;
    }
}
