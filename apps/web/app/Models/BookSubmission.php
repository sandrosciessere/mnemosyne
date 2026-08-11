<?php

namespace App\Models;

use App\Enums\IngestionPriority;
use App\Enums\SubmissionSourceType;
use App\Enums\SubmissionStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\BookSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BookSubmission extends Model
{
    /** @use HasFactory<BookSubmissionFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * Deliberately narrow: status, priority, approval fields, asset link
     * and paths are set exclusively by domain services — a client payload
     * must never be able to touch them.
     */
    protected $fillable = [
        'original_filename',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'source_type' => SubmissionSourceType::class,
            'priority' => IngestionPriority::class,
            'source_reference' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'upload_size_bytes' => 'integer',
            'is_exact_duplicate' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(BookAsset::class, 'book_asset_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(IngestionRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(IngestionRun::class)->latestOfMany();
    }

    public function events(): HasMany
    {
        return $this->hasMany(IngestionEvent::class)->orderBy('id');
    }

    /**
     * Single display status merging the approval lifecycle with the latest
     * run, for lists and dashboards. Load latestRun eagerly before calling
     * this in a loop.
     */
    public function derivedStatus(): string
    {
        if ($this->status !== SubmissionStatus::Approved) {
            return $this->status->value;
        }

        $run = $this->latestRun;

        return match ($run?->status?->value) {
            null => 'approved',
            'queued' => 'queued',
            'running' => 'processing',
            'paused' => 'paused',
            'needs_review' => 'needs_review',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'skipped' => 'unsupported',
            'succeeded' => 'completed',
        };
    }
}
