<?php

namespace App\Services\Answers;

/**
 * Lightweight deterministic question-language detection: the answer is
 * written in the language of the question unless the user explicitly
 * asks otherwise. Stopword-profile scoring over the languages the
 * product currently serves; falls back to Italian (product language)
 * when ambiguous.
 */
class ResponseLanguageDetector
{
    private const PROFILES = [
        'it' => ['che', 'chi', 'come', 'perché', 'perche', 'dove', 'quale', 'quali', 'il', 'lo', 'la', 'gli', 'le', 'un', 'una', 'di', 'del', 'della', 'nel', 'con', 'per', 'sono', 'è', 'quando', 'cosa', 'si', 'suo', 'sua', 'libro', 'non', 'più'],
        'en' => ['what', 'who', 'why', 'how', 'where', 'which', 'the', 'a', 'an', 'of', 'in', 'is', 'are', 'was', 'were', 'does', 'do', 'did', 'and', 'or', 'his', 'her', 'their', 'book', 'not', 'when'],
        'es' => ['qué', 'quién', 'cómo', 'por qué', 'dónde', 'cuál', 'el', 'los', 'las', 'una', 'del', 'en', 'es', 'son', 'libro', 'no', 'cuando'],
        'fr' => ['que', 'qui', 'comment', 'pourquoi', 'où', 'quel', 'quelle', 'le', 'les', 'une', 'des', 'dans', 'est', 'sont', 'livre', 'pas', 'quand'],
        'de' => ['was', 'wer', 'wie', 'warum', 'wo', 'welche', 'der', 'die', 'das', 'ein', 'eine', 'im', 'ist', 'sind', 'buch', 'nicht', 'wann'],
    ];

    public function detect(string $question): string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scores = [];

        foreach (self::PROFILES as $language => $profile) {
            $profileSet = array_flip($profile);
            $scores[$language] = count(array_filter($words, fn ($word) => isset($profileSet[$word])));
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $scores[$best] > 0 ? $best : 'it';
    }

    /** Human-readable name used inside prompts. */
    public function promptName(string $code): string
    {
        return match ($code) {
            'it' => 'Italian',
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            default => 'the language of the question',
        };
    }
}
