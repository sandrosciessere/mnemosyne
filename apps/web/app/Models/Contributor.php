<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\ContributorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contributor extends Model
{
    /** @use HasFactory<ContributorFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [
        'name',
        'sort_name',
        'normalized_name',
    ];

    public function editions(): BelongsToMany
    {
        return $this->belongsToMany(Edition::class, 'edition_contributors')
            ->withPivot(['role', 'credited_as', 'position'])
            ->withTimestamps();
    }

    public static function normalizeName(string $name): string
    {
        $normalized = class_exists(\Normalizer::class)
            ? (\Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name)
            : $name;
        $normalized = mb_strtolower(trim($normalized));

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }
}
