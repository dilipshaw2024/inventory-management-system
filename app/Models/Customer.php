<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Customer extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['credit_limit' => 'decimal:6', 'credit_days' => 'integer', 'credit_hold' => 'boolean', 'credit_hold_after_days' => 'integer', 'tax_exempt' => 'boolean', 'is_active' => 'boolean'];

    public function payments(){
        return $this->hasMany(Payment::class, 'customer_id');
    }
    public function contacts(){ return $this->hasMany(CustomerContact::class); }
    public function salesPriceList() { return $this->belongsTo(PriceList::class, 'sales_price_list_id'); }
    public function translations(){ return $this->hasMany(PartyTranslation::class, 'party_id')->where('party_type', 'customer'); }

    public function localizedName(?string $languageCode = null): string
    {
        $languageCode = PartyTranslation::normalizeLanguageCode($languageCode);
        $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();
        $preferred = $translations->first(fn (PartyTranslation $translation): bool => $translation->is_active && $translation->language_code === $languageCode);
        if ($preferred) return $preferred->name;
        $base = strtolower((string) strtok($languageCode, '-'));
        return $translations->first(fn (PartyTranslation $translation): bool => $translation->is_active && strtolower((string) strtok($translation->language_code, '-')) === $base)?->name ?: (string) $this->name;
    }
}
