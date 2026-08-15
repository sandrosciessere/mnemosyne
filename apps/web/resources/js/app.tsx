import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';
import { Ziggy } from './ziggy';

declare global {
    const route: typeof routeFn;
}

// Bundled Ziggy instead of the blade @routes inline script: the host CSP
// is script-src 'self', so no inline JS may run. The generated route list
// ships with the bundle; the origin is taken from the browser so the
// generated file never bakes in a wrong APP_URL.
(globalThis as typeof globalThis & { route: typeof routeFn }).route = ((name?: never, params?: never, absolute?: never) =>
    (routeFn as CallableFunction)(name, params, absolute, {
        ...Ziggy,
        url: window.location.origin,
    })) as typeof routeFn;

const appName = import.meta.env.VITE_APP_NAME || 'Mnemosyne';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
