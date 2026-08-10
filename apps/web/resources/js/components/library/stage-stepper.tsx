import { cn } from '@/lib/utils';
import { type IngestionStage } from '@/types/library';
import { AlertTriangle, Check, Circle, LoaderCircle, X } from 'lucide-react';

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

type StageState = 'pending' | 'in_progress' | 'done' | 'failed' | 'needs_review';

const STATE_TEXT: Record<StageState, string> = {
    pending: 'Pending',
    in_progress: 'In progress',
    done: 'Done',
    failed: 'Failed',
    needs_review: 'Needs review',
};

function stageState(stage: IngestionStage, currentStage: string | null | undefined, status: string): StageState {
    if (status === 'succeeded' || status === 'completed' || status === 'ready_for_enrichment') {
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
    if (status === 'queued' || status === 'cancelled') {
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
        default:
            return <Circle className={className} aria-hidden="true" />;
    }
}

interface StageStepperProps {
    currentStage: string | null | undefined;
    status: string;
    className?: string;
}

export function StageStepper({ currentStage, status, className }: StageStepperProps) {
    return (
        <ol className={cn('grid gap-2 sm:grid-cols-5', className)} aria-label="Ingestion stages">
            {INGESTION_STAGES.map((stage) => {
                const state = stageState(stage, currentStage, status);
                return (
                    <li
                        key={stage}
                        aria-current={state === 'in_progress' ? 'step' : undefined}
                        className={cn(
                            'flex items-center gap-2 rounded-md border p-2 sm:flex-col sm:items-start',
                            state === 'in_progress' && 'border-primary',
                            state === 'failed' && 'border-destructive',
                            (state === 'pending' || state === 'done') && 'border-sidebar-border/70 dark:border-sidebar-border',
                        )}
                    >
                        <span
                            className={cn(
                                'flex items-center gap-1.5 text-sm font-medium',
                                state === 'pending' && 'text-muted-foreground',
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
