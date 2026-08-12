<?php

namespace App\Services\Retrieval;

/**
 * In-memory result of deterministic chunking, ready for persistence.
 * `spans` entries are provenance pieces:
 * {source_node_id, spine_index, href, fragment, node_type, heading_path,
 *  canonical_start, canonical_end, utf16_start, utf16_end,
 *  chunk_start, chunk_end, source_hash}.
 */
class ChunkDraft
{
    public function __construct(
        public readonly int $ordinal,
        public readonly int $spineIndex,
        public readonly array $headingPath,
        public readonly string $sourceText,
        public readonly array $spans,
        public readonly int $canonicalStart,
        public readonly int $canonicalEnd,
        public readonly int $overlapPrefixChars,
        public readonly string $contentSha256,
    ) {}

    public function charCount(): int
    {
        return mb_strlen($this->sourceText);
    }
}
