<?php

/**
 * Diminutive/alias map, keyed by the formal name in normalized (lowercase) form.
 *
 * Read at *scoring* time (Detection phase 2), never at blocking — the trigram
 * name block can't expand nicknames, which is exactly why Jennifer/Jen depends
 * on the shared-household block to become a candidate at all.
 *
 * Lookup is bidirectional: PairScorer treats `jennifer` and `jen` as agreeing
 * whichever side each lands on. Deliberately small — a real deployment would
 * carry a full census-derived table.
 *
 * @return array<string, list<string>>
 */
return [
    'jennifer' => ['jen', 'jenny', 'jenn', 'jennie'],
    'robert' => ['bob', 'bobby', 'rob', 'robbie'],
    'william' => ['will', 'bill', 'billy', 'liam'],
    'elizabeth' => ['liz', 'beth', 'betsy', 'eliza', 'lizzie'],
    'michael' => ['mike', 'mikey', 'mick'],
    'katherine' => ['kate', 'katie', 'kathy', 'kat'],
    'christopher' => ['chris', 'topher'],
    'margaret' => ['maggie', 'meg', 'peggy', 'marge'],
    'thomas' => ['tom', 'tommy'],
    'richard' => ['rick', 'dick', 'rich', 'richie'],
    'patricia' => ['pat', 'patty', 'trish'],
    'deborah' => ['deb', 'debbie'],
    'james' => ['jim', 'jimmy', 'jamie'],
    'susan' => ['sue', 'susie'],
    'daniel' => ['dan', 'danny'],
    'anthony' => ['tony'],
    'stephen' => ['steve', 'stevie'],
    'nicholas' => ['nick', 'nicky'],
    'benjamin' => ['ben', 'benny'],
    'alexander' => ['alex', 'xander', 'sasha'],
];
