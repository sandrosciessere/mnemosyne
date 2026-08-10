type MetadataObject = Record<string, unknown>;

const KNOWN_FIELDS: [string, string][] = [
    ['title', 'Title'],
    ['creators', 'Creators'],
    ['languages', 'Languages'],
    ['identifiers', 'Identifiers'],
    ['publisher', 'Publisher'],
    ['description', 'Description'],
    ['subjects', 'Subjects'],
];

function renderValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }
    if (Array.isArray(value)) {
        return value.map((item) => renderValue(item)).join(', ');
    }
    if (typeof value === 'object') {
        const record = value as MetadataObject;
        // Common shapes: {name, role}, {scheme, value}
        if (typeof record.name === 'string') {
            return record.role ? `${record.name} (${String(record.role)})` : record.name;
        }
        if (typeof record.scheme === 'string' && record.value !== undefined) {
            return `${record.scheme}: ${String(record.value)}`;
        }
        return JSON.stringify(record);
    }
    return String(value);
}

export function MetadataList({ metadata }: { metadata: MetadataObject | null | undefined }) {
    if (!metadata) {
        return <p className="text-muted-foreground text-sm">No normalized metadata available.</p>;
    }

    const entries = KNOWN_FIELDS.filter(([key]) => {
        const value = metadata[key];
        return value !== null && value !== undefined && !(Array.isArray(value) && value.length === 0);
    });

    if (entries.length === 0) {
        return <p className="text-muted-foreground text-sm">No normalized metadata available.</p>;
    }

    return (
        <dl className="space-y-2 text-sm">
            {entries.map(([key, label]) => (
                <div key={key}>
                    <dt className="text-muted-foreground text-xs">{label}</dt>
                    <dd className="break-words">{renderValue(metadata[key])}</dd>
                </div>
            ))}
        </dl>
    );
}
