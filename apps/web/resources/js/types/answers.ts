/** Persisted grounded-answer pipeline status (real backend state, never a client-side timer). */
export type AnswerStatus = 'queued' | 'retrieving' | 'expanding_retrieval' | 'generating' | 'verifying' | 'ready' | 'insufficient' | 'failed';

/** Terminal answer semantics — insufficient evidence is a success state, not an error. */
export type AnswerOutcome = 'answered' | 'partially_answered' | 'insufficient_evidence';

/** Epistemic label of a verified claim — the confidence representation (no scores). */
export type ClaimLabel = 'textual_fact' | 'strong_inference' | 'interpretation' | 'conflict';

/** One verified claim of a grounded answer. Claim text is UNTRUSTED model output. */
export interface ClaimData {
    key: string;
    text: string;
    label: ClaimLabel | null;
    label_user: string | null;
    citations: number[];
}

/** One numbered citation resolving to a durable evidence snapshot. Excerpt is UNTRUSTED book text. */
export interface CitationData {
    number: number;
    evidence_key: string;
    book_asset_id: string | null;
    book_title: string | null;
    work_title: string | null;
    edition_label: string | null;
    heading_path: string[];
    node_type: string | null;
    spine_index: number;
    source_href: string | null;
    source_fragment: string | null;
    epub_cfi: string | null;
    canonical_start: number;
    canonical_end: number;
    excerpt: string;
    stale: boolean;
    stale_reason: string | null;
}

/** Canonical user-facing answer representation (GET /api/v1/answers/{id}). */
export interface AnswerData {
    id: string;
    status: AnswerStatus;
    outcome: AnswerOutcome | null;
    question: string;
    conversation_id: string | null;
    intent: string | null;
    capability_notice: string | null;
    retrieval_expanded: boolean;
    claims: ClaimData[];
    rejected_claim_count: number;
    citations: CitationData[];
    scope: { book_asset_id: string; title: string }[];
    skipped_assets: string[];
    error_code: string | null;
    created_at: string | null;
    completed_at: string | null;
}

/** One message of a conversation. Assistant messages carry the referenced grounded answer. */
export interface ConversationMessage {
    id: string;
    role: 'user' | 'assistant';
    created_at: string | null;
    content?: string;
    answer?: AnswerData | null;
}

/** Conversation detail (GET /api/v1/conversations/{id}). */
export interface ConversationDetail {
    id: string;
    title: string | null;
    messages: ConversationMessage[];
}

/** Summary row for the conversation picker. */
export interface ConversationSummary {
    id: string;
    title: string | null;
    last_activity_at: string | null;
}

/** Admin diagnostics attached to an answer when requested with debug access. */
export interface AnswerDiagnostics {
    classifier_version: string | null;
    retrieval_profile_version: string | null;
    unitizer_version: string | null;
    generator: {
        provider: string | null;
        model: string | null;
        revision: string | null;
        prompt_version: string | null;
    };
    verifier: {
        provider: string | null;
        model: string | null;
        revision: string | null;
        prompt_version: string | null;
    };
    retrieval_generation: string | null;
    evidence_stats: Record<string, unknown> | null;
    retrieval_diagnostics: Record<string, unknown> | null;
    timings_ms: Record<string, number> | null;
    error_message: string | null;
}

/** Answer with the admin diagnostics block (admin inspector). */
export interface AdminAnswerData extends AnswerData {
    diagnostics: AnswerDiagnostics;
}

/** Full claim audit row incl. rejected claims with the verifier verdict. */
export interface AdminClaimAudit {
    key: string;
    ordinal: number;
    text: string;
    generator_suggested_label: string | null;
    final_label: string | null;
    verification_status: string;
    verifier_support_level: string | null;
    verifier_reason_code: string | null;
    evidence_keys: string[];
}

/** Evidence packet unit; citation number is null for uncited units. */
export type AdminEvidenceData = Omit<CitationData, 'number'> & {
    number: number | null;
    retrieval_meta: Record<string, unknown> | null;
    unitizer_version: string | null;
};

/** Summary row of the admin answers list. */
export interface AdminAnswerRunSummary {
    id: string;
    question: string;
    status: AnswerStatus;
    outcome: AnswerOutcome | null;
    intent: string | null;
    user: { name: string | null; email: string | null };
    error_code: string | null;
    created_at: string | null;
    completed_at: string | null;
}
