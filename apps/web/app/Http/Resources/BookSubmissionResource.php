<?php

namespace App\Http\Resources;

use App\Models\BookSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BookSubmission
 */
class BookSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $run = $this->latestRun;

        return [
            'id' => $this->public_id,
            'original_filename' => $this->original_filename,
            'status' => $this->derivedStatus(),
            'approval_status' => $this->status->value,
            'source_type' => $this->source_type->value,
            'priority' => $this->priority->value,
            'note' => $this->note,
            'rejection_reason' => $this->rejection_reason,
            'is_exact_duplicate' => $this->is_exact_duplicate,
            'created_at' => $this->created_at->toIso8601String(),
            'run' => $run === null ? null : [
                'id' => $run->public_id,
                'status' => $run->status->value,
                'current_stage' => $run->current_stage?->value,
                'progress' => $run->progress,
                'error_code' => $run->last_error_code,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ],
            'asset' => $this->whenLoaded('asset', fn () => $this->asset === null ? null : [
                'id' => $this->asset->public_id,
                'sha256' => $this->asset->sha256,
                'ingestion_status' => $this->asset->ingestion_status->value,
                'epub_version' => $this->asset->epub_version,
            ]),
        ];
    }
}
