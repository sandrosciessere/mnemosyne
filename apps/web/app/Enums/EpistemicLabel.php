<?php

namespace App\Enums;

/**
 * The five user-facing epistemic outcomes. These labels ARE the
 * confidence representation — no fake percentages anywhere.
 */
enum EpistemicLabel: string
{
    case TextualFact = 'textual_fact';
    case StrongInference = 'strong_inference';
    case Interpretation = 'interpretation';
    case InsufficientEvidence = 'insufficient_evidence';
    case Conflict = 'conflict';

    /** Italian user-facing label (product language). */
    public function userLabel(): string
    {
        return match ($this) {
            self::TextualFact => 'Fatto testuale',
            self::StrongInference => 'Deduzione forte',
            self::Interpretation => 'Interpretazione',
            self::InsufficientEvidence => 'Evidenza insufficiente',
            self::Conflict => 'Contraddizione rilevata',
        };
    }
}
