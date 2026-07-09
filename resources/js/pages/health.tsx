import { Head } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useSyncExternalStore } from 'react';

// Returns false on the server / before hydration and true once client-side
// React is running, so a green hydration row proves Vite/React are interactive.
const emptySubscribe = () => () => {};
function useHydrated(): boolean {
    return useSyncExternalStore(
        emptySubscribe,
        () => true,
        () => false,
    );
}

interface HealthCheck {
    key: string;
    label: string;
    ok: boolean;
    detail: string;
}

interface HealthPageProps {
    checks: HealthCheck[];
}

function CheckRow({
    ok,
    label,
    detail,
}: {
    ok: boolean;
    label: string;
    detail: string;
}) {
    return (
        <li className="flex items-center gap-4 border-b border-border px-6 py-4 last:border-b-0">
            <span
                className={`flex size-7 shrink-0 items-center justify-center rounded-full ${
                    ok
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-red-100 text-red-700'
                }`}
            >
                {ok ? (
                    <Check className="size-4" strokeWidth={3} />
                ) : (
                    <X className="size-4" strokeWidth={3} />
                )}
            </span>
            <div className="min-w-0 flex-1">
                <p className="font-heading font-semibold text-brand-black">
                    {label}
                </p>
                <p className="truncate text-sm text-muted-foreground">
                    {detail}
                </p>
            </div>
            <span
                className={`shrink-0 text-sm font-semibold ${ok ? 'text-emerald-600' : 'text-red-600'}`}
            >
                {ok ? 'OK' : 'FAIL'}
            </span>
        </li>
    );
}

export default function Health({ checks }: HealthPageProps) {
    const hydrated = useHydrated();
    const allOk = hydrated && checks.every((check) => check.ok);

    return (
        <>
            <Head title="Health" />
            <div className="flex min-h-screen flex-col items-center bg-brand-cream px-4 py-16">
                <div className="w-full max-w-xl">
                    <header className="mb-8 text-center">
                        <p className="font-logo text-3xl font-extrabold text-brand-black">
                            Givebutter{' '}
                            <span className="text-brand-blue">Detect</span>
                        </p>
                        <h1 className="mt-2 font-heading text-lg font-semibold text-brand-black/70">
                            Foundation health check
                        </h1>
                    </header>

                    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        <div
                            className={`px-6 py-4 font-heading font-semibold ${
                                allOk
                                    ? 'bg-brand-yellow text-brand-black'
                                    : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            {allOk
                                ? 'All systems go — stack is wired end-to-end'
                                : 'Checking stack…'}
                        </div>
                        <ul>
                            {checks.map((check) => (
                                <CheckRow
                                    key={check.key}
                                    ok={check.ok}
                                    label={check.label}
                                    detail={check.detail}
                                />
                            ))}
                            <CheckRow
                                ok={hydrated}
                                label="React / Vite hydration"
                                detail={
                                    hydrated
                                        ? 'Client bundle mounted and interactive'
                                        : 'Waiting for client…'
                                }
                            />
                        </ul>
                    </div>

                    <p className="mt-6 text-center text-sm text-muted-foreground">
                        Gate before feature work — all rows green confirms the
                        stack.
                    </p>
                </div>
            </div>
        </>
    );
}
