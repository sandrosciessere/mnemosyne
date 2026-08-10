import { cn } from '@/lib/utils';

interface IngestionProgressProps {
    /** Progress as a percentage between 0 and 100. */
    progress: number | null | undefined;
    /** Related submission/run/asset status, used to adjust the label. */
    status?: string | null;
    className?: string;
}

/**
 * Progress bar for the ingestion pipeline only. It intentionally does not
 * claim anything about analysis/enrichment, which happen later.
 */
export function IngestionProgress({ progress, status, className }: IngestionProgressProps) {
    const readyWithWarnings = status === 'ready_for_enrichment_with_warnings';
    const ready = status === 'completed' || status === 'ready_for_enrichment' || status === 'succeeded' || readyWithWarnings;
    const value = ready ? 100 : Math.max(0, Math.min(100, Math.round(progress ?? 0)));
    const label = readyWithWarnings ? 'Ready for enrichment (with warnings)' : ready ? 'Ready for enrichment' : 'Ingestion progress';

    return (
        <div className={cn('w-full', className)}>
            <div className="mb-1 flex items-center justify-between gap-2 text-xs">
                <span className="text-muted-foreground">{label}</span>
                <span className="text-foreground font-medium tabular-nums">{value}%</span>
            </div>
            <div
                role="progressbar"
                aria-label={label}
                aria-valuenow={value}
                aria-valuemin={0}
                aria-valuemax={100}
                className="bg-secondary h-2 w-full overflow-hidden rounded-full"
            >
                <div className="bg-primary h-full rounded-full transition-all" style={{ width: `${value}%` }} />
            </div>
        </div>
    );
}
