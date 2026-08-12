<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetrievalEvidenceSpan extends Model
{
    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'heading_path' => 'array',
            'span_ordinal' => 'integer',
            'spine_index' => 'integer',
            'canonical_start' => 'integer',
            'canonical_end' => 'integer',
            'utf16_start' => 'integer',
            'utf16_end' => 'integer',
            'chunk_start' => 'integer',
            'chunk_end' => 'integer',
        ];
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(RetrievalChunk::class, 'retrieval_chunk_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(BookAsset::class, 'book_asset_id');
    }

    /** API/UI representation (stable contract for Milestone 3). */
    public function toProvenanceArray(): array
    {
        return [
            'source_node_id' => $this->source_node_id,
            'spine_index' => $this->spine_index,
            'href' => $this->href,
            'fragment' => $this->fragment,
            'node_type' => $this->node_type,
            'heading_path' => $this->heading_path ?? [],
            'canonical_start' => $this->canonical_start,
            'canonical_end' => $this->canonical_end,
            'utf16_start' => $this->utf16_start,
            'utf16_end' => $this->utf16_end,
            'chunk_start' => $this->chunk_start,
            'chunk_end' => $this->chunk_end,
            'source_hash' => $this->source_hash,
        ];
    }
}
