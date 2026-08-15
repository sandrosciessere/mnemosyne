import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Mnemosyne" />
            <div className="flex min-h-screen flex-col items-center justify-center bg-neutral-50 p-6 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-50">
                <main className="flex w-full max-w-xl flex-col items-center gap-6 text-center">
                    <h1 className="text-4xl font-semibold tracking-tight">Mnemosyne</h1>
                    <p className="text-lg text-neutral-600 dark:text-neutral-400">
                        AI-powered library analysis and grounded answers over your EPUB library.
                    </p>
                    <div className="flex gap-3">
                        {auth.user ? (
                            <Link
                                href="/dashboard"
                                className="rounded-md bg-neutral-900 px-5 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-50 dark:text-neutral-900 dark:hover:bg-neutral-300"
                            >
                                Go to dashboard
                            </Link>
                        ) : (
                            <Link
                                href="/login"
                                className="rounded-md bg-neutral-900 px-5 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-50 dark:text-neutral-900 dark:hover:bg-neutral-300"
                            >
                                Log in
                            </Link>
                        )}
                    </div>
                    <p className="text-sm text-neutral-500 dark:text-neutral-500">Accounts are provisioned by administrators.</p>
                </main>
            </div>
        </>
    );
}
