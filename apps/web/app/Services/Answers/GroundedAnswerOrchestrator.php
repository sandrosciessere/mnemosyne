<?php

namespace App\Services\Answers;

use App\Enums\AnswerOutcome;
use App\Enums\AnswerRunStatus;
use App\Enums\ClaimVerificationStatus;
use App\Enums\ConversationMessageRole;
use App\Enums\QueryIntent;
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
 * The grounded answer pipeline (second corrective revision):
 *
 *   well-formedness gate → decompose → TaskContract per subquestion →
 *   capability gate (unsupported global/longitudinal/reveal tasks
 *   short-circuit BEFORE generation) → task-aware multi-query
 *   retrieval (+ neighborhood, + ONE focused expansion) → structured
 *   generation → language enforcement → per-claim strict-entailment
 *   verification (claim-local protocol robustness) → ClaimEvidenceGate
 *   → ClaimRelevanceGate → TaskCoverageEvaluator → outcome from
 *   MATERIAL TASK COVERAGE → persistence.
 *
 * Correctness contract owned here:
 *   evidence must support the claim (ClaimEvidenceGate), the claim
 *   must answer its subquestion (ClaimRelevanceGate), the surviving
 *   claims must satisfy the requested task (TaskCoverageEvaluator),
 *   unsupported capabilities are declared before expensive generation,
 *   and rejected extra claims never make a complete answer "partial".
 */
class GroundedAnswerOrchestrator
{
    public function __construct(
        private readonly QueryIntentClassifier $classifier,
        private readonly QuestionDecomposer $decomposer,
        private readonly TaskContractClassifier $contracts,
        private readonly QuestionWellFormednessGate $wellFormedness,
        private readonly ResponseLanguageDetector $language,
        private readonly RetrievalPolicyResolver $policies,
        private readonly EvidencePacketBuilder $packets,
        private readonly EvidenceSufficiencyProbe $probe,
        private readonly QueryReformulator $reformulator,
        private readonly ClaimEvidenceGate $gate,
        private readonly ClaimRelevanceGate $relevance,
        private readonly TaskCoverageEvaluator $coverage,
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

        // ── Understanding: well-formedness, decomposition, contracts ─
        $t = hrtime(true);
        $assetIds = $run->scopeAssets()->pluck('book_assets.id')->all();
        $languageCode = $this->language->detect($run->question);

        $wellFormed = $this->wellFormedness->check($run->question);
        $subquestions = $this->decomposer->decompose($run->question);
        $isCompound = count($subquestions) > 1;

        /** @var array<string, TaskContract> $contracts */
        $contracts = [];

        foreach ($subquestions as $subquestion) {
            $contracts[$subquestion['key']] = $this->contracts->classify($subquestion['key'], $subquestion['text']);
        }

        $intent = $this->classifier->classify($run->question, count($assetIds));

        // Capability aggregation: the strictest material subquestion
        // decides the run-level notice; siblings keep their own level.
        $capabilityNotice = $intent->capabilityNotice();

        foreach ($contracts as $contract) {
            if ($capabilityNotice === null && $contract->capabilityNotice !== null) {
                $capabilityNotice = $contract->capabilityNotice;
            }
        }

        $policy = $this->policies->resolve($intent, count($assetIds));
        $timings['understanding'] = $this->ms($t);

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
            'task_contract_version' => TaskContract::VERSION,
            'claim_relevance_gate_version' => ClaimRelevanceGate::VERSION,
            'coverage_evaluator_version' => TaskCoverageEvaluator::VERSION,
            'response_language' => $languageCode,
            'subquestions' => array_map(
                fn ($sq) => $sq + ['status' => 'pending', 'contract' => $contracts[$sq['key']]->toArray()],
                $subquestions,
            ),
        ])->save();

        // ── Clarification short-circuit (cheap, before any retrieval) ─
        if (! $wellFormed['well_formed']) {
            $this->finishWithCoverage($run, AnswerOutcome::NeedsClarification, array_map(
                fn ($sq) => ['key' => $sq['key'], 'text' => $sq['text'], 'status' => 'needs_clarification', 'diagnosis' => $wellFormed['reason']],
                $subquestions,
            ), $timings, $pipelineStart);

            return;
        }

        // ── Capability gate: unsupported tasks never reach the model ─
        $supported = array_values(array_filter(
            $subquestions,
            fn ($sq) => $contracts[$sq['key']]->supportedInM3,
        ));

        if ($supported === []) {
            $evaluation = $this->coverage->evaluate($subquestions, $contracts, []);
            $this->finishWithCoverage($run, $evaluation['outcome'], $evaluation['subquestions'], $timings, $pipelineStart);

            return;
        }

        // ── Task-aware retrieval (multi-query + neighborhood) ────────
        $t = hrtime(true);
        $generation = $run->generation;

        $useComparative = $intent === QueryIntent::ComparativeMultiBook && count($supported) === 1 && count($assetIds) >= 2;

        $packet = $useComparative
            ? $this->packets->build($generation, $assetIds, $run->question, $policy)
            : $this->packets->buildForSubquestions($generation, $assetIds, $supported, $policy, $contracts);
        $timings['retrieval_and_packet'] = $this->ms($t);
        $timings['retrieval'] = $this->searchMs($packet);

        // ── ONE focused expansion, sufficiency-driven (pre-generation) ─
        // Packet fullness is NOT recall: the task-aware probe checks,
        // per supported subquestion, whether any packet unit plausibly
        // carries the asked information. The first materially uncovered
        // subquestion becomes the expansion target and gets dedicated
        // expansion queries (relation perspectives, state expressions,
        // hints from promising regions) — never just a larger top-K.
        $expansionTarget = null;
        $expansionQueries = [];
        $probeResults = [];

        if ($useComparative) {
            $expansionTarget = $packet->unitCount() < (int) $config['evidence']['min_sufficient_units'] ? 'ALL' : null;
        } else {
            foreach ($supported as $subquestion) {
                $probeResult = $this->probe->probe($contracts[$subquestion['key']], $packet);
                $probeResults[$subquestion['key']] = $probeResult;

                if ($expansionTarget === null && ! $probeResult['likely_sufficient']) {
                    $expansionTarget = $subquestion['key'];
                }
            }
        }

        $expansionUsed = false;

        if ($expansionTarget !== null) {
            $run->forceFill(['status' => AnswerRunStatus::ExpandingRetrieval])->save();

            $t = hrtime(true);

            if ($useComparative) {
                $expandedPacket = $this->packets->build($generation, $assetIds, $run->question, $policy, expanded: true);
            } else {
                $hints = $this->probe->regionHints($contracts[$expansionTarget], $packet);
                $expansionQueries[$expansionTarget] = $this->reformulator->expansionVariants($contracts[$expansionTarget], $hints);
                $expandedPacket = $this->packets->buildForSubquestions(
                    $generation, $assetIds, $supported, $policy, $contracts, [$expansionTarget], $expansionQueries,
                );
            }

            $timings['retrieval_expansion'] = $this->ms($t);
            $expansionUsed = true;

            // Adopt the expanded packet whenever it brings NEW candidate
            // evidence for the target (probe improved) or simply more
            // distinct regions — not only when the unit count grew.
            $improved = $useComparative
                ? $expandedPacket->unitCount() > $packet->unitCount()
                : ($this->probe->probe($contracts[$expansionTarget], $expandedPacket)['likely_sufficient']
                    || ($expandedPacket->stats['distinct_regions'] ?? 0) > ($packet->stats['distinct_regions'] ?? 0));

            if ($improved) {
                $packet = $expandedPacket;
            }

            $run->forceFill(['retrieval_expansion_count' => 1])->save();
        }

        $t = hrtime(true);
        $this->persistEvidence($run, $packet);
        $run->forceFill([
            'evidence_stats' => $packet->stats,
            'retrieval_diagnostics' => array_merge($packet->diagnostics, [
                'sufficiency_probe' => $probeResults,
                'expansion_target' => $expansionTarget,
                'expansion_trigger' => $expansionTarget !== null
                    ? ($probeResults[$expansionTarget]['reason'] ?? 'THIN_PACKET')
                    : null,
                'expansion_queries' => $expansionQueries,
                'expansion_used' => $expansionUsed,
            ]),
        ])->save();
        $timings['evidence_persistence'] = $this->ms($t);

        if ($packet->isEmpty()) {
            $evaluation = $this->coverage->evaluate($subquestions, $contracts, []);
            $this->finishWithCoverage($run, AnswerOutcome::InsufficientEvidence, $evaluation['subquestions'], $timings, $pipelineStart);

            return;
        }

        // ── Generation (one bounded repair; timing survives failure) ─
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
            subquestions: count($supported) > 1 ? $supported : [],
        );

        $t = hrtime(true);

        try {
            $generated = $this->generateWithRepair($run, $request, $generator);
        } finally {
            $timings['generation'] = $this->ms($t);
        }

        // ── Application-level language enforcement ───────────────────
        $generated = $this->enforceLanguage($run, $request, $generator, $generated, $languageCode, $timings);

        if ($generated->status === 'insufficient_evidence' || $generated->claims === []) {
            $evaluation = $this->coverage->evaluate($subquestions, $contracts, $this->statsFromPacketOnly($supported, $packet));
            $this->finishWithCoverage($run, AnswerOutcome::InsufficientEvidence, $evaluation['subquestions'], $timings, $pipelineStart);

            return;
        }

        // ── Verification + gates (claim-local robustness) ────────────
        $verifier = $this->providers->verifier();
        $verifierIdentity = $verifier->identity();
        $run->forceFill([
            'status' => AnswerRunStatus::Verifying,
            'verifier_provider' => $verifierIdentity->provider,
            'verifier_model' => $verifierIdentity->model,
            'verifier_revision' => $verifierIdentity->revision,
        ])->save();

        $subquestionTexts = [];
        foreach ($subquestions as $sq) {
            $subquestionTexts[$sq['key']] = $sq['text'];
        }
        $defaultSubquestion = count($supported) === 1 ? $supported[0]['key'] : null;

        $t = hrtime(true);
        $claimResults = [];
        $gateMs = 0.0;
        $protocolErrors = 0;

        try {
            foreach ($generated->claims as $claim) {
                $subquestionKey = $claim->subquestion ?? $defaultSubquestion;
                $contract = $contracts[$subquestionKey] ?? reset($contracts);

                [$verdict, $protocolError] = $this->verifyClaimRobustly(
                    $run, $packet, $claim, $verifier, $subquestionTexts[$subquestionKey] ?? null,
                );

                if ($protocolError) {
                    $protocolErrors++;
                }

                $g = hrtime(true);
                $gateResult = $this->gate->evaluate($claim, $verdict, $packet);

                // ONE bounded gate-informed retry when a direct verdict
                // picked non-asserting atoms (unchanged from pass 1).
                if (! $protocolError && ($gateResult['reason'] ?? null) === ClaimEvidenceGate::REASON_DIRECT_NOT_ESTABLISHED) {
                    $gateMs += $this->ms($g);
                    Log::info('answers.gate_informed_reverify', ['run' => $run->public_id, 'claim' => $claim->claimKey]);

                    try {
                        $verdict = $verifier->verify(
                            $run->question, $packet, $claim,
                            'the atoms you selected do not explicitly state this claim. Select the exact sentence atom(s) that state it (they may be elsewhere in the evidence), or answer "none".',
                            $subquestionTexts[$subquestionKey] ?? null,
                        );
                        $g = hrtime(true);
                        $gateResult = $this->gate->evaluate($claim, $verdict, $packet);
                    } catch (ProviderInvalidOutputException) {
                        // keep the original rejected verdict
                        $g = hrtime(true);
                    }
                }

                // Relevance: only claims that survived the evidence gate.
                $relevanceResult = ['result' => null, 'reason' => null];

                if ($gateResult['result'] === 'passed' && VerifierSupportLevel::from($verdict->supportLevel)->toEpistemicLabel() !== null) {
                    $relevanceResult = $this->relevance->evaluate($claim, $verdict, $contract, $packet);
                }

                $gateMs += $this->ms($g);

                $claimResults[$claim->claimKey] = [
                    'verdict' => $verdict,
                    'gate' => $gateResult,
                    'relevance' => $relevanceResult,
                    'protocol_error' => $protocolError,
                    'subquestion' => $subquestionKey,
                ];
            }
        } finally {
            $timings['claim_gate'] = round($gateMs, 1);
            $timings['verification'] = round($this->ms($t) - $gateMs, 1);
        }

        // Systemic verifier failure: EVERY claim died on protocol errors.
        if ($protocolErrors > 0 && $protocolErrors === count($generated->claims)) {
            throw new AnswerProviderException(
                'VERIFIER_PROTOCOL_ERROR',
                'The verifier returned malformed output for every claim.',
            );
        }

        // ── Persistence + coverage-based outcome ─────────────────────
        $t = hrtime(true);
        $perSubquestion = $this->persistClaims($run, $packet, $generated, $claimResults, $supported);
        $timings['claim_persistence'] = $this->ms($t);

        $evaluation = $this->coverage->evaluate($subquestions, $contracts, $perSubquestion);

        // ── Post-verification safety net for the single expansion ────
        // The pre-generation probe can be fooled by units that mention
        // the entity and the relation word without stating the fact.
        // If the strict verifier then rejects for lack of evidence
        // (NO_MENTION / missing support) on a supported subquestion and
        // the ONE expansion is still unspent, spend it now with the
        // dedicated expansion queries and re-run generation +
        // verification ONCE. Still bounded: at most one expansion and
        // one regeneration per answer run — no loop.
        $lateTarget = $this->lateExpansionTarget($evaluation['subquestions'], $contracts, $claimResults, $expansionUsed, $useComparative);

        if ($lateTarget !== null) {
            Log::info('answers.late_focused_expansion', ['run' => $run->public_id, 'subquestion' => $lateTarget]);
            $run->forceFill(['status' => AnswerRunStatus::ExpandingRetrieval])->save();

            $t = hrtime(true);
            $hints = $this->probe->regionHints($contracts[$lateTarget], $packet);
            $expansionQueries[$lateTarget] = $this->reformulator->expansionVariants($contracts[$lateTarget], $hints);
            $expandedPacket = $this->packets->buildForSubquestions(
                $generation, $assetIds, $supported, $policy, $contracts, [$lateTarget], $expansionQueries,
            );
            $timings['retrieval_expansion'] = ($timings['retrieval_expansion'] ?? 0) + $this->ms($t);
            $expansionUsed = true;

            $newKeys = array_diff(
                array_map(fn ($u) => $u->identity(), array_values($expandedPacket->units)),
                array_map(fn ($u) => $u->identity(), array_values($packet->units)),
            );

            $run->forceFill([
                'retrieval_expansion_count' => 1,
                'retrieval_diagnostics' => ($run->retrieval_diagnostics ?? []) + [
                    'late_expansion' => [
                        'target' => $lateTarget,
                        'trigger' => 'POST_VERIFICATION_NO_SUPPORT',
                        'queries' => $expansionQueries[$lateTarget],
                        'new_units' => count($newKeys),
                    ],
                ],
            ])->save();

            if ($newKeys !== []) {
                // Replace packet + claims and redo generation/verification once.
                $packet = $expandedPacket;
                GroundedAnswerClaim::query()->where('grounded_answer_run_id', $run->id)->delete();
                $this->persistEvidence($run, $packet);
                $run->forceFill(['evidence_stats' => $packet->stats, 'status' => AnswerRunStatus::Generating])->save();

                $request = new GenerationRequest(
                    question: $run->question,
                    packet: $packet,
                    conversationContext: $this->conversationContext($run),
                    languageName: $this->language->promptName($languageCode),
                    subquestions: count($supported) > 1 ? $supported : [],
                );

                $t = hrtime(true);

                try {
                    $generated = $this->generateWithRepair($run, $request, $generator);
                } finally {
                    $timings['generation'] = ($timings['generation'] ?? 0) + $this->ms($t);
                }

                $generated = $this->enforceLanguage($run, $request, $generator, $generated, $languageCode, $timings);

                if ($generated->status !== 'insufficient_evidence' && $generated->claims !== []) {
                    $run->forceFill(['status' => AnswerRunStatus::Verifying])->save();
                    $t = hrtime(true);
                    $claimResults = [];
                    $gateMs2 = 0.0;
                    $protocolErrors = 0;

                    try {
                        foreach ($generated->claims as $claim) {
                            $subquestionKey = $claim->subquestion ?? $defaultSubquestion;
                            $contract = $contracts[$subquestionKey] ?? reset($contracts);
                            [$verdict, $protocolError] = $this->verifyClaimRobustly($run, $packet, $claim, $verifier, $subquestionTexts[$subquestionKey] ?? null);

                            if ($protocolError) {
                                $protocolErrors++;
                            }

                            $g = hrtime(true);
                            $gateResult = $this->gate->evaluate($claim, $verdict, $packet);
                            $relevanceResult = ['result' => null, 'reason' => null];

                            if ($gateResult['result'] === 'passed' && VerifierSupportLevel::from($verdict->supportLevel)->toEpistemicLabel() !== null) {
                                $relevanceResult = $this->relevance->evaluate($claim, $verdict, $contract, $packet);
                            }
                            $gateMs2 += $this->ms($g);

                            $claimResults[$claim->claimKey] = [
                                'verdict' => $verdict, 'gate' => $gateResult, 'relevance' => $relevanceResult,
                                'protocol_error' => $protocolError, 'subquestion' => $subquestionKey,
                            ];
                        }
                    } finally {
                        $timings['claim_gate'] = ($timings['claim_gate'] ?? 0) + round($gateMs2, 1);
                        $timings['verification'] = ($timings['verification'] ?? 0) + round($this->ms($t) - $gateMs2, 1);
                    }

                    if ($protocolErrors > 0 && $protocolErrors === count($generated->claims)) {
                        throw new AnswerProviderException('VERIFIER_PROTOCOL_ERROR', 'The verifier returned malformed output for every claim.');
                    }

                    $t = hrtime(true);
                    $perSubquestion = $this->persistClaims($run, $packet, $generated, $claimResults, $supported);
                    $timings['claim_persistence'] = ($timings['claim_persistence'] ?? 0) + $this->ms($t);
                    $evaluation = $this->coverage->evaluate($subquestions, $contracts, $perSubquestion);
                } else {
                    $evaluation = $this->coverage->evaluate($subquestions, $contracts, $this->statsFromPacketOnly($supported, $packet));
                }
            }
        }

        $this->appendAssistantMessage($run);

        $timings['total'] = $this->ms($pipelineStart);
        $run->forceFill([
            'status' => $evaluation['outcome'] === AnswerOutcome::InsufficientEvidence
                ? AnswerRunStatus::Insufficient
                : AnswerRunStatus::Ready,
            'outcome' => $evaluation['outcome'],
            'subquestions' => $evaluation['subquestions'],
            'timings_ms' => array_map(fn ($v) => round($v, 1), $timings),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Claim-local verifier robustness: blind retry on the first invalid
     * output, then ONE candidate-listing repair, then a synthetic
     * `none` verdict (VERIFIER_INVALID_SUPPORT_ATOM) so ONE malformed
     * claim never destroys the rest of the answer. Transport failures
     * (unavailable/timeout) remain run-fatal and propagate.
     *
     * @return array{0: VerificationResult, 1: bool} verdict + protocol-error flag
     */
    private function verifyClaimRobustly(
        GroundedAnswerRun $run,
        EvidencePacket $packet,
        GeneratedClaimDraft $claim,
        Providers\VerifierProvider $verifier,
        ?string $subquestionText,
    ): array {
        try {
            return [$verifier->verify($run->question, $packet, $claim, null, $subquestionText), false];
        } catch (ProviderInvalidOutputException) {
            // Candidate-listing repair: enumerate the VALID atom IDs of
            // the units the generator proposed (bounded), so a bare
            // "E11" answer or a fabricated ID gets one precise chance.
            $candidates = [];

            foreach ($claim->evidenceKeys as $unitKey) {
                foreach (array_keys($packet->units[$unitKey]->atoms ?? []) as $atomKey) {
                    $candidates[] = $unitKey.'.'.$atomKey;
                }
            }

            $candidates = array_slice($candidates, 0, 24);

            Log::info('answers.verifier_atom_repair', ['run' => $run->public_id, 'claim' => $claim->claimKey]);

            try {
                return [$verifier->verify(
                    $run->question, $packet, $claim,
                    'your previous answer used an invalid or unit-level atom ID. Valid atom IDs for the proposed evidence are: '
                    .implode(', ', $candidates)
                    .'. Select one or more of these exact IDs (or any other valid unit.atom ID from the evidence), or answer "none".',
                    $subquestionText,
                ), false];
            } catch (ProviderInvalidOutputException) {
                return [new VerificationResult(
                    $claim->claimKey,
                    'none',
                    [],
                    [],
                    'VERIFIER_INVALID_SUPPORT_ATOM',
                ), true];
            }
        }
    }

    /**
     * Post-generation application-level language enforcement: claims in
     * the wrong language get ONE regeneration with explicit feedback;
     * still-wrong claims are dropped (never displayed). Proper nouns and
     * short answers are exempt via a length floor.
     */
    private function enforceLanguage(
        GroundedAnswerRun $run,
        GenerationRequest $request,
        Providers\GenerationProvider $generator,
        GenerationResult $generated,
        string $languageCode,
        array &$timings,
    ): GenerationResult {
        $mismatched = $this->mismatchedClaims($generated, $languageCode);

        if ($mismatched === []) {
            return $generated;
        }

        Log::info('answers.language_repair', ['run' => $run->public_id, 'claims' => $mismatched]);

        $t = hrtime(true);

        try {
            $regenerated = $generator->generate($request->withRepairFeedback(
                'some claims were not written in '.$this->language->promptName($languageCode)
                .'. Rewrite ALL claims in '.$this->language->promptName($languageCode)
                .' (proper nouns and short source quotes may stay as-is).',
            ));
        } catch (AnswerProviderException) {
            $regenerated = null;
        } finally {
            $timings['language_repair'] = $this->ms($t);
        }

        if ($regenerated !== null && $this->mismatchedClaims($regenerated, $languageCode) === []) {
            return $regenerated;
        }

        // Drop still-mismatched claims from the better of the two outputs.
        $source = $regenerated !== null && count($this->mismatchedClaims($regenerated, $languageCode)) < count($mismatched)
            ? $regenerated
            : $generated;
        $bad = array_flip($this->mismatchedClaims($source, $languageCode));
        $kept = array_values(array_filter($source->claims, fn ($claim) => ! isset($bad[$claim->claimKey])));

        return new GenerationResult($kept === [] ? 'insufficient_evidence' : $source->status, $kept);
    }

    /** @return list<string> claim keys whose language mismatches */
    private function mismatchedClaims(GenerationResult $generated, string $languageCode): array
    {
        $mismatched = [];

        foreach ($generated->claims as $claim) {
            if (mb_strlen($claim->text) >= 40 && $this->language->detect($claim->text) !== $languageCode) {
                $mismatched[] = $claim->claimKey;
            }
        }

        return $mismatched;
    }

    /**
     * A supported subquestion still unanswered after verification for
     * LACK OF EVIDENCE (not for capability, clarification, protocol or
     * relevance-only reasons) is a valid late expansion target — once.
     *
     * @param  list<array>  $rows  coverage rows
     * @param  array<string, TaskContract>  $contracts
     */
    private function lateExpansionTarget(array $rows, array $contracts, array $claimResults, bool $expansionUsed, bool $comparative): ?string
    {
        if ($expansionUsed || $comparative) {
            return null;
        }

        $evidenceLackDiagnoses = [
            TaskCoverageEvaluator::DIAG_NO_HITS,
            TaskCoverageEvaluator::DIAG_HITS_NOT_RELEVANT,
            TaskCoverageEvaluator::DIAG_NO_CLAIM,
            TaskCoverageEvaluator::DIAG_VERIFIER_REJECTED,
            TaskCoverageEvaluator::DIAG_GATE_REJECTED,
        ];

        foreach ($rows as $row) {
            if (($row['status'] ?? null) !== 'unanswered') {
                continue;
            }

            $contract = $contracts[$row['key']] ?? null;

            if ($contract === null || ! $contract->supportedInM3) {
                continue;
            }

            if (in_array($row['diagnosis'] ?? null, $evidenceLackDiagnoses, true)) {
                return $row['key'];
            }
        }

        return null;
    }

    /** Packet-only per-SQ stats when generation produced nothing. */
    private function statsFromPacketOnly(array $supported, EvidencePacket $packet): array
    {
        $stats = [];

        foreach ($supported as $subquestion) {
            $stats[$subquestion['key']] = [
                'surviving' => 0, 'generated' => 0, 'verifier_rejected' => 0,
                'gate_rejected' => 0, 'relevance_rejected' => 0, 'protocol_errors' => 0,
                'packet_units' => $packet->stats['per_subquestion'][$subquestion['key']] ?? $packet->unitCount(),
            ];
        }

        return $stats;
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

    private function persistEvidence(GroundedAnswerRun $run, EvidencePacket $packet): void
    {
        DB::transaction(function () use ($run, $packet) {
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
     * Persists claims with all three gate verdicts and returns the
     * per-subquestion coverage stats for the TaskCoverageEvaluator.
     *
     * @param  array<string, array{verdict: VerificationResult, gate: array,
     *                             relevance: array, protocol_error: bool, subquestion: ?string}>  $claimResults
     * @return array<string, array> per-subquestion stats
     */
    private function persistClaims(
        GroundedAnswerRun $run,
        EvidencePacket $packet,
        GenerationResult $generated,
        array $claimResults,
        array $supported,
    ): array {
        $evidenceByKey = GroundedAnswerEvidence::query()
            ->where('grounded_answer_run_id', $run->id)
            ->get()
            ->keyBy('evidence_key');

        $perSubquestion = $this->statsFromPacketOnly($supported, $packet);

        DB::transaction(function () use ($run, $packet, $generated, $claimResults, $evidenceByKey, &$perSubquestion) {
            $citationCounter = 0;

            foreach ($generated->claims as $ordinal => $draft) {
                $result = $claimResults[$draft->claimKey];
                $verdict = $result['verdict'];
                $gateResult = $result['gate'];
                $relevanceResult = $result['relevance'];
                $subquestionKey = $result['subquestion'];

                $level = VerifierSupportLevel::from(
                    $gateResult['final_level_override'] ?? $verdict->supportLevel,
                );
                $finalLabel = $level->toEpistemicLabel();
                $verified = $finalLabel !== null
                    && $gateResult['result'] === 'passed'
                    && $relevanceResult['result'] === 'passed';

                if ($subquestionKey !== null && isset($perSubquestion[$subquestionKey])) {
                    $perSubquestion[$subquestionKey]['generated']++;

                    if ($result['protocol_error']) {
                        $perSubquestion[$subquestionKey]['protocol_errors']++;
                    } elseif ($verified) {
                        $perSubquestion[$subquestionKey]['surviving']++;
                    } elseif ($relevanceResult['result'] === 'rejected') {
                        $perSubquestion[$subquestionKey]['relevance_rejected']++;
                    } elseif ($gateResult['result'] === 'rejected' && $verdict->supportLevel !== 'none') {
                        $perSubquestion[$subquestionKey]['gate_rejected']++;
                    } else {
                        $perSubquestion[$subquestionKey]['verifier_rejected']++;
                    }
                }

                $claim = new GroundedAnswerClaim;
                $claim->forceFill([
                    'grounded_answer_run_id' => $run->id,
                    'ordinal' => $ordinal,
                    'claim_key' => $draft->claimKey,
                    'claim_text' => $draft->text,
                    'claim_type' => $gateResult['claim_type'],
                    'subquestion_key' => $subquestionKey,
                    'generator_suggested_label' => $draft->suggestedLabel,
                    'final_label' => $verified ? $finalLabel->value : null,
                    'verification_status' => $verified
                        ? ClaimVerificationStatus::Verified
                        : ClaimVerificationStatus::Rejected,
                    'verifier_support_level' => $verdict->supportLevel,
                    'verifier_reason_code' => $verdict->reasonCode,
                    'gate_result' => $gateResult['result'],
                    'gate_reason_code' => $gateResult['reason'],
                    'relevance_result' => $relevanceResult['result'],
                    'relevance_reason_code' => $relevanceResult['reason'],
                ])->save();

                if (! $verified) {
                    continue;
                }

                $effectiveAtomKeys = $gateResult['atom_keys_override'] ?? $verdict->supportedAtomKeys;
                $atomsByUnit = [];

                foreach ($effectiveAtomKeys as $atomKey) {
                    $atomsByUnit[EvidencePacket::unitKeyOf($atomKey)][] = $atomKey;
                }

                $effectiveEvidenceKeys = array_keys($atomsByUnit);

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
        });

        return $perSubquestion;
    }

    /**
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

    /**
     * Referential conversation context: previous USER questions only.
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

    /**
     * Terminal non-failure outcomes with per-subquestion coverage rows
     * (clarification, capability-limited, insufficient, ready).
     */
    private function finishWithCoverage(
        GroundedAnswerRun $run,
        AnswerOutcome $outcome,
        array $subquestionRows,
        array $timings,
        int|float $pipelineStart,
    ): void {
        $this->appendAssistantMessage($run);

        $timings['total'] = $this->ms($pipelineStart);
        $run->forceFill([
            'status' => $outcome === AnswerOutcome::InsufficientEvidence
                ? AnswerRunStatus::Insufficient
                : AnswerRunStatus::Ready,
            'outcome' => $outcome,
            'subquestions' => $subquestionRows,
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
