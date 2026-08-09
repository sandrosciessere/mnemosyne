import { EmptyState } from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Search as SearchIcon } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Search',
        href: '/search',
    },
];

export default function Search() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Search" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <EmptyState
                    icon={SearchIcon}
                    title="Search not available yet"
                    description="Lexical, semantic and hybrid search across the library will be available once the retrieval engine is implemented."
                />
            </div>
        </AppLayout>
    );
}
