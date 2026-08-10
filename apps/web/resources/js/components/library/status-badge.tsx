import { Badge } from '@/components/ui/badge';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

const STATUS_MAP: Record<string, { label: string; variant: BadgeVariant }> = {
    // Submission statuses
    pending_approval: { label: 'Pending approval', variant: 'outline' },
    approved: { label: 'Approved', variant: 'outline' },
    processing: { label: 'Processing', variant: 'secondary' },
    completed: { label: 'Completed', variant: 'default' },
    rejected: { label: 'Rejected', variant: 'destructive' },
    // Run statuses
    queued: { label: 'Queued', variant: 'outline' },
    running: { label: 'Running', variant: 'secondary' },
    needs_review: { label: 'Needs review', variant: 'secondary' },
    failed: { label: 'Failed', variant: 'destructive' },
    succeeded: { label: 'Succeeded', variant: 'default' },
    cancelled: { label: 'Cancelled', variant: 'outline' },
    // Asset statuses
    pending: { label: 'Pending', variant: 'outline' },
    ready_for_enrichment: { label: 'Ready for enrichment', variant: 'default' },
};

export function statusLabel(status: string): string {
    return STATUS_MAP[status]?.label ?? status.replaceAll('_', ' ');
}

export function StatusBadge({ status, className }: { status: string; className?: string }) {
    const entry = STATUS_MAP[status] ?? { label: status.replaceAll('_', ' '), variant: 'outline' as BadgeVariant };

    return (
        <Badge variant={entry.variant} className={className}>
            {entry.label}
        </Badge>
    );
}
