<?php

namespace App\Models;

use App\Enums\AnswerOutcome;
use App\Enums\AnswerRunStatus;
use App\Enums\QueryIntent;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroundedAnswerRun extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => AnswerRunStatus::class,
            'outcome' => AnswerOutcome::class,
            'classified_intent' => QueryIntent::class,
            'retrieval_diagnostics' => 'array',
            'evidence_stats' => 'array',
            'timings_ms' => 'array',
            'retrieval_expansion_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'user_message_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(RetrievalGeneration::class, 'retrieval_generation_id');
    }

    public function scopeAssets(): BelongsToMany
    {
        return $this->belongsToMany(
            BookAsset::class,
            'grounded_answer_scopes',
            'grounded_answer_run_id',
            'book_asset_id',
        )->withTimestamps();
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(GroundedAnswerEvidence::class)->orderBy('ordinal');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(GroundedAnswerClaim::class)->orderBy('ordinal');
    }
}
