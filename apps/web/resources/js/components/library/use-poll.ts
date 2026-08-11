import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Reload the given page props at a fixed interval while `active` is true.
 * The interval is cleared as soon as `active` becomes false or on unmount.
 */
export function usePoll(active: boolean, only: string[], intervalMs = 5000) {
    const onlyKey = only.join(',');

    useEffect(() => {
        if (!active) {
            return;
        }
        const props = onlyKey.split(',');
        const id = window.setInterval(() => {
            router.reload({ only: props });
        }, intervalMs);
        return () => window.clearInterval(id);
    }, [active, intervalMs, onlyKey]);
}
