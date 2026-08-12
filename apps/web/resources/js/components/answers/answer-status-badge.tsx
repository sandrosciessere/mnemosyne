import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type AnswerStatus } from '@/types/answers';

const TERMINAL_STYLES: Partial<Record<AnswerStatus, string>> = {
    ready: 'border-transparent bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200',
    insufficient: 'border-transparent bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200',
    failed: 'border-transparent bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-200',
};

const ACTIVE_STYLE = 'border-transparent bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-200';

/** Answer run status badge: ready=green, insufficient=amber, failed=red, active pipeline states=blue. */
export function AnswerStatusBadge({ status, className }: { status: AnswerStatus; className?: string }) {
    return <Badge className={cn('hover:bg-inherit', TERMINAL_STYLES[status] ?? ACTIVE_STYLE, className)}>{status.replaceAll('_', ' ')}</Badge>;
}
