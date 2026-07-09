<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Never matched on, never diffed — tags exist only so a merge can union them.
 *
 * @property int $id
 * @property string $name
 */
#[Fillable(['name'])]
class Tag extends Model
{
    /** @return BelongsToMany<Contact, $this> */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tags');
    }
}
