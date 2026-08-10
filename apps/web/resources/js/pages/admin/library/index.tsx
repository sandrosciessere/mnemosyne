import { EmptyState } from '@/components/empty-state';
import { formatDate } from '@/components/library/format';
import { Paginator } from '@/components/library/paginator';
import { StatusBadge } from '@/components/library/status-badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Paginator as PaginatorData } from '@/types/library';
import { Head, Link, router } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Library admin',
        href: '/admin/library',
    },
];

interface WorkRow {
    public_id: string;
    title: string;
    language: string | null;
    status: string;
    editions_count: number;
    created_at: string;
}

interface LibraryIndexProps {
    filters: { q: string | null };
    works: PaginatorData<WorkRow>;
}

export default function LibraryIndex({ filters, works }: LibraryIndexProps) {
    const [search, setSearch] = useState(filters.q ?? '');
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timeout = window.setTimeout(() => {
            const params: Record<string, string> = {};
            if (search.trim()) {
                params.q = search.trim();
            }
            router.get('/admin/library', params, { preserveState: true, replace: true });
        }, 400);
        return () => window.clearTimeout(timeout);
    }, [search]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Library admin" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Works</h1>
                        <p className="text-muted-foreground text-sm">{works.total} works in the catalog.</p>
                    </div>
                    <div className="grid min-w-52 gap-1.5 sm:max-w-xs">
                        <Label htmlFor="works-search">Search title</Label>
                        <Input
                            id="works-search"
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="e.g. Moby Dick"
                        />
                    </div>
                </div>

                {works.data.length === 0 ? (
                    <EmptyState
                        icon={BookOpen}
                        title="No works found"
                        description="Works appear here once EPUB submissions are ingested and reconciled."
                    />
                ) : (
                    <>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Title
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Language
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Editions
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Created
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {works.data.map((work) => (
                                        <tr
                                            key={work.public_id}
                                            className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/admin/library/works/${work.public_id}`}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {work.title}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3">{work.language ?? '—'}</td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={work.status} />
                                            </td>
                                            <td className="px-4 py-3 tabular-nums">{work.editions_count}</td>
                                            <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">{formatDate(work.created_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Paginator paginator={works} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
