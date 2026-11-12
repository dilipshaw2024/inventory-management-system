<?php

namespace App\Console\Commands;

use App\Models\ServiceTechnician;
use App\Models\User;
use App\Notifications\TechnicianCertificationExpiryNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendTechnicianCertificationAlerts extends Command
{
    protected $signature = 'erp:service:certification-alerts {--days=90 : Alert horizon in days} {--company= : Limit alerts to one company ID}';
    protected $description = 'Notify service/report users about expiring technician certifications.';

    public function handle(): int
    {
        $days = max(0, min(3650, (int) $this->option('days')));
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $today = Carbon::today();
        $cutoff = $today->copy()->addDays($days)->toDateString();
        $users = User::where('is_active', true)->with('roles.permissions')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->get()->filter(fn (User $user): bool => $user->hasPermission('service.manage') || $user->hasPermission('reports.view'));
        $sent = 0;
        ServiceTechnician::with('user')->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get()->each(function (ServiceTechnician $technician) use ($users, $today, $cutoff, &$sent): void {
            foreach ((array) ($technician->skill_certifications ?? []) as $certification) {
                $expiresOn = $certification['expires_on'] ?? null;
                $skill = strtolower(trim((string) ($certification['skill'] ?? '')));
                if (!$expiresOn || $skill === '' || (string) $expiresOn > $cutoff) continue;
                $recipients = $users->where('company_id', $technician->company_id);
                if ($technician->user?->is_active) $recipients = $recipients->push($technician->user);
                foreach ($recipients->unique('id') as $user) {
                    $duplicate = $user->notifications()->where('type', TechnicianCertificationExpiryNotification::class)->whereDate('created_at', $today)->whereJsonContains('data->technician_id', $technician->id)->whereJsonContains('data->skill', $skill)->exists();
                    if ($duplicate) continue;
                    $user->notify(new TechnicianCertificationExpiryNotification(['technician_id' => $technician->id, 'employee_code' => $technician->employee_code, 'user_name' => $technician->user?->name, 'skill' => $skill, 'certificate_no' => $certification['certificate_no'] ?? null, 'expires_on' => $expiresOn, 'is_expired' => (string) $expiresOn < $today->toDateString(), 'days_until_expiry' => $today->diffInDays(Carbon::parse($expiresOn), false)]));
                    $sent++;
                }
            }
        });
        $this->info("Created {$sent} technician certification alert(s).");
        return self::SUCCESS;
    }
}
