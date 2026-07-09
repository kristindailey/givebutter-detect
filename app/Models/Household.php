<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Mirrors `HouseholdResource`. `head_contact_id` is the only role marker the real
 * schema carries — members have no relationship label.
 *
 * @property int $id
 * @property string $name
 * @property int|null $head_contact_id
 * @property string|null $envelope_name
 */
#[Fillable(['name', 'head_contact_id', 'envelope_name'])]
class Household extends Model
{
    /** @return BelongsToMany<Contact, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'household_contacts');
    }

    /** @return BelongsTo<Contact, $this> */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'head_contact_id');
    }
}
