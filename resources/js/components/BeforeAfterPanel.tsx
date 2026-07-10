import { ArrowRight } from 'lucide-react';
import { Fragment } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDate, formatMoney } from '@/lib/merge';
import type { ProjectionDerived } from '@/lib/merge';
import { cn } from '@/lib/utils';

// The demo payoff: the three derived fields recomputed from the post-repoint
// union of transactions, shown before → after. When the preview resolves, the
// changed `contact_since` after-value flashes (see .animate-value-flash). The
// flash is keyed off `flashKey` so a remount replays it on every fresh preview.

interface BeforeAfterPanelProps {
    derived: ProjectionDerived | null;
    loading: boolean;
    flashKey: number;
}

type Formatter = (value: string | null) => string;

interface Row {
    key: keyof ProjectionDerived;
    label: string;
    format: Formatter;
    flashOnChange: boolean;
}

const ROWS: Row[] = [
    {
        key: 'contact_since',
        label: 'Contact since',
        format: formatDate,
        flashOnChange: true,
    },
    {
        key: 'total_contributions',
        label: 'Total contributions',
        format: formatMoney,
        flashOnChange: false,
    },
    {
        key: 'last_donation_amount',
        label: 'Last donation',
        format: formatMoney,
        flashOnChange: false,
    },
];

// One shared grid so the before/arrow/after columns align across all three rows.
const GRID =
    'grid grid-cols-[minmax(0,1fr)_auto_auto_auto] items-center gap-x-3 gap-y-2';

function LoadingRows() {
    return (
        <div className={GRID}>
            {ROWS.map((row) => (
                <Fragment key={row.key}>
                    <Skeleton className="h-4 w-28" />
                    <Skeleton className="h-6 w-24 justify-self-end" />
                    <ArrowRight className="size-4 text-muted-foreground/40" />
                    <Skeleton className="h-6 w-24 justify-self-end" />
                </Fragment>
            ))}
        </div>
    );
}

export function BeforeAfterPanel({
    derived,
    loading,
    flashKey,
}: BeforeAfterPanelProps) {
    if (loading || derived === null) {
        return <LoadingRows />;
    }

    return (
        <div className={GRID}>
            {ROWS.map((row) => {
                const field = derived[row.key];
                const changed = field.before !== field.after;
                const shouldFlash = row.flashOnChange && changed;

                return (
                    <Fragment key={row.key}>
                        <span className="text-sm text-muted-foreground">
                            {row.label}
                        </span>
                        <span className="text-right text-sm text-brand-black/50 tabular-nums line-through decoration-brand-black/20">
                            {row.format(field.before)}
                        </span>
                        <ArrowRight className="size-4 text-muted-foreground" />
                        <span
                            // Remount on each fresh preview so the flash replays.
                            key={shouldFlash ? flashKey : undefined}
                            className={cn(
                                'rounded-md px-1.5 py-0.5 text-right text-sm font-semibold text-brand-black tabular-nums',
                                changed && 'text-brand-blue',
                                shouldFlash && 'animate-value-flash',
                            )}
                        >
                            {row.format(field.after)}
                        </span>
                    </Fragment>
                );
            })}
        </div>
    );
}
