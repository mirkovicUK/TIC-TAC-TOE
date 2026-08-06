import { Head } from '@inertiajs/react';

// Placeholder entry page. The create and join forms are built in task 5.6.
export default function Home() {
    return (
        <>
            <Head title="Tic-Tac-Toe" />
            <main className="flex min-h-screen items-center justify-center">
                <h1 className="text-2xl font-semibold">Tic-Tac-Toe</h1>
            </main>
        </>
    );
}
