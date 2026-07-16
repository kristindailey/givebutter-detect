import { Check } from 'lucide-react';
import { SCALAR_LABELS } from '@/lib/merge';
import type { PickSource, Picks, ScalarField } from '@/lib/merge';
import { cn } from '@/lib/utils';

// The conflict-only scalar picker. Only fields the projection flags as a genuine
// conflict (both sides present, different) get a choice — identical values and
// gap-fills aren't decisions, so they never render here. Default = survivor.
//
// `disabled` is cosmetic consistency, not a guard: the picks are captured when
// Merge is clicked, so a card pressed mid-request could never have changed what
// commits. It exists so the picker doesn't stay invitingly live while every other
// control on the screen has visibly stopped accepting input.

interface FieldPickerProps {
    scalars: Record<string, ScalarField>;
    picks: Picks;
    onPick: (field: string, source: PickSource) => void;
    survivorName: string;
    loserName: string;
    disabled?: boolean;
}

function ChoiceCard({
    owner,
    value,
    selected,
    onSelect,
    disabled,
}: {
    owner: string;
    value: string | null;
    selected: boolean;
    onSelect: () => void;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={selected}
            disabled={disabled}
            className={cn(
                'flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-left transition-colors disabled:opacity-60',
                selected
                    ? 'border-brand-blue bg-brand-blue/5'
                    : 'border-border',
                // Dropped rather than overridden while disabled: a `disabled:hover:`
                // rule would have to name the idle background, and then quietly lie
                // the day the idle background changes.
                !disabled && !selected && 'hover:bg-muted/50',
            )}
        >
            <span
                className={cn(
                    'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                    selected
                        ? 'border-brand-blue bg-brand-blue text-brand-white'
                        : 'border-muted-foreground/40',
                )}
            >
                {selected && <Check className="size-3" strokeWidth={3} />}
            </span>
            <span className="min-w-0">
                <span className="block text-xs font-medium text-muted-foreground">
                    {owner}
                </span>
                <span className="block truncate text-sm font-medium text-brand-black">
                    {value ?? '—'}
                </span>
            </span>
        </button>
    );
}

export function FieldPicker({
    scalars,
    picks,
    onPick,
    survivorName,
    loserName,
    disabled,
}: FieldPickerProps) {
    const conflicts = Object.entries(scalars).filter(
        ([, field]) => field.conflict,
    );

    if (conflicts.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No conflicting fields — every identity value agrees or fills a
                gap. Nothing to choose.
            </p>
        );
    }

    return (
        <div className="space-y-4">
            {conflicts.map(([field, resolution]) => {
                const chosen = picks[field] ?? 'survivor';

                return (
                    <div key={field}>
                        <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {SCALAR_LABELS[field] ?? field}
                        </p>
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <ChoiceCard
                                owner={survivorName}
                                value={resolution.survivor}
                                selected={chosen === 'survivor'}
                                onSelect={() => onPick(field, 'survivor')}
                                disabled={disabled}
                            />
                            <ChoiceCard
                                owner={loserName}
                                value={resolution.loser}
                                selected={chosen === 'loser'}
                                onSelect={() => onPick(field, 'loser')}
                                disabled={disabled}
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
