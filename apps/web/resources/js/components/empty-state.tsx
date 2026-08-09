import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface EmptyStateProps {
    icon: LucideIcon;
    title: string;
    description: string;
    children?: ReactNode;
}

export function EmptyState({ icon: Icon, title, description, children }: EmptyStateProps) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border flex h-full min-h-52 flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-8 text-center">
            <Icon className="text-muted-foreground/60 size-8" aria-hidden="true" />
            <p className="text-base font-medium">{title}</p>
            <p className="text-muted-foreground max-w-md text-sm">{description}</p>
            {children}
        </div>
    );
}
