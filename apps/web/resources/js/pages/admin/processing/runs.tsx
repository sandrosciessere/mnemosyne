import { EmptyState } from '@/components/empty-state';
import { formatDate } from '@/components/library/format';
import { IngestionProgress } from '@/components/library/ingestion-progress';
import { Paginator } from '@/components/library/paginator';
import { INGESTION_STAGES, STAGE_LABELS, stageLabel } from '@/components/library/stage-stepper';
import { StatusBadge } from '@/components/library/status-badge';
import { usePoll } from '@/components/library/use-poll';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type IngestionPriority, type Paginator as PaginatorData, type RunStatus } from '@/types/library';
import { Head, Link, router } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Processing', href: '/admin/processing' },
    { title: 'Runs', href: '/admin/processing/runs' },
];

const RUN_STATUSES: RunStatus[] = ['queued', 'running', 'needs_review', 'failed', 'succeeded', 'cancelled'];
const PRIORITIES: IngestionPriority[] = ['high', 'normal', 'low'];
const ACTIVE_RUN_STATUSES: RunStatus[] = ['queued', 'running'];

interface RunRow {
    public_id: string;
    filename: string;
    submitter: string | null;
    source_type: string | null;
    status: RunStatus;
    stage: string | null;
    priority: IngestionPriority;
    progress: number | null;
    queued_at: string | null;
    updated_at: string | null;
}

interface Filters {
    status: string | null;
    stage: string | null;
    priority: string | null;
    q: string | null;
}

interface RunsProps {
    filters: Filters;
    runs: PaginatorData<RunRow>;
}

export default function Runs({ filters, runs }: RunsProps) {
    const [search, setSearch] = useState(filters.q ?? '');
    const firstRender = useRef(true);

    usePoll(true, ['runs'], 15000);

    const applyFilters = (overrides: Partial<Filters>) => {
        const next: Record<string, string> = {};
        const merged = { ...filters, q: search, ...overrides };
        for (const key of ['status', 'stage', 'priority', 'q'] as const) {
            const value = merged[key];
            if (value && value !== 'all') {
                next[key] = value;
            }
        }
        router.get('/admin/processing/runs', next, { preserveState: true, replace: true });
    };

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timeout = window.setTimeout(() => applyFilters({ q: search }), 400);
        return () => window.clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ingestion runs" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div>
                    <h1 className="text-xl font-semibold">Ingestion runs</h1>
                    <p className="text-muted-foreground text-sm">{runs.total} runs match the current filters.</p>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-status">Status</Label>
                        <Select value={filters.status ?? 'all'} onValueChange={(value) => applyFilters({ status: value })}>
                            <SelectTrigger id="filter-status" className="w-40">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {RUN_STATUSES.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {status.replaceAll('_', ' ')}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-stage">Stage</Label>
                        <Select value={filters.stage ?? 'all'} onValueChange={(value) => applyFilters({ stage: value })}>
                            <SelectTrigger id="filter-stage" className="w-40">
                                <SelectValue placeholder="All stages" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All stages</SelectItem>
                                {INGESTION_STAGES.map((stage) => (
                                    <SelectItem key={stage} value={stage}>
                                        {STAGE_LABELS[stage]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-priority">Priority</Label>
                        <Select value={filters.priority ?? 'all'} onValueChange={(value) => applyFilters({ priority: value })}>
                            <SelectTrigger id="filter-priority" className="w-36">
                                <SelectValue placeholder="All priorities" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All priorities</SelectItem>
                                {PRIORITIES.map((priority) => (
                                    <SelectItem key={priority} value={priority}>
                                        {priority}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid min-w-52 flex-1 gap-1.5 sm:max-w-xs">
                        <Label htmlFor="filter-q">Search filename</Label>
                        <Input
                            id="filter-q"
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="e.g. moby-dick.epub"
                        />
                    </div>
                </div>

                {runs.data.length === 0 ? (
                    <EmptyState icon={ListChecks} title="No runs found" description="No ingestion runs match the current filters." />
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
                                            Stage
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Priority
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Progress
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Queued
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Updated
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {runs.data.map((run) => (
                                        <tr
                                            key={run.public_id}
                                            className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/admin/processing/runs/${run.public_id}`}
                                                    className="font-medium break-all underline-offset-4 hover:underline"
                                                >
                                                    {run.filename}
                                                </Link>
                                                {run.source_type && <p className="text-muted-foreground text-xs">{run.source_type}</p>}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-3">{run.submitter ?? '—'}</td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={run.status} />
                                            </td>
                                            <td className="px-4 py-3">{stageLabel(run.stage)}</td>
                                            <td className="px-4 py-3">{run.priority}</td>
                                            <td className="min-w-40 px-4 py-3">
                                                {ACTIVE_RUN_STATUSES.includes(run.status) || run.status === 'needs_review' ? (
                                                    <IngestionProgress progress={run.progress} status={run.status} />
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">{formatDate(run.queued_at)}</td>
                                            <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">{formatDate(run.updated_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Paginator paginator={runs} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
