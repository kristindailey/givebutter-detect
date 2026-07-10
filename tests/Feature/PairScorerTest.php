<?php

use App\Models\Contact;
use App\Services\Detection\CandidateGenerator;
use App\Services\Detection\PairScorer;
use Database\Seeders\DemoSeeder;

/**
 * The trust-critical half of the matcher: the weighted scorer and the asymmetric
 * household modifier, against the demo seed's stable hero IDs. Getting both hero
 * cases right — catch Jennifer/Jen, refuse parent/child — is the whole demo, and
 * the parent/child assertion pins down that it is refused *because of* the
 * modifier, not by accident.
 *
 * Runs on Postgres, so the `similarity()` probe the scorer uses for the name and
 * address trigram signals is the real `pg_trgm` one.
 */
beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->scorer = app(PairScorer::class);
});

/** Load a contact with the relations the scorer reads, archived-inclusive. */
function loadForScoring(int $id): Contact
{
    return Contact::withArchived()->with(['emails', 'phones', 'households'])->findOrFail($id);
}

/** Pull one signal's entry out of a breakdown by name. */
function signalEntry(array $breakdown, string $signal): ?array
{
    return collect($breakdown)->firstWhere('signal', $signal);
}

it('scores Jennifer/Jen in the flagged-high band', function () {
    $result = $this->scorer->score(
        loadForScoring(DemoSeeder::JENNIFER_ID),
        loadForScoring(DemoSeeder::JEN_ID),
    );

    // 94 = name 25 (nickname) + address 20 (identical) + household boost 49. They
    // share no email or phone, so those signals contribute nothing — the pair is
    // carried entirely by the fuzzy name, the address, and the household boost.
    expect($result['score'])->toBe(94.0)
        ->and($result['band'])->toBe('auto');
});

it('matches the Jennifer/Jen name through the nickname table, not a trigram fluke', function () {
    $result = $this->scorer->score(
        loadForScoring(DemoSeeder::JENNIFER_ID),
        loadForScoring(DemoSeeder::JEN_ID),
    );

    $name = signalEntry($result['breakdown'], 'name');
    $household = signalEntry($result['breakdown'], 'household');

    // `Jen` ≈ `Jennifer` is a nickname match (agreement 1.0 → full 25), not the
    // borderline ~0.56 trigram; and the household boost fired on dob agreement.
    expect($name['via'])->toBe('nickname')
        ->and($name['contribution'])->toBe(25.0)
        ->and($household['modifier'])->toBe('+boost')
        ->and($household['reason'])->toBe('dob agreement');
});

it('refuses parent/child, and does so because of the modifier and the dob conflict', function () {
    $result = $this->scorer->score(
        loadForScoring(DemoSeeder::PARENT_ID),
        loadForScoring(DemoSeeder::CHILD_ID),
    );

    $email = signalEntry($result['breakdown'], 'email');
    $household = signalEntry($result['breakdown'], 'household');

    // The naive rule merges these on the shared inbox alone. The score is low, and
    // the assertion nails down *why*: the shared-household email was dampened (a
    // family inbox is weak evidence) and the dob conflict pushed toward "different
    // people". Both branches must have fired — not an incidental near-miss.
    expect($result['score'])->toBeLessThan(60.0)
        ->and($result['score'])->toBeGreaterThan(30.0)
        ->and($result['band'])->toBe('ignore')
        ->and($email['note'])->toBe('dampened: shared household')
        ->and($email['matched'])->toContain('hayesfamily@gmail.com')
        ->and($household['modifier'])->toBe('-conflict')
        ->and($household['reason'])->toBe('dob conflict');
});

it('keeps every noise pair below the Review band', function () {
    // The queue-integrity invariant, now testable because scoring exists (it was
    // deferred here from Phase 1, which built the GIN indexes this enumeration
    // needs to run fast). Every candidate pair touching a noise contact
    // (id > LAST_CURATED) is scored; the highest must stay under 60, so nothing we
    // did not deliberately seed can ever reach a human reviewer.
    $contacts = Contact::withArchived()->with(['emails', 'phones', 'households'])->get()->keyBy('id');

    $maxNoiseScore = app(CandidateGenerator::class)->generate()
        ->filter(fn (array $pair) => $pair['a_id'] > DemoSeeder::LAST_CURATED_ID
            || $pair['b_id'] > DemoSeeder::LAST_CURATED_ID)
        ->map(fn (array $pair) => $this->scorer->score(
            $contacts->get($pair['a_id']),
            $contacts->get($pair['b_id']),
        )['score'])
        ->max();

    expect($maxNoiseScore)->toBeLessThan(60.0);
});
