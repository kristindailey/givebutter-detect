import { cn } from '@/lib/utils';

// A single "why" chip. Tone encodes the household modifier's direction: a boost
// reads positive, a dob conflict negative, a dampened shared-inbox muted. Purely
// presentational — the queue derives these from the precomputed `signal_breakdown`.

export type ChipTone = 'default' | 'positive' | 'muted' | 'negative';

const TONE_CLASSES: Record<ChipTone, string> = {
    default: 'border-border bg-muted text-brand-black/70',
    positive: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    muted: 'border-amber-200 bg-amber-50 text-amber-700',
    negative: 'border-red-200 bg-red-50 text-red-700',
};

export function SignalChip({
    label,
    tone = 'default',
}: {
    label: string;
    tone?: ChipTone;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                TONE_CLASSES[tone],
            )}
        >
            {label}
        </span>
    );
}
