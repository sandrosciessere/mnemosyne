<?php

namespace App\Enums;

/**
 * Canonical query intents (query-intent classifier). M3 provides honest
 * bounded support for the first four; the remaining three are DETECTED
 * so the answer can carry an explicit capability notice instead of
 * pretending a shallow Top-K answer is complete coverage (they need
 * M4 enrichment / M5 deep analysis).
 */
enum QueryIntent: string
{
    case PointLookup = 'point_lookup';
    case LocalExplanation = 'local_explanation';
    case GlobalSummary = 'global_summary';
    case Longitudinal = 'longitudinal';
    case ComparativeMultiBook = 'comparative_multi_book';
    case QuoteLocation = 'quote_location';
    case TrickyInference = 'tricky_inference';

    /** Fully supported by the M3 bounded pipeline. */
    public function isSupportedInM3(): bool
    {
        return match ($this) {
            self::PointLookup,
            self::LocalExplanation,
            self::QuoteLocation,
            self::ComparativeMultiBook => true,
            default => false,
        };
    }

    /** Capability notice code for intents beyond M3 (null when supported). */
    public function capabilityNotice(): ?string
    {
        return $this->isSupportedInM3() ? null : 'LIMITED_'.strtoupper($this->value).'_SUPPORT';
    }
}
