<?php

namespace App\Services\Answers;

/**
 * task-contract 1.0.0 — structured description of WHAT one subquestion
 * asks for. Exists to prevent grounded-but-irrelevant answers: a claim
 * that does not fit the contract cannot satisfy the task, and tasks
 * beyond honest M3 capability are declared BEFORE expensive generation.
 * Deliberately small — not an ontology.
 */
class TaskContract
{
    public const VERSION = 'task-contract 1.0.0';

    // Task types
    public const FACT_LOOKUP = 'fact_lookup';

    public const YES_NO_FACT = 'yes_no_fact';

    public const RELATIONSHIP_LOOKUP = 'relationship_lookup';

    public const LOCAL_DESCRIPTION = 'local_description';

    public const LOCAL_EXPLANATION = 'local_explanation';

    public const LIST_ENTITIES = 'list_entities';

    public const TOP_N_RANKING = 'top_n_ranking';

    public const COMPARISON = 'comparison';

    public const QUOTE_LOCATION = 'quote_location';

    public const TEMPORAL_EVOLUTION = 'temporal_evolution';

    public const GLOBAL_SUMMARY = 'global_summary';

    public const IDENTITY_REVEAL = 'identity_reveal';

    public const TRICKY_INFERENCE = 'tricky_inference';

    // Answer shapes
    public const SHAPE_SCALAR = 'scalar';

    public const SHAPE_YES_NO = 'yes_no_with_explanation';

    public const SHAPE_DESCRIPTION = 'description';

    public const SHAPE_LIST = 'list';

    public const SHAPE_TOP_N_LIST = 'top_n_list';

    public const SHAPE_COMPARISON = 'comparison';

    public const SHAPE_LOCATION = 'location';

    public const SHAPE_EVOLUTION = 'evolution';

    public const SHAPE_EXPLANATION = 'explanation';

    public function __construct(
        public readonly string $subquestionKey,
        public readonly string $question,
        public readonly string $taskType,
        public readonly string $answerShape,
        public readonly ?string $targetEntityType,
        public readonly ?int $requestedCount,
        public readonly string $coverageRequirement, // local | global | longitudinal
        public readonly ?string $relationshipType,
        public readonly bool $requiresRanking,
        public readonly bool $supportedInM3,
        public readonly ?string $capabilityNotice,
        /** @var list<string> content terms preserved for retrieval/relevance */
        public readonly array $anchorTerms = [],
    ) {}

    /** Auditable representation persisted inside the run's subquestions JSON. */
    public function toArray(): array
    {
        return [
            'task_type' => $this->taskType,
            'answer_shape' => $this->answerShape,
            'entity_type' => $this->targetEntityType,
            'requested_count' => $this->requestedCount,
            'coverage' => $this->coverageRequirement,
            'relationship' => $this->relationshipType,
            'requires_ranking' => $this->requiresRanking,
            'supported_in_m3' => $this->supportedInM3,
            'capability_notice' => $this->capabilityNotice,
        ];
    }
}
