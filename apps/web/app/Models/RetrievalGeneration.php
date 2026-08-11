<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable-once-active retrieval profile (chunker + lexical + embedding
 * + fusion + reranker configuration). See ADR/docs: a changed component
 * means a NEW generation; queries always execute against exactly one.
 */
class RetrievalGeneration extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    public function assetStates(): HasMany
    {
        return $this->hasMany(RetrievalAssetState::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(RetrievalChunk::class);
    }

    public static function active(): ?self
    {
        return static::query()->where('status', 'active')->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Name of this generation's partial HNSW ANN index. */
    public function annIndexName(): string
    {
        return 'retrieval_emb_hnsw_gen_'.$this->id;
    }
}
