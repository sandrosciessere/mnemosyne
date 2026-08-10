<?php

namespace App\Services\Library;

use App\Exceptions\Library\StorageException;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * All library filesystem access goes through this service. Paths handed
 * out or persisted are ALWAYS relative to the data disk; nothing here
 * accepts a client-provided path.
 */
class LibraryStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk('data');
    }

    public function incomingPathFor(BookSubmission $submission): string
    {
        return 'library/incoming/'.$submission->public_id.'/source.epub';
    }

    /**
     * Move an uploaded EPUB into the incoming area. UploadedFile::storeAs
     * streams/moves the PHP temp file — the file is never loaded into
     * memory.
     */
    public function storeUpload(BookSubmission $submission, UploadedFile $file): string
    {
        $relative = $this->incomingPathFor($submission);

        $stored = $file->storeAs(
            dirname($relative),
            basename($relative),
            ['disk' => 'data'],
        );

        if ($stored === false) {
            throw new StorageException('UPLOAD_STORE_FAILED', 'Could not store the uploaded file.');
        }

        return $relative;
    }

    /**
     * Promote a validated incoming file to immutable content-addressed
     * original storage. The destination is addressed by $sha256, so the
     * bytes written there MUST hash to $sha256 — this is re-verified during
     * the copy. If the incoming file changed between the hash stage and
     * promotion (partial write, re-stage, corruption), promotion refuses
     * rather than poison the content-addressed store under the wrong
     * address.
     *
     * Idempotent: if the destination already exists (exact duplicate or a
     * retried stage) its bytes are re-verified against $sha256 and reused;
     * originals are never overwritten.
     */
    public function promoteToOriginal(string $incomingRelative, string $sha256): string
    {
        $destination = BookAsset::originalStoragePath($sha256);
        $disk = $this->disk();

        if ($disk->exists($destination)) {
            // Content-addressed by construction, but verify before trusting
            // a pre-existing original — a mismatch means the store is
            // already corrupt and must never be silently reused.
            $existingHash = hash_file('sha256', $this->absolutePath($destination));
            if ($existingHash !== $sha256) {
                throw new StorageException(
                    'ORIGINAL_HASH_MISMATCH',
                    'An existing original does not hash to its content address; refusing to reuse.',
                );
            }

            return $destination;
        }

        if (! $disk->exists($incomingRelative)) {
            throw new StorageException('INCOMING_FILE_MISSING', 'Incoming file is gone before promotion.');
        }

        $directory = dirname($destination);
        $disk->makeDirectory($directory);

        // Copy to a unique temp name in the destination directory, hashing
        // the bytes as they land, then atomic rename. A crash between the
        // two leaves only a harmless temp file (reaped by cleanup).
        $temp = $directory.'/.tmp-'.Str::ulid()->toBase32().'-'.basename($destination);
        $source = $disk->readStream($incomingRelative);
        if ($source === null) {
            throw new StorageException('INCOMING_FILE_UNREADABLE', 'Cannot open incoming file.');
        }

        try {
            $disk->writeStream($temp, $source);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        $writtenHash = hash_file('sha256', $this->absolutePath($temp));
        if ($writtenHash !== $sha256) {
            $disk->delete($temp);

            throw new StorageException(
                'PROMOTION_HASH_MISMATCH',
                'The file to promote no longer hashes to its expected content address; not promoting.',
            );
        }

        try {
            if (! $disk->move($temp, $destination)) {
                throw new StorageException('PROMOTION_FAILED', 'Atomic promotion to original storage failed.');
            }
        } catch (\Throwable $exception) {
            $disk->delete($temp);

            if ($exception instanceof StorageException) {
                throw $exception;
            }

            // Lost a race to an identical concurrent promotion: the
            // destination now exists with identical (verified) bytes.
            if ($disk->exists($destination)) {
                return $destination;
            }

            throw new StorageException('PROMOTION_FAILED', 'Atomic promotion to original storage failed: '.$exception->getMessage());
        }

        return $destination;
    }

    /** Remove a submission's incoming directory (after success/terminal failure). */
    public function cleanupIncoming(BookSubmission $submission): void
    {
        // Filesystem/selftest submissions stage under their own ULID dir,
        // not the submission's — always derive from the recorded path.
        $directory = $submission->incoming_path !== null
            ? dirname($submission->incoming_path)
            : 'library/incoming/'.$submission->public_id;

        if (str_starts_with($directory, 'library/incoming/') && $directory !== 'library/incoming') {
            $this->disk()->deleteDirectory($directory);
        }
    }

    public function absolutePath(string $relative): string
    {
        return $this->disk()->path($relative);
    }

    /**
     * Cheap guard against accepting uploads on a nearly-full disk. Not a
     * quota system — just prevents trivially avoidable disk-full failures.
     */
    public function hasFreeSpaceFor(int $bytes): bool
    {
        $root = config('mnemosyne.data_path');
        $free = is_dir($root) ? @disk_free_space($root) : false;

        if ($free === false) {
            return true; // Cannot measure: do not block uploads on it.
        }

        return $free >= ($bytes + (int) config('mnemosyne.ingestion.min_free_disk_bytes'));
    }
}
