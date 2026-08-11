<?php

namespace App\Models;

use App\Enums\AssetIngestionStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\BookAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookAsset extends Model
{
    /** @use HasFactory<BookAssetFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'validation_status' => 'pending',
        'ingestion_status' => 'pending',
        'mime_type' => 'application/epub+zip',
    ];

    protected $fillable = [
        'sha256',
        'original_filename',
        'size_bytes',
        'mime_type',
    ];

    protected function casts(): array
    {
        return [
            'ingestion_status' => AssetIngestionStatus::class,
            'extracted_metadata' => 'array',
            'structure_summary' => 'array',
            'reconciliation' => 'array',
            'size_bytes' => 'integer',
            'uncompressed_size_bytes' => 'integer',
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(BookSubmission::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(IngestionRun::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(BookAccessGrant::class);
    }

    /** Candidates where this asset is the newly ingested side. */
    public function duplicateCandidates(): HasMany
    {
        return $this->hasMany(DuplicateCandidate::class);
    }

    /** Candidates where this asset is the pre-existing side. */
    public function duplicateOfCandidates(): HasMany
    {
        return $this->hasMany(DuplicateCandidate::class, 'duplicate_of_asset_id');
    }

    /**
     * Content-addressed location relative to the data root. Sharded two
     * levels deep so no directory ever holds millions of files.
     */
    public static function originalStoragePath(string $sha256): string
    {
        return sprintf(
            'library/original/sha256/%s/%s/%s.epub',
            substr($sha256, 0, 2),
            substr($sha256, 2, 2),
            $sha256,
        );
    }

    /** Artifact directory (relative to data root) for a pipeline version. */
    public function artifactDir(string $pipelineVersion): string
    {
        return sprintf('library/extracted/%s/v%s', $this->public_id, $pipelineVersion);
    }
}
