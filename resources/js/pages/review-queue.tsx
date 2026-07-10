import { Head, Link } from '@inertiajs/react';
import { ArrowLeftRight, ChevronRight, ShieldCheck } from 'lucide-react';
import { ScoreBadge } from '@/components/ScoreBadge';
import type { ScoreBand } from '@/components/ScoreBadge';
import { SignalChip } from '@/components/SignalChip';
import type { ChipTone } from '@/components/SignalChip';
import AppShell from '@/layouts/AppShell';
import { show } from '@/routes/duplicates';

// One entry of the precomputed `signal_breakdown` written by `detect:run`.
// Additive signals carry a `contribution`; the household modifier carries a
// `modifier` nudge. The queue only renders this — it never recomputes a score.
interface SignalEntry {
    signal: 'name' | 'email' | 'phone' | 'address' | 'household';
    contribution?: number;
    note?: string;
    modifier?: string;
}

interface ContactSummary {
    id: number;
    name: string;
    email: string | null;
}

interface Candidate {
    id: number;
    score: number;
    band: ScoreBand;
    contact_a: ContactSummary;
    contact_b: ContactSummary;
    signals: SignalEntry[];
}

interface ReviewQueueProps {
    candidates: Candidate[];
}

interface Chip {
    key: string;
    label: string;
    tone: ChipTone;
}

const HOUSEHOLD_CHIPS: Record<string, { label: string; tone: ChipTone }> = {
    '+boost': { label: 'household +boost', tone: 'positive' },
    '-conflict': { label: 'household conflict', tone: 'negative' },
    '-dampen': { label: 'household dampened', tone: 'muted' },
    neutral: { label: 'household', tone: 'default' },
};

// Turn the precomputed breakdown into display chips: fired additive signals (with
// their point contribution) plus the asymmetric household modifier. Presentation
// only — the "why" is already decided server-side.
function toChips(signals: SignalEntry[]): Chip[] {
    const chips: Chip[] = [];

    signals.forEach((entry, index) => {
        if (entry.signal === 'household') {
            const chip =
                HOUSEHOLD_CHIPS[entry.modifier ?? 'neutral'] ??
                HOUSEHOLD_CHIPS.neutral;
            chips.push({ key: `household-${index}`, ...chip });

            return;
        }

        const contribution = entry.contribution ?? 0;

        if (contribution <= 0) {
            return;
        }

        const points = Math.round(contribution);
        chips.push({
            key: `${entry.signal}-${index}`,
            label: entry.note
                ? `${entry.signal} ${points} · dampened`
                : `${entry.signal} ${points}`,
            tone: entry.note ? 'muted' : 'default',
        });
    });

    return chips;
}

function ContactTabs({ duplicateCount }: { duplicateCount: number }) {
    const tabs = [
        { label: 'Individuals', count: 107, active: false },
        { label: 'Companies', count: 3, active: false },
        {
            label: 'Data Hygiene and Duplicates',
            count: duplicateCount,
            active: true,
        },
    ];

    return (
        <div className="flex gap-6 border-b border-border">
            {tabs.map((tab) => (
                <span
                    key={tab.label}
                    aria-current={tab.active ? 'page' : undefined}
                    className={`flex items-center gap-2 border-b-2 pb-3 text-sm font-medium select-none ${
                        tab.active
                            ? 'border-brand-blue text-brand-blue'
                            : 'border-transparent text-brand-black/60'
                    }`}
                >
                    {tab.label}
                    <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                        {tab.count}
                    </span>
                </span>
            ))}
        </div>
    );
}

function CandidateRow({ candidate }: { candidate: Candidate }) {
    const chips = toChips(candidate.signals);
    const { contact_a: a, contact_b: b } = candidate;

    return (
        <Link
            href={show(candidate.id)}
            className="flex items-center gap-4 px-5 py-4 transition-colors hover:bg-muted/50"
        >
            <ScoreBadge score={candidate.score} band={candidate.band} />

            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2 font-heading text-base font-semibold text-brand-black">
                    <span>{a.name}</span>
                    <ArrowLeftRight className="size-4 text-muted-foreground" />
                    <span>{b.name}</span>
                </div>
                <p className="mt-0.5 truncate text-sm text-muted-foreground">
                    {a.email ?? '—'}
                    <span className="px-1.5">·</span>
                    {b.email ?? '—'}
                </p>
                {chips.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {chips.map((chip) => (
                            <SignalChip
                                key={chip.key}
                                label={chip.label}
                                tone={chip.tone}
                            />
                        ))}
                    </div>
                )}
            </div>

            <ChevronRight className="size-5 shrink-0 text-muted-foreground" />
        </Link>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center gap-3 px-6 py-20 text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                <ShieldCheck className="size-6" />
            </span>
            <p className="font-heading text-lg font-semibold text-brand-black">
                No duplicates to review
            </p>
            <p className="max-w-sm text-sm text-muted-foreground">
                You&apos;re all caught up — every flagged pair has been merged
                or dismissed.
            </p>
        </div>
    );
}

export default function ReviewQueue({ candidates }: ReviewQueueProps) {
    return (
        <AppShell>
            <Head title="Duplicates" />

            <div className="mx-auto max-w-5xl px-6 py-8">
                <h1 className="font-heading text-2xl font-semibold text-brand-black">
                    Contacts
                </h1>

                <div className="mt-6">
                    <ContactTabs duplicateCount={candidates.length} />
                </div>

                <div className="mt-6">
                    <p className="mb-3 text-sm text-muted-foreground">
                        Candidate duplicate pairs, ranked by match confidence.
                        Review each pair before merging.
                    </p>

                    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        {candidates.length === 0 ? (
                            <EmptyState />
                        ) : (
                            <div className="divide-y divide-border">
                                {candidates.map((candidate) => (
                                    <CandidateRow
                                        key={candidate.id}
                                        candidate={candidate}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
