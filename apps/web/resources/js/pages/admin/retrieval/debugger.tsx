import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type EvidenceSpanApi, type NeighborChunk, type SearchMeta, type SearchResult } from '@/types/retrieval';
import { Head } from '@inertiajs/react';
import { AlertTriangle, ChevronsUpDown } from 'lucide-react';
import { useState, type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Retrieval', href: '/admin/retrieval' },
    { title: 'Search debugger', href: '/admin/retrieval/debugger' },
];

interface DebuggerProps {
    active_generation: string | null;
    embedding_model: string | null;
    reranker_model: string | null;
    books: { public_id: string; title: string }[];
}

type SearchMode = 'hybrid' | 'exact' | 'lexical' | 'dense';

interface ApiError {
    code: string;
    message: string;
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function apiHeaders(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
    };
}

async function readApiError(response: Response): Promise<ApiError> {
    try {
        const body: unknown = await response.json();
        if (typeof body === 'object' && body !== null && 'error' in body) {
            const error = (body as { error: { code?: unknown; message?: unknown } }).error;
            if (typeof error === 'object' && error !== null) {
                return {
                    code: typeof error.code === 'string' ? error.code : `HTTP_${response.status}`,
                    message: typeof error.message === 'string' ? error.message : 'Request failed.',
                };
            }
        }
    } catch {
        // Non-JSON body — fall through to the generic error.
    }
    return { code: `HTTP_${response.status}`, message: 'Request failed.' };
}

function formatScore(value: number | null | undefined, decimals: number): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }
    return value.toFixed(decimals);
}

function formatRank(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }
    return String(value);
}

interface Segment {
    text: string;
    mark: boolean;
}

/**
 * Split the excerpt on the literal matched texts and wrap matches in
 * <mark>. Pure string splitting on React text nodes — no HTML parsing,
 * no dangerouslySetInnerHTML — so response text can never be rendered
 * as markup.
 */
function highlightSegments(excerpt: string, needles: string[]): Segment[] {
    const unique = [...new Set(needles.filter((needle) => needle.length > 0))].sort((a, b) => b.length - a.length);
    let segments: Segment[] = [{ text: excerpt, mark: false }];

    for (const needle of unique) {
        segments = segments.flatMap((segment) => {
            if (segment.mark) {
                return [segment];
            }
            const parts = segment.text.split(needle);
            if (parts.length === 1) {
                return [segment];
            }
            const out: Segment[] = [];
            parts.forEach((part, index) => {
                if (index > 0) {
                    out.push({ text: needle, mark: true });
                }
                if (part !== '') {
                    out.push({ text: part, mark: false });
                }
            });
            return out;
        });
    }

    return segments;
}

function Excerpt({ excerpt, truncated, needles }: { excerpt: string; truncated: boolean; needles: string[] }) {
    const segments = needles.length > 0 ? highlightSegments(excerpt, needles) : [{ text: excerpt, mark: false }];

    return (
        <p className="text-sm whitespace-pre-line">
            {segments.map((segment, index) =>
                segment.mark ? (
                    <mark key={index} className="rounded-xs bg-yellow-200 px-0.5 dark:bg-yellow-800/70 dark:text-yellow-50">
                        {segment.text}
                    </mark>
                ) : (
                    <span key={index}>{segment.text}</span>
                ),
            )}
            {truncated ? '…' : ''}
        </p>
    );
}

function ScoresTable({ scores }: { scores: NonNullable<SearchResult['scores']> }) {
    return (
        <div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[38rem] text-xs">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                            <th scope="col" className="px-2 py-1.5 font-medium">
                                exact_rank
                            </th>
                            <th scope="col" className="px-2 py-1.5 font-medium">
                                lexical_rank
                            </th>
                            <th scope="col" className="px-2 py-1.5 font-medium">
                                lexical_score
                            </th>
                            <th scope="col" className="px-2 py-1.5 font-medium">
                                dense_rank
                            </th>
                            <th scope="col" className="px-2 py-1.5 font-medium">
                                dense_similarity
                            </th>
                            <th scope="col" className="px-2 py-1.5 font-medium">
                                rrf_score
                            </th>
                            <th scope="col" className="px-2 py-1.5 font-medium">
                                rerank_score
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td className="px-2 py-1.5 tabular-nums">{formatRank(scores.exact_rank)}</td>
                            <td className="px-2 py-1.5 tabular-nums">{formatRank(scores.lexical_rank)}</td>
                            <td className="px-2 py-1.5 tabular-nums">{formatScore(scores.lexical_score, 4)}</td>
                            <td className="px-2 py-1.5 tabular-nums">{formatRank(scores.dense_rank)}</td>
                            <td className="px-2 py-1.5 tabular-nums">{formatScore(scores.dense_similarity, 4)}</td>
                            <td className="px-2 py-1.5 tabular-nums">{formatScore(scores.rrf_score, 5)}</td>
                            <td className="px-2 py-1.5 tabular-nums">{formatScore(scores.rerank_score, 3)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p className="text-muted-foreground mt-1 text-xs">
                exact = literal source match · lexical = keyword rank · dense = semantic similarity · scores are not probabilities
            </p>
        </div>
    );
}

function EvidenceSpans({ spans }: { spans: EvidenceSpanApi[] }) {
    if (spans.length === 0) {
        return null;
    }

    return (
        <Collapsible>
            <CollapsibleTrigger asChild>
                <Button variant="ghost" size="sm" className="-ml-2">
                    <ChevronsUpDown aria-hidden="true" />
                    Evidence spans ({spans.length})
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-2">
                <ul className="space-y-2">
                    {spans.map((span, index) => (
                        <li key={index} className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border p-2 text-xs">
                            <p>
                                <span className="font-mono">{span.source_node_id}</span>
                                {span.node_type ? <span className="text-muted-foreground"> · {span.node_type}</span> : null}
                            </p>
                            <p className="text-muted-foreground font-mono break-all">
                                {span.href ?? '—'}
                                {span.fragment ? `#${span.fragment}` : ''}
                            </p>
                            <p className="text-muted-foreground">
                                canonical [{span.canonical_start},{span.canonical_end}) · utf16 [{span.utf16_start},{span.utf16_end}) · chunk [
                                {span.chunk_start},{span.chunk_end}) · hash{' '}
                                <span className="font-mono" title={span.source_hash}>
                                    {span.source_hash.slice(0, 12)}
                                </span>
                            </p>
                        </li>
                    ))}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}

interface NeighborsState {
    loading: boolean;
    error: ApiError | null;
    data: { previous: NeighborChunk | null; next: NeighborChunk | null } | null;
}

function NeighborExcerpt({ label, neighbor }: { label: string; neighbor: NeighborChunk | null }) {
    return (
        <div className="text-muted-foreground text-xs">
            <p className="font-medium">{label}</p>
            {neighbor === null ? <p>—</p> : <p className="whitespace-pre-line">{neighbor.excerpt}</p>}
        </div>
    );
}

function ResultCard({ result }: { result: SearchResult }) {
    const [neighbors, setNeighbors] = useState<NeighborsState>({ loading: false, error: null, data: null });

    const firstSpan = result.evidence_spans[0] ?? null;
    const href = firstSpan?.href ?? null;

    const loadContext = async () => {
        setNeighbors({ loading: true, error: null, data: null });
        try {
            const response = await fetch(`/api/v1/retrieval/chunks/${result.chunk_id}/neighbors`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: apiHeaders(),
            });
            if (!response.ok) {
                setNeighbors({ loading: false, error: await readApiError(response), data: null });
                return;
            }
            const body = (await response.json()) as { data: { previous: NeighborChunk | null; next: NeighborChunk | null } };
            setNeighbors({ loading: false, error: null, data: body.data });
        } catch {
            setNeighbors({ loading: false, error: { code: 'NETWORK_ERROR', message: 'Could not reach the server.' }, data: null });
        }
    };

    return (
        <li>
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary" aria-label={`Rank ${result.rank}`}>
                            #{result.rank}
                        </Badge>
                        <CardTitle className="text-base break-all">{result.book.title}</CardTitle>
                        {result.book.work_title && <span className="text-muted-foreground text-sm">{result.book.work_title}</span>}
                    </div>
                    {result.heading_path.length > 0 && <p className="text-muted-foreground text-sm">{result.heading_path.join(' › ')}</p>}
                    <p className="text-muted-foreground font-mono text-xs break-all">
                        spine {result.spine_index ?? '—'}
                        {href ? ` · ${href}` : ''}
                    </p>
                </CardHeader>
                <CardContent className="space-y-3">
                    <Excerpt
                        excerpt={result.excerpt}
                        truncated={result.excerpt_truncated}
                        needles={result.exact_matches.map((match) => match.text)}
                    />
                    {result.scores && <ScoresTable scores={result.scores} />}
                    <EvidenceSpans spans={result.evidence_spans} />
                    <div>
                        <Button variant="outline" size="sm" onClick={loadContext} disabled={neighbors.loading}>
                            {neighbors.loading ? 'Loading context…' : 'Load context'}
                        </Button>
                        {neighbors.error && (
                            <p className="text-destructive mt-2 text-xs">
                                <span className="font-mono">{neighbors.error.code}</span> · {neighbors.error.message}
                            </p>
                        )}
                        {neighbors.data && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border mt-2 space-y-3 rounded-md border p-3">
                                <p className="text-muted-foreground text-xs font-medium">Context — not ranked evidence</p>
                                <NeighborExcerpt label="Previous" neighbor={neighbors.data.previous} />
                                <NeighborExcerpt label="Next" neighbor={neighbors.data.next} />
                            </div>
                        )}
                    </div>
                </CardContent>
            </Card>
        </li>
    );
}

export default function RetrievalDebugger({ active_generation, embedding_model, reranker_model, books }: DebuggerProps) {
    const [query, setQuery] = useState('');
    const [mode, setMode] = useState<SearchMode>('hybrid');
    const [topK, setTopK] = useState('10');
    // Opt-in like the API default: reranking adds seconds of CPU latency.
    const [rerank, setRerank] = useState(false);
    const [caseSensitive, setCaseSensitive] = useState(false);
    const [selectedBooks, setSelectedBooks] = useState<string[]>([]);

    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [results, setResults] = useState<SearchResult[] | null>(null);
    const [meta, setMeta] = useState<SearchMeta | null>(null);

    const allAuthorized = selectedBooks.length === 0;

    const toggleBook = (publicId: string, checked: boolean) => {
        setSelectedBooks((current) => (checked ? [...current, publicId] : current.filter((id) => id !== publicId)));
    };

    const execute = async (event: FormEvent) => {
        event.preventDefault();
        if (loading || query.trim() === '') {
            return;
        }

        setLoading(true);
        setError(null);

        const topKNumber = Math.min(25, Math.max(1, Number.parseInt(topK, 10) || 10));

        const payload: Record<string, unknown> = {
            query,
            mode,
            top_k: topKNumber,
            rerank,
            case_sensitive: caseSensitive,
            debug: true,
        };
        if (selectedBooks.length > 0) {
            payload.scope = { book_asset_ids: selectedBooks };
        }

        try {
            const response = await fetch('/api/v1/retrieval/search', {
                method: 'POST',
                credentials: 'same-origin',
                headers: apiHeaders(),
                body: JSON.stringify(payload),
            });
            if (!response.ok) {
                setError(await readApiError(response));
                setResults(null);
                setMeta(null);
                return;
            }
            const body = (await response.json()) as { data: SearchResult[]; meta: SearchMeta };
            setResults(body.data);
            setMeta(body.meta);
        } catch {
            setError({ code: 'NETWORK_ERROR', message: 'Could not reach the server.' });
            setResults(null);
            setMeta(null);
        } finally {
            setLoading(false);
        }
    };

    const timingEntries = meta?.timings_ms ? Object.entries(meta.timings_ms) : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Search debugger" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div>
                    <h1 className="text-xl font-semibold">Search debugger</h1>
                    <p className="text-muted-foreground text-sm">
                        {active_generation ? (
                            <>
                                Generation <span className="font-mono">{active_generation}</span>
                                {embedding_model ? ` · embedding ${embedding_model}` : ''}
                                {reranker_model ? ` · reranker ${reranker_model}` : ' · no reranker'}
                            </>
                        ) : (
                            'No retrieval generation is active — searches will fail until one is activated.'
                        )}
                    </p>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Query</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={execute} className="space-y-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="debugger-query">Query</Label>
                                <Input
                                    id="debugger-query"
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    required
                                    placeholder="Text to search for…"
                                />
                                <p className="text-muted-foreground text-xs">
                                    Exact mode accepts literals up to 200 characters (the chunk-boundary guarantee); longer hybrid queries skip the
                                    exact component.
                                </p>
                            </div>

                            <div className="flex flex-wrap items-end gap-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="debugger-mode">Mode</Label>
                                    <Select value={mode} onValueChange={(value) => setMode(value as SearchMode)}>
                                        <SelectTrigger id="debugger-mode" className="w-36">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="hybrid">hybrid</SelectItem>
                                            <SelectItem value="exact">exact</SelectItem>
                                            <SelectItem value="lexical">lexical</SelectItem>
                                            <SelectItem value="dense">dense</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="debugger-top-k">Top K (1–25)</Label>
                                    <Input
                                        id="debugger-top-k"
                                        type="number"
                                        min={1}
                                        max={25}
                                        value={topK}
                                        onChange={(event) => setTopK(event.target.value)}
                                        className="w-24"
                                    />
                                </div>
                                <div className="flex items-center gap-2 pb-2">
                                    <Checkbox id="debugger-rerank" checked={rerank} onCheckedChange={(checked) => setRerank(checked === true)} />
                                    <Label htmlFor="debugger-rerank">Rerank</Label>
                                </div>
                                <div className="flex items-center gap-2 pb-2">
                                    <Checkbox
                                        id="debugger-case-sensitive"
                                        checked={caseSensitive}
                                        onCheckedChange={(checked) => setCaseSensitive(checked === true)}
                                    />
                                    <Label htmlFor="debugger-case-sensitive">Case sensitive</Label>
                                    <span className="text-muted-foreground text-xs">(only meaningful for exact mode)</span>
                                </div>
                            </div>

                            <fieldset className="grid gap-1.5">
                                <legend className="mb-1.5 text-sm font-medium">Books in scope</legend>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="debugger-all-books"
                                        checked={allAuthorized}
                                        onCheckedChange={(checked) => {
                                            if (checked === true) {
                                                setSelectedBooks([]);
                                            }
                                        }}
                                    />
                                    <Label htmlFor="debugger-all-books">All authorized</Label>
                                </div>
                                {books.length === 0 ? (
                                    <p className="text-muted-foreground text-xs">No indexed books available.</p>
                                ) : (
                                    <div className="border-sidebar-border/70 dark:border-sidebar-border max-h-48 space-y-1 overflow-y-auto rounded-md border p-2">
                                        {books.map((book) => (
                                            <div key={book.public_id} className="flex items-center gap-2">
                                                <Checkbox
                                                    id={`debugger-book-${book.public_id}`}
                                                    checked={selectedBooks.includes(book.public_id)}
                                                    onCheckedChange={(checked) => toggleBook(book.public_id, checked === true)}
                                                />
                                                <Label htmlFor={`debugger-book-${book.public_id}`} className="text-sm font-normal break-all">
                                                    {book.title}
                                                </Label>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </fieldset>

                            <Button type="submit" disabled={loading || query.trim() === ''}>
                                {loading ? 'Searching…' : 'Execute search'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {error && (
                    <Alert variant="destructive">
                        <AlertTriangle aria-hidden="true" className="size-4" />
                        <AlertTitle>
                            Search failed (<span className="font-mono">{error.code}</span>)
                        </AlertTitle>
                        <AlertDescription>{error.message}</AlertDescription>
                    </Alert>
                )}

                {meta && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Search meta</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span>
                                    Generation <span className="font-mono">{meta.generation}</span>
                                </span>
                                {meta.reranker_used ? (
                                    <Badge variant="default">Reranker used</Badge>
                                ) : (
                                    <Badge variant="outline">Reranker not used</Badge>
                                )}
                                {!meta.reranker_used && meta.reranker_fallback_reason && (
                                    <Badge variant="secondary">fallback: {meta.reranker_fallback_reason}</Badge>
                                )}
                                {meta.dense_unavailable === true && <Badge variant="destructive">dense unavailable</Badge>}
                                {typeof meta.diagnostics?.lexical_strategy === 'string' && (
                                    <Badge variant="secondary">lexical: {meta.diagnostics.lexical_strategy}</Badge>
                                )}
                                {meta.exact_skipped_reason && <Badge variant="secondary">exact skipped: {meta.exact_skipped_reason}</Badge>}
                            </div>
                            {meta.skipped_assets.length > 0 && (
                                <div>
                                    <p className="text-muted-foreground text-xs font-medium">
                                        Skipped assets — granted but not indexed in this generation
                                    </p>
                                    <ul className="mt-1 space-y-0.5">
                                        {meta.skipped_assets.map((assetId) => (
                                            <li key={assetId} className="font-mono text-xs">
                                                {assetId}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                            {timingEntries.length > 0 && (
                                <div>
                                    <h3 className="text-muted-foreground mb-1 text-xs font-medium">Timings</h3>
                                    <table className="w-full max-w-md text-xs">
                                        <thead>
                                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                                <th scope="col" className="py-1.5 pr-3 font-medium">
                                                    Stage
                                                </th>
                                                <th scope="col" className="py-1.5 font-medium">
                                                    ms
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {timingEntries.map(([stage, ms]) => (
                                                <tr
                                                    key={stage}
                                                    className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0"
                                                >
                                                    <td className="py-1.5 pr-3 font-mono">{stage}</td>
                                                    <td className="py-1.5 tabular-nums">{ms.toFixed(1)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {results !== null &&
                    (results.length === 0 ? (
                        <Card>
                            <CardContent className="pt-6">
                                <p className="text-muted-foreground text-sm">No results.</p>
                            </CardContent>
                        </Card>
                    ) : (
                        <ol className="space-y-4" aria-label="Search results">
                            {results.map((result) => (
                                <ResultCard key={result.chunk_id} result={result} />
                            ))}
                        </ol>
                    ))}
            </div>
        </AppLayout>
    );
}
