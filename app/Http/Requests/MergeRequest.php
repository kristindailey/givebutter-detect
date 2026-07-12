<?php

namespace App\Http\Requests;

use App\Models\Contact;
use App\Services\MergeService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the committing merge POST. The two contacts must exist, be
 * `individual` (companies aren't mergeable), be unarchived, and be distinct. Picks
 * are whitelisted server-side to real scalar fields with a `survivor|loser` value —
 * the client is never trusted to name arbitrary fields, and unknown keys are
 * dropped before validation so the service only ever sees a clean map.
 */
class MergeRequest extends FormRequest
{
    /**
     * `bail` stops each id at its first failure, which does two things: it keeps
     * `notArchived()` from running a lookup on an id that isn't even a valid
     * contact, and it makes the "first error is what the client toasts" contract
     * deliberate rather than incidental to rule order.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $mergeable = Rule::exists('contacts', 'id')->where('type', Contact::TYPE_INDIVIDUAL);

        return [
            'survivor_id' => ['bail', 'required', 'integer', $mergeable, $this->notArchived('keep')],
            'loser_id' => ['bail', 'required', 'integer', 'different:survivor_id', $mergeable, $this->notArchived('merge in')],
            'picks' => ['sometimes', 'array'],
            'picks.*' => ['string', Rule::in(['survivor', 'loser'])],
        ];
    }

    /**
     * An archived contact is the loser of a previous merge. Because candidates are
     * strictly pairwise (no clustering), A≈B and B≈C can both be pending — merging
     * A≈B archives B while B≈C stays queued, so a survivor override onto B would
     * re-point C's transactions onto a soft-deleted record and hide that giving
     * history under `ArchivedScope`.
     *
     * Kept as its own rule rather than a `whereNull('archived_at')` on the `exists`
     * above, because custom messages key off the rule *name* — a second `exists`
     * would collide with the first and force one hedged message to cover "missing",
     * "company", and "archived" alike. A distinct rule type owns its own copy, so
     * this can state the archived case as fact.
     */
    private function notArchived(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            $archived = Contact::withArchived()
                ->whereKey($value)
                ->whereNotNull('archived_at')
                ->exists();

            if ($archived) {
                $fail("The contact you chose to {$label} was already merged away. Reload the queue and try again.");
            }
        };
    }

    /**
     * With the archived case split out into `notArchived()`, `exists` is left
     * catching only "no such contact" and "that's a company" — neither of which a
     * reviewer can reach from the UI (contacts arrive by seeder; the seed has no
     * companies), so this is a shouldn't-happen message and reads like one.
     *
     * Laravel puts the first error in the 422 `message`, which is what `merge.ts`
     * toasts verbatim — so this copy, and `notArchived()`'s, are a contract with
     * the client rather than labels. Tests pin both.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'survivor_id.exists' => 'The contact you chose to keep is not a mergeable contact.',
            'loser_id.exists' => 'The contact you chose to merge in is not a mergeable contact.',
        ];
    }

    /**
     * Drop any pick key that isn't a resolvable scalar field before validation, so
     * the client can never move an unknown or derived field through the picker.
     */
    protected function prepareForValidation(): void
    {
        $picks = $this->input('picks');

        if (is_array($picks)) {
            $this->merge([
                'picks' => array_intersect_key($picks, array_flip(MergeService::SCALAR_FIELDS)),
            ]);
        }
    }

    /**
     * The validated picker choices, defaulting to an empty map (all survivor).
     *
     * @return array<string, string>
     */
    public function picks(): array
    {
        /** @var array<string, string> */
        return $this->validated('picks', []);
    }
}
