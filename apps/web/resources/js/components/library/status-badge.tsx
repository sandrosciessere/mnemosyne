import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

const STATUS_MAP: Record<string, { label: string; variant: BadgeVariant; className?: string }> = {
    // Submission statuses
    pending_approval: { label: 'Pending approval', variant: 'outline' },
    approved: { label: 'Approved', variant: 'outline' },
    processing: { label: 'Processing', variant: 'secondary' },
    completed: { label: 'Completed', variant: 'default' },
    rejected: { label: 'Rejected', variant: 'destructive' },
    unsupported: { label: 'Not supported', variant: 'outline' },
    // Run statuses
    queued: { label: 'Queued', variant: 'outline' },
    running: { label: 'Running', variant: 'secondary' },
    paused: { label: 'Paused', variant: 'secondary' },
    needs_review: { label: 'Needs review', variant: 'secondary' },
    failed: { label: 'Failed', variant: 'destructive' },
    succeeded: { label: 'Succeeded', variant: 'default' },
    cancelled: { label: 'Cancelled', variant: 'outline' },
    skipped: { label: 'Unsupported', variant: 'outline' },
    // Asset statuses
    pending: { label: 'Pending', variant: 'outline' },
    ready_for_enrichment: { label: 'Ready for enrichment', variant: 'default' },
    ready_for_enrichment_with_warnings: {
        label: 'Ready (with warnings)',
        variant: 'secondary',
        className: 'bg-yellow-100 text-yellow-900 dark:bg-yellow-900/40 dark:text-yellow-200',
    },
};

export function statusLabel(status: string): string {
    return STATUS_MAP[status]?.label ?? status.replaceAll('_', ' ');
}

export function StatusBadge({ status, className }: { status: string; className?: string }) {
    const entry = STATUS_MAP[status] ?? { label: status.replaceAll('_', ' '), variant: 'outline' as BadgeVariant };

    return (
        <Badge variant={entry.variant} className={cn(entry.className, className)}>
            {entry.label}
        </Badge>
    );
}
