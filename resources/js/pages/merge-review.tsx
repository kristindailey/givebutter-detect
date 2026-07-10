import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Wrench } from 'lucide-react';
import AppShell from '@/layouts/AppShell';
import { index } from '@/routes/duplicates';

// Placeholder — the Merge Review feature builds out the side-by-side diff,
// per-field picker, and the before/after panel behind this same route. Kept
// minimal on purpose so the Review Queue's row navigation is real today.
export default function MergeReview({ candidateId }: { candidateId: number }) {
    return (
        <AppShell>
            <Head title="Merge Review" />

            <div className="mx-auto max-w-5xl px-6 py-8">
                <Link
                    href={index()}
                    className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-blue"
                >
                    <ArrowLeft className="size-4" />
                    Back to queue
                </Link>

                <div className="mt-6 flex flex-col items-center gap-3 rounded-2xl border border-border bg-card px-6 py-20 text-center shadow-sm">
                    <span className="flex size-12 items-center justify-center rounded-full bg-brand-cream text-brand-black">
                        <Wrench className="size-6" />
                    </span>
                    <p className="font-heading text-lg font-semibold text-brand-black">
                        Merge Review — coming next
                    </p>
                    <p className="max-w-sm text-sm text-muted-foreground">
                        The diff, per-field picker, and before/after panel for
                        candidate #{candidateId} land in the Merge Review
                        feature.
                    </p>
                </div>
            </div>
        </AppShell>
    );
}
