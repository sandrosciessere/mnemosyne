import { EmptyState } from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Library as LibraryIcon } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Library',
        href: '/library',
    },
];

export default function Library() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Library" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <EmptyState
                    icon={LibraryIcon}
                    title="No books indexed yet"
                    description="Once EPUB ingestion is implemented, the books you are authorized to read will be listed here."
                />
            </div>
        </AppLayout>
    );
}
