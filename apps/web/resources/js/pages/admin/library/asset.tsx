import { formatBytes, formatDate } from '@/components/library/format';
import { MetadataList } from '@/components/library/metadata-list';
import { StatusBadge } from '@/components/library/status-badge';
import { StructureSummaryList } from '@/components/library/structure-summary';
import { WarningsSummary } from '@/components/library/warnings-summary';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type Reconciliation, type StructureSummary, type WarningSummaryItem } from '@/types/library';
import { Head, Link } from '@inertiajs/react';
import { ChevronsUpDown, Download } from 'lucide-react';

interface AssetDetail {
    public_id: string;
    original_filename?: string | null;
    sha256?: string | null;
    content_sha256?: string | null;
    size_bytes?: number | null;
    epub_version?: string | null;
    ingestion_status: string;
    validation_status: string | null;
    pipeline_version: string | null;
    storage_path: string | null;
    metadata: Record<string, unknown> | null;
    structure_summary: StructureSummary | null;
    reconciliation: Reconciliation | null;
    edition: {
        public_id: string;
        title: string;
        work_public_id: string;
        work_title: string;
    } | null;
    created_at: string;
    can_download?: boolean;
}

interface AssetSubmission {
    public_id: string;
    submitter: { name: string; email: string } | null;
    source_type: string | null;
    is_exact_duplicate: boolean;
    created_at: string;
}

interface AssetRun {
    public_id: string;
    status: string;
    pipeline_version: string | null;
    finished_at: string | null;
}

interface AssetDuplicate {
    public_id: string;
    reason: string;
    status: string;
    other_asset: { public_id: string; original_filename: string } | null;
}

interface AssetShowProps {
    asset: AssetDetail;
    submissions: AssetSubmission[];
    runs: AssetRun[];
    duplicates: AssetDuplicate[];
    warnings_summary: WarningSummaryItem[];
}

function HashValue({ hash }: { hash: string | null | undefined }) {
    if (!hash) {
        return <span>—</span>;
    }
    return (
        <span className="font-mono text-xs" title={hash}>
            {hash.length > 16 ? `${hash.slice(0, 16)}…` : hash}
        </span>
    );
}

export default function AssetShow({ asset, submissions, runs, duplicates, warnings_summary }: AssetShowProps) {
    const name = asset.original_filename ?? asset.public_id;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Library admin', href: '/admin/library' },
        { title: name, href: `/admin/library/assets/${asset.public_id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={name} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <CardTitle className="text-lg break-all">{name}</CardTitle>
                                <StatusBadge status={asset.ingestion_status} />
                                {warnings_summary.length > 0 && (
                                    <a href="#warnings" className="text-muted-foreground text-xs underline underline-offset-2">
                                        {warnings_summary.length === 1 ? '1 warning' : `${warnings_summary.length} warnings`} — why?
                                    </a>
                                )}
                            </div>
                            <a href={`/library/books/${asset.public_id}/download`} className={cn(buttonVariants({ variant: 'outline', size: 'sm' }))}>
                                <Download aria-hidden="true" />
                                Download EPUB
                            </a>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <dl className="grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground text-xs">SHA-256</dt>
                                <dd>
                                    <HashValue hash={asset.sha256} />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Content SHA-256</dt>
                                <dd>
                                    <HashValue hash={asset.content_sha256} />
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
                                <dt className="text-muted-foreground text-xs">Pipeline version</dt>
                                <dd>{asset.pipeline_version ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Storage path</dt>
                                <dd className="font-mono text-xs break-all">{asset.storage_path ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Created</dt>
                                <dd>{formatDate(asset.created_at)}</dd>
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
                            {asset.edition && (
                                <div>
                                    <dt className="text-muted-foreground text-xs">Edition / work</dt>
                                    <dd>
                                        {asset.edition.title} —{' '}
                                        <Link
                                            href={`/admin/library/works/${asset.edition.work_public_id}`}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {asset.edition.work_title}
                                        </Link>
                                    </dd>
                                </div>
                            )}
                        </dl>
                        <div>
                            <h3 className="text-muted-foreground mb-1 text-xs font-medium">Structure</h3>
                            <StructureSummaryList summary={asset.structure_summary} />
                        </div>
                        <Collapsible>
                            <CollapsibleTrigger className={cn(buttonVariants({ variant: 'ghost', size: 'sm' }), '-ml-2')}>
                                <ChevronsUpDown aria-hidden="true" />
                                Normalized metadata
                            </CollapsibleTrigger>
                            <CollapsibleContent className="pt-3">
                                <MetadataList metadata={asset.metadata} />
                            </CollapsibleContent>
                        </Collapsible>
                    </CardContent>
                </Card>

                <WarningsSummary warnings={warnings_summary} />

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Submissions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {submissions.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No submissions linked to this asset.</p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {submissions.map((submission) => (
                                        <li key={submission.public_id} className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <span className="font-medium">
                                                    {submission.submitter
                                                        ? `${submission.submitter.name} (${submission.submitter.email})`
                                                        : 'Filesystem import'}
                                                </span>
                                                <p className="text-muted-foreground text-xs">
                                                    {submission.source_type ?? ''}
                                                    {submission.is_exact_duplicate ? ' · exact duplicate' : ''}
                                                </p>
                                            </div>
                                            <span className="text-muted-foreground text-xs">{formatDate(submission.created_at)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Possible duplicates</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {duplicates.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No duplicate candidates.</p>
                            ) : (
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
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Ingestion runs</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {runs.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No runs for this asset.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[32rem] text-sm">
                                    <thead>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                Run
                                            </th>
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                Status
                                            </th>
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                Pipeline
                                            </th>
                                            <th scope="col" className="px-3 py-2 font-medium">
                                                Finished
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {runs.map((run) => (
                                            <tr
                                                key={run.public_id}
                                                className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0"
                                            >
                                                <td className="px-3 py-2">
                                                    <Link
                                                        href={`/admin/processing/runs/${run.public_id}`}
                                                        className="font-mono text-xs underline-offset-4 hover:underline"
                                                    >
                                                        {run.public_id}
                                                    </Link>
                                                </td>
                                                <td className="px-3 py-2">
                                                    <StatusBadge status={run.status} />
                                                </td>
                                                <td className="px-3 py-2">{run.pipeline_version ?? '—'}</td>
                                                <td className="text-muted-foreground px-3 py-2 whitespace-nowrap">{formatDate(run.finished_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
