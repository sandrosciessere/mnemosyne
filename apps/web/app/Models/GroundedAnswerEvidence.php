<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GroundedAnswerEvidence extends Model
{
    protected $table = 'grounded_answer_evidence';

    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'heading_path' => 'array',
            'retrieval_meta' => 'array',
            'ordinal' => 'integer',
            'citation_number' => 'integer',
            'spine_index' => 'integer',
            'canonical_start' => 'integer',
            'canonical_end' => 'integer',
            'utf16_start' => 'integer',
            'utf16_end' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(GroundedAnswerRun::class, 'grounded_answer_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(BookAsset::class, 'book_asset_id');
    }

    public function claims(): BelongsToMany
    {
        return $this->belongsToMany(
            GroundedAnswerClaim::class,
            'grounded_answer_claim_evidence',
            'grounded_answer_evidence_id',
            'grounded_answer_claim_id',
        )->withPivot('atoms');
    }
}
