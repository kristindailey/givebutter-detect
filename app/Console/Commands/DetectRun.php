<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Services\Detection\CandidateGenerator;
use App\Services\Detection\PairScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The batch scoring job. Generates candidate pairs (Phase 1's blocking), scores
 * each with the weighted `PairScorer` + household modifier, and repopulates the
 * `duplicate_candidates` queue — truncate + rewrite, so a rerun always reflects
 * the current data.
 *
 * Only pairs at or above the Review band are persisted; Ignore-band pairs (the
 * parent/child ~35 among them) are scored and discarded, which is why the review
 * queue never has to filter noise on read. Candidates are precomputed here rather
 * than scored on page load — the honest production shape, and far faster.
 */
#[Signature('detect:run')]
#[Description('Score candidate pairs and repopulate the duplicate_candidates queue')]
class DetectRun extends Command
{
    public function handle(CandidateGenerator $generator, PairScorer $scorer): int
    {
        $pairs = $generator->generate();
        $contacts = $this->loadContacts($pairs);
        $reviewFloor = (float) config('detection.bands.review');
        $now = now();

        $rows = [];

        foreach ($pairs as $pair) {
            $a = $contacts->get($pair['a_id']);
            $b = $contacts->get($pair['b_id']);

            if ($a === null || $b === null) {
                continue;
            }

            $result = $scorer->score($a, $b);

            if ($result['score'] < $reviewFloor) {
                continue;
            }

            $rows[] = [
                'contact_a_id' => $pair['a_id'],
                'contact_b_id' => $pair['b_id'],
                'score' => $result['score'],
                'signal_breakdown' => json_encode($result['breakdown'], JSON_THROW_ON_ERROR),
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            DuplicateCandidate::truncate();

            foreach (array_chunk($rows, 500) as $chunk) {
                DuplicateCandidate::insert($chunk);
            }
        });

        $this->components->info(sprintf(
            'Scored %d candidate pairs; %d entered the queue (score ≥ %d).',
            $pairs->count(),
            count($rows),
            (int) $reviewFloor,
        ));

        return self::SUCCESS;
    }

    /**
     * Load every contact referenced by a candidate pair, archived-inclusive (a
     * rerun after a merge can still reference an archived loser), with the
     * relations the scorer reads eager-loaded so scoring adds no per-pair queries
     * beyond the trigram probe.
     *
     * @param  Collection<int, array{a_id: int, b_id: int}>  $pairs
     * @return Collection<int, Contact>
     */
    private function loadContacts(Collection $pairs): Collection
    {
        $ids = $pairs
            ->flatMap(fn (array $pair): array => [$pair['a_id'], $pair['b_id']])
            ->unique();

        return Contact::withArchived()
            ->with(['emails', 'phones', 'households'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }
}
