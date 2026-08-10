import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { type WarningSummaryItem } from '@/types/library';
import { AlertTriangle } from 'lucide-react';
import { stageLabel } from './stage-stepper';

/**
 * Admin-facing aggregated warning list: one entry per unique warning
 * code with the stages it was seen in and its useful context (affected
 * documents, fonts, counts). The raw event timeline stays available for
 * forensic detail — this answers "why does this book have warnings?".
 */
export function WarningsSummary({ warnings, className }: { warnings: WarningSummaryItem[]; className?: string }) {
    if (warnings.length === 0) {
        return null;
    }

    return (
        <Card className={className} id="warnings">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <AlertTriangle className="size-4 text-yellow-600 dark:text-yellow-400" aria-hidden="true" />
                    Warnings ({warnings.length})
                </CardTitle>
            </CardHeader>
            <CardContent>
                <ul className="space-y-4">
                    {warnings.map((warning) => (
                        <li key={warning.code} className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border p-3">
                            <p className="font-mono text-sm font-semibold">{warning.code}</p>
                            <p className="mt-1 text-sm">{warning.message}</p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {warning.stages.length === 1 ? 'Stage: ' : 'Stages: '}
                                {warning.stages.map((stage) => stageLabel(stage)).join(', ')}
                                {warning.occurrences > 1 ? ` · ${warning.occurrences} occurrences · 1 unique issue` : ''}
                            </p>
                            <WarningDetails details={warning.details} />
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

const DETAIL_LABELS: Record<string, string> = {
    hrefs: 'Affected documents',
    uris: 'Affected resources',
    fields: 'Affected fields',
    resources: 'Missing resources',
};

function WarningDetails({ details }: { details: Record<string, unknown> }) {
    const entries = Object.entries(details ?? {}).filter(([key, value]) => {
        if (key === 'book_level') {
            return value === true;
        }
        return value !== null && value !== undefined && (!Array.isArray(value) || value.length > 0);
    });

    if (entries.length === 0) {
        return null;
    }

    return (
        <dl className="mt-2 space-y-1">
            {entries.map(([key, value]) => (
                <div key={key} className="text-xs">
                    <dt className="text-muted-foreground inline">{DETAIL_LABELS[key] ?? key}: </dt>
                    <dd className="inline">
                        {Array.isArray(value) ? (
                            <>
                                {value.length}
                                <ul className="mt-0.5 ml-4 list-disc font-mono">
                                    {value.map((item) => (
                                        <li key={String(item)}>{String(item)}</li>
                                    ))}
                                </ul>
                            </>
                        ) : key === 'book_level' ? (
                            'most of the book appears to be image-only'
                        ) : (
                            String(value)
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
