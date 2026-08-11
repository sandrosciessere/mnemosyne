<?php

namespace App\Models;

use App\Enums\IngestionStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionStageAttempt extends Model
{
    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'stage' => IngestionStage::class,
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'result_summary' => 'array',
            'worker_meta' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(IngestionRun::class, 'ingestion_run_id');
    }
}
