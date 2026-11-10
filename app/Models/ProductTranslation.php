<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductTranslation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public static function normalizeLanguageCode(?string $languageCode): string
    {
        $languageCode = str_replace('_', '-', trim((string) $languageCode));
        $parts = array_values(array_filter(explode('-', $languageCode), fn (string $part): bool => $part !== ''));
        if ($parts === []) return '';
        $normalized = [strtolower($parts[0])];
        foreach (array_slice($parts, 1) as $part) {
            $normalized[] = strlen($part) === 2 || (strlen($part) === 3 && ctype_digit($part)) ? strtoupper($part) : ucfirst(strtolower($part));
        }
        return implode('-', $normalized);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
