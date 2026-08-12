<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetrievalEmbedding extends Model
{
    protected $guarded = ['*'];

    protected $fillable = [];

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(RetrievalChunk::class, 'retrieval_chunk_id');
    }
}
