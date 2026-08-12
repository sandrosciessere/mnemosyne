<?php

namespace App\Services\Answers\Providers;

use App\Exceptions\Answers\ProviderInvalidOutputException;
use App\Services\Answers\EvidencePacket;

/**
 * Application-level validation of generator output — the provider
 * schema is a first line of defence, THIS is authoritative. The model
 * cannot turn an unknown key (E999) into a citation: unknown evidence
 * references reject the output.
 */
class GeneratorOutputValidator
{
    private const STATUSES = ['answered', 'partially_answered', 'insufficient_evidence'];

    private const LABELS = ['textual_fact', 'strong_inference', 'interpretation'];

    private const MAX_CLAIM_TEXT_CHARS = 1200;

    /**
     * @param  array  $raw  decoded provider JSON
     * @param  list<string>  $subquestionKeys  allowed SQ keys ([] = simple question)
     *
     * @throws ProviderInvalidOutputException with GENERATOR_INVALID_OUTPUT
     */
    public function validate(array $raw, EvidencePacket $packet, int $maxClaims, array $subquestionKeys = []): GenerationResult
    {
        $status = $raw['status'] ?? null;

        if (! in_array($status, self::STATUSES, true)) {
            $this->reject('unknown status');
        }

        $claims = $raw['claims'] ?? null;

        if (! is_array($claims) || ! array_is_list($claims)) {
            $this->reject('claims must be an array');
        }

        if ($status === 'insufficient_evidence') {
            // Conservative normalization: some models attach "there is
            // no evidence for X" pseudo-claims to an insufficient
            // verdict. Nothing may be published from an insufficient
            // answer anyway — drop them instead of failing the run.
            return new GenerationResult($status, []);
        }

        if ($claims === []) {
            $this->reject('an answered status requires at least one claim');
        }

        if (count($claims) > $maxClaims) {
            $this->reject('too many claims (max '.$maxClaims.')');
        }

        $drafts = [];
        $seenKeys = [];

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                $this->reject('malformed claim entry');
            }

            $key = $claim['claim_key'] ?? null;

            if (! is_string($key) || preg_match('/^CL\d{1,3}$/', $key) !== 1) {
                $this->reject('claim_key must match CL<n>');
            }

            if (isset($seenKeys[$key])) {
                $this->reject('duplicate claim_key '.$key);
            }
            $seenKeys[$key] = true;

            $text = $claim['text'] ?? null;

            if (! is_string($text) || trim($text) === '') {
                $this->reject('claim text empty');
            }

            if (mb_strlen($text) > self::MAX_CLAIM_TEXT_CHARS) {
                $this->reject('claim text too long');
            }

            $label = $claim['suggested_label'] ?? null;

            if (! in_array($label, self::LABELS, true)) {
                $this->reject('invalid suggested_label');
            }

            $keys = $claim['evidence_keys'] ?? null;

            if (! is_array($keys)) {
                $this->reject('evidence_keys must be an array');
            }

            if ($keys === []) {
                // Conservative normalization: an evidence-less claim is
                // typically the model narrating an UNSUPPORTED part
                // ("non è possibile stabilire…"). It can never be
                // verified or displayed — drop it; subquestion coverage
                // reports the gap honestly.
                continue;
            }

            // Models sometimes cite the atom form (E7.S2) although the
            // generator contract asks for unit keys: normalize
            // unambiguously to the parent unit.
            $keys = array_values(array_unique(array_map(
                fn ($evidenceKey) => is_string($evidenceKey) && preg_match('/^(E\d+)\.S\d+$/', $evidenceKey, $m) === 1
                    ? $m[1]
                    : $evidenceKey,
                $keys,
            )));

            foreach ($keys as $evidenceKey) {
                if (! is_string($evidenceKey) || ! $packet->has($evidenceKey)) {
                    $this->reject('unknown evidence key '.(is_string($evidenceKey) ? $evidenceKey : gettype($evidenceKey)));
                }
            }

            $subquestion = null;

            if ($subquestionKeys !== []) {
                $subquestion = $claim['subquestion'] ?? null;

                if (! is_string($subquestion) || ! in_array($subquestion, $subquestionKeys, true)) {
                    $this->reject('claim must carry a valid subquestion key');
                }
            }

            $drafts[] = new GeneratedClaimDraft($key, trim($text), $label, $keys, $subquestion);
        }

        if ($drafts === []) {
            // Every claim was dropped as evidence-less: the honest
            // reading of this output is insufficiency, not an answer.
            return new GenerationResult('insufficient_evidence', []);
        }

        return new GenerationResult($status, $drafts);
    }

    private function reject(string $reason): never
    {
        throw new ProviderInvalidOutputException(
            'GENERATOR_INVALID_OUTPUT',
            'Generator output rejected: '.$reason,
        );
    }
}
