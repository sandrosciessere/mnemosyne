import { EventsTimeline } from '@/components/library/events-timeline';
import { formatBytes, formatDate, formatDuration, formatRelative } from '@/components/library/format';
import { IngestionProgress } from '@/components/library/ingestion-progress';
import { MetadataList } from '@/components/library/metadata-list';
import { StageStepper, stageLabel } from '@/components/library/stage-stepper';
import { StatusBadge } from '@/components/library/status-badge';
import { StructureSummaryList } from '@/components/library/structure-summary';
import { usePoll } from '@/components/library/use-poll';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type Contributor,
    type IngestionPriority,
    type PipelineEvent,
    type Reconciliation,
    type ReviewIssue,
    type RunStatus,
    type StructureSummary,
} from '@/types/library';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';

interface RunDetail {
    public_id: string;
    status: RunStatus;
    stage: string | null;
    priority: IngestionPriority;
    progress: number | null;
    pipeline_version: string | null;
    cancel_requested: boolean;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    heartbeat_at: string | null;
    error_code: string | null;
    error_message: string | null;
    review_issues: ReviewIssue[];
    overridden_issues: string[];
    correlation_id: string | null;
}

interface SubmissionSummary {
    public_id: string;
    original_filename: string;
    source_type: string | null;
    submitter: { name: string; email: string } | null;
    note: string | null;
    is_exact_duplicate: boolean;
    created_at: string;
}

interface AssetDetail {
    public_id: string;
    sha256: string | null;
    content_sha256: string | null;
    epub_version: string | null;
    size_bytes: number | null;
    ingestion_status: string;
    validation_status: string | null;
    metadata: Record<string, unknown> | null;
    structure_summary: StructureSummary | null;
    reconciliation: Reconciliation | null;
    edition: {
        public_id: string;
        title: string;
        language: string | null;
        publisher: string | null;
        work: { public_id: string; title: string; status: string };
        contributors: Contributor[];
    } | null;
}

interface Attempt {
    stage: string;
    attempt: number;
    status: string;
    handler_version: string | null;
    duration_ms: number | null;
    error_code: string | null;
    error_message: string | null;
    result_summary: unknown;
    started_at: string | null;
}

interface Duplicate {
    public_id: string;
    reason: string;
    status: string;
    other_asset: { public_id: string; original_filename: string } | null;
    evidence: unknown;
}

interface ShowProps {
    run: RunDetail;
    submission: SubmissionSummary | null;
    asset: AssetDetail | null;
    attempts: Attempt[];
    events: PipelineEvent[];
    duplicates: Duplicate[];
}

const ACTIVE_STATUSES: RunStatus[] = ['queued', 'running'];
const CANCELLABLE: RunStatus[] = ['queued', 'running', 'needs_review'];
const RETRYABLE: RunStatus[] = ['failed', 'needs_review'];

function truncateHash(hash: string | null): string {
    if (!hash) {
        return '—';
    }
    return hash.length > 16 ? `${hash.slice(0, 16)}…` : hash;
}

function severityVariant(severity: string | undefined): 'destructive' | 'secondary' | 'outline' {
    if (severity === 'block' || severity === 'blocker' || severity === 'security' || severity === 'critical' || severity === 'error') {
        return 'destructive';
    }
    if (severity === 'warning' || severity === 'warn') {
        return 'secondary';
    }
    return 'outline';
}

export default function RunShow({ run, submission, asset, attempts, events, duplicates }: ShowProps) {
    const [cancelOpen, setCancelOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Processing', href: '/admin/processing' },
        { title: 'Runs', href: '/admin/processing/runs' },
        { title: submission?.original_filename ?? run.public_id, href: `/admin/processing/runs/${run.public_id}` },
    ];

    usePoll(ACTIVE_STATUSES.includes(run.status), ['run', 'submission', 'asset', 'attempts', 'events', 'duplicates'], 5000);

    const baseUrl = `/admin/processing/runs/${run.public_id}`;

    const retry = () => {
        router.post(`${baseUrl}/retry`, {}, { preserveScroll: true });
    };

    const cancel = () => {
        router.post(`${baseUrl}/cancel`, {}, { preserveScroll: true, onFinish: () => setCancelOpen(false) });
    };

    const setPriority = (priority: string) => {
        router.patch(`${baseUrl}/priority`, { priority }, { preserveScroll: true });
    };

    const override = (code: string) => {
        router.post(`${baseUrl}/override`, { code }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Run ${run.public_id}`} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold break-all">{submission?.original_filename ?? `Run ${run.public_id}`}</h1>
                            <StatusBadge status={run.status} />
                            {run.cancel_requested && <Badge variant="outline">Cancel requested</Badge>}
                        </div>
                        <p className="text-muted-foreground text-sm">
                            Run {run.public_id}
                            {run.pipeline_version ? ` · pipeline ${run.pipeline_version}` : ''}
                            {run.correlation_id ? ` · correlation ${run.correlation_id}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="run-priority" className="text-xs">
                                Priority
                            </Label>
                            <Select value={run.priority} onValueChange={setPriority}>
                                <SelectTrigger id="run-priority" className="h-9 w-28">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="high">high</SelectItem>
                                    <SelectItem value="normal">normal</SelectItem>
                                    <SelectItem value="low">low</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        {RETRYABLE.includes(run.status) && (
                            <Button variant="outline" size="sm" onClick={retry}>
                                Retry
                            </Button>
                        )}
                        {CANCELLABLE.includes(run.status) && (
                            <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                                <DialogTrigger asChild>
                                    <Button variant="destructive" size="sm">
                                        Cancel run
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Cancel this run?</DialogTitle>
                                        <DialogDescription>
                                            The ingestion run will be stopped. The submission can be retried later if needed.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button variant="ghost">Keep running</Button>
                                        </DialogClose>
                                        <Button variant="destructive" onClick={cancel}>
                                            Cancel run
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Pipeline</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <StageStepper currentStage={run.stage} status={run.status} />
                        <IngestionProgress progress={run.progress} status={run.status} />
                        <dl className="text-muted-foreground grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                            <div>
                                <dt>Queued</dt>
                                <dd className="text-foreground">{formatDate(run.queued_at)}</dd>
                            </div>
                            <div>
                                <dt>Started</dt>
                                <dd className="text-foreground">{formatDate(run.started_at)}</dd>
                            </div>
                            <div>
                                <dt>Finished</dt>
                                <dd className="text-foreground">{formatDate(run.finished_at)}</dd>
                            </div>
                            <div>
                                <dt>Last heartbeat</dt>
                                <dd className="text-foreground" title={formatDate(run.heartbeat_at)}>
                                    {run.heartbeat_at ? formatRelative(run.heartbeat_at) : '—'}
                                </dd>
                            </div>
                        </dl>
                        {run.status === 'failed' && (run.error_code || run.error_message) && (
                            <Alert variant="destructive">
                                <AlertTriangle aria-hidden="true" className="size-4" />
                                <AlertTitle>Run failed{run.error_code ? ` (${run.error_code})` : ''}</AlertTitle>
                                <AlertDescription>{run.error_message ?? 'No further details available.'}</AlertDescription>
                            </Alert>
                        )}
                    </CardContent>
                </Card>

                {run.review_issues.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Review issues</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-3">
                                {run.review_issues.map((issue) => {
                                    const overridden = run.overridden_issues.includes(issue.code);
                                    return (
                                        <li
                                            key={issue.code}
                                            className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-start justify-between gap-2 rounded-md border p-3"
                                        >
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className={overridden ? 'text-muted-foreground font-medium line-through' : 'font-medium'}>
                                                        {issue.code}
                                                    </span>
                                                    {issue.severity && <Badge variant={severityVariant(issue.severity)}>{issue.severity}</Badge>}
                                                    {issue.stage && (
                                                        <span className="text-muted-foreground text-xs">stage: {stageLabel(issue.stage)}</span>
                                                    )}
                                                    {overridden && <Badge variant="outline">Overridden</Badge>}
                                                </div>
                                                <p className={overridden ? 'text-muted-foreground text-sm line-through' : 'text-sm'}>
                                                    {issue.message}
                                                </p>
                                                {issue.details !== null && issue.details !== undefined && (
                                                    <p className="text-muted-foreground text-xs break-all">{JSON.stringify(issue.details)}</p>
                                                )}
                                                {!issue.overrideable && !overridden && (
                                                    <p className="text-destructive text-xs font-medium">Security block — cannot be overridden</p>
                                                )}
                                            </div>
                                            {issue.overrideable && !overridden && (
                                                <Button variant="outline" size="sm" onClick={() => override(issue.code)}>
                                                    Override
                                                </Button>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {submission && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Submission</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <dl className="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                                    <div>
                                        <dt className="text-muted-foreground text-xs">File</dt>
                                        <dd className="font-medium break-all">{submission.original_filename}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground text-xs">Submitter</dt>
                                        <dd>{submission.submitter ? `${submission.submitter.name} (${submission.submitter.email})` : '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground text-xs">Source</dt>
                                        <dd>{submission.source_type ?? '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground text-xs">Submitted</dt>
                                        <dd>{formatDate(submission.created_at)}</dd>
                                    </div>
                                </dl>
                                {submission.note && (
                                    <p>
                                        <span className="text-muted-foreground">Note:</span> {submission.note}
                                    </p>
                                )}
                                {submission.is_exact_duplicate && (
                                    <p className="text-muted-foreground text-xs">Exact duplicate of an existing asset.</p>
                                )}
                                <Link href="/admin/submissions" className="text-sm underline-offset-4 hover:underline">
                                    View submissions queue
                                </Link>
                            </CardContent>
                        </Card>
                    )}

                    {asset && (
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <CardTitle className="text-base">Asset</CardTitle>
                                    <StatusBadge status={asset.ingestion_status} />
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <dl className="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                                    <div>
                                        <dt className="text-muted-foreground text-xs">SHA-256</dt>
                                        <dd className="font-mono text-xs" title={asset.sha256 ?? undefined}>
                                            {truncateHash(asset.sha256)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground text-xs">Content SHA-256</dt>
                                        <dd className="font-mono text-xs" title={asset.content_sha256 ?? undefined}>
                                            {truncateHash(asset.content_sha256)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground text-xs">Size</dt>
                                        <dd>{formatBytes(asset.size_bytes)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground text-xs">EPUB version</dt>
                                        <dd>{asset.epub_version ?? '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground text-xs">Validation</dt>
                                        <dd>{asset.validation_status ?? '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground text-xs">Reconciliation</dt>
                                        <dd>
                                            {asset.reconciliation
                                                ? `${asset.reconciliation.method}${
                                                      asset.reconciliation.confidence !== null && asset.reconciliation.confidence !== undefined
                                                          ? ` (confidence ${asset.reconciliation.confidence})`
                                                          : ''
                                                  }`
                                                : '—'}
                                        </dd>
                                    </div>
                                </dl>
                                <div>
                                    <h3 className="text-muted-foreground mb-1 text-xs font-medium">Structure</h3>
                                    <StructureSummaryList summary={asset.structure_summary} />
                                </div>
                                <Link href={`/admin/library/assets/${asset.public_id}`} className="text-sm underline-offset-4 hover:underline">
                                    View asset detail
                                </Link>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {asset?.edition && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Edition and work</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm">
                            <dl className="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground text-xs">Edition</dt>
                                    <dd className="font-medium">{asset.edition.title}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Work</dt>
                                    <dd>
                                        <Link
                                            href={`/admin/library/works/${asset.edition.work.public_id}`}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {asset.edition.work.title}
                                        </Link>{' '}
                                        <span className="text-muted-foreground text-xs">({asset.edition.work.status})</span>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Language</dt>
                                    <dd>{asset.edition.language ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Publisher</dt>
                                    <dd>{asset.edition.publisher ?? '—'}</dd>
                                </div>
                                <div className="sm:col-span-2">
                                    <dt className="text-muted-foreground text-xs">Contributors</dt>
                                    <dd>
                                        {asset.edition.contributors.length > 0
                                            ? asset.edition.contributors.map((c) => `${c.name} (${c.role})`).join(', ')
                                            : '—'}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                )}

                {duplicates.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Possible duplicates</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-2 text-sm">
                                {duplicates.map((duplicate) => (
                                    <li key={duplicate.public_id} className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            {duplicate.other_asset ? (
                                                <Link
                                                    href={`/admin/library/assets/${duplicate.other_asset.public_id}`}
                                                    className="font-medium break-all underline-offset-4 hover:underline"
                                                >
                                                    {duplicate.other_asset.original_filename}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">Unknown asset</span>
                                            )}
                                            <p className="text-muted-foreground text-xs">Reason: {duplicate.reason.replaceAll('_', ' ')}</p>
                                        </div>
                                        <StatusBadge status={duplicate.status} />
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {asset && (
                    <Card>
                        <CardContent className="pt-6">
                            <Collapsible>
                                <CollapsibleTrigger asChild>
                                    <Button variant="ghost" size="sm" className="-ml-2">
                                        <ChevronsUpDown aria-hidden="true" />
                                        Normalized metadata
                                    </Button>
                                </CollapsibleTrigger>
                                <CollapsibleContent className="pt-3">
                                    <MetadataList metadata={asset.metadata} />
                                </CollapsibleContent>
                            </Collapsible>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Stage attempts</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {attempts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No attempts recorded yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[44rem] text-sm">
                                    <thead>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                Stage
                                            </th>
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                #
                                            </th>
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                Status
                                            </th>
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                Handler
                                            </th>
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                Duration
                                            </th>
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                Error
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {attempts.map((attempt, index) => (
                                            <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0">
                                                <td className="px-3 py-2">{stageLabel(attempt.stage)}</td>
                                                <td className="px-3 py-2 tabular-nums">{attempt.attempt}</td>
                                                <td className="px-3 py-2">
                                                    <StatusBadge status={attempt.status} />
                                                </td>
                                                <td className="text-muted-foreground px-3 py-2">{attempt.handler_version ?? '—'}</td>
                                                <td className="px-3 py-2 whitespace-nowrap">{formatDuration(attempt.duration_ms)}</td>
                                                <td className="text-muted-foreground px-3 py-2">
                                                    {attempt.error_code ? (
                                                        <span title={attempt.error_message ?? undefined}>{attempt.error_code}</span>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Events</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <EventsTimeline events={events} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
