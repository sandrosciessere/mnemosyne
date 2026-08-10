<?php

namespace App\Models;

use App\Enums\BibliographicStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\WorkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Work extends Model
{
    /** @use HasFactory<WorkFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'status' => 'provisional',
    ];

    protected $fillable = [
        'canonical_title',
        'normalized_title',
        'original_language',
    ];

    protected function casts(): array
    {
        return [
            'status' => BibliographicStatus::class,
            'reconciliation' => 'array',
        ];
    }

    public function editions(): HasMany
    {
        return $this->hasMany(Edition::class);
    }

    public function assets(): HasManyThrough
    {
        return $this->hasManyThrough(BookAsset::class, Edition::class);
    }

    /**
     * Deterministic title key used for conservative reconciliation:
     * NFC-normalized, lowercased, punctuation collapsed to spaces.
     */
    public static function normalizeTitle(string $title): string
    {
        $normalized = class_exists(\Normalizer::class)
            ? (\Normalizer::normalize($title, \Normalizer::FORM_C) ?: $title)
            : $title;
        $normalized = mb_strtolower(trim($normalized));
        $normalized = preg_replace('/[\p{P}\p{S}]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }
}
