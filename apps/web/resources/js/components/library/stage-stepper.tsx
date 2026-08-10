import { cn } from '@/lib/utils';
import { type IngestionStage, type PipelineStageInfo } from '@/types/library';
import { AlertTriangle, Ban, Check, Circle, Copy, LoaderCircle, Pause, X } from 'lucide-react';

export const INGESTION_STAGES: IngestionStage[] = ['hash', 'validate', 'parse', 'normalize', 'structure'];

export const STAGE_LABELS: Record<IngestionStage, string> = {
    hash: 'Hash',
    validate: 'Validate',
    parse: 'Parse',
    normalize: 'Normalize',
    structure: 'Structure',
};

export function stageLabel(stage: string | null | undefined): string {
    if (!stage) {
        return '—';
    }
    return STAGE_LABELS[stage as IngestionStage] ?? stage;
}

type StageState = 'pending' | 'in_progress' | 'done' | 'failed' | 'needs_review' | 'paused' | 'reused' | 'not_executed';

const STATE_TEXT: Record<StageState, string> = {
    pending: 'Pending',
    in_progress: 'In progress',
    done: 'Done',
    failed: 'Failed',
    needs_review: 'Needs review',
    paused: 'Paused',
    reused: 'Reused',
    not_executed: 'Not executed',
};

/** Durable per-stage facts (attempt-backed) → visual state. */
function stateFromExecution(info: PipelineStageInfo, runStatus: string): StageState {
    switch (info.execution_status) {
        case 'succeeded':
            return 'done';
        case 'failed':
            return 'failed';
        case 'needs_review':
            return 'needs_review';
        case 'running':
            return runStatus === 'paused' ? 'paused' : 'in_progress';
        case 'reused':
            return 'reused';
        case 'cancelled':
        case 'not_executed':
            return 'not_executed';
        default:
            return runStatus === 'paused' ? 'paused' : 'pending';
    }
}

function stageState(stage: IngestionStage, currentStage: string | null | undefined, status: string): StageState {
    if (status === 'succeeded' || status === 'completed' || status === 'ready_for_enrichment' || status === 'ready_for_enrichment_with_warnings') {
        return 'done';
    }
    const currentIndex = currentStage ? INGESTION_STAGES.indexOf(currentStage as IngestionStage) : -1;
    if (currentIndex === -1) {
        return 'pending';
    }
    const index = INGESTION_STAGES.indexOf(stage);
    if (index < currentIndex) {
        return 'done';
    }
    if (index > currentIndex) {
        return 'pending';
    }
    if (status === 'failed') {
        return 'failed';
    }
    if (status === 'needs_review') {
        return 'needs_review';
    }
    if (status === 'paused') {
        return 'paused';
    }
    if (status === 'queued' || status === 'cancelled' || status === 'skipped') {
        return 'pending';
    }
    return 'in_progress';
}

function StageIcon({ state }: { state: StageState }) {
    const className = 'size-4';
    switch (state) {
        case 'done':
            return <Check className={className} aria-hidden="true" />;
        case 'failed':
            return <X className={className} aria-hidden="true" />;
        case 'needs_review':
            return <AlertTriangle className={className} aria-hidden="true" />;
        case 'in_progress':
            return <LoaderCircle className={cn(className, 'animate-spin')} aria-hidden="true" />;
        case 'paused':
            return <Pause className={className} aria-hidden="true" />;
        case 'reused':
            return <Copy className={className} aria-hidden="true" />;
        case 'not_executed':
            return <Ban className={className} aria-hidden="true" />;
        default:
            return <Circle className={className} aria-hidden="true" />;
    }
}

interface StageStepperProps {
    currentStage: string | null | undefined;
    status: string;
    /**
     * Durable per-stage execution facts from the backend. When provided
     * the stepper renders ONLY these (an exact-duplicate run shows
     * "Reused", never a fake "Done"); the legacy inference from
     * currentStage/status remains as a fallback for callers without it.
     */
    stages?: PipelineStageInfo[];
    className?: string;
}

export function StageStepper({ currentStage, status, stages, className }: StageStepperProps) {
    const byStage = new Map((stages ?? []).map((info) => [info.stage, info]));

    return (
        <ol className={cn('grid gap-2 sm:grid-cols-5', className)} aria-label="Ingestion stages">
            {INGESTION_STAGES.map((stage) => {
                const info = byStage.get(stage);
                const state = info ? stateFromExecution(info, status) : stageState(stage, currentStage, status);
                return (
                    <li
                        key={stage}
                        aria-current={state === 'in_progress' ? 'step' : undefined}
                        className={cn(
                            'flex items-center gap-2 rounded-md border p-2 sm:flex-col sm:items-start',
                            state === 'in_progress' && 'border-primary',
                            state === 'failed' && 'border-destructive',
                            (state === 'pending' || state === 'done' || state === 'paused' || state === 'reused' || state === 'not_executed') &&
                                'border-sidebar-border/70 dark:border-sidebar-border',
                        )}
                    >
                        <span
                            className={cn(
                                'flex items-center gap-1.5 text-sm font-medium',
                                (state === 'pending' || state === 'reused' || state === 'not_executed') && 'text-muted-foreground',
                                state === 'failed' && 'text-destructive',
                            )}
                        >
                            <StageIcon state={state} />
                            {STAGE_LABELS[stage]}
                        </span>
                        <span className="text-muted-foreground text-xs">{STATE_TEXT[state]}</span>
                    </li>
                );
            })}
        </ol>
    );
}
