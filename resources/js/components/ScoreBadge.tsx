import { cn } from '@/lib/utils';

// The 0–100 confidence score + its band, rendered as a single badge. `auto`
// (≥90) is the agent-eligible band (butter yellow); `review` (60–89) is the
// human queue (accent blue). Band is decided server-side from the score.

export type ScoreBand = 'auto' | 'review';

export function ScoreBadge({
    score,
    band,
}: {
    score: number;
    band: ScoreBand;
}) {
    const isAuto = band === 'auto';

    return (
        <div className="flex items-center gap-3">
            <span
                className={cn(
                    'flex size-12 items-center justify-center rounded-full font-heading text-lg font-bold tabular-nums',
                    isAuto
                        ? 'bg-brand-yellow text-brand-black'
                        : 'bg-brand-blue/10 text-brand-blue',
                )}
            >
                {Math.round(score)}
            </span>
            <span
                className={cn(
                    'w-28 text-xs font-semibold tracking-wide uppercase',
                    isAuto ? 'text-brand-black/70' : 'text-brand-blue',
                )}
            >
                {isAuto ? 'Agent-eligible' : 'Review'}
            </span>
        </div>
    );
}
