import { EmptyState } from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Library, ScrollText, Search } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

export default function Dashboard() {
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div>
                    <h1 className="text-xl font-semibold">Welcome, {auth.user.name}</h1>
                    <p className="text-muted-foreground text-sm">
                        Mnemosyne is being bootstrapped. Library ingestion, search and analyses will appear here as they come online.
                    </p>
                </div>
                <div className="grid flex-1 auto-rows-fr gap-4 md:grid-cols-3">
                    <EmptyState icon={Library} title="No books indexed yet" description="The library is empty. Ingestion has not been set up yet." />
                    <EmptyState
                        icon={Search}
                        title="Search not available yet"
                        description="Search will be enabled once the first books are indexed."
                    />
                    <EmptyState icon={ScrollText} title="No analyses yet" description="Requested analyses and their progress will appear here." />
                </div>
            </div>
        </AppLayout>
    );
}
