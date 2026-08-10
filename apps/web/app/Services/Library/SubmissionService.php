<?php

namespace App\Services\Library;

use App\Enums\IngestionEventType;
use App\Enums\IngestionPriority;
use App\Enums\SubmissionSourceType;
use App\Enums\SubmissionStatus;
use App\Exceptions\Library\InvalidTransitionException;
use App\Exceptions\Library\StorageException;
use App\Models\BookSubmission;
use App\Models\IngestionEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Ingestion\IngestionOrchestrator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SubmissionService
{
    public function __construct(
        private readonly LibraryStorage $storage,
        private readonly IngestionOrchestrator $orchestrator,
    ) {}

    /**
     * Authenticated upload path. Creates the submission, stores the file
     * under library/incoming/, and auto-approves when (and only when) the
     * persistent system setting enables it.
     */
    public function createFromUpload(User $user, UploadedFile $file, ?string $note = null): BookSubmission
    {
        $size = $file->getSize() ?: 0;

        if (! $this->storage->hasFreeSpaceFor($size)) {
            throw new StorageException(
                'INSUFFICIENT_STORAGE',
                'Not enough free space to accept this upload right now.',
            );
        }

        $submission = new BookSubmission;
        $submission->forceFill([
            'user_id' => $user->id,
            'source_type' => SubmissionSourceType::Upload,
            'original_filename' => mb_substr($file->getClientOriginalName() ?: 'book.epub', 0, 500),
            'note' => $note,
            'status' => SubmissionStatus::PendingApproval,
            'priority' => IngestionPriority::Normal,
            'upload_size_bytes' => $size,
        ])->save();

        $incoming = $this->storage->storeUpload($submission, $file);
        $submission->forceFill(['incoming_path' => $incoming])->save();

        IngestionEvent::record(IngestionEventType::SubmissionCreated, $submission, actor: $user, payload: [
            'source_type' => SubmissionSourceType::Upload->value,
            'size_bytes' => $size,
        ]);

        if (SystemSetting::autoApprovalEnabled()) {
            $this->approve($submission, actor: null, auto: true);
        }

        return $submission->refresh();
    }

    /**
     * Filesystem discovery path (mnemosyne:library:discover). The file is
     * NOT copied here; the hash stage reads it from the allowlisted import
     * root recorded in source_reference.
     */
    public function createFromFilesystem(
        string $incomingRelativePath,
        string $originalFilename,
        array $sourceReference,
        IngestionPriority $priority = IngestionPriority::Low,
    ): BookSubmission {
        $submission = new BookSubmission;
        $submission->forceFill([
            'user_id' => null,
            'source_type' => SubmissionSourceType::Filesystem,
            'source_reference' => $sourceReference,
            'original_filename' => mb_substr($originalFilename, 0, 500),
            'status' => SubmissionStatus::PendingApproval,
            'priority' => $priority,
            'incoming_path' => $incomingRelativePath,
        ])->save();

        IngestionEvent::record(IngestionEventType::SubmissionCreated, $submission, payload: [
            'source_type' => SubmissionSourceType::Filesystem->value,
            'source_reference' => $sourceReference,
        ]);

        if (SystemSetting::autoApprovalEnabled()) {
            $this->approve($submission, actor: null, auto: true);
        }

        return $submission->refresh();
    }

    public function approve(BookSubmission $submission, ?User $actor, bool $auto = false): void
    {
        DB::transaction(function () use ($submission, $actor, $auto) {
            $submission = BookSubmission::query()->lockForUpdate()->findOrFail($submission->id);

            if ($submission->status !== SubmissionStatus::PendingApproval) {
                throw new InvalidTransitionException(
                    'SUBMISSION_NOT_PENDING',
                    'Only pending submissions can be approved.',
                );
            }

            $submission->forceFill([
                'status' => SubmissionStatus::Approved,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ])->save();

            IngestionEvent::record(
                $auto ? IngestionEventType::SubmissionAutoApproved : IngestionEventType::SubmissionApproved,
                $submission,
                actor: $actor,
            );

            $this->orchestrator->startRun($submission);
        });
    }

    public function reject(BookSubmission $submission, User $actor, string $reason): void
    {
        DB::transaction(function () use ($submission, $actor, $reason) {
            $submission = BookSubmission::query()->lockForUpdate()->findOrFail($submission->id);

            if ($submission->status !== SubmissionStatus::PendingApproval) {
                throw new InvalidTransitionException(
                    'SUBMISSION_NOT_PENDING',
                    'Only pending submissions can be rejected.',
                );
            }

            $submission->forceFill([
                'status' => SubmissionStatus::Rejected,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => mb_substr($reason, 0, 1000),
            ])->save();

            IngestionEvent::record(IngestionEventType::SubmissionRejected, $submission, actor: $actor, payload: [
                'reason' => mb_substr($reason, 0, 1000),
            ]);
        });
        // Retention of the rejected incoming file is a future policy
        // decision (documented in docs/architecture/epub-ingestion.md);
        // nothing is deleted automatically in this milestone.
    }

    /**
     * Cancel a submission. Before approval this is a plain status change;
     * after approval it delegates to cooperative run cancellation.
     */
    public function cancel(BookSubmission $submission, ?User $actor = null): void
    {
        DB::transaction(function () use ($submission, $actor) {
            $submission = BookSubmission::query()->lockForUpdate()->findOrFail($submission->id);

            if ($submission->status === SubmissionStatus::PendingApproval) {
                $submission->forceFill(['status' => SubmissionStatus::Cancelled])->save();
                IngestionEvent::record(IngestionEventType::SubmissionCancelled, $submission, actor: $actor);

                return;
            }

            $activeRun = $submission->runs()
                ->whereIn('status', ['queued', 'running', 'needs_review'])
                ->first();

            if ($activeRun === null) {
                throw new InvalidTransitionException(
                    'SUBMISSION_NOT_CANCELLABLE',
                    'This submission has no pending approval and no active run.',
                );
            }

            $this->orchestrator->requestCancel($activeRun, $actor);
        });
    }
}
