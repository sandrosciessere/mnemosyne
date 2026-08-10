import InputError from '@/components/input-error';
import { formatBytes } from '@/components/library/format';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Library', href: '/library' },
    { title: 'My submissions', href: '/library/submissions' },
    { title: 'Submit EPUB', href: '/library/submissions/create' },
];

export default function SubmissionsCreate({ maxUploadBytes }: { maxUploadBytes: number }) {
    const { data, setData, post, processing, progress, errors } = useForm<{ epub: File | null; note: string }>({
        epub: null,
        note: '',
    });
    const [sizeError, setSizeError] = useState<string | null>(null);

    const handleFileChange = (file: File | null) => {
        setData('epub', file);
        if (file && file.size > maxUploadBytes) {
            setSizeError(`This file is ${formatBytes(file.size)}, which exceeds the maximum upload size of ${formatBytes(maxUploadBytes)}.`);
        } else {
            setSizeError(null);
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!data.epub || sizeError) {
            return;
        }
        post('/library/submissions');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Submit EPUB" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Submit an EPUB</CardTitle>
                        <CardDescription>
                            Your file will go through the ingestion pipeline. Depending on system settings it may first need an administrator's
                            approval. Maximum upload size: {formatBytes(maxUploadBytes)}.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="epub">EPUB file</Label>
                                <Input
                                    id="epub"
                                    type="file"
                                    accept=".epub"
                                    onChange={(e) => handleFileChange(e.target.files?.[0] ?? null)}
                                    aria-describedby="epub-help"
                                />
                                <p id="epub-help" className="text-muted-foreground text-xs">
                                    Only .epub files are accepted.
                                </p>
                                {data.epub && (
                                    <p className="text-sm">
                                        Selected: <span className="font-medium">{data.epub.name}</span> ({formatBytes(data.epub.size)})
                                    </p>
                                )}
                                <InputError message={sizeError ?? errors.epub} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="note">Note (optional)</Label>
                                <textarea
                                    id="note"
                                    value={data.note}
                                    onChange={(e) => setData('note', e.target.value)}
                                    rows={3}
                                    maxLength={1000}
                                    placeholder="Anything the administrators should know about this file"
                                    className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                                />
                                <InputError message={errors.note} />
                            </div>

                            {progress && (
                                <div>
                                    <div className="mb-1 flex items-center justify-between text-xs">
                                        <span className="text-muted-foreground">Uploading…</span>
                                        <span className="font-medium tabular-nums">{progress.percentage ?? 0}%</span>
                                    </div>
                                    <div
                                        role="progressbar"
                                        aria-label="Upload progress"
                                        aria-valuenow={progress.percentage ?? 0}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        className="bg-secondary h-2 w-full overflow-hidden rounded-full"
                                    >
                                        <div
                                            className="bg-primary h-full rounded-full transition-all"
                                            style={{ width: `${progress.percentage ?? 0}%` }}
                                        />
                                    </div>
                                </div>
                            )}

                            <div className="flex items-center gap-2">
                                <Button type="submit" disabled={processing || !data.epub || !!sizeError}>
                                    {processing ? 'Uploading…' : 'Submit EPUB'}
                                </Button>
                                <Button asChild variant="ghost">
                                    <Link href="/library/submissions">Cancel</Link>
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
