import { EmptyState } from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Settings2 } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'System',
        href: '/admin/system',
    },
];

export default function System() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="System" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <EmptyState
                    icon={Settings2}
                    title="System monitoring not available yet"
                    description="Queue health, resource usage and model configuration will be shown here. Queue monitoring is available to admins at /horizon."
                />
            </div>
        </AppLayout>
    );
}
