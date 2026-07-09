<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Source of truth for the three derived contact fields. A merge re-points the
 * loser's transactions to the survivor, then recomputes from the union.
 *
 * @property string $id
 * @property int $contact_id
 * @property string $amount
 * @property string $status
 * @property string|null $payment_method
 * @property CarbonImmutable|null $captured_at
 * @property CarbonImmutable|null $refunded_at
 */
#[Fillable(['id', 'contact_id', 'amount', 'status', 'payment_method', 'captured_at', 'refunded_at'])]
class Transaction extends Model
{
    /** The only status the recompute rules count. */
    public const string STATUS_SUCCEEDED = 'succeeded';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * A refunded row is excluded from every derived field, even though it is
     * still a real transaction on the record.
     */
    public function countsTowardGiving(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED && $this->refunded_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'captured_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
