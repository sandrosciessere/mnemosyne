<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $fillable = [];

    /**
     * The single authoritative predicate for a warning event that reflects
     * an actual book/content condition — as opposed to operational noise
     * (transient worker/infra failure retries carry `retry_in_seconds`).
     * Used both to decide the asset's ready(-with-warnings) status and to
     * build the admin-facing warning summary, so the two can never diverge.
     */
    public function scopeContentWarnings(Builder $query): Builder
    {
        return $query->where('type', 'stage.warning')
            ->whereNull('payload->retry_in_seconds');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(BookSubmission::class, 'book_submission_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(IngestionRun::class, 'ingestion_run_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Append an event to the timeline. The only supported write path:
     * events are never created from request payloads, so forceFill here
     * does not weaken mass-assignment protection.
     */
    public static function record(
        string $type,
        ?BookSubmission $submission = null,
        ?IngestionRun $run = null,
        ?User $actor = null,
        array $payload = [],
    ): self {
        $event = new self;
        $event->forceFill([
            'type' => $type,
            'book_submission_id' => $submission?->id ?? $run?->book_submission_id,
            'ingestion_run_id' => $run?->id,
            'actor_user_id' => $actor?->id,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ])->save();

        return $event;
    }
}
