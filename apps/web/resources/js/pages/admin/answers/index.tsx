import { AnswerStatusBadge } from '@/components/answers/answer-status-badge';
import { EmptyState } from '@/components/empty-state';
import { formatDate } from '@/components/library/format';
import { usePoll } from '@/components/library/use-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AdminAnswerRunSummary } from '@/types/answers';
import { Head, Link } from '@inertiajs/react';
import { MessageSquareQuote } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Answers',
        href: '/admin/answers',
    },
];

interface AnswersIndexProps {
    runs: AdminAnswerRunSummary[];
}

const TERMINAL_STATUSES = ['ready', 'insufficient', 'failed'];

export default function AnswersIndex({ runs }: AnswersIndexProps) {
    const hasActiveRuns = runs.some((run) => !TERMINAL_STATUSES.includes(run.status));

    usePoll(hasActiveRuns, ['runs'], 10000);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Answers" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div>
                    <h1 className="text-xl font-semibold">Answers</h1>
                    <p className="text-muted-foreground text-sm">Latest {runs.length} grounded answer runs — full audit trail per run.</p>
                </div>

                {runs.length === 0 ? (
                    <EmptyState icon={MessageSquareQuote} title="No answer runs yet" description="Grounded answer runs will appear here." />
                ) : (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[64rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Question
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        User
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Status
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Outcome
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Intent
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Error
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Created
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Completed
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((run) => (
                                    <tr key={run.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0">
                                        <td className="max-w-md px-4 py-3">
                                            <Link
                                                href={`/admin/answers/${run.id}`}
                                                className="block truncate font-medium underline-offset-4 hover:underline"
                                                title={run.question}
                                            >
                                                {run.question}
                                            </Link>
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">
                                            {run.user.name ?? '—'}
                                            {run.user.email && <p className="text-xs break-all">{run.user.email}</p>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <AnswerStatusBadge status={run.status} />
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">{run.outcome?.replaceAll('_', ' ') ?? '—'}</td>
                                        <td className="text-muted-foreground px-4 py-3">{run.intent?.replaceAll('_', ' ') ?? '—'}</td>
                                        <td className="px-4 py-3 font-mono text-xs">{run.error_code ?? '—'}</td>
                                        <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">{formatDate(run.created_at)}</td>
                                        <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">{formatDate(run.completed_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
