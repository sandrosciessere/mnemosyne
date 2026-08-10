import { EventsTimeline } from '@/components/library/events-timeline';
import { formatDate } from '@/components/library/format';
import { IngestionProgress } from '@/components/library/ingestion-progress';
import { StageStepper } from '@/components/library/stage-stepper';
import { StatusBadge } from '@/components/library/status-badge';
import { StructureSummaryList } from '@/components/library/structure-summary';
import { usePoll } from '@/components/library/use-poll';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type Contributor, type PipelineEvent, type RunStatus, type StructureSummary, type SubmissionStatus } from '@/types/library';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Copy, Download } from 'lucide-react';
import { useState } from 'react';

interface SubmissionDetail {
    public_id: string;
    original_filename: string;
    note: string | null;
    status: SubmissionStatus;
    approval_status: string | null;
    rejection_reason: string | null;
    is_exact_duplicate: boolean;
    created_at: string;
    can_cancel: boolean;
}

interface RunDetail {
    public_id: string;
    status: RunStatus;
    current_stage: string | null;
    progress: number | null;
    started_at: string | null;
    finished_at: string | null;
    error_code: string | null;
    error_message: string | null;
    review_issues: { code: string; message: string }[];
}

interface AssetDetail {
    public_id: string;
    ingestion_status: string;
    epub_version: string | null;
    structure_summary: StructureSummary | null;
    title: string | null;
    work_title: string | null;
    contributors: Contributor[];
    can_download: boolean;
}

interface ShowProps {
    submission: SubmissionDetail;
    run: RunDetail | null;
    asset: AssetDetail | null;
    events: PipelineEvent[];
}

const ACTIVE_SUBMISSION_STATUSES: SubmissionStatus[] = ['queued', 'processing'];
const ACTIVE_RUN_STATUSES: RunStatus[] = ['queued', 'running'];

export default function SubmissionShow({ submission, run, asset, events }: ShowProps) {
    const [cancelOpen, setCancelOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Library', href: '/library' },
        { title: 'My submissions', href: '/library/submissions' },
        { title: submission.original_filename, href: `/library/submissions/${submission.public_id}` },
    ];

    const isActive = ACTIVE_SUBMISSION_STATUSES.includes(submission.status) || (run !== null && ACTIVE_RUN_STATUSES.includes(run.status));
    usePoll(isActive, ['submission', 'run', 'asset', 'events'], 5000);

    const cancel = () => {
        router.post(
            `/library/submissions/${submission.public_id}/cancel`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setCancelOpen(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={submission.original_filename} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <CardTitle className="text-lg break-all">{submission.original_filename}</CardTitle>
                                <StatusBadge status={submission.status} />
                            </div>
                            {submission.can_cancel && (
                                <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="destructive" size="sm">
                                            Cancel submission
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Cancel this submission?</DialogTitle>
                                            <DialogDescription>
                                                Ingestion of “{submission.original_filename}” will be stopped. This cannot be undone; you can submit
                                                the file again later.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button variant="ghost">Keep submission</Button>
                                            </DialogClose>
                                            <Button variant="destructive" onClick={cancel}>
                                                Cancel submission
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <p className="text-muted-foreground">Submitted {formatDate(submission.created_at)}</p>
                        {submission.note && (
                            <p>
                                <span className="text-muted-foreground">Note:</span> {submission.note}
                            </p>
                        )}
                        {submission.is_exact_duplicate && (
                            <Alert>
                                <Copy aria-hidden="true" className="size-4" />
                                <AlertTitle>Exact duplicate</AlertTitle>
                                <AlertDescription>This file already existed in the library — you were linked to the existing book.</AlertDescription>
                            </Alert>
                        )}
                        {submission.status === 'rejected' && (
                            <Alert variant="destructive">
                                <AlertTriangle aria-hidden="true" className="size-4" />
                                <AlertTitle>Submission rejected</AlertTitle>
                                <AlertDescription>{submission.rejection_reason ?? 'No reason was provided.'}</AlertDescription>
                            </Alert>
                        )}
                    </CardContent>
                </Card>

                {run && (
                    <Card>
                        <CardHeader className="pb-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <CardTitle className="text-base">Ingestion run</CardTitle>
                                <StatusBadge status={run.status} />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <StageStepper currentStage={run.current_stage} status={run.status} />
                            <IngestionProgress progress={run.progress} status={run.status} />
                            <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                <span>Started: {formatDate(run.started_at)}</span>
                                <span>Finished: {formatDate(run.finished_at)}</span>
                            </div>
                            {run.status === 'failed' && (run.error_code || run.error_message) && (
                                <Alert variant="destructive">
                                    <AlertTriangle aria-hidden="true" className="size-4" />
                                    <AlertTitle>Ingestion failed{run.error_code ? ` (${run.error_code})` : ''}</AlertTitle>
                                    <AlertDescription>{run.error_message ?? 'No further details available.'}</AlertDescription>
                                </Alert>
                            )}
                            {run.status === 'needs_review' && run.review_issues.length > 0 && (
                                <Alert>
                                    <AlertTriangle aria-hidden="true" className="size-4" />
                                    <AlertTitle>Review required</AlertTitle>
                                    <AlertDescription>
                                        <p className="mb-1">An administrator will review these issues:</p>
                                        <ul className="list-disc space-y-0.5 pl-4">
                                            {run.review_issues.map((issue) => (
                                                <li key={issue.code}>
                                                    <span className="font-medium">{issue.code}</span>: {issue.message}
                                                </li>
                                            ))}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            )}
                        </CardContent>
                    </Card>
                )}

                {asset && (
                    <Card>
                        <CardHeader className="pb-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <CardTitle className="text-base">Book</CardTitle>
                                <StatusBadge status={asset.ingestion_status} />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <dl className="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground text-xs">Title</dt>
                                    <dd className="font-medium">{asset.title ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Work</dt>
                                    <dd>{asset.work_title ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Contributors</dt>
                                    <dd>{asset.contributors.length > 0 ? asset.contributors.map((c) => `${c.name} (${c.role})`).join(', ') : '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">EPUB version</dt>
                                    <dd>{asset.epub_version ?? '—'}</dd>
                                </div>
                            </dl>
                            <div>
                                <h3 className="text-muted-foreground mb-1 text-xs font-medium">Structure</h3>
                                <StructureSummaryList summary={asset.structure_summary} />
                            </div>
                            {asset.can_download && (
                                <a
                                    href={`/library/books/${asset.public_id}/download`}
                                    className={cn(buttonVariants({ variant: 'outline', size: 'sm' }))}
                                >
                                    <Download aria-hidden="true" />
                                    Download EPUB
                                </a>
                            )}
                        </CardContent>
                    </Card>
                )}

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
