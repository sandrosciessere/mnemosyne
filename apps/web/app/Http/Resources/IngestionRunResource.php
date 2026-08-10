<?php

namespace App\Http\Resources;

use App\Models\IngestionRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IngestionRun
 */
class IngestionRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'current_stage' => $this->current_stage?->value,
            'priority' => $this->priority->value,
            'progress' => $this->progress,
            'pipeline_version' => $this->pipeline_version,
            'cancel_requested' => $this->cancel_requested,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'error_code' => $this->last_error_code,
            'error_message' => $this->last_error_message,
            'review_issues' => $this->review_issues ?? [],
            'submission' => $this->whenLoaded('submission', fn () => [
                'id' => $this->submission->public_id,
                'original_filename' => $this->submission->original_filename,
                'source_type' => $this->submission->source_type->value,
            ]),
            'asset' => $this->whenLoaded('asset', fn () => $this->asset === null ? null : [
                'id' => $this->asset->public_id,
                'sha256' => $this->asset->sha256,
                'content_sha256' => $this->asset->content_sha256,
                'ingestion_status' => $this->asset->ingestion_status->value,
            ]),
            'attempts' => $this->whenLoaded('attempts', fn () => $this->attempts->map(fn ($attempt) => [
                'stage' => $attempt->stage->value,
                'attempt' => $attempt->attempt,
                'status' => $attempt->status,
                'handler_version' => $attempt->handler_version,
                'duration_ms' => $attempt->duration_ms,
                'error_code' => $attempt->error_code,
            ])->all()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'type' => $event->type,
                'payload' => $event->payload,
                'created_at' => $event->created_at->toIso8601String(),
            ])->all()),
        ];
    }
}
