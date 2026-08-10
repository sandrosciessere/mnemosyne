<?php

namespace App\Enums;

/**
 * Coarse lifecycle of a BookAsset. READY_FOR_ENRICHMENT is the terminal
 * state of the current pipeline: the asset is structurally understood but
 * not yet semantically enriched (no embeddings, summaries, entities).
 */
enum AssetIngestionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
    case ReadyForEnrichment = 'ready_for_enrichment';
    // Structurally complete, but recoverable warnings were recorded: not
    // interchangeable with a completely clean book.
    case ReadyForEnrichmentWithWarnings = 'ready_for_enrichment_with_warnings';
    // An administrator intentionally marked this book unsupported/skipped.
    case Unsupported = 'unsupported';

    public function isReadyForEnrichment(): bool
    {
        return $this === self::ReadyForEnrichment
            || $this === self::ReadyForEnrichmentWithWarnings;
    }
}
