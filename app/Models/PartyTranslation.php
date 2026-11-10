<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PartyTranslation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public static function normalizeLanguageCode(?string $languageCode): string
    {
        return ProductTranslation::normalizeLanguageCode($languageCode);
    }

    public function customer() { return $this->belongsTo(Customer::class, 'party_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class, 'party_id'); }
}
