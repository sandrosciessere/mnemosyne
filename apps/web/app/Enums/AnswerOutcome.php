<?php

namespace App\Enums;

/**
 * Terminal answer semantics (distinct from pipeline status): a
 * successful run may still honestly conclude that the evidence does not
 * support an answer. Insufficient evidence is a SUCCESS state, not an
 * error.
 */
enum AnswerOutcome: string
{
    case Answered = 'answered';
    case PartiallyAnswered = 'partially_answered';
    case InsufficientEvidence = 'insufficient_evidence';
    // The question is materially ambiguous/incomplete: ask the user to
    // rephrase instead of guessing a referent. NOT a failure — the run
    // terminates as `ready` with this outcome, cheaply.
    case NeedsClarification = 'needs_clarification';
}
