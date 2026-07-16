import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ArrayUnionSummary } from '@/components/ArrayUnionSummary';
import { BeforeAfterPanel } from '@/components/BeforeAfterPanel';
import { FieldPicker } from '@/components/FieldPicker';
import { SurvivorToggle } from '@/components/SurvivorToggle';
import type { ToggleContact } from '@/components/SurvivorToggle';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import AppShell from '@/layouts/AppShell';
import { commitMerge, fetchPreview, MergeRejectedError } from '@/lib/merge';
import type { PickSource, Picks, Projection } from '@/lib/merge';
import { dismiss } from '@/routes/candidates';
import { index } from '@/routes/duplicates';

interface MergeReviewProps {
    candidateId: number;
    proposedSurvivorId: number;
    contacts: ToggleContact[];
}

function Panel({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle?: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
            <h2 className="font-heading text-sm font-semibold tracking-wide text-brand-black uppercase">
                {title}
            </h2>
            {subtitle && (
                <p className="mt-0.5 text-sm text-muted-foreground">
                    {subtitle}
                </p>
            )}
            <div className="mt-4">{children}</div>
        </section>
    );
}

export default function MergeReview({
    candidateId,
    proposedSurvivorId,
    contacts,
}: MergeReviewProps) {
    const [survivorId, setSurvivorId] = useState(proposedSurvivorId);
    const [projection, setProjection] = useState<Projection | null>(null);
    const [loading, setLoading] = useState(true);
    const [picks, setPicks] = useState<Picks>({});
    const [committing, setCommitting] = useState(false);
    const [dismissing, setDismissing] = useState(false);
    const [flashKey, setFlashKey] = useState(0);

    const survivor = useMemo(
        () => contacts.find((contact) => contact.id === survivorId),
        [contacts, survivorId],
    );
    const loser = useMemo(
        () => contacts.find((contact) => contact.id !== survivorId),
        [contacts, survivorId],
    );

    // Fetch the projection on mount and whenever the survivor flips. Derived
    // values depend on who survives, so a flip re-fetches; scalar picks are local
    // and never re-fetch. The loading/reset is set in `handleSurvivorChange` (mount
    // starts loading), so the effect only sets state from its async callbacks. A
    // stale in-flight request is aborted.
    useEffect(() => {
        if (loser === undefined) {
            return;
        }

        const controller = new AbortController();

        fetchPreview(survivorId, loser.id, controller.signal)
            .then((data) => {
                setProjection(data);
                setLoading(false);
                setFlashKey((key) => key + 1);
            })
            .catch(() => {
                // An aborted request is expected on a rapid survivor flip — the
                // newer fetch will settle the state, so ignore it.
                if (controller.signal.aborted) {
                    return;
                }

                setLoading(false);
                toast.error('Could not load the merge preview.');
            });

        return () => controller.abort();
    }, [survivorId, loser]);

    if (survivor === undefined || loser === undefined) {
        return (
            <AppShell>
                <Head title="Merge Review" />
                <div className="mx-auto max-w-5xl px-6 py-8 text-sm text-muted-foreground">
                    This pair can no longer be reviewed.
                </div>
            </AppShell>
        );
    }

    const handleSurvivorChange = (nextSurvivorId: number) => {
        if (nextSurvivorId === survivorId) {
            return;
        }

        // A flip swaps the meaning of survivor/loser, so prior picks no longer
        // apply — reset them to the fresh survivor defaults, and clear the stale
        // projection so the panels show their loading state until the re-fetch.
        setPicks({});
        setProjection(null);
        setLoading(true);
        setSurvivorId(nextSurvivorId);
    };

    const handlePick = (field: string, source: PickSource) => {
        setPicks((previous) => ({ ...previous, [field]: source }));
    };

    const handleMerge = () => {
        setCommitting(true);

        commitMerge(survivorId, loser.id, picks)
            .then(() => {
                toast.success(`Merged into ${survivor.name}.`);
                router.visit(index().url);
            })
            .catch((error: unknown) => {
                // A guard rejection carries the server's reason; anything else
                // (a dropped connection, say) has none worth showing.
                if (error instanceof MergeRejectedError) {
                    toast.error(error.message);
                } else {
                    toast.error('The merge could not be completed.');
                }

                setCommitting(false);
            });
    };

    const handleDismiss = () => {
        // Unlike a merge, a dismiss has no guards of its own to explain — it's
        // idempotent and always redirects — so any failure here is unplanned: a 419
        // on a tab left open, a 404 once a reseed has renumbered the pairs. Hence
        // one fixed message rather than the server's: `lib/merge.ts` reads the
        // response body only for the merge guards, precisely so an unplanned
        // failure can't toast internals at a reviewer.
        const reportFailure = () => {
            toast.error('The pair could not be dismissed.');

            // Keeps the reviewer here to retry. Without this, Inertia covers the
            // screen with a modal dump of the raw response.
            return false;
        };

        router.post(
            dismiss(candidateId).url,
            {},
            {
                onStart: () => setDismissing(true),
                onFinish: () => setDismissing(false),
                onSuccess: () => toast.success('Marked as not a duplicate.'),
                onHttpException: reportFailure,
                onNetworkError: reportFailure,
            },
        );
    };

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

                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="font-heading text-2xl font-semibold text-brand-black">
                        Merge Review
                    </h1>
                    <SurvivorToggle
                        contacts={contacts}
                        survivorId={survivorId}
                        onChange={handleSurvivorChange}
                        disabled={committing || dismissing}
                    />
                </div>

                <p className="mt-1 text-sm text-muted-foreground">
                    Keeping{' '}
                    <span className="font-medium text-brand-black">
                        {survivor.name}
                    </span>{' '}
                    · folding in{' '}
                    <span className="font-medium text-brand-black">
                        {loser.name}
                    </span>{' '}
                    (soft-deleted)
                </p>

                <div className="mt-6 grid gap-4">
                    <Panel
                        title="Conflicting fields"
                        subtitle="Only fields where the two records disagree. Everything else agrees or fills a gap automatically."
                    >
                        {loading || projection === null ? (
                            <div className="space-y-2">
                                <Skeleton className="h-4 w-24" />
                                <Skeleton className="h-16 w-full" />
                            </div>
                        ) : (
                            <FieldPicker
                                scalars={projection.scalars}
                                picks={picks}
                                onPick={handlePick}
                                survivorName={survivor.name}
                                loserName={loser.name}
                            />
                        )}
                    </Panel>

                    <Panel
                        title="Array fields"
                        subtitle="Emails, phones, addresses, tags, and external IDs auto-union. Both records' values are kept."
                    >
                        {loading || projection === null ? (
                            <Skeleton className="h-16 w-full" />
                        ) : (
                            <ArrayUnionSummary arrays={projection.arrays} />
                        )}
                    </Panel>

                    <Panel
                        title="Before → After"
                        subtitle="Derived fields recomputed from the union of both contacts' transactions."
                    >
                        <BeforeAfterPanel
                            derived={projection?.derived ?? null}
                            loading={loading}
                            flashKey={flashKey}
                        />
                    </Panel>
                </div>

                <div className="mt-6 flex flex-wrap items-center justify-end gap-3">
                    <Button
                        variant="ghost"
                        onClick={handleDismiss}
                        disabled={committing || dismissing}
                    >
                        {dismissing ? 'Dismissing…' : 'Not a duplicate'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={index()}>Cancel</Link>
                    </Button>
                    <Button
                        onClick={handleMerge}
                        disabled={
                            committing ||
                            dismissing ||
                            loading ||
                            projection === null
                        }
                        className="bg-brand-blue text-brand-white hover:bg-brand-blue/90"
                    >
                        {committing ? 'Merging…' : 'Merge'}
                    </Button>
                </div>
            </div>
        </AppShell>
    );
}
