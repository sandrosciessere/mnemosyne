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
}
