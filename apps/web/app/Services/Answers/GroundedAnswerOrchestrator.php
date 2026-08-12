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
use App\Services\Answers\Providers\GenerationResult;
use App\Services\Answers\Providers\VerificationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The grounded answer pipeline:
 *
 *   classify → retrieval policy → EvidencePacket (+ at most ONE bounded
 *   expansion) → structured generation (+ at most one repair) →
 *   independent per-claim verification → final epistemic labels →
 *   server-side citations → persistence.
 *
 * Correctness invariants owned here:
 * - generator output is NEVER published unverified (verifier failure =
 *   pipeline failure, not a fallback);
 * - a claim the verifier scored `none` is rejected and never displayed
 *   as supported;
 * - citation numbers are assigned server-side by first appearance;
 * - conversation history is referential context only — previous
 *   assistant prose is NEVER included, so it can never become evidence.
 */
class GroundedAnswerOrchestrator
{
    public function __construct(
        private readonly QueryIntentClassifier $classifier,
        private readonly RetrievalPolicyResolver $policies,
        private readonly EvidencePacketBuilder $packets,
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

        // ── Classification ─────────────────────────────────────────
        $t = hrtime(true);
        $assetIds = $run->scopeAssets()->pluck('book_assets.id')->all();
        $intent = $this->classifier->classify($run->question, count($assetIds));
        $policy = $this->policies->resolve($intent, count($assetIds));
        $timings['classification'] = $this->ms($t);

        $run->forceFill([
            'status' => AnswerRunStatus::Retrieving,
            'started_at' => now(),
            'classified_intent' => $intent,
            'query_classifier_version' => QueryIntentClassifier::VERSION,
            'capability_notice' => $intent->capabilityNotice(),
            'retrieval_profile_version' => RetrievalPolicyResolver::VERSION,
            'evidence_unitizer_version' => EvidenceUnitizer::VERSION,
            'generator_prompt_version' => AnswerPromptBuilder::GENERATOR_PROMPT_VERSION,
            'verifier_prompt_version' => AnswerPromptBuilder::VERIFIER_PROMPT_VERSION,
        ])->save();

        // ── Retrieval + packet (with one bounded expansion) ────────
        $t = hrtime(true);
        $generation = $run->generation;
        $packet = $this->packets->build($generation, $assetIds, $run->question, $policy);
        $timings['retrieval_and_packet'] = $this->ms($t);
        $timings['retrieval'] = $this->searchMs($packet);

        if ($packet->unitCount() < (int) $config['evidence']['min_sufficient_units']) {
            $run->forceFill(['status' => AnswerRunStatus::ExpandingRetrieval])->save();

            $t = hrtime(true);
            $expandedPacket = $this->packets->build($generation, $assetIds, $run->question, $policy, expanded: true);
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
            'retrieval_diagnostics' => $packet->diagnostics,
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

        $context = $this->conversationContext($run);

        $t = hrtime(true);
        $generated = $this->generateWithRepair($run, $packet, $context, $generator);
        $timings['generation'] = $this->ms($t);

        if ($generated->status === 'insufficient_evidence' || $generated->claims === []) {
            $this->finishInsufficient($run, $timings, $pipelineStart);

            return;
        }

        // ── Independent verification (per claim) ───────────────────
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

        foreach ($generated->claims as $claim) {
            $verdicts[$claim->claimKey] = $this->verifyWithRetry($run, $packet, $claim, $verifier);
        }
        $timings['verification'] = $this->ms($t);

        // ── Final labels + citations + persistence ─────────────────
        $t = hrtime(true);
        $outcome = $this->persistClaims($run, $generated, $verdicts);
        $timings['claim_persistence'] = $this->ms($t);

        if ($outcome === null) {
            // Every claim was rejected by the verifier: honest
            // insufficient-evidence terminal state (audit keeps the
            // rejected claims).
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

    private function generateWithRepair(
        GroundedAnswerRun $run,
        EvidencePacket $packet,
        ?string $context,
        Providers\GenerationProvider $generator,
    ): GenerationResult {
        try {
            return $generator->generate($run->question, $packet, $context, null);
        } catch (ProviderInvalidOutputException $exception) {
            Log::info('answers.generator_repair', ['run' => $run->public_id, 'reason' => $exception->getMessage()]);

            return $generator->generate($run->question, $packet, $context, $exception->getMessage());
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
     * Maps verifier verdicts to final labels, persists claims + the
     * claim↔evidence relation, and assigns server-side citation numbers
     * by first appearance across VERIFIED claims.
     *
     * @param  array<string, VerificationResult>  $verdicts
     * @return AnswerOutcome|null null when no claim survived verification
     */
    private function persistClaims(GroundedAnswerRun $run, GenerationResult $generated, array $verdicts): ?AnswerOutcome
    {
        $evidenceByKey = GroundedAnswerEvidence::query()
            ->where('grounded_answer_run_id', $run->id)
            ->get()
            ->keyBy('evidence_key');

        return DB::transaction(function () use ($run, $generated, $verdicts, $evidenceByKey) {
            $citationCounter = 0;
            $anyRejected = false;
            $verifiedCount = 0;

            foreach ($generated->claims as $ordinal => $draft) {
                $verdict = $verdicts[$draft->claimKey];
                $level = VerifierSupportLevel::from($verdict->supportLevel);
                $finalLabel = $level->toEpistemicLabel();
                $verified = $finalLabel !== null;

                $claim = new GroundedAnswerClaim;
                $claim->forceFill([
                    'grounded_answer_run_id' => $run->id,
                    'ordinal' => $ordinal,
                    'claim_key' => $draft->claimKey,
                    'claim_text' => $draft->text,
                    'generator_suggested_label' => $draft->suggestedLabel,
                    'final_label' => $finalLabel?->value,
                    'verification_status' => $verified
                        ? ClaimVerificationStatus::Verified
                        : ClaimVerificationStatus::Rejected,
                    'verifier_support_level' => $verdict->supportLevel,
                    'verifier_reason_code' => $verdict->reasonCode,
                ])->save();

                if (! $verified) {
                    $anyRejected = true;

                    continue;
                }

                $verifiedCount++;

                // The verifier's evidence selection is authoritative.
                $evidenceIds = [];

                foreach ($verdict->supportedEvidenceKeys as $key) {
                    $evidence = $evidenceByKey->get($key);

                    if ($evidence === null) {
                        continue; // validated upstream; defensive
                    }

                    if ($evidence->citation_number === null) {
                        $evidence->forceFill(['citation_number' => ++$citationCounter])->save();
                    }

                    $evidenceIds[] = $evidence->id;
                }

                $claim->evidence()->attach($evidenceIds);
            }

            if ($verifiedCount === 0) {
                return null;
            }

            return ($generated->status === 'partially_answered' || $anyRejected)
                ? AnswerOutcome::PartiallyAnswered
                : AnswerOutcome::Answered;
        });
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
