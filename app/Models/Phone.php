<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $contact_id
 * @property string|null $type
 * @property string $value
 * @property string|null $normalized_value
 */
#[Fillable(['contact_id', 'type', 'value', 'normalized_value'])]
class Phone extends Model
{
    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
