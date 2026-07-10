<?php

use App\Services\Detection\CandidateGenerator;
use Database\Seeders\DemoSeeder;

/**
 * The blocking half of the matcher, exercised against the demo seed's stable
 * hero IDs. The full weighted-scoring proof (no noise pair clears the Review
 * band) needs Phase 2's weights and lands in the Detection Phase 2 test; what
 * this phase owns is that blocking emits the right *set* of pairs — the hero
 * pair survives, and the fuzzy name block over-generates on noise as designed.
 *
 * Runs on Postgres (`givebutter_detect_testing`), so `%` and the GIN trigram
 * indexes the generator relies on are the real ones.
 */
beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

it('generates the Jennifer/Jen hero pair from the household block', function () {
    // Their emails and phones differ and trigram('jen smith','jennifer smith')
    // sits at the threshold, so only the same-household block reliably emits this
    // pair. If candidate generation ever drops it, the headline demo is dead.
    $pairs = (new CandidateGenerator)->generate();

    $hero = $pairs->contains(
        fn (array $pair) => $pair['a_id'] === DemoSeeder::JENNIFER_ID
            && $pair['b_id'] === DemoSeeder::JEN_ID,
    );

    expect($hero)->toBeTrue();
});

it('returns canonically-ordered, deduped pairs', function () {
    // Every block applies `a.id < b.id` in SQL and the five UNION together, so no
    // pair is reversed and none repeats regardless of how many blocks fired on it.
    $pairs = (new CandidateGenerator)->generate();

    $reversed = $pairs->filter(fn (array $pair) => $pair['a_id'] >= $pair['b_id']);
    $keys = $pairs->map(fn (array $pair) => $pair['a_id'].'-'.$pair['b_id']);

    expect($reversed)->toBeEmpty()
        ->and($keys->duplicates())->toBeEmpty();
});

it('over-generates on the name trigram block, proving blocking is doing work', function () {
    // ~4k pairs at the default 0.3 threshold — almost all obvious non-matches a
    // seq-scan would never surface. This is blocking working as intended; it is
    // also what keeps the Phase 2 "no noise pair clears the band" assertion from
    // being vacuous (there have to be noise pairs for it to reject).
    $pairs = (new CandidateGenerator)->generate();

    expect($pairs->count())->toBeGreaterThan(1000);
});
