import { EmptyState } from '@/components/empty-state';
import { formatDate } from '@/components/library/format';
import { IngestionProgress } from '@/components/library/ingestion-progress';
import { Paginator } from '@/components/library/paginator';
import { stageLabel } from '@/components/library/stage-stepper';
import { StatusBadge } from '@/components/library/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Paginator as PaginatorData, type SubmissionStatus } from '@/types/library';
import { Head, Link } from '@inertiajs/react';
import { Inbox, Upload } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Library', href: '/library' },
    { title: 'My submissions', href: '/library/submissions' },
];

interface SubmissionRow {
    public_id: string;
    original_filename: string;
    status: SubmissionStatus;
    note: string | null;
    rejection_reason: string | null;
    progress: number | null;
    current_stage: string | null;
    is_exact_duplicate: boolean;
    created_at: string;
}

const PROGRESS_STATUSES: SubmissionStatus[] = ['queued', 'processing', 'needs_review'];

export default function SubmissionsIndex({ submissions }: { submissions: PaginatorData<SubmissionRow> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My submissions" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">My submissions</h1>
                        <p className="text-muted-foreground text-sm">EPUB files you have submitted for ingestion into the library.</p>
                    </div>
                    <Button asChild>
                        <Link href="/library/submissions/create">
                            <Upload aria-hidden="true" />
                            Submit EPUB
                        </Link>
                    </Button>
                </div>

                {submissions.data.length === 0 ? (
                    <EmptyState
                        icon={Inbox}
                        title="No submissions yet"
                        description="Submit an EPUB file and you will be able to follow its ingestion progress here."
                    >
                        <Button asChild className="mt-2">
                            <Link href="/library/submissions/create">
                                <Upload aria-hidden="true" />
                                Submit EPUB
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            File
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Stage
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Progress
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Submitted
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {submissions.data.map((submission) => (
                                        <tr
                                            key={submission.public_id}
                                            className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/library/submissions/${submission.public_id}`}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {submission.original_filename}
                                                </Link>
                                                {submission.is_exact_duplicate && (
                                                    <p className="text-muted-foreground text-xs">Duplicate of an existing book</p>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={submission.status} />
                                            </td>
                                            <td className="px-4 py-3">{stageLabel(submission.current_stage)}</td>
                                            <td className="min-w-40 px-4 py-3">
                                                {PROGRESS_STATUSES.includes(submission.status) ? (
                                                    <IngestionProgress progress={submission.progress} status={submission.status} />
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">{formatDate(submission.created_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Paginator paginator={submissions} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
