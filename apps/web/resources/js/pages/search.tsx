import { formatRelative } from '@/components/library/format';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type AnswerData,
    type AnswerStatus,
    type CitationData,
    type ClaimData,
    type ClaimLabel,
    type ConversationDetail,
    type ConversationSummary,
} from '@/types/answers';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Check, Circle, Info, LoaderCircle, MessageSquarePlus, SearchX } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Search',
        href: '/search',
    },
];

const QUESTION_MIN = 3;
const QUESTION_MAX = 2000;

interface SearchProps {
    books: { public_id: string; title: string; searchable: boolean }[];
    answers_enabled: boolean;
    conversations: ConversationSummary[];
}

interface ApiError {
    code: string;
    message: string;
}

const NETWORK_ERROR: ApiError = { code: 'NETWORK_ERROR', message: 'Impossibile raggiungere il server.' };

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
                    message: typeof error.message === 'string' ? error.message : 'Richiesta non riuscita.',
                };
            }
        }
    } catch {
        // Non-JSON body — fall through to the generic error.
    }
    return { code: `HTTP_${response.status}`, message: 'Richiesta non riuscita.' };
}

function isTerminal(status: AnswerStatus): boolean {
    return status === 'ready' || status === 'insufficient' || status === 'failed';
}

/** Format a persisted backend duration: <1 min → "42 s"; else "3 min 28 s". */
function formatDuration(durationMs: number): string {
    const totalSeconds = Math.max(0, Math.round(durationMs / 1000));
    if (totalSeconds < 60) {
        return `${totalSeconds} s`;
    }
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${minutes} min ${seconds} s`;
}

/**
 * Operational metadata footer (elapsed time). Rendered visually separate
 * from the epistemic label badges: duration must never read as confidence.
 */
function AnswerDurationFooter({ durationMs }: { durationMs: number | null }) {
    if (durationMs === null) {
        return null;
    }
    return (
        <p className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-t pt-3 text-xs">
            Completata in {formatDuration(durationMs)}
        </p>
    );
}

/** One rendered turn of the conversation: a question bubble or an answer card. */
type Entry =
    | { kind: 'question'; key: string; text: string }
    | { kind: 'answer'; key: string; answerId: string; status: AnswerStatus; answer: AnswerData | null };

// ---------------------------------------------------------------------------
// Progress — the persisted backend status only, never a client-side timer.
// ---------------------------------------------------------------------------

const STEP_LABELS: Record<string, string> = {
    queued: 'In coda',
    retrieving: 'Ricerca evidenze',
    expanding_retrieval: 'Espansione ricerca',
    generating: 'Generazione risposta',
    verifying: 'Verifica indipendente delle affermazioni',
};

function AnswerProgress({ status }: { status: AnswerStatus }) {
    // The expansion step is conditional: it is shown only while the backend
    // actually reports it, so a run that never expanded never claims it did.
    const order: string[] = ['queued', 'retrieving', 'generating', 'verifying'];
    if (status === 'expanding_retrieval') {
        order.splice(2, 0, 'expanding_retrieval');
    }
    const currentIndex = order.indexOf(status);

    return (
        <div className="space-y-3">
            <ol className="space-y-1.5" aria-label="Avanzamento della risposta">
                {order.map((step, index) => {
                    const state = index < currentIndex ? 'done' : index === currentIndex ? 'current' : 'pending';
                    return (
                        <li
                            key={step}
                            aria-current={state === 'current' ? 'step' : undefined}
                            className={cn(
                                'flex items-center gap-2 text-sm',
                                state === 'pending' && 'text-muted-foreground',
                                state === 'current' && 'font-medium',
                            )}
                        >
                            {state === 'done' ? (
                                <Check className="size-4 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                            ) : state === 'current' ? (
                                <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
                            ) : (
                                <Circle className="text-muted-foreground size-4" aria-hidden="true" />
                            )}
                            {STEP_LABELS[step]}
                        </li>
                    );
                })}
            </ol>
            <p className="text-muted-foreground text-xs">Il modello locale può impiegare alcuni minuti.</p>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Claims — epistemic labels, plain-text rendering, numbered citation chips.
// ---------------------------------------------------------------------------

const LABEL_STYLES: Record<ClaimLabel, string> = {
    textual_fact: 'border-transparent bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200',
    strong_inference: 'border-transparent bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-200',
    interpretation: 'border-transparent bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200',
    conflict: 'border-transparent bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-200',
};

function ClaimLabelBadge({ claim }: { claim: ClaimData }) {
    if (claim.label === null) {
        return null;
    }
    return <Badge className={cn('shrink-0 hover:bg-inherit', LABEL_STYLES[claim.label])}>{claim.label_user ?? claim.label}</Badge>;
}

function scrollToSource(answerId: string, number: number) {
    document.getElementById(`source-${answerId}-${number}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

function CitationChips({ answerId, numbers }: { answerId: string; numbers: number[] }) {
    return (
        <>
            {numbers.map((number) => (
                <button
                    key={number}
                    type="button"
                    onClick={() => scrollToSource(answerId, number)}
                    className="border-input hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring ml-1 inline-flex items-center rounded border px-1 align-baseline font-mono text-xs tabular-nums focus-visible:ring-2 focus-visible:outline-hidden"
                    aria-label={`Vai alla fonte ${number}`}
                >
                    [{number}]
                </button>
            ))}
        </>
    );
}

function sourceLetter(index: number): string {
    return String.fromCharCode(65 + (index % 26));
}

function ClaimItem({ answerId, claim }: { answerId: string; claim: ClaimData }) {
    if (claim.label === 'conflict') {
        return (
            <li className="rounded-md border border-red-300 p-3 dark:border-red-800">
                <div className="flex flex-wrap items-start gap-2">
                    <ClaimLabelBadge claim={claim} />
                    <p className="min-w-0 flex-1 text-sm whitespace-pre-line">{claim.text}</p>
                </div>
                {claim.citations.length > 0 && (
                    <p className="mt-2 text-sm">
                        {claim.citations.map((number, index) => (
                            <span key={number}>
                                {index > 0 ? ' / ' : ''}
                                Fonte {sourceLetter(index)}
                                <CitationChips answerId={answerId} numbers={[number]} />
                            </span>
                        ))}
                    </p>
                )}
            </li>
        );
    }

    return (
        <li className="flex flex-wrap items-start gap-2">
            <ClaimLabelBadge claim={claim} />
            <p className="min-w-0 flex-1 text-sm whitespace-pre-line">
                {claim.text}
                <CitationChips answerId={answerId} numbers={claim.citations} />
            </p>
        </li>
    );
}

/**
 * Verified claims list. For compound questions (subquestions present) claims
 * carrying a subquestion key are grouped under small muted subquestion headers.
 */
function ClaimsList({ answer }: { answer: AnswerData }) {
    const subquestions = answer.subquestions;
    const grouped = subquestions !== null && answer.claims.some((claim) => claim.subquestion !== null);

    if (!grouped) {
        return (
            <ul className="space-y-3" aria-label="Affermazioni verificate">
                {answer.claims.map((claim) => (
                    <ClaimItem key={claim.key} answerId={answer.id} claim={claim} />
                ))}
            </ul>
        );
    }

    const byKey = new Map<string, ClaimData[]>();
    const ungrouped: ClaimData[] = [];
    for (const claim of answer.claims) {
        const subquestion = claim.subquestion !== null ? (subquestions?.find((entry) => entry.key === claim.subquestion) ?? null) : null;
        if (subquestion === null) {
            ungrouped.push(claim);
        } else {
            const list = byKey.get(subquestion.key) ?? [];
            list.push(claim);
            byKey.set(subquestion.key, list);
        }
    }

    return (
        <div className="space-y-4" aria-label="Affermazioni verificate">
            {(subquestions ?? []).map((subquestion) => {
                const claims = byKey.get(subquestion.key) ?? [];
                if (claims.length === 0) {
                    return null;
                }
                return (
                    <div key={subquestion.key}>
                        <h4 className="text-muted-foreground mb-2 text-xs font-medium whitespace-pre-line">{subquestion.text}</h4>
                        <ul className="space-y-3">
                            {claims.map((claim) => (
                                <ClaimItem key={claim.key} answerId={answer.id} claim={claim} />
                            ))}
                        </ul>
                    </div>
                );
            })}
            {ungrouped.length > 0 && (
                <ul className="space-y-3">
                    {ungrouped.map((claim) => (
                        <ClaimItem key={claim.key} answerId={answer.id} claim={claim} />
                    ))}
                </ul>
            )}
        </div>
    );
}

/** Amber block listing subquestions without sufficient evidence (partial answers). */
function UnansweredSubquestions({ answer }: { answer: AnswerData }) {
    const unanswered = (answer.subquestions ?? []).filter((subquestion) => subquestion.status === 'unanswered');
    if (unanswered.length === 0) {
        return null;
    }
    return (
        <div className="rounded-md border border-amber-300 p-3 dark:border-amber-800">
            <p className="text-sm font-medium">Evidenza insufficiente</p>
            <p className="text-muted-foreground mt-1 text-sm">
                Le seguenti parti della domanda non hanno evidenza sufficiente nei passaggi disponibili:
            </p>
            <ul className="mt-2 list-disc space-y-1 pl-5">
                {unanswered.map((subquestion) => (
                    <li key={subquestion.key} className="text-sm whitespace-pre-line">
                        {subquestion.text}
                    </li>
                ))}
            </ul>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Sources panel — durable citation snapshots with reader deep links.
// ---------------------------------------------------------------------------

function SourceItem({ answerId, citation }: { answerId: string; citation: CitationData }) {
    const readerLinkable = citation.book_asset_id !== null && citation.stale_reason !== 'ASSET_REMOVED';

    return (
        <li id={`source-${answerId}-${citation.number}`} className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="secondary" className="font-mono tabular-nums">
                    [{citation.number}]
                </Badge>
                <span className="text-sm font-medium break-all">{citation.book_title ?? 'Libro sconosciuto'}</span>
                {citation.work_title && <span className="text-muted-foreground text-sm break-all">{citation.work_title}</span>}
                {citation.stale && (
                    <Badge className="border-transparent bg-amber-100 text-amber-900 hover:bg-inherit dark:bg-amber-900/40 dark:text-amber-200">
                        Fonte modificata
                    </Badge>
                )}
            </div>
            {citation.heading_path.length > 0 && <p className="text-muted-foreground mt-1 text-xs">{citation.heading_path.join(' › ')}</p>}
            <blockquote className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground mt-2 border-l-2 pl-3 text-sm whitespace-pre-line italic">
                {citation.excerpt}
            </blockquote>
            {citation.spans.length > 0 && <p className="text-muted-foreground mt-1 text-xs">Evidenza puntuale evidenziata nel libro.</p>}
            {readerLinkable ? (
                <Button asChild variant="outline" size="sm" className="mt-2">
                    <Link href={`/library/books/${citation.book_asset_id}/reader?answer=${answerId}&evidence=${citation.evidence_key}`}>
                        Apri nel libro
                    </Link>
                </Button>
            ) : (
                <p className="text-muted-foreground mt-2 text-xs">Il libro non è più disponibile: il passaggio non può essere aperto.</p>
            )}
        </li>
    );
}

// ---------------------------------------------------------------------------
// Answer card — the core citation-first UI.
// ---------------------------------------------------------------------------

function AnswerCard({ answer }: { answer: AnswerData }) {
    if (answer.status === 'failed') {
        return (
            <div className="space-y-2">
                <Alert variant="destructive">
                    <AlertTriangle aria-hidden="true" className="size-4" />
                    <AlertTitle>
                        La risposta non è stata completata{answer.error_code ? <span className="font-mono"> ({answer.error_code})</span> : null}
                    </AlertTitle>
                    <AlertDescription>Si è verificato un errore durante l'elaborazione. Riprova più tardi.</AlertDescription>
                </Alert>
                {answer.duration_ms !== null && <p className="text-muted-foreground text-xs">Completata in {formatDuration(answer.duration_ms)}</p>}
            </div>
        );
    }

    const insufficient = answer.status === 'insufficient' || answer.outcome === 'insufficient_evidence';

    return (
        <Card>
            <CardContent className="space-y-4 pt-6">
                {answer.capability_notice !== null && (
                    <Alert className="border-amber-300 dark:border-amber-800">
                        <Info aria-hidden="true" className="size-4" />
                        <AlertTitle>Capacità limitata in questa versione</AlertTitle>
                        <AlertDescription>
                            Questa domanda richiede capacità di analisi (riassunto globale / evoluzione / inferenza complessa) che saranno complete in
                            una versione futura; la risposta si basa solo sui passaggi recuperati.
                        </AlertDescription>
                    </Alert>
                )}

                {answer.skipped_assets.length > 0 && (
                    <p className="text-muted-foreground text-xs">
                        Alcuni libri selezionati non sono ancora indicizzati e non sono stati considerati (
                        {answer.skipped_assets.length === 1 ? '1 libro' : `${answer.skipped_assets.length} libri`}).
                    </p>
                )}

                {insufficient ? (
                    <div className="flex items-start gap-3">
                        <SearchX className="text-muted-foreground mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        <div>
                            <p className="text-sm font-medium">Evidenza insufficiente</p>
                            <p className="text-muted-foreground text-sm">
                                Nei passaggi recuperati non ci sono elementi sufficienti per rispondere in modo fondato.
                            </p>
                        </div>
                    </div>
                ) : (
                    <>
                        {answer.outcome === 'partially_answered' && (
                            <Alert>
                                <Info aria-hidden="true" className="size-4" />
                                <AlertTitle>Risposta parziale</AlertTitle>
                                <AlertDescription>
                                    Alcune parti della domanda non hanno evidenza sufficiente.
                                    {answer.rejected_claim_count > 0 &&
                                        ` ${answer.rejected_claim_count} ${
                                            answer.rejected_claim_count === 1
                                                ? 'affermazione scartata dalla verifica indipendente'
                                                : 'affermazioni scartate dalla verifica indipendente'
                                        }.`}
                                </AlertDescription>
                            </Alert>
                        )}

                        {answer.claims.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Nessuna affermazione verificata disponibile.</p>
                        ) : (
                            <ClaimsList answer={answer} />
                        )}

                        <UnansweredSubquestions answer={answer} />
                    </>
                )}

                {answer.citations.length > 0 && (
                    <div>
                        <h3 className="mb-2 text-sm font-semibold">Fonti</h3>
                        <ol className="space-y-2" aria-label="Fonti citate">
                            {[...answer.citations]
                                .sort((a, b) => a.number - b.number)
                                .map((citation) => (
                                    <SourceItem key={citation.evidence_key} answerId={answer.id} citation={citation} />
                                ))}
                        </ol>
                    </div>
                )}

                <AnswerDurationFooter durationMs={answer.duration_ms} />
            </CardContent>
        </Card>
    );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function Search({ books, answers_enabled, conversations }: SearchProps) {
    const [question, setQuestion] = useState('');
    const [selectedBooks, setSelectedBooks] = useState<string[]>([]);
    const [entries, setEntries] = useState<Entry[]>([]);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [activeAnswerId, setActiveAnswerId] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [loadingConversation, setLoadingConversation] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const trimmed = question.trim();
    const busy = submitting || activeAnswerId !== null;
    const hasTerminalAnswer = entries.some((entry) => entry.kind === 'answer' && isTerminal(entry.status));

    // Poll the persisted run status until it is terminal.
    useEffect(() => {
        if (activeAnswerId === null) {
            return;
        }
        let cancelled = false;
        let inFlight = false;

        const tick = async () => {
            if (inFlight) {
                return;
            }
            inFlight = true;
            try {
                const response = await fetch(`/api/v1/answers/${activeAnswerId}`, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: apiHeaders(),
                });
                if (cancelled) {
                    return;
                }
                if (!response.ok) {
                    setError(await readApiError(response));
                    setActiveAnswerId(null);
                    return;
                }
                const body = (await response.json()) as { data: AnswerData };
                if (cancelled) {
                    return;
                }
                setEntries((current) =>
                    current.map((entry) =>
                        entry.kind === 'answer' && entry.answerId === activeAnswerId
                            ? { ...entry, status: body.data.status, answer: body.data }
                            : entry,
                    ),
                );
                if (isTerminal(body.data.status)) {
                    setActiveAnswerId(null);
                }
            } catch {
                // Transient network error — keep polling.
            } finally {
                inFlight = false;
            }
        };

        void tick();
        const id = window.setInterval(() => {
            void tick();
        }, 2500);
        return () => {
            cancelled = true;
            window.clearInterval(id);
        };
    }, [activeAnswerId]);

    const toggleBook = (publicId: string, checked: boolean) => {
        setSelectedBooks((current) => (checked ? [...current, publicId] : current.filter((id) => id !== publicId)));
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (busy || trimmed.length < QUESTION_MIN || trimmed.length > QUESTION_MAX) {
            return;
        }

        setSubmitting(true);
        setError(null);

        const payload: Record<string, unknown> = { question: trimmed };
        if (selectedBooks.length > 0) {
            payload.scope = { book_asset_ids: selectedBooks };
        }
        if (conversationId !== null) {
            payload.conversation_id = conversationId;
        }

        try {
            const response = await fetch('/api/v1/answers', {
                method: 'POST',
                credentials: 'same-origin',
                headers: apiHeaders(),
                body: JSON.stringify(payload),
            });
            if (!response.ok) {
                const apiError = await readApiError(response);
                if (apiError.code === 'TOO_MANY_ACTIVE_ANSWERS') {
                    setError({ code: apiError.code, message: 'Attendi che la risposta in corso finisca prima di fare una nuova domanda.' });
                } else {
                    setError(apiError);
                }
                return;
            }
            const body = (await response.json()) as { data: { id: string; status: AnswerStatus; conversation_id: string | null } };
            setEntries((current) => [
                ...current,
                { kind: 'question', key: `q-${body.data.id}`, text: trimmed },
                { kind: 'answer', key: `a-${body.data.id}`, answerId: body.data.id, status: body.data.status, answer: null },
            ]);
            setConversationId(body.data.conversation_id);
            setActiveAnswerId(body.data.id);
            setQuestion('');
        } catch {
            setError(NETWORK_ERROR);
        } finally {
            setSubmitting(false);
        }
    };

    const loadConversation = async (id: string) => {
        if (loadingConversation) {
            return;
        }
        setLoadingConversation(true);
        setError(null);
        try {
            const response = await fetch(`/api/v1/conversations/${id}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: apiHeaders(),
            });
            if (!response.ok) {
                setError(await readApiError(response));
                return;
            }
            const body = (await response.json()) as { data: ConversationDetail };
            const loaded: Entry[] = [];
            for (const message of body.data.messages) {
                if (message.role === 'user') {
                    loaded.push({ kind: 'question', key: message.id, text: message.content ?? '' });
                } else if (message.answer) {
                    loaded.push({
                        kind: 'answer',
                        key: message.id,
                        answerId: message.answer.id,
                        status: message.answer.status,
                        answer: message.answer,
                    });
                }
            }
            setEntries(loaded);
            setConversationId(body.data.id);
            const pending = [...loaded].reverse().find((entry) => entry.kind === 'answer' && !isTerminal(entry.status));
            setActiveAnswerId(pending !== undefined && pending.kind === 'answer' ? pending.answerId : null);
        } catch {
            setError(NETWORK_ERROR);
        } finally {
            setLoadingConversation(false);
        }
    };

    const startNewConversation = () => {
        setEntries([]);
        setConversationId(null);
        setActiveAnswerId(null);
        setError(null);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Search" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 lg:flex-row lg:items-start">
                <div className="min-w-0 flex-1 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h1 className="text-xl font-semibold">Domande sui tuoi libri</h1>
                            <p className="text-muted-foreground text-sm">
                                Risposte fondate sui passaggi dei libri, con citazioni verificabili — non un chatbot generico.
                            </p>
                        </div>
                        {(entries.length > 0 || conversationId !== null) && (
                            <Button variant="outline" onClick={startNewConversation}>
                                <MessageSquarePlus aria-hidden="true" />
                                Nuova conversazione
                            </Button>
                        )}
                    </div>

                    {entries.length > 0 && (
                        <ol className="space-y-4" aria-label="Conversazione">
                            {entries.map((entry) =>
                                entry.kind === 'question' ? (
                                    <li key={entry.key} className="flex justify-end">
                                        <p className="bg-secondary text-secondary-foreground max-w-[85%] rounded-xl px-4 py-2 text-sm whitespace-pre-line">
                                            {entry.text}
                                        </p>
                                    </li>
                                ) : (
                                    <li key={entry.key}>
                                        {entry.answer !== null && isTerminal(entry.status) ? (
                                            <AnswerCard answer={entry.answer} />
                                        ) : (
                                            <Card>
                                                <CardContent className="pt-6">
                                                    <AnswerProgress status={entry.status} />
                                                </CardContent>
                                            </Card>
                                        )}
                                    </li>
                                ),
                            )}
                        </ol>
                    )}

                    {error && (
                        <Alert variant="destructive">
                            <AlertTriangle aria-hidden="true" className="size-4" />
                            <AlertTitle>
                                Richiesta non riuscita (<span className="font-mono">{error.code}</span>)
                            </AlertTitle>
                            <AlertDescription>{error.message}</AlertDescription>
                        </Alert>
                    )}

                    {answers_enabled ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">{hasTerminalAnswer ? 'Fai un’altra domanda' : 'Fai una domanda'}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submit} className="space-y-3">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="answer-question" className="sr-only">
                                            Domanda
                                        </Label>
                                        <Textarea
                                            id="answer-question"
                                            value={question}
                                            onChange={(event) => setQuestion(event.target.value)}
                                            rows={3}
                                            minLength={QUESTION_MIN}
                                            maxLength={QUESTION_MAX}
                                            placeholder="Fai una domanda sui libri selezionati…"
                                            disabled={busy}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Da {QUESTION_MIN} a {QUESTION_MAX} caratteri. Il modello locale può impiegare alcuni minuti per
                                            rispondere.
                                        </p>
                                    </div>
                                    <Button type="submit" disabled={busy || trimmed.length < QUESTION_MIN || trimmed.length > QUESTION_MAX}>
                                        {submitting ? 'Invio…' : activeAnswerId !== null ? 'Risposta in corso…' : 'Chiedi'}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    ) : (
                        <Alert>
                            <Info aria-hidden="true" className="size-4" />
                            <AlertTitle>Le risposte non sono ancora disponibili</AlertTitle>
                            <AlertDescription>
                                Nessuna generazione di retrieval è attiva: le domande sui libri saranno disponibili quando l'indice sarà pronto.
                            </AlertDescription>
                        </Alert>
                    )}
                </div>

                <div className="w-full shrink-0 space-y-4 lg:w-80">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Ambito</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <p className="text-muted-foreground text-xs">
                                Nessuna selezione = tutti i libri accessibili.
                                {conversationId !== null && ' L’ambito si applica alla prossima domanda.'}
                            </p>
                            {books.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Nessun libro accessibile.</p>
                            ) : (
                                <div className="border-sidebar-border/70 dark:border-sidebar-border max-h-64 space-y-1 overflow-y-auto rounded-md border p-2">
                                    {books.map((book) => (
                                        <div key={book.public_id} className="flex items-center gap-2">
                                            <Checkbox
                                                id={`scope-book-${book.public_id}`}
                                                checked={selectedBooks.includes(book.public_id)}
                                                disabled={!book.searchable}
                                                onCheckedChange={(checked) => toggleBook(book.public_id, checked === true)}
                                            />
                                            <Label
                                                htmlFor={`scope-book-${book.public_id}`}
                                                className={cn('text-sm font-normal break-all', !book.searchable && 'text-muted-foreground')}
                                            >
                                                {book.title}
                                                {!book.searchable && <span className="text-muted-foreground text-xs"> (non indicizzato)</span>}
                                            </Label>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Conversazioni recenti</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {conversations.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Nessuna conversazione.</p>
                            ) : (
                                <ul className="space-y-1">
                                    {conversations.map((conversation) => (
                                        <li key={conversation.id}>
                                            <button
                                                type="button"
                                                onClick={() => void loadConversation(conversation.id)}
                                                disabled={loadingConversation}
                                                className={cn(
                                                    'hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring w-full rounded-md px-2 py-1.5 text-left text-sm focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-50',
                                                    conversation.id === conversationId && 'bg-accent text-accent-foreground',
                                                )}
                                            >
                                                <span className="block truncate">{conversation.title ?? 'Conversazione senza titolo'}</span>
                                                <span className="text-muted-foreground block text-xs">
                                                    {formatRelative(conversation.last_activity_at)}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
