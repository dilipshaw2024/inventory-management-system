<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Models\Concerns\BelongsToCompany;

class ServiceTechnician extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['skills' => 'array', 'skill_certifications' => 'array', 'working_calendar' => 'array', 'hourly_rate' => 'decimal:6', 'is_available' => 'boolean'];
    public function user() { return $this->belongsTo(User::class); }

    public function hasSkill(string $skill, ?Carbon $asOf = null): bool
    {
        $normalized = strtolower(trim($skill));
        if ($normalized === '') return false;
        $skills = collect($this->skills ?? [])->map(fn ($value): string => strtolower(trim((string) $value)))->filter()->unique();
        if (!$skills->contains($normalized)) return false;
        $certifications = collect($this->skill_certifications ?? [])->filter(fn ($certification): bool => is_array($certification) && strtolower(trim((string) ($certification['skill'] ?? ''))) === $normalized);
        if ($certifications->isEmpty()) return true;
        $date = ($asOf ?: now())->toDateString();
        return $certifications->contains(fn (array $certification): bool => empty($certification['expires_on']) || (string) $certification['expires_on'] >= $date);
    }
}
