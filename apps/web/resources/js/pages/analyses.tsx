import { EmptyState } from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Analyses',
        href: '/analyses',
    },
];

export default function Analyses() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Analyses" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <EmptyState
                    icon={ScrollText}
                    title="No analyses yet"
                    description="Fast, Accurate and Deep Analysis requests will be tracked here once the analysis engine is implemented."
                />
            </div>
        </AppLayout>
    );
}
