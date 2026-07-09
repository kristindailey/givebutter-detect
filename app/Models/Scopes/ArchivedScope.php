<?php

namespace App\Models\Scopes;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides archived contacts by default, mirroring Givebutter's reversible
 * `DELETE` + `restore` semantics.
 *
 * Merge flows must lift this scope via `Contact::withArchived()` — the loser has
 * to stay loadable while it is being archived, and after.
 *
 * @implements Scope<Contact>
 */
class ArchivedScope implements Scope
{
    /**
     * @param  Builder<covariant Contact>  $builder
     */
    public function apply(Builder $builder, $model): void
    {
        $builder->whereNull($model->qualifyColumn('archived_at'));
    }
}
