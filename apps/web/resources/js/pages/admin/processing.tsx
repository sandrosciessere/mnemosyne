import { EmptyState } from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Processing',
        href: '/admin/processing',
    },
];

export default function Processing() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Processing" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <EmptyState
                    icon={ListChecks}
                    title="No ingestion jobs yet"
                    description="The ingestion state machine is not implemented yet. Job progress, failures and retries will be monitored here."
                />
            </div>
        </AppLayout>
    );
}
