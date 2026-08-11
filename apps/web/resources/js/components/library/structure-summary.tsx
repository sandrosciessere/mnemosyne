import { type StructureSummary } from '@/types/library';

const FIELD_LABELS: [keyof StructureSummary, string][] = [
    ['spine_items', 'Spine items'],
    ['sections', 'Sections'],
    ['nodes', 'Nodes'],
    ['text_chars', 'Text characters'],
    ['toc_entries', 'TOC entries'],
    ['headings', 'Headings'],
    ['paragraphs', 'Paragraphs'],
];

export function StructureSummaryList({ summary }: { summary: StructureSummary | null | undefined }) {
    if (!summary) {
        return <p className="text-muted-foreground text-sm">No structure summary available.</p>;
    }

    const entries = FIELD_LABELS.filter(([key]) => summary[key] !== null && summary[key] !== undefined);

    if (entries.length === 0) {
        return <p className="text-muted-foreground text-sm">No structure summary available.</p>;
    }

    return (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
            {entries.map(([key, label]) => (
                <div key={key}>
                    <dt className="text-muted-foreground text-xs">{label}</dt>
                    <dd className="font-medium tabular-nums">{summary[key]?.toLocaleString()}</dd>
                </div>
            ))}
        </dl>
    );
}
