<?php

use App\Services\Detection\Normalizer;

/**
 * The Normalizer is pure string logic, so it's tested directly — no database.
 * Every blocking key in the system comes out of this class; if it drifts, the
 * self-joins in CandidateGenerator quietly match fewer pairs.
 */
beforeEach(function () {
    $this->normalizer = new Normalizer;
});

it('builds a name key that is lowercase, unaccented, and free of punctuation', function (
    ?string $first,
    ?string $last,
    ?string $expected,
) {
    expect($this->normalizer->nameKey($first, $last))->toBe($expected);
})->with([
    'plain' => ['Jennifer', 'Smith', 'jennifer smith'],
    'accents folded' => ['José', 'Núñez', 'jose nunez'],
    'punctuation stripped' => ["D'Angelo", 'Smith-Jones', 'dangelo smithjones'],
    'whitespace collapsed' => ['  Mary   Anne ', ' Smith ', 'mary anne smith'],
    'tabs separate names rather than fusing them' => ["Mary\tAnne", 'Smith', 'mary anne smith'],
    'newlines separate names too' => ["Mary\nAnne", 'Smith', 'mary anne smith'],
    'digits stripped' => ['Jennifer2', 'Smith', 'jennifer smith'],
    'missing last name' => ['Jennifer', null, 'jennifer'],
    'missing both' => [null, null, null],
    'blank is null, never empty string' => ['', '  ', null],
]);

it('keeps jen and jennifer as distinct name keys', function () {
    // The nickname table is a *scoring* concern. If blocking ever folded these
    // together the household-block dependency would silently stop mattering,
    // and the hero pair would pass for the wrong reason.
    expect($this->normalizer->nameKey('Jen', 'Smith'))->toBe('jen smith')
        ->and($this->normalizer->nameKey('Jennifer', 'Smith'))->toBe('jennifer smith');
});

it('normalizes an email to lowercase and trimmed', function () {
    expect($this->normalizer->email('  Jennifer.Smith@Example.COM '))->toBe('jennifer.smith@example.com')
        ->and($this->normalizer->email(null))->toBeNull()
        ->and($this->normalizer->email('   '))->toBeNull();
});

it('does not fold plus-addressing or gmail dots', function () {
    // Documented as future work, not built. Pinned so the omission stays a choice.
    expect($this->normalizer->email('jen+donations@gmail.com'))->toBe('jen+donations@gmail.com')
        ->and($this->normalizer->email('j.e.n@gmail.com'))->toBe('j.e.n@gmail.com');
});

it('reduces a phone to its last ten digits', function (?string $input, ?string $expected) {
    expect($this->normalizer->phone($input))->toBe($expected);
})->with([
    'formatted' => ['(555) 867-5309', '5558675309'],
    'country code dropped' => ['+1 555 867 5309', '5558675309'],
    'already bare' => ['5558675309', '5558675309'],
    'extension digits are not special-cased' => ['555-867-5309 x22', '5867530922'],
    'short numbers survive intact' => ['867-5309', '8675309'],
    'no digits' => ['n/a', null],
    'null' => [null, null],
]);

it('builds an address key from street, city, and zip', function () {
    expect($this->normalizer->addressKey('123 Main St.', 'Springfield', '62704'))
        ->toBe('123 main st springfield 62704');
});

it('keeps digits in the address key but drops punctuation', function () {
    expect($this->normalizer->addressKey('1600  Pennsylvania Ave, N.W.', 'Washington', '20500-0003'))
        ->toBe('1600 pennsylvania ave n w washington 20500 0003');
});

it('skips missing address parts without leaving double spaces', function () {
    expect($this->normalizer->addressKey('123 Main St', null, '62704'))->toBe('123 main st 62704')
        ->and($this->normalizer->addressKey('123 Main St', '', ''))->toBe('123 main st')
        ->and($this->normalizer->addressKey(null, null, null))->toBeNull();
});

it('is idempotent — normalizing a key again returns the same key', function () {
    // detect:run rewrites keys over rows the seeder already normalized.
    $nameKey = $this->normalizer->nameKey('José', "O'Brien");
    $addressKey = $this->normalizer->addressKey('123 Main St.', 'Springfield', '62704');

    expect($this->normalizer->name($nameKey))->toBe($nameKey)
        ->and($this->normalizer->address($addressKey))->toBe($addressKey);
});
