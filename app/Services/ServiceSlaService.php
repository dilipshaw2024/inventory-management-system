<?php

namespace App\Services;

use Carbon\CarbonInterface;

class ServiceSlaService
{
    public function status(?CarbonInterface $dueAt, ?CarbonInterface $assignedAt, string $requestStatus, ?CarbonInterface $now = null): string
    {
        if (!$dueAt) return 'not_tracked';
        if (in_array($requestStatus, ['resolved', 'cancelled'], true)) return 'closed';
        if ($assignedAt) return $assignedAt->lessThanOrEqualTo($dueAt) ? 'met' : 'breached';
        return ($now ?: now())->lessThanOrEqualTo($dueAt) ? 'due' : 'breached';
    }
}
