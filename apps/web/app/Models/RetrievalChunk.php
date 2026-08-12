<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RetrievalChunk extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'heading_path' => 'array',
            'ordinal' => 'integer',
            'spine_index' => 'integer',
            'char_count' => 'integer',
            'token_estimate' => 'integer',
            'canonical_start' => 'integer',
            'canonical_end' => 'integer',
            'overlap_prefix_chars' => 'integer',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(RetrievalGeneration::class, 'retrieval_generation_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(BookAsset::class, 'book_asset_id');
    }

    public function spans(): HasMany
    {
        return $this->hasMany(RetrievalEvidenceSpan::class)->orderBy('span_ordinal');
    }

    public function embedding(): HasOne
    {
        return $this->hasOne(RetrievalEmbedding::class);
    }
}
