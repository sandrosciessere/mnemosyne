<?php

namespace App\Services\Answers;

use App\Enums\AnswerOutcome;
use App\Enums\AnswerRunStatus;
use App\Enums\ClaimVerificationStatus;
use App\Enums\ConversationMessageRole;
use App\Enums\VerifierSupportLevel;
use App\Exceptions\Answers\AnswerProviderException;
use App\Exceptions\Answers\ProviderInvalidOutputException;
use App\Exceptions\Library\WorkerTimeoutException;
use App\Exceptions\Library\WorkerUnavailableException;
use App\Models\ConversationMessage;
use App\Models\GroundedAnswerClaim;
use App\Models\GroundedAnswerEvidence;
use App\Models\GroundedAnswerRun;
use App\Services\Answers\Providers\AnswerPromptBuilder;
use App\Services\Answers\Providers\AnswerProviderFactory;
use App\Services\Answers\Providers\GeneratedClaimDraft;
use App\Services\Answers\Providers\GenerationRequest;
use App\Services\Answers\Providers\GenerationResult;
use App\Services\Answers\Providers\VerificationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The grounded answer pipeline (verifier-precision revision):
 *
 *   classify → decompose (bounded) → retrieval policy → EvidencePacket
 *   (per-subquestion for compound questions, + at most ONE bounded and
 *   FOCUSED expansion) → structured generation (+ one repair) →
 *   independent per-claim STRICT-ENTAILMENT verification (sentence
 *   atoms) → deterministic ClaimEvidenceGate → final epistemic labels →
 *   per-subquestion coverage → server-side citations → persistence.
 *
 * Correctness invariants owned here:
 * - generator output is NEVER published unverified;
 * - the model verifier is NOT trusted alone: the application gate can
 *   reject a verifier-positive claim (verifier_positive/gate_rejected
 *   is auditable), and rejected claims are never displayed;
 * - unsupported subquestions surface as an honest partial answer —
 *   evidence for one part never manufactures an answer for another;
 * - citation numbers are server-assigned by first appearance, and every
 *   citation records the minimal verified CitationSpan atoms;
 * - conversation history is referential context only.
 */
class GroundedAnswerOrchestrator
{
    public function __construct(
        private readonly QueryIntentClassifier $classifier,
        private readonly QuestionDecomposer $decomposer,
        private readonly ResponseLanguageDetector $language,
        private readonly RetrievalPolicyResolver $policies,
        private readonly EvidencePacketBuilder $packets,
        private readonly ClaimEvidenceGate $gate,
        private readonly AnswerProviderFactory $providers,
    ) {}

    public function execute(GroundedAnswerRun $run): void
    {
        if ($run->status !== AnswerRunStatus::Queued) {
            return; // duplicate delivery / already handled
        }

        $timings = [];
        $pipelineStart = hrtime(true);

        try {
            $this->pipeline($run, $timings);
        } catch (AnswerProviderException $exception) {
            $this->fail($run, $exception->errorCode, $exception->getMessage(), $timings, $pipelineStart);
        } catch (WorkerTimeoutException|WorkerUnavailableException $exception) {
            $this->fail($run, 'RETRIEVAL_UNAVAILABLE', $exception->getMessage(), $timings, $pipelineStart);
        } catch (\Throwable $exception) {
            Log::error('answers.pipeline_error', ['run' => $run->public_id, 'error' => $exception->getMessage()]);
            $this->fail($run, 'ANSWER_PIPELINE_ERROR', $exception->getMessage(), $timings, $pipelineStart);
        }
    }

    private function pipeline(GroundedAnswerRun $run, array &$timings): void
    {
        $pipelineStart = hrtime(true);
        $config = config('mnemosyne.answers');

        // ── Classification + decomposition + language ──────────────
        $t = hrtime(true);
        $assetIds = $run->scopeAssets()->pluck('book_assets.id')->all();
        $intent = $this->classifier->classify($run->question, count($assetIds));
        $subquestions = $this->decomposer->decompose($run->question);
        $isCompound = count($subquestions) > 1;
        $languageCode = $this->language->detect($run->question);
        $policy = $this->policies->resolve($intent, count($assetIds));

        // A compound question whose parts include an identity/reveal
        // part inherits the capability notice from the strictest part.
        $capabilityNotice = $intent->capabilityNotice();

        if ($isCompound && $capabilityNotice === null) {
            foreach ($subquestions as $subquestion) {
                $subIntent = $this->classifier->classify($subquestion['text'], count($assetIds));

                if ($subIntent->capabilityNotice() !== null) {
                    $capabilityNotice = $subIntent->capabilityNotice();
                    break;
                }
            }
        }

        $timings['classification'] = $this->ms($t);

        $run->forceFill([
            'status' => AnswerRunStatus::Retrieving,
            'started_at' => now(),
            'classified_intent' => $intent,
            'query_classifier_version' => QueryIntentClassifier::VERSION,
            'capability_notice' => $capabilityNotice,
            'retrieval_profile_version' => RetrievalPolicyResolver::VERSION,
            'evidence_unitizer_version' => EvidenceUnitizer::VERSION,
            'generator_prompt_version' => AnswerPromptBuilder::GENERATOR_PROMPT_VERSION,
            'verifier_prompt_version' => AnswerPromptBuilder::VERIFIER_PROMPT_VERSION,
            'question_decomposer_version' => QuestionDecomposer::VERSION,
            'claim_gate_version' => ClaimEvidenceGate::VERSION,
            'response_language' => $languageCode,
            'subquestions' => $isCompound
                ? array_map(fn ($sq) => $sq + ['status' => 'pending'], $subquestions)
                : null,
        ])->save();

        // ── Retrieval + packet (one bounded, FOCUSED expansion) ─────
        $t = hrtime(true);
        $generation = $run->generation;

        $packet = $isCompound
            ? $this->packets->buildForSubquestions($generation, $assetIds, $subquestions, $policy)
            : $this->packets->build($generation, $assetIds, $run->question, $policy);
        $timings['retrieval_and_packet'] = $this->ms($t);
        $timings['retrieval'] = $this->searchMs($packet);

        $minUnits = (int) $config['evidence']['min_sufficient_units'];
        $expansionTarget = $this->expansionTarget($packet, $subquestions, $isCompound, $minUnits);

        if ($expansionTarget !== null) {
            $run->forceFill(['status' => AnswerRunStatus::ExpandingRetrieval])->save();

            $t = hrtime(true);
            $expandedPacket = $isCompound
                ? $this->packets->buildForSubquestions($generation, $assetIds, $subquestions, $policy, [$expansionTarget])
                : $this->packets->build($generation, $assetIds, $run->question, $policy, expanded: true);
            $timings['retrieval_expansion'] = $this->ms($t);

            if ($expandedPacket->unitCount() > $packet->unitCount()) {
                $packet = $expandedPacket;
            }

            $run->forceFill(['retrieval_expansion_count' => 1])->save();
        }

        $t = hrtime(true);
        $this->persistEvidence($run, $packet);
        $run->forceFill([
            'evidence_stats' => $packet->stats,
            'retrieval_diagnostics' => $packet->diagnostics + ($expansionTarget !== null ? ['expansion_target' => $expansionTarget] : []),
        ])->save();
        $timings['evidence_persistence'] = $this->ms($t);

        if ($packet->isEmpty()) {
            $this->finishInsufficient($run, $timings, $pipelineStart);

            return;
        }

        // ── Generation (one bounded repair on invalid output) ──────
        $generator = $this->providers->generator();
        $generatorIdentity = $generator->identity();
        $run->forceFill([
            'status' => AnswerRunStatus::Generating,
            'generator_provider' => $generatorIdentity->provider,
            'generator_model' => $generatorIdentity->model,
            'generator_revision' => $generatorIdentity->revision,
        ])->save();

        $request = new GenerationRequest(
            question: $run->question,
            packet: $packet,
            conversationContext: $this->conversationContext($run),
            languageName: $this->language->promptName($languageCode),
            subquestions: $isCompound ? $subquestions : [],
        );

        $t = hrtime(true);
        $generated = $this->generateWithRepair($run, $request, $generator);
        $timings['generation'] = $this->ms($t);

        if ($generated->status === 'insufficient_evidence' || $generated->claims === []) {
            $this->finishInsufficient($run, $timings, $pipelineStart);

            return;
        }

        // ── Independent verification + application gate ────────────
        $verifier = $this->providers->verifier();
        $verifierIdentity = $verifier->identity();
        $run->forceFill([
            'status' => AnswerRunStatus::Verifying,
            'verifier_provider' => $verifierIdentity->provider,
            'verifier_model' => $verifierIdentity->model,
            'verifier_revision' => $verifierIdentity->revision,
        ])->save();

        $t = hrtime(true);
        $verdicts = [];
        $gateResults = [];
        $gateMs = 0.0;

        foreach ($generated->claims as $claim) {
            $verdicts[$claim->claimKey] = $this->verifyWithRetry($run, $packet, $claim, $verifier);

            $g = hrtime(true);
            $gateResults[$claim->claimKey] = $this->gate->evaluate($claim, $verdicts[$claim->claimKey], $packet);
            $gateMs += $this->ms($g);

            // ONE bounded gate-informed retry: the verifier answered
            // direct but selected atoms that do not state the claim
            // (wrong sentence picked). Tell it exactly that; the second
            // verdict — whatever it is — is final.
            if (($gateResults[$claim->claimKey]['reason'] ?? null) === ClaimEvidenceGate::REASON_DIRECT_NOT_ESTABLISHED) {
                Log::info('answers.gate_informed_reverify', ['run' => $run->public_id, 'claim' => $claim->claimKey]);

                try {
                    $verdicts[$claim->claimKey] = $verifier->verify(
                        $run->question,
                        $packet,
                        $claim,
                        'the atoms you selected do not explicitly state this claim. Select the exact sentence atom(s) that state it (they may be elsewhere in the evidence), or answer "none".',
                    );

                    $g = hrtime(true);
                    $gateResults[$claim->claimKey] = $this->gate->evaluate($claim, $verdicts[$claim->claimKey], $packet);
                    $gateMs += $this->ms($g);
                } catch (ProviderInvalidOutputException) {
                    // Keep the original rejected verdict — never fail the
                    // whole run for a rescue attempt.
                }
            }
        }
        $timings['claim_gate'] = round($gateMs, 1);
        $timings['verification'] = $this->ms($t) - $timings['claim_gate'];

        // ── Labels + coverage + citations + persistence ────────────
        $t = hrtime(true);
        $outcome = $this->persistClaims($run, $packet, $generated, $verdicts, $gateResults, $subquestions, $isCompound);
        $timings['claim_persistence'] = $this->ms($t);

        if ($outcome === null) {
            $this->finishInsufficient($run, $timings, $pipelineStart);

            return;
        }

        $this->appendAssistantMessage($run);

        $timings['total'] = $this->ms($pipelineStart);
        $run->forceFill([
            'status' => AnswerRunStatus::Ready,
            'outcome' => $outcome,
            'timings_ms' => array_map(fn ($v) => round($v, 1), $timings),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * The single bounded expansion is FOCUSED: for compound questions
     * it targets the first subquestion whose packet share is too thin
     * instead of blindly raising the global Top-K.
     */
    private function expansionTarget(EvidencePacket $packet, array $subquestions, bool $isCompound, int $minUnits): ?string
    {
        if (! $isCompound) {
            return $packet->unitCount() < $minUnits ? 'ALL' : null;
        }

        $perSubquestion = $packet->stats['per_subquestion'] ?? [];

        foreach ($subquestions as $subquestion) {
            if (($perSubquestion[$subquestion['key']] ?? 0) < max(1, intdiv($minUnits, 2))) {
                return $subquestion['key'];
            }
        }

        return null;
    }

    private function generateWithRepair(
        GroundedAnswerRun $run,
        GenerationRequest $request,
        Providers\GenerationProvider $generator,
    ): GenerationResult {
        try {
            return $generator->generate($request);
        } catch (ProviderInvalidOutputException $exception) {
            Log::info('answers.generator_repair', ['run' => $run->public_id, 'reason' => $exception->getMessage()]);

            return $generator->generate($request->withRepairFeedback($exception->getMessage()));
        }
    }

    private function verifyWithRetry(
        GroundedAnswerRun $run,
        EvidencePacket $packet,
        GeneratedClaimDraft $claim,
        Providers\VerifierProvider $verifier,
    ): VerificationResult {
        try {
            return $verifier->verify($run->question, $packet, $claim);
        } catch (ProviderInvalidOutputException $exception) {
            // One bounded retry for a malformed verifier verdict (cheap:
            // the evidence prefix is KV-cached); a second failure is an
            // honest VERIFIER_INVALID_OUTPUT pipeline failure — claims
            // are NEVER published unverified.
            Log::info('answers.verifier_retry', ['run' => $run->public_id, 'claim' => $claim->claimKey]);

            return $verifier->verify($run->question, $packet, $claim);
        }
    }

    private function persistEvidence(GroundedAnswerRun $run, EvidencePacket $packet): void
    {
        DB::transaction(function () use ($run, $packet) {
            // Expansion rebuilds the packet: replace prior rows (only
            // reachable before claims exist, so no pivot rows are lost).
            GroundedAnswerEvidence::query()->where('grounded_answer_run_id', $run->id)->delete();

            $ordinal = 0;

            foreach ($packet->units as $key => $unit) {
                $evidence = new GroundedAnswerEvidence;
                $evidence->forceFill([
                    'grounded_answer_run_id' => $run->id,
                    'evidence_key' => $key,
                    'ordinal' => $ordinal++,
                    'book_asset_id' => $unit->bookAssetId,
                    'book_title' => $unit->bookTitle,
                    'work_title' => $unit->workTitle,
                    'edition_label' => $unit->editionLabel,
                    'source_node_id' => $unit->sourceNodeId,
                    'spine_index' => $unit->spineIndex,
                    'source_href' => $unit->sourceHref,
                    'source_fragment' => $unit->sourceFragment,
                    'node_type' => $unit->nodeType,
                    'heading_path' => $unit->headingPath,
                    'epub_cfi' => null, // M1 artifacts carry no CFI; never invented
                    'canonical_start' => $unit->canonicalStart,
                    'canonical_end' => $unit->canonicalEnd,
                    'utf16_start' => $unit->utf16Start,
                    'utf16_end' => $unit->utf16End,
                    'source_hash' => $unit->sourceHash,
                    'source_content_sha256' => $unit->sourceContentSha256,
                    'text_hash' => $unit->textHash(),
                    'excerpt' => $unit->text,
                    'retrieval_meta' => $unit->retrievalMeta,
                    'unitizer_version' => EvidenceUnitizer::VERSION,
                ])->save();
            }
        });
    }

    /**
     * Final labels (verifier verdict AND application gate), citation
     * numbering by first appearance, CitationSpan atoms on the
     * claim↔evidence relation, per-subquestion coverage.
     *
     * @param  array<string, VerificationResult>  $verdicts
     * @param  array<string, array{result: string, reason: ?string, claim_type: string}>  $gateResults
     * @return AnswerOutcome|null null when no claim survived
     */
    private function persistClaims(
        GroundedAnswerRun $run,
        EvidencePacket $packet,
        GenerationResult $generated,
        array $verdicts,
        array $gateResults,
        array $subquestions,
        bool $isCompound,
    ): ?AnswerOutcome {
        $evidenceByKey = GroundedAnswerEvidence::query()
            ->where('grounded_answer_run_id', $run->id)
            ->get()
            ->keyBy('evidence_key');

        return DB::transaction(function () use ($run, $packet, $generated, $verdicts, $gateResults, $evidenceByKey, $subquestions, $isCompound) {
            $citationCounter = 0;
            $anyRejected = false;
            $verifiedCount = 0;
            $answeredSubquestions = [];

            foreach ($generated->claims as $ordinal => $draft) {
                $verdict = $verdicts[$draft->claimKey];
                $gateResult = $gateResults[$draft->claimKey];
                // The gate may promote an atomic strong verdict to
                // direct when its structural check CONFIRMED explicit
                // predication (auditable via gate_reason_code); the raw
                // verifier level stays persisted unmodified.
                $level = VerifierSupportLevel::from(
                    $gateResult['final_level_override'] ?? $verdict->supportLevel,
                );
                $finalLabel = $level->toEpistemicLabel();
                $verified = $finalLabel !== null && $gateResult['result'] === 'passed';

                $claim = new GroundedAnswerClaim;
                $claim->forceFill([
                    'grounded_answer_run_id' => $run->id,
                    'ordinal' => $ordinal,
                    'claim_key' => $draft->claimKey,
                    'claim_text' => $draft->text,
                    'claim_type' => $gateResult['claim_type'],
                    'subquestion_key' => $draft->subquestion,
                    'generator_suggested_label' => $draft->suggestedLabel,
                    'final_label' => $verified ? $finalLabel->value : null,
                    'verification_status' => $verified
                        ? ClaimVerificationStatus::Verified
                        : ClaimVerificationStatus::Rejected,
                    'verifier_support_level' => $verdict->supportLevel,
                    'verifier_reason_code' => $verdict->reasonCode,
                    'gate_result' => $gateResult['result'],
                    'gate_reason_code' => $gateResult['reason'],
                ])->save();

                if (! $verified) {
                    $anyRejected = true;

                    continue;
                }

                $verifiedCount++;

                if ($draft->subquestion !== null) {
                    $answeredSubquestions[$draft->subquestion] = true;
                }

                // Attach evidence with the minimal verified atom spans
                // (the gate may have substituted a structurally
                // confirmed sibling atom).
                $effectiveAtomKeys = $gateResult['atom_keys_override'] ?? $verdict->supportedAtomKeys;
                $atomsByUnit = [];

                foreach ($effectiveAtomKeys as $atomKey) {
                    $unitKey = EvidencePacket::unitKeyOf($atomKey);
                    $atomsByUnit[$unitKey][] = $atomKey;
                }

                $effectiveEvidenceKeys = array_values(array_unique(array_map(
                    fn ($atomKey) => EvidencePacket::unitKeyOf($atomKey),
                    $effectiveAtomKeys,
                )));

                foreach ($effectiveEvidenceKeys as $key) {
                    $evidence = $evidenceByKey->get($key);

                    if ($evidence === null) {
                        continue; // validated upstream; defensive
                    }

                    if ($evidence->citation_number === null) {
                        $evidence->forceFill(['citation_number' => ++$citationCounter])->save();
                    }

                    $claim->evidence()->attach($evidence->id, [
                        'atoms' => json_encode($this->atomSpans($packet, $atomsByUnit[$key] ?? [])),
                    ]);
                }
            }

            if ($verifiedCount === 0) {
                if ($isCompound) {
                    $this->persistSubquestionCoverage($run, $subquestions, []);
                }

                return null;
            }

            if ($isCompound) {
                $this->persistSubquestionCoverage($run, $subquestions, $answeredSubquestions);

                $unanswered = count(array_filter(
                    $subquestions,
                    fn ($sq) => ! isset($answeredSubquestions[$sq['key']]),
                ));

                if ($unanswered > 0) {
                    return AnswerOutcome::PartiallyAnswered;
                }
            }

            return ($generated->status === 'partially_answered' || $anyRejected)
                ? AnswerOutcome::PartiallyAnswered
                : AnswerOutcome::Answered;
        });
    }

    /**
     * Minimal verified CitationSpans: absolute canonical/UTF-16 offsets
     * of the selected atoms (text is derivable from the evidence
     * excerpt via offsets — not duplicated).
     *
     * @param  list<string>  $atomKeys
     * @return list<array{key: string, canonical_start: int, canonical_end: int, utf16_start: int, utf16_end: int}>
     */
    private function atomSpans(EvidencePacket $packet, array $atomKeys): array
    {
        $spans = [];

        foreach ($atomKeys as $atomKey) {
            $atom = $packet->atom($atomKey);

            if ($atom !== null) {
                $spans[] = [
                    'key' => $atomKey,
                    'canonical_start' => $atom['canonical_start'],
                    'canonical_end' => $atom['canonical_end'],
                    'utf16_start' => $atom['utf16_start'],
                    'utf16_end' => $atom['utf16_end'],
                ];
            }
        }

        return $spans;
    }

    private function persistSubquestionCoverage(GroundedAnswerRun $run, array $subquestions, array $answered): void
    {
        $run->forceFill([
            'subquestions' => array_map(fn ($sq) => [
                'key' => $sq['key'],
                'text' => $sq['text'],
                'status' => isset($answered[$sq['key']]) ? 'answered' : 'unanswered',
            ], $subquestions),
        ])->save();
    }

    /**
     * Referential conversation context: the PREVIOUS USER QUESTIONS
     * only (bounded). Assistant prose is deliberately excluded — prior
     * model output must never be able to become evidence for a later
     * claim.
     */
    private function conversationContext(GroundedAnswerRun $run): ?string
    {
        if ($run->conversation_id === null) {
            return null;
        }

        $previous = ConversationMessage::query()
            ->where('conversation_id', $run->conversation_id)
            ->where('role', ConversationMessageRole::User->value)
            ->where('id', '<', $run->user_message_id ?? PHP_INT_MAX)
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('content')
            ->reverse()
            ->values();

        if ($previous->isEmpty()) {
            return null;
        }

        return $previous->map(fn ($q, $i) => 'Previous question '.($i + 1).': '.mb_substr((string) $q, 0, 300))->implode("\n");
    }

    private function finishInsufficient(GroundedAnswerRun $run, array $timings, int|float $pipelineStart): void
    {
        $this->appendAssistantMessage($run);

        $timings['total'] = $this->ms($pipelineStart);
        $run->forceFill([
            'status' => AnswerRunStatus::Insufficient,
            'outcome' => AnswerOutcome::InsufficientEvidence,
            'timings_ms' => array_map(fn ($v) => round($v, 1), $timings),
            'completed_at' => now(),
        ])->save();
    }

    private function appendAssistantMessage(GroundedAnswerRun $run): void
    {
        if ($run->conversation_id === null) {
            return;
        }

        $message = new ConversationMessage;
        $message->forceFill([
            'conversation_id' => $run->conversation_id,
            'role' => ConversationMessageRole::Assistant,
            'content' => null, // authoritative content = verified claims
            'grounded_answer_run_id' => $run->id,
        ])->save();
    }

    private function fail(GroundedAnswerRun $run, string $code, string $message, array $timings, int|float $pipelineStart): void
    {
        $run->refresh();

        if ($run->status->isTerminal()) {
            return;
        }

        $timings['total'] = $this->ms($pipelineStart);
        $run->forceFill([
            'status' => AnswerRunStatus::Failed,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 1024),
            'timings_ms' => array_map(fn ($v) => round($v, 1), $timings),
            'completed_at' => now(),
        ])->save();
    }

    private function searchMs(EvidencePacket $packet): float
    {
        return round(array_sum(array_map(
            fn ($search) => (float) ($search['ms'] ?? 0),
            $packet->diagnostics['searches'] ?? [],
        )), 1);
    }

    private function ms(int|float $since): float
    {
        return (hrtime(true) - $since) / 1_000_000;
    }
}
