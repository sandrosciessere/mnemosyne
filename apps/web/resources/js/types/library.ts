export * from './retrieval';

export interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginator<T> {
    data: T[];
    links: PaginatorLink[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

export type SubmissionStatus =
    | 'pending_approval'
    | 'approved'
    | 'queued'
    | 'processing'
    | 'paused'
    | 'needs_review'
    | 'failed'
    | 'completed'
    | 'rejected'
    | 'cancelled'
    | 'unsupported';

export type RunStatus = 'queued' | 'running' | 'paused' | 'needs_review' | 'failed' | 'succeeded' | 'cancelled' | 'skipped';

export type AssetIngestionStatus =
    | 'pending'
    | 'processing'
    | 'needs_review'
    | 'failed'
    | 'ready_for_enrichment'
    | 'ready_for_enrichment_with_warnings'
    | 'unsupported';

export type IngestionStage = 'hash' | 'validate' | 'parse' | 'normalize' | 'structure';

export type IngestionPriority = 'high' | 'normal' | 'low';

export interface Contributor {
    name: string;
    role: string;
}

export interface StructureSummary {
    spine_items?: number;
    sections?: number;
    nodes?: number;
    text_chars?: number;
    toc_entries?: number;
    headings?: number;
    paragraphs?: number;
}

export interface ReviewIssue {
    code: string;
    severity?: string;
    message: string;
    overrideable?: boolean;
    details?: unknown;
    stage?: string;
}

export interface PipelineEvent {
    type: string;
    payload?: Record<string, unknown> | null;
    actor?: string | null;
    created_at: string;
}

export interface Book {
    public_id: string;
    title: string;
    authors: string[];
    language: string | null;
    ingestion_status: string;
    epub_version: string | null;
    can_download: boolean;
}

export interface Reconciliation {
    method: string;
    confidence: number | string | null;
    evidence?: unknown;
    version?: string | number | null;
}

/** Durable per-stage execution fact derived from stage attempts. */
export interface PipelineStageInfo {
    stage: IngestionStage;
    execution_status: 'succeeded' | 'failed' | 'needs_review' | 'running' | 'cancelled' | 'reused' | 'not_executed' | 'pending';
    attempts: number;
    duration_ms: number | null;
}

/** Aggregated unique warning (one per code, across stages). */
export interface WarningSummaryItem {
    code: string;
    message: string;
    stages: string[];
    occurrences: number;
    details: Record<string, unknown>;
}

/** Exact-duplicate disposition of a run. */
export interface DuplicateInfo {
    reused_asset: {
        public_id: string;
        ingestion_status: string;
        original_filename: string;
    };
    disposition: string | null;
}
