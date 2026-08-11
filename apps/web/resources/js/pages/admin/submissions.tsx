import { EmptyState } from '@/components/empty-state';
import { formatDate } from '@/components/library/format';
import { Paginator } from '@/components/library/paginator';
import { StatusBadge } from '@/components/library/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type IngestionPriority, type Paginator as PaginatorData, type SubmissionStatus } from '@/types/library';
import { Head, Link, router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Submissions',
        href: '/admin/submissions',
    },
];

const FILTER_STATUSES: SubmissionStatus[] = [
    'pending_approval',
    'queued',
    'processing',
    'needs_review',
    'failed',
    'completed',
    'rejected',
    'cancelled',
];

interface SubmissionRow {
    public_id: string;
    original_filename: string;
    status: SubmissionStatus;
    source_type: string | null;
    submitter: { name: string; email: string } | null;
    priority: IngestionPriority;
    note: string | null;
    is_exact_duplicate: boolean;
    run_public_id: string | null;
    created_at: string;
}

interface AdminSubmissionsProps {
    filters: { status: string | null };
    pending_count: number;
    submissions: PaginatorData<SubmissionRow>;
}

export default function AdminSubmissions({ filters, pending_count, submissions }: AdminSubmissionsProps) {
    const [rejectTarget, setRejectTarget] = useState<SubmissionRow | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [rejectError, setRejectError] = useState<string | null>(null);

    const setStatus = (value: string) => {
        const params: Record<string, string> = {};
        if (value !== 'all') {
            params.status = value;
        }
        router.get('/admin/submissions', params, { preserveState: true, replace: true });
    };

    const approve = (submission: SubmissionRow) => {
        router.post(`/admin/submissions/${submission.public_id}/approve`, {}, { preserveScroll: true });
    };

    const openReject = (submission: SubmissionRow) => {
        setRejectTarget(submission);
        setRejectReason('');
        setRejectError(null);
    };

    const reject = () => {
        if (!rejectTarget) {
            return;
        }
        if (rejectReason.trim().length === 0) {
            setRejectError('A rejection reason is required.');
            return;
        }
        router.post(
            `/admin/submissions/${rejectTarget.public_id}/reject`,
            { reason: rejectReason.trim() },
            {
                preserveScroll: true,
                onSuccess: () => setRejectTarget(null),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Submissions" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Submissions</h1>
                        <p className="text-muted-foreground text-sm">
                            {pending_count} {pending_count === 1 ? 'submission' : 'submissions'} awaiting approval.
                        </p>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-status">Status</Label>
                        <Select value={filters.status ?? 'all'} onValueChange={setStatus}>
                            <SelectTrigger id="filter-status" className="w-44">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {FILTER_STATUSES.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {status.replaceAll('_', ' ')}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {submissions.data.length === 0 ? (
                    <EmptyState icon={Inbox} title="No submissions found" description="No submissions match the current filter." />
                ) : (
                    <>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[56rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            File
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Submitter
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Priority
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Submitted
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Actions
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
                                                {submission.run_public_id ? (
                                                    <Link
                                                        href={`/admin/processing/runs/${submission.run_public_id}`}
                                                        className="font-medium break-all underline-offset-4 hover:underline"
                                                    >
                                                        {submission.original_filename}
                                                    </Link>
                                                ) : (
                                                    <span className="font-medium break-all">{submission.original_filename}</span>
                                                )}
                                                <p className="text-muted-foreground text-xs">
                                                    {submission.source_type ?? ''}
                                                    {submission.is_exact_duplicate ? ' · exact duplicate' : ''}
                                                </p>
                                                {submission.note && <p className="text-muted-foreground text-xs">Note: {submission.note}</p>}
                                            </td>
                                            <td className="px-4 py-3">
                                                {submission.submitter ? (
                                                    <>
                                                        <span>{submission.submitter.name}</span>
                                                        <p className="text-muted-foreground text-xs">{submission.submitter.email}</p>
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={submission.status} />
                                            </td>
                                            <td className="px-4 py-3">{submission.priority}</td>
                                            <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">{formatDate(submission.created_at)}</td>
                                            <td className="px-4 py-3">
                                                {submission.status === 'pending_approval' ? (
                                                    <div className="flex gap-2">
                                                        <Button size="sm" onClick={() => approve(submission)}>
                                                            Approve
                                                        </Button>
                                                        <Button size="sm" variant="destructive" onClick={() => openReject(submission)}>
                                                            Reject
                                                        </Button>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Paginator paginator={submissions} />
                    </>
                )}
            </div>

            <Dialog open={rejectTarget !== null} onOpenChange={(open) => !open && setRejectTarget(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reject submission</DialogTitle>
                        <DialogDescription>
                            Reject “{rejectTarget?.original_filename}”. The submitter will see the reason you provide.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="reject-reason">Reason (required)</Label>
                        <textarea
                            id="reject-reason"
                            value={rejectReason}
                            onChange={(e) => {
                                setRejectReason(e.target.value);
                                setRejectError(null);
                            }}
                            rows={3}
                            required
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm"
                        />
                        {rejectError && <p className="text-sm text-red-600 dark:text-red-400">{rejectError}</p>}
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={reject}>
                            Reject submission
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
