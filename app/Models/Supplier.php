<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Supplier extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['payment_terms_days' => 'integer', 'rating' => 'decimal:2', 'tax_exempt' => 'boolean', 'tax_exemption_expires_at' => 'date', 'is_active' => 'boolean', 'planning_calendar' => 'array'];

    public function taxExemptionIsActive(?CarbonInterface $asOf = null): bool
    {
        return (bool) $this->tax_exempt && (!$this->tax_exemption_expires_at || $this->tax_exemption_expires_at->greaterThanOrEqualTo($asOf ?: now()));
    }

    public function products(){
        return $this->hasMany(Product::class, 'supplier_id');
    }

    public function purchases(){
        return $this->hasMany(Purchase::class, 'supplier_id');
    }
    public function purchasePriceList() { return $this->belongsTo(PriceList::class, 'purchase_price_list_id'); }
    public function contacts() { return $this->hasMany(SupplierContact::class); }
    public function translations(){ return $this->hasMany(PartyTranslation::class, 'party_id')->where('party_type', 'supplier'); }

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
