<?php

namespace App\Services;

use App\Models\RecurringJournalRun;
use App\Models\RecurringJournalTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecurringJournalService
{
    public function nextRunDate(CarbonImmutable $date, string $frequency, int $interval = 1): CarbonImmutable
    {
        if ($interval < 1) throw new \InvalidArgumentException('Recurring journal interval must be at least one.');
        return match ($frequency) {
            'daily' => $date->addDays($interval),
            'weekly' => $date->addWeeks($interval),
            'monthly' => $date->addMonthsNoOverflow($interval),
            default => throw new \InvalidArgumentException('Unsupported recurring journal frequency.'),
        };
    }

    /** @return array{generated:int, skipped:int, errors:array<int,string>} */
    public function generateDue(?int $companyId = null, ?string $asOf = null, int $limit = 100): array
    {
        $date = CarbonImmutable::parse($asOf ?: now()->toDateString())->startOfDay();
        $query = RecurringJournalTemplate::query()
            ->where('is_active', true)
            ->whereDate('next_run_on', '<=', $date->toDateString())
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->where(fn ($scope) => $scope->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date->toDateString()))
            ->orderBy('next_run_on')->orderBy('id')
            ->limit(max(1, $limit));
        if ($companyId) $query->where('company_id', $companyId);

        $result = ['generated' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($query->get()->pluck('id') as $templateId) {
            try {
                if ($this->generateOne((int) $templateId, $date)) $result['generated']++;
                else $result['skipped']++;
            } catch (\Throwable $exception) {
                $result['errors'][] = 'Template '.$templateId.': '.$exception->getMessage();
            }
        }
        return $result;
    }

    public function generateOne(int $templateId, CarbonImmutable $asOf): bool
    {
        return DB::transaction(function () use ($templateId, $asOf): bool {
            $template = RecurringJournalTemplate::query()->lockForUpdate()->findOrFail($templateId);
            if (!$template->is_active || $template->next_run_on->gt($asOf) || ($template->ends_on && $template->next_run_on->gt($template->ends_on))) return false;
            $runDate = $template->next_run_on->toDateString();
            if (RecurringJournalRun::where('recurring_journal_template_id', $template->id)->whereDate('run_date', $runDate)->exists()) {
                $this->advance($template);
                return false;
            }

            $journal = app(AccountingService::class)->post([
                'company_id' => $template->company_id,
                'entry_no' => 'REC-'.$template->id.'-'.str_replace('-', '', $runDate),
                'date' => $runDate,
                'description' => $template->description ?: $template->name,
            ], $template->lines, $template);
            RecurringJournalRun::create([
                'company_id' => $template->company_id,
                'recurring_journal_template_id' => $template->id,
                'run_date' => $runDate,
                'journal_entry_id' => $journal->id,
            ]);
            $this->advance($template);
            return true;
        });
    }

    private function advance(RecurringJournalTemplate $template): void
    {
        $next = $this->nextRunDate(CarbonImmutable::parse($template->next_run_on->toDateString()), $template->frequency, (int) $template->interval);
        $template->update(['next_run_on' => $next->toDateString(), 'is_active' => $template->ends_on && $next->gt($template->ends_on) ? false : $template->is_active]);
    }
}
