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
    | 'needs_review'
    | 'failed'
    | 'completed'
    | 'rejected'
    | 'cancelled';

export type RunStatus = 'queued' | 'running' | 'needs_review' | 'failed' | 'succeeded' | 'cancelled';

export type AssetIngestionStatus = 'pending' | 'processing' | 'needs_review' | 'failed' | 'ready_for_enrichment';

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
