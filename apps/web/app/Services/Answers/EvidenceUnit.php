<?php

namespace App\Services\Answers;

/**
 * One citeable evidence unit: an exact canonical source slice with full
 * provenance. The model only ever sees the opaque key (E1, E2, ...) and
 * the text; every other field is application-owned provenance.
 *
 * Invariant: text == canonical_source[canonicalStart:canonicalEnd]
 * (codepoints) == utf16_slice(utf16Start, utf16End).
 */
class EvidenceUnit
{
    public string $key = '';

    public ?int $citationNumber = null;

    public function __construct(
        public readonly int $bookAssetId,
        public readonly string $bookPublicId,
        public readonly ?string $bookTitle,
        public readonly ?string $workTitle,
        public readonly ?string $editionLabel,
        public readonly ?string $sourceNodeId,
        public readonly int $spineIndex,
        public readonly ?string $sourceHref,
        public readonly ?string $sourceFragment,
        public readonly ?string $nodeType,
        public readonly array $headingPath,
        public readonly int $canonicalStart,
        public readonly int $canonicalEnd,
        public readonly int $utf16Start,
        public readonly int $utf16End,
        public readonly ?string $sourceHash,
        public readonly string $sourceContentSha256,
        public readonly string $text,
        public array $retrievalMeta,
    ) {}

    /**
     * Deterministic citeable source atoms (sentence-level) inside this
     * unit, keyed S1..Sn. Computed once by the unitizer; the model may
     * only reference these IDs — the application owns every coordinate.
     *
     * @var array<string, array{key: string, canonical_start: int, canonical_end: int,
     *                          utf16_start: int, utf16_end: int, text: string}>
     */
    public array $atoms = [];

    /** Overlap-dedupe identity: same canonical evidence appears once. */
    public function identity(): string
    {
        return $this->bookAssetId.':'.$this->canonicalStart.':'.$this->canonicalEnd.':'.($this->sourceHash ?? '-');
    }

    public function textHash(): string
    {
        return hash('sha256', $this->text);
    }

    public function charCount(): int
    {
        return mb_strlen($this->text);
    }
}
