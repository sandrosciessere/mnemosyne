<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryEntry extends Model
{
    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * Maximum RAW path bytes we retain. base64 expands 4/3, so 760 raw
     * bytes -> 1016 base64 chars, still within the varchar(1024) column and
     * its btree index. Paths longer than this are a pathological edge; they
     * are stored truncated (and will simply fail the import re-validation).
     */
    public const MAX_RAW_PATH_BYTES = 760;

    /**
     * Encode raw (possibly non-UTF-8) path bytes into the AUTHORITATIVE,
     * lossless, ASCII-safe value stored in `relative_path`. Uniqueness and
     * path reconstruction are both keyed off this value.
     */
    public static function encodeRelativePath(string $rawBytes): string
    {
        return base64_encode(substr($rawBytes, 0, self::MAX_RAW_PATH_BYTES));
    }

    /**
     * Decode `relative_path` back to the EXACT raw bytes, to rebuild the
     * absolute path at import time. Returns '' if the stored value is not
     * valid base64 (a forged/corrupt row), which import re-validation then
     * rejects.
     */
    public function rawRelativePath(): string
    {
        $decoded = base64_decode((string) $this->relative_path, true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Best-effort human-readable path (valid UTF-8; invalid bytes become
     * U+FFFD). Never authoritative — display/logs only.
     */
    public static function toDisplayString(string $bytes, int $limit = 1024): string
    {
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $safe = mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);

        return mb_substr($safe, 0, $limit);
    }

    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(BookSubmission::class, 'book_submission_id');
    }
}
