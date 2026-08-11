import { EmptyState } from '@/components/empty-state';
import { Paginator } from '@/components/library/paginator';
import { StatusBadge } from '@/components/library/status-badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type Book, type Paginator as PaginatorData } from '@/types/library';
import { Head, Link } from '@inertiajs/react';
import { Download, Library as LibraryIcon, ListChecks, Upload } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Library',
        href: '/library',
    },
];

interface LibraryProps {
    is_admin: boolean;
    books: PaginatorData<Book>;
}

export default function Library({ books }: LibraryProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Library" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">Library</h1>
                        <p className="text-muted-foreground text-sm">
                            {books.total} {books.total === 1 ? 'book' : 'books'} available to you.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href="/library/submissions">
                                <ListChecks aria-hidden="true" />
                                My submissions
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/library/submissions/create">
                                <Upload aria-hidden="true" />
                                Submit EPUB
                            </Link>
                        </Button>
                    </div>
                </div>

                {books.data.length === 0 ? (
                    <EmptyState
                        icon={LibraryIcon}
                        title="No books in the library yet"
                        description="The library fills up as EPUB submissions are approved and ingested. Submit an EPUB to get started."
                    >
                        <Button asChild className="mt-2">
                            <Link href="/library/submissions/create">
                                <Upload aria-hidden="true" />
                                Submit EPUB
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {books.data.map((book) => (
                                <Card key={book.public_id} className="flex flex-col">
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base leading-snug">{book.title}</CardTitle>
                                        {book.authors.length > 0 && <p className="text-muted-foreground text-sm">{book.authors.join(', ')}</p>}
                                    </CardHeader>
                                    <CardContent className="mt-auto flex flex-col gap-3">
                                        <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs">
                                            <StatusBadge status={book.ingestion_status} />
                                            {book.language && <span>Language: {book.language}</span>}
                                            {book.epub_version && <span>EPUB {book.epub_version}</span>}
                                        </div>
                                        {book.can_download && (
                                            <a
                                                href={`/library/books/${book.public_id}/download`}
                                                className={cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'w-full')}
                                            >
                                                <Download aria-hidden="true" />
                                                Download EPUB
                                            </a>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                        <Paginator paginator={books} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
