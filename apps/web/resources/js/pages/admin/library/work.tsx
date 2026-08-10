import { formatBytes, formatDate } from '@/components/library/format';
import { StatusBadge } from '@/components/library/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Contributor, type Reconciliation } from '@/types/library';
import { Head, Link } from '@inertiajs/react';

interface WorkDetail {
    public_id: string;
    title: string;
    normalized_title: string | null;
    language: string | null;
    status: string;
    reconciliation: Reconciliation | null;
    created_at: string;
}

interface EditionAsset {
    public_id: string;
    original_filename: string;
    sha256: string | null;
    ingestion_status: string;
    size_bytes: number | null;
    epub_version: string | null;
}

interface Edition {
    public_id: string;
    title: string;
    subtitle: string | null;
    language: string | null;
    publisher: string | null;
    publication_year: number | null;
    status: string;
    contributors: Contributor[];
    identifiers: { scheme: string; value: string }[];
    assets: EditionAsset[];
}

export default function WorkShow({ work, editions }: { work: WorkDetail; editions: Edition[] }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Library admin', href: '/admin/library' },
        { title: work.title, href: `/admin/library/works/${work.public_id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={work.title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <CardTitle className="text-lg">{work.title}</CardTitle>
                            <StatusBadge status={work.status} />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground text-xs">Normalized title</dt>
                                <dd>{work.normalized_title ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Language</dt>
                                <dd>{work.language ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Reconciliation</dt>
                                <dd>
                                    {work.reconciliation
                                        ? `${work.reconciliation.method}${
                                              work.reconciliation.confidence !== null && work.reconciliation.confidence !== undefined
                                                  ? ` (confidence ${work.reconciliation.confidence})`
                                                  : ''
                                          }`
                                        : '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Created</dt>
                                <dd>{formatDate(work.created_at)}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <h2 className="text-base font-semibold">
                    {editions.length} {editions.length === 1 ? 'edition' : 'editions'}
                </h2>

                {editions.map((edition) => (
                    <Card key={edition.public_id}>
                        <CardHeader className="pb-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <CardTitle className="text-base">
                                    {edition.title}
                                    {edition.subtitle ? ` — ${edition.subtitle}` : ''}
                                </CardTitle>
                                <StatusBadge status={edition.status} />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <dl className="grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <dt className="text-muted-foreground text-xs">Language</dt>
                                    <dd>{edition.language ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Publisher</dt>
                                    <dd>{edition.publisher ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Publication year</dt>
                                    <dd>{edition.publication_year ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Identifiers</dt>
                                    <dd>
                                        {edition.identifiers.length > 0
                                            ? edition.identifiers.map((identifier) => `${identifier.scheme}: ${identifier.value}`).join(', ')
                                            : '—'}
                                    </dd>
                                </div>
                                <div className="sm:col-span-2 lg:col-span-4">
                                    <dt className="text-muted-foreground text-xs">Contributors</dt>
                                    <dd>
                                        {edition.contributors.length > 0 ? edition.contributors.map((c) => `${c.name} (${c.role})`).join(', ') : '—'}
                                    </dd>
                                </div>
                            </dl>

                            {edition.assets.length === 0 ? (
                                <p className="text-muted-foreground">No assets for this edition.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[40rem] text-sm">
                                        <thead>
                                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                                <th scope="col" className="px-3 py-2 font-medium">
                                                    File
                                                </th>
                                                <th scope="col" className="px-3 py-2 font-medium">
                                                    Status
                                                </th>
                                                <th scope="col" className="px-3 py-2 font-medium">
                                                    Size
                                                </th>
                                                <th scope="col" className="px-3 py-2 font-medium">
                                                    EPUB
                                                </th>
                                                <th scope="col" className="px-3 py-2 font-medium">
                                                    SHA-256
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {edition.assets.map((asset) => (
                                                <tr
                                                    key={asset.public_id}
                                                    className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0"
                                                >
                                                    <td className="px-3 py-2">
                                                        <Link
                                                            href={`/admin/library/assets/${asset.public_id}`}
                                                            className="font-medium break-all underline-offset-4 hover:underline"
                                                        >
                                                            {asset.original_filename}
                                                        </Link>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <StatusBadge status={asset.ingestion_status} />
                                                    </td>
                                                    <td className="px-3 py-2 whitespace-nowrap">{formatBytes(asset.size_bytes)}</td>
                                                    <td className="px-3 py-2">{asset.epub_version ?? '—'}</td>
                                                    <td className="px-3 py-2 font-mono text-xs" title={asset.sha256 ?? undefined}>
                                                        {asset.sha256 ? `${asset.sha256.slice(0, 16)}…` : '—'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </AppLayout>
    );
}
