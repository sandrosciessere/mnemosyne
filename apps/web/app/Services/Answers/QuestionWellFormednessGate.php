<?php

namespace App\Services\Answers;

/**
 * QuestionWellFormednessGate 1.0.0 — detects MATERIALLY malformed
 * questions before any expensive retrieval/generation. High-confidence
 * patterns only: a determiner left dangling with no noun ("Come si
 * evolve il a livello di forma?") can never be answered by guessing a
 * referent. Normal shorthand, pronouns and typos must NOT trigger.
 *
 * Conversation history may make a question answerable ("e il violino?"
 * after discussing it), but resolution stays conversational context —
 * never evidence. This gate only fires on patterns no context can fix
 * (the dangling determiner still has no noun regardless of history).
 */
class QuestionWellFormednessGate
{
    public const VERSION = 'question-wellformedness 1.0.0';

    /**
     * @return array{well_formed: bool, reason: ?string}
     */
    public function check(string $question): array
    {
        $q = ' '.mb_strtolower(trim($question)).' ';

        // Determiner immediately followed by a preposition, conjunction,
        // question mark or end of clause: the noun is missing. Valid
        // Italian/English never produces "il a", "the of", "la ?".
        if (preg_match(
            '/\s(il|lo|la|i|gli|le|un|uno|una|the)\s+(a|di|da|in|con|su|per|tra|fra|e|o|of|at|to|\?|\.|,)(\s|$)/u',
            $q,
        ) === 1) {
            return ['well_formed' => false, 'reason' => 'DANGLING_DETERMINER'];
        }

        // Determiner as the final token of a question.
        if (preg_match('/\s(il|lo|la|i|gli|le|un|uno|una|the)\s*\?\s*$/u', $q) === 1) {
            return ['well_formed' => false, 'reason' => 'DANGLING_DETERMINER'];
        }

        return ['well_formed' => true, 'reason' => null];
    }
}
