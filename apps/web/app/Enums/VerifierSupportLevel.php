<?php

namespace App\Enums;

/**
 * Independent verifier judgment per claim. The VERIFIER decides the
 * final epistemic category; the generator's suggestion is advisory.
 */
enum VerifierSupportLevel: string
{
    case Direct = 'direct';
    case Strong = 'strong';
    case Interpretive = 'interpretive';
    case None = 'none';
    case Conflict = 'conflict';

    /**
     * Application mapping to the final user-facing label. `none` maps to
     * null — an unsupported claim never gets a supported label (it is
     * removed or surfaces as insufficient evidence).
     */
    public function toEpistemicLabel(): ?EpistemicLabel
    {
        return match ($this) {
            self::Direct => EpistemicLabel::TextualFact,
            self::Strong => EpistemicLabel::StrongInference,
            self::Interpretive => EpistemicLabel::Interpretation,
            self::Conflict => EpistemicLabel::Conflict,
            self::None => null,
        };
    }
}
