<?php

namespace App\Models;

use App\Enums\DuplicateCandidateStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateCandidate extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $fillable = [];

    /**
     * A candidate is an UNORDERED pair of assets: (A,B) and (B,A) are the
     * same signal. asset_low_id/asset_high_id hold the pair in canonical
     * order and carry the DB-enforced symmetric unique; the directional
     * book_asset_id/duplicate_of_asset_id are kept for provenance/display
     * and are always written in the same canonical order.
     *
     * @return array{0: int, 1: int} [low, high]
     */
    public static function orderedPair(int $a, int $b): array
    {
        return $a <= $b ? [$a, $b] : [$b, $a];
    }

    /** Scope to the canonical pair regardless of the order supplied. */
    public function scopeForPair(Builder $query, int $a, int $b): Builder
    {
        [$low, $high] = self::orderedPair($a, $b);

        return $query->where('asset_low_id', $low)->where('asset_high_id', $high);
    }

    protected function casts(): array
    {
        return [
            'status' => DuplicateCandidateStatus::class,
            'evidence' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(BookAsset::class, 'book_asset_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(BookAsset::class, 'duplicate_of_asset_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
