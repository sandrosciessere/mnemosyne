import { formatDate, formatRelative } from '@/components/library/format';
import { type PipelineEvent } from '@/types/library';

function formatPayloadValue(value: unknown): string {
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }
    return JSON.stringify(value);
}

export function EventsTimeline({ events }: { events: PipelineEvent[] }) {
    if (events.length === 0) {
        return <p className="text-muted-foreground text-sm">No events recorded yet.</p>;
    }

    const sorted = [...events].sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());

    return (
        <ol className="space-y-3">
            {sorted.map((event, index) => {
                const payloadEntries = Object.entries(event.payload ?? {}).filter(([, value]) => value !== null && value !== undefined);
                return (
                    <li key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-l-2 pl-3">
                        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <span className="text-sm font-medium">{event.type.replaceAll('_', ' ')}</span>
                            <time dateTime={event.created_at} title={formatDate(event.created_at)} className="text-muted-foreground text-xs">
                                {formatRelative(event.created_at)}
                            </time>
                            {event.actor ? <span className="text-muted-foreground text-xs">by {event.actor}</span> : null}
                        </div>
                        {payloadEntries.length > 0 && (
                            <dl className="text-muted-foreground mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                {payloadEntries.map(([key, value]) => (
                                    <div key={key} className="flex gap-1">
                                        <dt className="font-medium">{key}:</dt>
                                        <dd className="break-all">{formatPayloadValue(value)}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
