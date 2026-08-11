import { formatBytes } from '@/components/library/format';
import { stageLabel } from '@/components/library/stage-stepper';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'System',
        href: '/admin/system',
    },
];

interface SystemProps {
    settings: {
        submission_auto_approval: boolean;
    };
    environment: {
        max_upload_bytes: number;
        ingestion_concurrency: number;
        stale_after_minutes: number;
        submissions_per_hour: number;
    };
    pipeline: {
        pipeline_version: string;
        stage_handlers: Record<string, string>;
    };
}

export default function System({ settings, environment, pipeline }: SystemProps) {
    const { data, setData, put, processing, recentlySuccessful, errors } = useForm({
        submission_auto_approval: settings.submission_auto_approval,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/admin/system/settings', { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="System" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div>
                    <h1 className="text-xl font-semibold">System</h1>
                    <p className="text-muted-foreground text-sm">Ingestion settings, environment limits and pipeline versions.</p>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Settings</CardTitle>
                        <CardDescription>Changes take effect immediately.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="auto-approval"
                                    checked={data.submission_auto_approval}
                                    onCheckedChange={(checked) => setData('submission_auto_approval', checked === true)}
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="auto-approval">Auto-approve submissions</Label>
                                    <p className="text-muted-foreground text-sm">
                                        When enabled, user submissions are queued for ingestion immediately. When disabled, an administrator must
                                        approve each submission before it is processed.
                                    </p>
                                </div>
                            </div>
                            {errors.submission_auto_approval && (
                                <p className="text-sm text-red-600 dark:text-red-400">{errors.submission_auto_approval}</p>
                            )}
                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving…' : 'Save settings'}
                                </Button>
                                {recentlySuccessful && (
                                    <p className="text-muted-foreground text-sm" role="status">
                                        Saved.
                                    </p>
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Environment-controlled (requires .env change + restart)</CardTitle>
                        <CardDescription>These values cannot be changed from this page.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[24rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Setting
                                        </th>
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Value
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <th scope="row" className="px-3 py-2 text-left font-normal">
                                            Maximum upload size
                                        </th>
                                        <td className="px-3 py-2">{formatBytes(environment.max_upload_bytes)}</td>
                                    </tr>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <th scope="row" className="px-3 py-2 text-left font-normal">
                                            Ingestion concurrency
                                        </th>
                                        <td className="px-3 py-2 tabular-nums">{environment.ingestion_concurrency}</td>
                                    </tr>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <th scope="row" className="px-3 py-2 text-left font-normal">
                                            Runs considered stale after
                                        </th>
                                        <td className="px-3 py-2">{environment.stale_after_minutes} minutes</td>
                                    </tr>
                                    <tr>
                                        <th scope="row" className="px-3 py-2 text-left font-normal">
                                            Submissions per user per hour
                                        </th>
                                        <td className="px-3 py-2 tabular-nums">{environment.submissions_per_hour}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Pipeline versions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[24rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Component
                                        </th>
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Version
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <th scope="row" className="px-3 py-2 text-left font-normal">
                                            Pipeline
                                        </th>
                                        <td className="px-3 py-2 font-mono text-xs">{pipeline.pipeline_version}</td>
                                    </tr>
                                    {Object.entries(pipeline.stage_handlers).map(([stage, version]) => (
                                        <tr key={stage} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0">
                                            <th scope="row" className="px-3 py-2 text-left font-normal">
                                                {stageLabel(stage)} handler
                                            </th>
                                            <td className="px-3 py-2 font-mono text-xs">{version}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
