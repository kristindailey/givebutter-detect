<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $contact_id
 * @property string|null $address_1
 * @property string|null $address_2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $zipcode
 * @property string|null $country
 * @property string|null $type
 * @property bool $is_primary
 */
#[Fillable([
    'contact_id', 'address_1', 'address_2', 'city', 'state',
    'zipcode', 'country', 'type', 'is_primary',
])]
class Address extends Model
{
    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
