<?php

namespace App\Models;

use App\Enums\BibliographicStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\EditionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Edition extends Model
{
    /** @use HasFactory<EditionFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'status' => 'provisional',
    ];

    protected $fillable = [
        'title',
        'subtitle',
        'language',
        'publisher',
        'publication_date',
        'publication_year',
        'edition_statement',
        'description',
        'rights',
        'subjects',
        'source_metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => BibliographicStatus::class,
            'subjects' => 'array',
            'source_metadata' => 'array',
            'publication_year' => 'integer',
        ];
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(BookAsset::class);
    }

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(Contributor::class, 'edition_contributors')
            ->withPivot(['role', 'credited_as', 'position'])
            ->orderByPivot('position')
            ->withTimestamps();
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(EditionIdentifier::class);
    }
}
