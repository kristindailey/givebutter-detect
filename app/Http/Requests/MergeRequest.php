<?php

namespace App\Http\Requests;

use App\Models\Contact;
use App\Services\MergeService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the committing merge POST. The two contacts must exist and be
 * `individual` (companies aren't mergeable), and must be distinct. Picks are
 * whitelisted server-side to real scalar fields with a `survivor|loser` value —
 * the client is never trusted to name arbitrary fields, and unknown keys are
 * dropped before validation so the service only ever sees a clean map.
 */
class MergeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $individual = Rule::exists('contacts', 'id')->where('type', Contact::TYPE_INDIVIDUAL);

        return [
            'survivor_id' => ['required', 'integer', $individual],
            'loser_id' => ['required', 'integer', 'different:survivor_id', $individual],
            'picks' => ['sometimes', 'array'],
            'picks.*' => ['string', Rule::in(['survivor', 'loser'])],
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
