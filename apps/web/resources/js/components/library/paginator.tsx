import { cn } from '@/lib/utils';
import { type Paginator as PaginatorData } from '@/types/library';
import { Link } from '@inertiajs/react';

function decodeLabel(label: string): string {
    return label
        .replaceAll('&laquo;', '«')
        .replaceAll('&raquo;', '»')
        .replaceAll('&lsaquo;', '‹')
        .replaceAll('&rsaquo;', '›')
        .replaceAll('&hellip;', '…')
        .replaceAll('&nbsp;', ' ')
        .replaceAll('&amp;', '&');
}

export function Paginator({ paginator, className }: { paginator: PaginatorData<unknown>; className?: string }) {
    if (paginator.last_page <= 1) {
        return null;
    }

    return (
        <nav aria-label="Pagination" className={cn('flex flex-wrap items-center gap-1', className)}>
            {paginator.links.map((link, index) => {
                const label = decodeLabel(link.label);
                const baseClasses =
                    'inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-2 text-sm ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';

                if (!link.url) {
                    return (
                        <span key={index} aria-hidden="true" className={cn(baseClasses, 'border-transparent', 'text-muted-foreground opacity-50')}>
                            {label}
                        </span>
                    );
                }

                return (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        aria-current={link.active ? 'page' : undefined}
                        className={cn(
                            baseClasses,
                            link.active
                                ? 'bg-primary text-primary-foreground border-transparent'
                                : 'border-input bg-background hover:bg-accent hover:text-accent-foreground',
                        )}
                    >
                        {label}
                    </Link>
                );
            })}
        </nav>
    );
}
