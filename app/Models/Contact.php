<?php

namespace App\Models;

use App\Models\Scopes\ArchivedScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mirrors Givebutter's `ContactResource`.
 *
 * `total_contributions`, `contact_since`, and `last_donation_amount` are derived
 * — recomputed from transactions on merge, and deliberately left out of the
 * fillable list so no request payload can set them directly.
 *
 * @property int $id
 * @property string|null $external_id
 * @property string $type
 * @property string|null $prefix
 * @property string|null $first_name
 * @property string|null $preferred_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property string|null $suffix
 * @property CarbonImmutable|null $dob
 * @property string|null $company
 * @property string|null $title
 * @property string|null $primary_email
 * @property string|null $primary_phone
 * @property string $total_contributions
 * @property CarbonImmutable|null $contact_since
 * @property string|null $last_donation_amount
 * @property string|null $name_key
 * @property string|null $address_key
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'external_id', 'type', 'prefix', 'first_name', 'preferred_name', 'middle_name',
    'last_name', 'suffix', 'dob', 'company', 'title', 'primary_email', 'primary_phone',
    'name_key', 'address_key',
])]
#[ScopedBy([ArchivedScope::class])]
class Contact extends Model
{
    /** A person. The only mergeable type — companies aren't merged. */
    public const string TYPE_INDIVIDUAL = 'individual';

    /** An organization. */
    public const string TYPE_COMPANY = 'company';

    /**
     * Include archived contacts. Required by the merge flows, which must load a
     * loser that is being (or has been) archived.
     *
     * @return Builder<static>
     */
    public static function withArchived(): Builder
    {
        return static::query()->withoutGlobalScope(ArchivedScope::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Archiving is never mass-assignable — the merge commit mass-assigns from
     * user picker input, and `archived_at` is the one field that could silently
     * retire the survivor. Set it only through these two calls.
     */
    public function archive(): void
    {
        $this->archived_at = now();
        $this->save();
    }

    public function restore(): void
    {
        $this->archived_at = null;
        $this->save();
    }

    /** @return HasMany<Email, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    /** @return HasMany<Phone, $this> */
    public function phones(): HasMany
    {
        return $this->hasMany(Phone::class);
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /** @return HasMany<ExternalId, $this> */
    public function externalIds(): HasMany
    {
        return $this->hasMany(ExternalId::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return BelongsToMany<Household, $this> */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_contacts');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tags');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'contact_since' => 'date',
            'total_contributions' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }
}
