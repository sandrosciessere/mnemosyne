import { formatDate, formatDurationSeconds, formatRelative } from '@/components/library/format';
import { stageLabel } from '@/components/library/stage-stepper';
import { StatusBadge } from '@/components/library/status-badge';
import { usePoll } from '@/components/library/use-poll';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Processing',
        href: '/admin/processing',
    },
];

interface ProcessingProps {
    summary: {
        pending_approval: number;
        queued: number;
        running: number;
        needs_review: number;
        failed: number;
        ready_for_enrichment: number;
        completed_last_day: number;
    };
    queue: {
        depths: { high: number; normal: number; low: number };
        oldest_queued_at: string | null;
        configured_concurrency: number;
    };
    stages: Record<string, number>;
    recent_failures: {
        public_id: string;
        filename: string;
        status: string;
        stage: string | null;
        error_code: string | null;
        finished_at: string | null;
    }[];
    recent_completions: {
        public_id: string;
        filename: string;
        duration_seconds: number | null;
        finished_at: string | null;
    }[];
}

function StatCard({ label, value, href }: { label: string; value: number; href: string }) {
    return (
        <Link
            href={href}
            className="border-sidebar-border/70 dark:border-sidebar-border hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring flex flex-col gap-1 rounded-xl border p-4 focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <span className="text-2xl font-semibold tabular-nums">{value}</span>
            <span className="text-muted-foreground text-sm">{label}</span>
        </Link>
    );
}

export default function Processing({ summary, queue, stages, recent_failures, recent_completions }: ProcessingProps) {
    usePoll(true, ['summary', 'queue', 'stages', 'recent_failures', 'recent_completions'], 15000);

    const stageEntries = Object.entries(stages ?? {});
    const maxStageCount = Math.max(1, ...stageEntries.map(([, count]) => count));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Processing" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">Processing</h1>
                        <p className="text-muted-foreground text-sm">Ingestion control plane. Updates every 15 seconds.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href="/admin/submissions">Submissions</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/admin/processing/runs">All runs</Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Pending approval" value={summary.pending_approval} href="/admin/submissions?status=pending_approval" />
                    <StatCard label="Queued" value={summary.queued} href="/admin/processing/runs?status=queued" />
                    <StatCard label="Running" value={summary.running} href="/admin/processing/runs?status=running" />
                    <StatCard label="Needs review" value={summary.needs_review} href="/admin/processing/runs?status=needs_review" />
                    <StatCard label="Failed" value={summary.failed} href="/admin/processing/runs?status=failed" />
                    <StatCard label="Ready for enrichment" value={summary.ready_for_enrichment} href="/admin/library" />
                    <StatCard label="Completed (last 24h)" value={summary.completed_last_day} href="/admin/processing/runs?status=succeeded" />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Queue</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="text-muted-foreground text-xs">High priority</dt>
                                    <dd className="font-medium tabular-nums">{queue.depths.high}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Normal priority</dt>
                                    <dd className="font-medium tabular-nums">{queue.depths.normal}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Low priority</dt>
                                    <dd className="font-medium tabular-nums">{queue.depths.low}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Oldest queued</dt>
                                    <dd className="font-medium" title={formatDate(queue.oldest_queued_at)}>
                                        {queue.oldest_queued_at ? formatRelative(queue.oldest_queued_at) : '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Concurrency</dt>
                                    <dd className="font-medium tabular-nums">{queue.configured_concurrency}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Active runs by stage</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {stageEntries.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No active runs.</p>
                            ) : (
                                <ul className="space-y-2">
                                    {stageEntries.map(([stage, count]) => (
                                        <li key={stage} className="flex items-center gap-3 text-sm">
                                            <span className="w-24 shrink-0">{stageLabel(stage)}</span>
                                            <span className="bg-secondary h-3 flex-1 overflow-hidden rounded-sm" aria-hidden="true">
                                                <span
                                                    className="bg-primary block h-full rounded-sm"
                                                    style={{ width: `${Math.round((count / maxStageCount) * 100)}%` }}
                                                />
                                            </span>
                                            <span className="w-8 text-right font-medium tabular-nums">{count}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Recent failures</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recent_failures.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No recent failures.</p>
                            ) : (
                                <ul className="space-y-2">
                                    {recent_failures.map((failure) => (
                                        <li key={failure.public_id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                            <div className="min-w-0">
                                                <Link
                                                    href={`/admin/processing/runs/${failure.public_id}`}
                                                    className="font-medium break-all underline-offset-4 hover:underline"
                                                >
                                                    {failure.filename}
                                                </Link>
                                                <p className="text-muted-foreground text-xs">
                                                    {stageLabel(failure.stage)}
                                                    {failure.error_code ? ` · ${failure.error_code}` : ''}
                                                    {failure.finished_at ? ` · ${formatRelative(failure.finished_at)}` : ''}
                                                </p>
                                            </div>
                                            <StatusBadge status={failure.status} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Recent completions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recent_completions.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No recent completions.</p>
                            ) : (
                                <ul className="space-y-2">
                                    {recent_completions.map((completion) => (
                                        <li key={completion.public_id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                            <Link
                                                href={`/admin/processing/runs/${completion.public_id}`}
                                                className="min-w-0 font-medium break-all underline-offset-4 hover:underline"
                                            >
                                                {completion.filename}
                                            </Link>
                                            <span className="text-muted-foreground text-xs">
                                                {formatDurationSeconds(completion.duration_seconds)}
                                                {completion.finished_at ? ` · ${formatRelative(completion.finished_at)}` : ''}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
