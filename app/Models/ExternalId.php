<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mirrored but never matched on — external-ID matching is out of scope.
 *
 * @property int $id
 * @property int $contact_id
 * @property string|null $label
 * @property string $external_id
 */
#[Fillable(['contact_id', 'label', 'external_id'])]
class ExternalId extends Model
{
    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
