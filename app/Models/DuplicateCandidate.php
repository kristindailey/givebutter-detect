<?php

namespace App\Models;

use App\Models\Scopes\ArchivedScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A precomputed pairwise candidate, written by `detect:run`. Pairs are canonically
 * ordered (`contact_a_id < contact_b_id`) and strictly pairwise — A≈B and B≈C are
 * two independent rows, not a cluster.
 *
 * @property int $id
 * @property int $contact_a_id
 * @property int $contact_b_id
 * @property string $score
 * @property array<string, mixed> $signal_breakdown
 * @property string $resolution
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $detected_at
 */
#[Fillable(['contact_a_id', 'contact_b_id', 'score', 'signal_breakdown', 'detected_at'])]
class DuplicateCandidate extends Model
{
    /** Awaiting a human decision — the only rows the Review Queue shows. */
    public const string RESOLUTION_PENDING = 'pending';

    /** Confirmed duplicate. A labeled positive. */
    public const string RESOLUTION_MERGED = 'merged';

    /** Dismissed as "not a duplicate". A labeled negative — in production this is
     * the training signal for the scoring weights. */
    public const string RESOLUTION_DISMISSED = 'dismissed';

    /**
     * The Review Queue: pending pairs, highest confidence first.
     *
     * @param  Builder<DuplicateCandidate>  $query
     * @return Builder<DuplicateCandidate>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('resolution', self::RESOLUTION_PENDING)->orderByDesc('score');
    }

    /**
     * Queue state is never mass-assignable — a resolution is the outcome of an
     * action, not a field a request payload gets to name. Resolved rows are kept,
     * not deleted: each one is a labeled example.
     */
    public function markMerged(): void
    {
        $this->resolve(self::RESOLUTION_MERGED);
    }

    public function markDismissed(): void
    {
        $this->resolve(self::RESOLUTION_DISMISSED);
    }

    private function resolve(string $resolution): void
    {
        $this->resolution = $resolution;
        $this->resolved_at = now();
        $this->save();
    }

    /**
     * Archived-inclusive: once a pair is merged the loser carries `archived_at`,
     * and a resolved row must still be able to load both sides.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contactA(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_a_id')->withoutGlobalScope(ArchivedScope::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contactB(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_b_id')->withoutGlobalScope(ArchivedScope::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signal_breakdown' => 'array',
            'score' => 'decimal:2',
            'resolved_at' => 'datetime',
            'detected_at' => 'datetime',
        ];
    }
}
