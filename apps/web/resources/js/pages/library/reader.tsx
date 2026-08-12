import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ChevronLeft, ChevronRight, ChevronsUpDown } from 'lucide-react';
import { useEffect } from 'react';

interface ReaderSection {
    spine_index: number;
    href: string;
    label: string | null;
    char_count: number;
}

interface ReaderNode {
    id: string;
    type: string;
    level: number | null;
    text: string;
    utf16_start: number | null;
}

interface ReaderHighlight {
    evidence_key: string;
    citation_number: number | null;
    spine_index: number;
    node_id: string;
    utf16_start: number;
    utf16_end: number;
}

interface StaleNotice {
    evidence_key: string;
    citation_number: number | null;
    status: string;
}

interface ReaderProps {
    asset: { public_id: string; title: string; edition_label: string | null };
    sections: ReaderSection[];
    current_section: {
        spine_index: number;
        label: string | null;
        nodes: ReaderNode[];
        missing: boolean;
    };
    highlights: ReaderHighlight[];
    stale_notices: StaleNotice[];
    answer_id: string | null;
}

/**
 * A highlight range with its unique DOM id. Several highlights can share one
 * evidence_key (one per minimal citation span), so the id carries a document
 * -order occurrence suffix: `evidence-<key>-<n>`.
 */
interface AnnotatedHighlight extends ReaderHighlight {
    dom_id: string;
}

interface Segment {
    text: string;
    domId: string | null;
}

/**
 * Split node text into plain/highlighted segments by UTF-16 code-unit
 * offsets (String.prototype.slice is UTF-16 code-unit based). Ranges
 * never overlap by contract, but clamp defensively so a malformed range
 * can never duplicate or drop text.
 */
function segmentNodeText(text: string, ranges: AnnotatedHighlight[]): Segment[] {
    const sorted = [...ranges].sort((a, b) => a.utf16_start - b.utf16_start);
    const segments: Segment[] = [];
    let cursor = 0;

    for (const range of sorted) {
        const start = Math.min(Math.max(range.utf16_start, cursor), text.length);
        const end = Math.min(Math.max(range.utf16_end, start), text.length);
        if (start > cursor) {
            segments.push({ text: text.slice(cursor, start), domId: null });
        }
        if (end > start) {
            segments.push({ text: text.slice(start, end), domId: range.dom_id });
        }
        cursor = Math.max(cursor, end);
    }
    if (cursor < text.length) {
        segments.push({ text: text.slice(cursor), domId: null });
    }
    return segments;
}

function NodeText({ node, highlights }: { node: ReaderNode; highlights: AnnotatedHighlight[] }) {
    if (highlights.length === 0) {
        return <>{node.text}</>;
    }
    return (
        <>
            {segmentNodeText(node.text, highlights).map((segment, index) =>
                segment.domId !== null ? (
                    <mark key={index} id={segment.domId} className="rounded bg-yellow-200 px-0.5 dark:bg-yellow-700/60">
                        {segment.text}
                    </mark>
                ) : (
                    <span key={index}>{segment.text}</span>
                ),
            )}
        </>
    );
}

function ReaderNodeView({ node, highlights }: { node: ReaderNode; highlights: AnnotatedHighlight[] }) {
    const content = <NodeText node={node} highlights={highlights} />;

    switch (node.type) {
        case 'heading': {
            const level = Math.min(4, Math.max(2, node.level ?? 2));
            const className = cn('font-semibold', level === 2 && 'mt-8 text-2xl', level === 3 && 'mt-6 text-xl', level === 4 && 'mt-4 text-lg');
            if (level === 2) {
                return <h2 className={className}>{content}</h2>;
            }
            if (level === 3) {
                return <h3 className={className}>{content}</h3>;
            }
            return <h4 className={className}>{content}</h4>;
        }
        case 'blockquote':
            return (
                <blockquote className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-l-2 pl-4 italic">
                    {content}
                </blockquote>
            );
        case 'list_item':
            return (
                <div className="flex gap-2 pl-4">
                    <span aria-hidden="true">•</span>
                    <p>{content}</p>
                </div>
            );
        default:
            return <p>{content}</p>;
    }
}

function sectionTitle(section: ReaderSection): string {
    return section.label ?? section.href;
}

export default function Reader({ asset, sections, current_section, highlights, stale_notices, answer_id }: ReaderProps) {
    const { url } = usePage();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Library', href: '/library' },
        { title: asset.title, href: `/library/books/${asset.public_id}/reader` },
    ];

    const currentPosition = sections.findIndex((section) => section.spine_index === current_section.spine_index);
    const previous = currentPosition > 0 ? sections[currentPosition - 1] : null;
    const next = currentPosition >= 0 && currentPosition < sections.length - 1 ? sections[currentPosition + 1] : null;

    /** Build a section URL preserving the answer/evidence deep-link params. */
    const sectionUrl = (spineIndex: number): string => {
        const query = new URLSearchParams(url.split('?')[1] ?? '');
        query.set('section', String(spineIndex));
        return `/library/books/${asset.public_id}/reader?${query.toString()}`;
    };

    // Group highlights per node and assign each one a unique DOM id in
    // document order: an evidence key can have several minimal spans, so the
    // id gets a per-key occurrence suffix (`evidence-<key>-0`, `-1`, …).
    const rawByNode = new Map<string, ReaderHighlight[]>();
    for (const highlight of highlights) {
        const list = rawByNode.get(highlight.node_id) ?? [];
        list.push(highlight);
        rawByNode.set(highlight.node_id, list);
    }
    const highlightsByNode = new Map<string, AnnotatedHighlight[]>();
    const occurrenceByKey = new Map<string, number>();
    for (const node of current_section.nodes) {
        const list = rawByNode.get(node.id);
        if (list === undefined) {
            continue;
        }
        const sorted = [...list].sort((a, b) => a.utf16_start - b.utf16_start);
        highlightsByNode.set(
            node.id,
            sorted.map((highlight) => {
                const occurrence = occurrenceByKey.get(highlight.evidence_key) ?? 0;
                occurrenceByKey.set(highlight.evidence_key, occurrence + 1);
                return { ...highlight, dom_id: `evidence-${highlight.evidence_key}-${occurrence}` };
            }),
        );
    }

    const firstEvidenceKey = highlights[0]?.evidence_key ?? null;

    // Scroll the FIRST mark (document order) of the deep-linked evidence into
    // view once the section is mounted.
    useEffect(() => {
        if (firstEvidenceKey === null) {
            return;
        }
        document.getElementById(`evidence-${firstEvidenceKey}-0`)?.scrollIntoView({ block: 'center' });
    }, [firstEvidenceKey, current_section.spine_index]);

    const backToAnswer = () => {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            router.visit('/search');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={asset.title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="min-w-0">
                        <h1 className="text-xl font-semibold break-all">
                            {asset.title}
                            {asset.edition_label && <span className="text-muted-foreground text-base font-normal"> — {asset.edition_label}</span>}
                        </h1>
                        <p className="text-muted-foreground text-sm">{current_section.label ?? `Sezione ${current_section.spine_index}`}</p>
                    </div>
                    {answer_id !== null && (
                        <Button variant="outline" onClick={backToAnswer}>
                            <ArrowLeft aria-hidden="true" />
                            Torna alla risposta
                        </Button>
                    )}
                </div>

                {stale_notices.length > 0 && (
                    <Alert className="border-amber-300 dark:border-amber-800">
                        <AlertTriangle aria-hidden="true" className="size-4" />
                        <AlertTitle>Citazioni non più localizzabili</AlertTitle>
                        <AlertDescription>
                            <p>
                                Il testo di questo libro è cambiato dopo la creazione della risposta: la posizione esatta di alcune citazioni non può
                                più essere garantita (CITATION_SOURCE_CHANGED).
                            </p>
                            <ul className="mt-1 list-none">
                                {stale_notices.map((notice) => (
                                    <li key={notice.evidence_key} className="font-mono text-xs">
                                        {notice.citation_number !== null ? `[${notice.citation_number}] ` : ''}
                                        {notice.evidence_key} · {notice.status}
                                    </li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    {previous !== null ? (
                        <Button asChild variant="outline" size="sm">
                            <Link href={sectionUrl(previous.spine_index)}>
                                <ChevronLeft aria-hidden="true" />
                                Sezione precedente
                            </Link>
                        </Button>
                    ) : (
                        <Button variant="outline" size="sm" disabled>
                            <ChevronLeft aria-hidden="true" />
                            Sezione precedente
                        </Button>
                    )}
                    {next !== null ? (
                        <Button asChild variant="outline" size="sm">
                            <Link href={sectionUrl(next.spine_index)}>
                                Sezione successiva
                                <ChevronRight aria-hidden="true" />
                            </Link>
                        </Button>
                    ) : (
                        <Button variant="outline" size="sm" disabled>
                            Sezione successiva
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    )}
                    <Collapsible>
                        <CollapsibleTrigger asChild>
                            <Button variant="ghost" size="sm">
                                <ChevronsUpDown aria-hidden="true" />
                                Indice sezioni ({sections.length})
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="pt-2">
                            <ol className="border-sidebar-border/70 dark:border-sidebar-border max-h-64 space-y-0.5 overflow-y-auto rounded-md border p-2">
                                {sections.map((section) => (
                                    <li key={section.spine_index}>
                                        <Link
                                            href={sectionUrl(section.spine_index)}
                                            className={cn(
                                                'hover:bg-accent hover:text-accent-foreground block rounded px-2 py-1 text-sm break-all',
                                                section.spine_index === current_section.spine_index && 'bg-accent text-accent-foreground',
                                            )}
                                        >
                                            {sectionTitle(section)}
                                        </Link>
                                    </li>
                                ))}
                            </ol>
                        </CollapsibleContent>
                    </Collapsible>
                </div>

                {current_section.missing ? (
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm font-medium">Sezione non disponibile</p>
                            <p className="text-muted-foreground text-sm">
                                Questa sezione non è presente nel testo canonico del libro. Usa l'indice per scegliere un'altra sezione.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="space-y-4 pt-6 leading-relaxed">
                            {current_section.nodes.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Questa sezione non contiene testo.</p>
                            ) : (
                                current_section.nodes.map((node) => (
                                    <ReaderNodeView key={node.id} node={node} highlights={highlightsByNode.get(node.id) ?? []} />
                                ))
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
