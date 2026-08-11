<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetrievalAssetState extends Model
{
    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'chunk_count' => 'integer',
            'embedded_count' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
}
