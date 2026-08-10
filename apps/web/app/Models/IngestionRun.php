<?php

namespace App\Models;

use App\Enums\IngestionPriority;
use App\Enums\IngestionRunStatus;
use App\Enums\IngestionStage;
use App\Models\Concerns\HasPublicId;
use Database\Factories\IngestionRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestionRun extends Model
{
    /** @use HasFactory<IngestionRunFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => IngestionRunStatus::class,
            'current_stage' => IngestionStage::class,
            'priority' => IngestionPriority::class,
            'progress' => 'integer',
            'cancel_requested' => 'boolean',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'review_issues' => 'array',
            'overridden_issues' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(BookSubmission::class, 'book_submission_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(BookAsset::class, 'book_asset_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(IngestionStageAttempt::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IngestionEvent::class)->orderBy('id');
    }

    public function nextAttemptNumber(IngestionStage $stage): int
    {
        return 1 + (int) $this->attempts()->where('stage', $stage->value)->max('attempt');
    }
}
