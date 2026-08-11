import { formatDate } from '@/components/library/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type GenerationStatus, type GenerationSummary } from '@/types/retrieval';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Retrieval',
        href: '/admin/retrieval',
    },
];

interface RetrievalIndexProps {
    generations: GenerationSummary[];
    eligible_assets: number;
}

const STATUS_META: Record<GenerationStatus, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    active: { label: 'Active', variant: 'default' },
    building: { label: 'Building', variant: 'secondary' },
    superseded: { label: 'Superseded', variant: 'outline' },
    failed: { label: 'Failed', variant: 'destructive' },
};

function GenerationStatusBadge({ status }: { status: GenerationStatus }) {
    const meta = STATUS_META[status] ?? { label: status, variant: 'outline' as const };
    return <Badge variant={meta.variant}>{meta.label}</Badge>;
}

function InlineStat({ label, value, destructive = false }: { label: string; value: number; destructive?: boolean }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className={destructive && value > 0 ? 'text-destructive font-medium tabular-nums' : 'font-medium tabular-nums'}>
                {value}
                {destructive && value > 0 ? ' — needs attention' : ''}
            </dd>
        </div>
    );
}

function formatWeights(weights: Record<string, number>): string {
    return Object.entries(weights)
        .map(([key, value]) => `${key}:${value}`)
        .join(' ');
}

function GenerationCard({ generation }: { generation: GenerationSummary }) {
    const isActive = generation.status === 'active';

    return (
        <Card
            className={
                isActive
                    ? 'border-primary'
                    : 'border-sidebar-border/70 dark:border-sidebar-border ' + (generation.status === 'superseded' ? 'opacity-75' : '')
            }
        >
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-center gap-2">
                    <CardTitle className="text-base">{generation.embedding.model_key}</CardTitle>
                    <GenerationStatusBadge status={generation.status} />
                    <span className="text-muted-foreground font-mono text-xs">{generation.public_id}</span>
                </div>
                <p className="text-muted-foreground text-sm">
                    <span className="font-mono" title={`${generation.embedding.hf_id}@${generation.embedding.revision}`}>
                        {generation.embedding.hf_id}@{generation.embedding.revision.slice(0, 12)}
                    </span>{' '}
                    · {generation.embedding.dimensions} dims · {generation.embedding.metric}
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                    <div className="sm:col-span-3">
                        <dt className="text-muted-foreground text-xs">Chunker</dt>
                        <dd className="font-mono text-xs">
                            {generation.chunker_version} · target:{generation.chunker_config.target_chars} min:{generation.chunker_config.min_chars}{' '}
                            max:{generation.chunker_config.max_chars} overlap_tail:{generation.chunker_config.overlap_tail_chars}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-xs">Fusion</dt>
                        <dd className="font-mono text-xs">
                            {generation.fusion
                                ? `${generation.fusion.algorithm} v${generation.fusion.version} k:${generation.fusion.k} ${formatWeights(generation.fusion.weights)}`
                                : '—'}
                        </dd>
                    </div>
                    <div className="sm:col-span-2">
                        <dt className="text-muted-foreground text-xs">Reranker</dt>
                        <dd className="font-mono text-xs">
                            {generation.reranker ? `${generation.reranker.provider} · ${generation.reranker.model_key}` : 'none'}
                        </dd>
                    </div>
                </dl>

                <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4 lg:grid-cols-7">
                    <InlineStat label="Ready" value={generation.assets.ready} />
                    <InlineStat label="Pending" value={generation.assets.pending} />
                    <InlineStat label="Chunking" value={generation.assets.chunking} />
                    <InlineStat label="Embedding" value={generation.assets.embedding} />
                    <InlineStat label="Failed" value={generation.assets.failed} destructive />
                    <InlineStat label="Chunks" value={generation.chunks} />
                    <InlineStat label="Embeddings" value={generation.embeddings} />
                </dl>

                <p className="text-muted-foreground text-xs">
                    {generation.activated_at ? `Activated ${formatDate(generation.activated_at)}` : 'Never activated'}
                </p>

                {generation.recent_failures.length > 0 && (
                    <div>
                        <h3 className="text-muted-foreground mb-1 text-xs font-medium">Recent failures</h3>
                        <ul className="space-y-2">
                            {generation.recent_failures.map((failure) => (
                                <li key={`${failure.asset}-${failure.error_code ?? 'unknown'}`} className="text-sm">
                                    <span className="font-medium break-all">{failure.filename}</span>
                                    <p className="text-muted-foreground text-xs">
                                        <span className="font-mono">{failure.error_code ?? 'UNKNOWN'}</span>
                                        {failure.error_message ? ` · ${failure.error_message}` : ''}
                                        {` · ${failure.attempts} ${failure.attempts === 1 ? 'attempt' : 'attempts'}`}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function RetrievalIndex({ generations, eligible_assets }: RetrievalIndexProps) {
    const sorted = [...generations].sort((a, b) => (a.status === 'active' ? -1 : 0) - (b.status === 'active' ? -1 : 0));
    const active = generations.find((generation) => generation.status === 'active') ?? null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Retrieval" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">Retrieval</h1>
                        <p className="text-muted-foreground text-sm">
                            {eligible_assets} {eligible_assets === 1 ? 'book' : 'books'} eligible for enrichment ·{' '}
                            {active ? `${active.assets.ready} ready in the active generation` : 'no active generation'}
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href="/admin/retrieval/debugger">Open search debugger</Link>
                    </Button>
                </div>

                {sorted.length === 0 ? (
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm">No retrieval generations yet.</p>
                            <p className="text-muted-foreground mt-1 font-mono text-xs">Create one with mnemosyne:retrieval:create-generation</p>
                        </CardContent>
                    </Card>
                ) : (
                    sorted.map((generation) => <GenerationCard key={generation.public_id} generation={generation} />)
                )}
            </div>
        </AppLayout>
    );
}
