import { AnswerStatusBadge } from '@/components/answers/answer-status-badge';
import { formatDate } from '@/components/library/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type AdminAnswerData, type AdminClaimAudit, type AdminEvidenceData } from '@/types/answers';
import { Head } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';

interface AnswerShowProps {
    answer: AdminAnswerData;
    user: { name: string | null; email: string | null };
    all_claims: AdminClaimAudit[];
    all_evidence: AdminEvidenceData[];
}

function Field({ label, value, mono = false }: { label: string; value: string | null | undefined; mono?: boolean }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className={cn('text-sm break-all', mono && 'font-mono text-xs')}>{value ?? '—'}</dd>
        </div>
    );
}

function JsonCollapsible({ label, value }: { label: string; value: unknown }) {
    if (value === null || value === undefined) {
        return <p className="text-muted-foreground text-xs">{label}: —</p>;
    }
    return (
        <Collapsible>
            <CollapsibleTrigger asChild>
                <Button variant="ghost" size="sm" className="-ml-2">
                    <ChevronsUpDown aria-hidden="true" />
                    {label}
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-2">
                <pre className="bg-muted overflow-x-auto rounded-md p-3 font-mono text-xs">{JSON.stringify(value, null, 2)}</pre>
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function AnswerShow({ answer, user, all_claims, all_evidence }: AnswerShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Answers', href: '/admin/answers' },
        { title: answer.id, href: `/admin/answers/${answer.id}` },
    ];

    const diagnostics = answer.diagnostics;
    const timingEntries = diagnostics.timings_ms ? Object.entries(diagnostics.timings_ms) : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Answer ${answer.id}`} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold">Answer run</h1>
                        <AnswerStatusBadge status={answer.status} />
                        {answer.outcome && <Badge variant="outline">{answer.outcome.replaceAll('_', ' ')}</Badge>}
                        {answer.retrieval_expanded && <Badge variant="secondary">retrieval expanded</Badge>}
                        <span className="text-muted-foreground font-mono text-xs">{answer.id}</span>
                    </div>
                    <p className="text-muted-foreground mt-1 text-sm break-all">{answer.question}</p>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Run overview</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
                            <Field label="User" value={user.name ? `${user.name} (${user.email ?? '—'})` : (user.email ?? '—')} />
                            <Field label="Intent" value={answer.intent} />
                            <Field label="Conversation" value={answer.conversation_id} mono />
                            <Field label="Created" value={formatDate(answer.created_at)} />
                            <Field label="Completed" value={formatDate(answer.completed_at)} />
                            <Field label="Rejected claims" value={String(answer.rejected_claim_count)} />
                            <Field label="Scope" value={answer.scope.map((asset) => asset.title).join(', ') || 'all accessible books'} />
                            <Field label="Skipped assets" value={answer.skipped_assets.length > 0 ? answer.skipped_assets.join(', ') : null} mono />
                        </dl>

                        {answer.capability_notice !== null && (
                            <div>
                                <h3 className="text-muted-foreground mb-1 text-xs font-medium">Capability notice</h3>
                                <p className="text-sm whitespace-pre-line">{answer.capability_notice}</p>
                            </div>
                        )}

                        {(answer.error_code !== null || diagnostics.error_message !== null) && (
                            <div className="rounded-md border border-red-300 p-3 dark:border-red-800">
                                <h3 className="text-muted-foreground mb-1 text-xs font-medium">Error</h3>
                                <p className="font-mono text-sm">{answer.error_code ?? '—'}</p>
                                {diagnostics.error_message && <p className="text-muted-foreground text-sm">{diagnostics.error_message}</p>}
                            </div>
                        )}

                        <div>
                            <h3 className="text-muted-foreground mb-1 text-xs font-medium">Versions and providers</h3>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
                                <Field label="Classifier" value={diagnostics.classifier_version} mono />
                                <Field label="Decomposer" value={diagnostics.decomposer_version} mono />
                                <Field label="Claim gate" value={diagnostics.claim_gate_version} mono />
                                <Field label="Retrieval profile" value={diagnostics.retrieval_profile_version} mono />
                                <Field label="Unitizer" value={diagnostics.unitizer_version} mono />
                                <Field label="Retrieval generation" value={diagnostics.retrieval_generation} mono />
                                <Field
                                    label="Generator"
                                    value={
                                        diagnostics.generator.provider
                                            ? `${diagnostics.generator.provider} · ${diagnostics.generator.model ?? '—'}@${diagnostics.generator.revision ?? '—'}`
                                            : null
                                    }
                                    mono
                                />
                                <Field label="Generator prompt" value={diagnostics.generator.prompt_version} mono />
                                <Field
                                    label="Verifier"
                                    value={
                                        diagnostics.verifier.provider
                                            ? `${diagnostics.verifier.provider} · ${diagnostics.verifier.model ?? '—'}@${diagnostics.verifier.revision ?? '—'}`
                                            : null
                                    }
                                    mono
                                />
                                <Field label="Verifier prompt" value={diagnostics.verifier.prompt_version} mono />
                            </dl>
                        </div>

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
                                            <tr key={stage} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0">
                                                <td className="py-1.5 pr-3 font-mono">{stage}</td>
                                                <td className="py-1.5 tabular-nums">{typeof ms === 'number' ? ms.toFixed(1) : String(ms)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {answer.subquestions !== null && answer.subquestions.length > 0 && (
                            <div>
                                <h3 className="text-muted-foreground mb-1 text-xs font-medium">Subquestions ({answer.subquestions.length})</h3>
                                <ul className="space-y-1">
                                    {answer.subquestions.map((subquestion) => (
                                        <li key={subquestion.key} className="flex flex-wrap items-start gap-2 text-sm">
                                            <span className="text-muted-foreground font-mono text-xs">{subquestion.key}</span>
                                            <Badge
                                                variant={subquestion.status === 'unanswered' ? 'destructive' : 'outline'}
                                                className="font-mono text-xs"
                                            >
                                                {subquestion.status}
                                            </Badge>
                                            <span className="min-w-0 flex-1 whitespace-pre-line">{subquestion.text}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <JsonCollapsible label="Evidence stats" value={diagnostics.evidence_stats} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Claims audit ({all_claims.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {all_claims.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No claims recorded.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[96rem] text-sm">
                                    <thead>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                #
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Claim
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Type
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Subquestion
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Generator label
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Final label
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Verification
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Support level
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Reason
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Gate
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Support atoms
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Evidence
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {all_claims.map((claim) => {
                                            const rejected = claim.verification_status !== 'verified';
                                            const verifierPositive = claim.verifier_support_level !== null && claim.verifier_support_level !== 'none';
                                            const gateOverride = verifierPositive && claim.gate_result === 'rejected';
                                            return (
                                                <tr
                                                    key={claim.key}
                                                    className={cn(
                                                        'border-sidebar-border/70 dark:border-sidebar-border border-b align-top last:border-b-0',
                                                        rejected && 'bg-red-50 dark:bg-red-950/30',
                                                    )}
                                                >
                                                    <td className="px-2 py-2 tabular-nums">{claim.ordinal}</td>
                                                    <td className="max-w-lg px-2 py-2 whitespace-pre-line">{claim.text}</td>
                                                    <td className="px-2 py-2 font-mono text-xs">{claim.claim_type ?? '—'}</td>
                                                    <td className="px-2 py-2 font-mono text-xs">{claim.subquestion_key ?? '—'}</td>
                                                    <td className="px-2 py-2 font-mono text-xs">{claim.generator_suggested_label ?? '—'}</td>
                                                    <td className="px-2 py-2 font-mono text-xs">{claim.final_label ?? '—'}</td>
                                                    <td className={cn('px-2 py-2 font-mono text-xs', rejected && 'text-destructive font-medium')}>
                                                        {claim.verification_status}
                                                    </td>
                                                    <td className="px-2 py-2 font-mono text-xs">{claim.verifier_support_level ?? '—'}</td>
                                                    <td className="px-2 py-2 font-mono text-xs">{claim.verifier_reason_code ?? '—'}</td>
                                                    <td className="px-2 py-2 font-mono text-xs">
                                                        <span className={cn(claim.gate_result === 'rejected' && 'text-destructive font-medium')}>
                                                            {claim.gate_result ?? '—'}
                                                        </span>
                                                        {claim.gate_reason_code !== null && (
                                                            <p className="text-muted-foreground">{claim.gate_reason_code}</p>
                                                        )}
                                                        {gateOverride && (
                                                            <Badge className="mt-1 border-transparent bg-amber-100 font-mono text-xs whitespace-nowrap text-amber-900 hover:bg-inherit dark:bg-amber-900/40 dark:text-amber-200">
                                                                verifier_positive / gate_rejected
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="max-w-xs px-2 py-2 font-mono text-xs break-all">
                                                        {claim.support_atoms.length > 0 ? claim.support_atoms.join(', ') : '—'}
                                                    </td>
                                                    <td className="px-2 py-2 font-mono text-xs">
                                                        {claim.evidence_keys.length > 0 ? claim.evidence_keys.join(', ') : '—'}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Evidence packet ({all_evidence.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {all_evidence.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No evidence recorded.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[64rem] text-sm">
                                    <thead>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Key
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Citation
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Book
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Spine
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Canonical range
                                            </th>
                                            <th scope="col" className="px-2 py-2 font-medium">
                                                Details
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {all_evidence.map((evidence) => (
                                            <tr
                                                key={evidence.evidence_key}
                                                className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top last:border-b-0"
                                            >
                                                <td className="px-2 py-2 font-mono text-xs">{evidence.evidence_key}</td>
                                                <td className="px-2 py-2 tabular-nums">{evidence.number ?? '—'}</td>
                                                <td className="max-w-xs px-2 py-2">
                                                    <span className="break-all">{evidence.book_title ?? '—'}</span>
                                                    {evidence.stale && <p className="text-destructive font-mono text-xs">{evidence.stale_reason}</p>}
                                                    {evidence.heading_path.length > 0 && (
                                                        <p className="text-muted-foreground text-xs">{evidence.heading_path.join(' › ')}</p>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2 tabular-nums">{evidence.spine_index}</td>
                                                <td className="px-2 py-2 font-mono text-xs whitespace-nowrap">
                                                    [{evidence.canonical_start},{evidence.canonical_end})
                                                </td>
                                                <td className="min-w-72 px-2 py-2">
                                                    <Collapsible>
                                                        <CollapsibleTrigger asChild>
                                                            <Button variant="ghost" size="sm" className="-ml-2">
                                                                <ChevronsUpDown aria-hidden="true" />
                                                                Excerpt
                                                            </Button>
                                                        </CollapsibleTrigger>
                                                        <CollapsibleContent className="pt-1">
                                                            <p className="text-muted-foreground max-w-xl text-xs whitespace-pre-line">
                                                                {evidence.excerpt}
                                                            </p>
                                                        </CollapsibleContent>
                                                    </Collapsible>
                                                    <JsonCollapsible
                                                        label="Retrieval meta"
                                                        value={
                                                            evidence.retrieval_meta !== null
                                                                ? { ...evidence.retrieval_meta, unitizer_version: evidence.unitizer_version }
                                                                : { unitizer_version: evidence.unitizer_version }
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Retrieval diagnostics</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <JsonCollapsible label="retrieval_diagnostics" value={diagnostics.retrieval_diagnostics} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
