<?php

namespace App\Services\Answers;

/**
 * claim-type 1.0.0 — deterministic claim-type detection so the
 * ClaimEvidenceGate can apply stricter support rules where
 * hallucination is most dangerous. Not an ontology: the load-bearing
 * distinction is ATOMIC facts (identity / species / class membership /
 * name / date / quantity / explicit role or location) versus
 * inferential/interpretive material.
 *
 * Atomic facts must be DIRECTLY evidenced: "strong inference" can
 * never manufacture "X is a dog" from dogs standing next to X.
 */
class ClaimTypeClassifier
{
    public const VERSION = 'claim-type 1.0.0';

    public const ATOMIC_FACT = 'atomic_fact';

    public const CAUSAL_INFERENCE = 'causal_inference';

    public const INTERPRETIVE = 'interpretive_synthesis';

    public const GENERAL = 'general';

    public function classify(string $claimText): string
    {
        $text = mb_strtolower(trim($claimText));

        // Naming: "si chiama X", "is named/called X", "il nome ... è".
        if (preg_match('/\b(si chiama|si chiamava|il nome .{0,30}(è|era)|is (named|called)|name (is|was))\b/u', $text)) {
            return self::ATOMIC_FACT;
        }

        // Dates / quantities as the asserted value.
        if (preg_match('/\b(nel|nell\'anno|in the year|in)\s+\d{3,4}\b/u', $text)
            || preg_match('/\b(una|due|tre|quattro|cinque|sei|sette|otto|nove|dieci|\d+)\s+(volte|anni|giorni|mesi|figli|fratelli|times|years|days|months|sons|brothers)\b/u', $text)) {
            return self::ATOMIC_FACT;
        }

        // Explicit location assertions.
        if (preg_match('/\b(si trova (a|in|nel|nella)|vive (a|in|nel|nella)|abita (a|in)|is located in|lives in|takes place in)\b/u', $text)) {
            return self::ATOMIC_FACT;
        }

        // Copular identity / species / class / role: "X è un cane",
        // "X era l'autista", "the chauffeur is Y", "X assume/prende
        // l'identità di Y". Kept deliberately broad: identity claims
        // are exactly where association-based hallucination bites.
        if (preg_match('/\b(?:è|era|sono|erano|is|was|are|were)\s+(?:un|una|uno|il|lo|la|i|gli|le|a|an|the)\s+\p{L}/u', $text)
            || preg_match('/\b(?:è|era|is|was)\s+(?:un\'|l\')\p{L}/u', $text)
            || preg_match('/\b(assume|assumeva|prende|prese|assumes?|takes over|took over)\s+(l\'identità|la sua identità|l\'identita|the identity|his identity|her identity)\b/u', $text)) {
            return self::ATOMIC_FACT;
        }

        // Causal / consolidation inferences (verb-prefix matching:
        // contribuì/contribuirono/consolidare all count).
        if (preg_match('/\b(perché|poiché|contribu|rafforz|consolid|permett|porta a|caus|because|helps?|leads to|strengthen|allow|enabl)/u', $text)) {
            return self::CAUSAL_INFERENCE;
        }

        // Analytic/figurative readings.
        if (preg_match('/\b(rappresent|simboleggi|allegoria|metafor|suggerisc|riflett|represent|symboliz|allegory|suggest|reflect)/u', $text)) {
            return self::INTERPRETIVE;
        }

        return self::GENERAL;
    }
}
