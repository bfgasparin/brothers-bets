<?php

namespace App\Http\Controllers;

use App\Http\Requests\Manage\CopyCommitRequest;
use App\Http\Requests\Manage\CopyPreviewRequest;
use App\Models\Entry;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Notifications\PredictionsOverwrittenNotification;
use App\Services\Predictions\PredictionImporter;
use App\Services\Scoring\RankSnapshotter;
use App\Services\Scoring\ScoreEngine;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin tool to bulk-copy players' predictions from one pool into a sibling pool of the same
 * tournament — the "select a pool the player already joined" counterpart of {@see EntryImportController}
 * (which pastes JSON instead). The flow mirrors the backfill: {@see create()} picks the source and
 * destination pools, {@see preview()} lists the source pool's members so the admin chooses whom to
 * import, and {@see commit()} copies each selection and re-scores the destination once.
 *
 * Group scorelines always copy; the knockout copies only between pools of the same bracket shape
 * ({@see PredictionImporter::importAllCompatible()}). Tournament-scoped like the rest of the manage
 * area; the pools are validated fields, not in the URL, and the prediction lock is deliberately bypassed.
 */
class EntryCopyController extends Controller
{
    public function create(Tournament $tournament): Response
    {
        return Inertia::render('manage/copy-predictions', [
            'tournament' => $this->tournamentIdentity($tournament),
            'pools' => $tournament->pools()->orderBy('name')->get()
                ->map(fn (Pool $pool): array => $this->poolIdentity($pool))
                ->all(),
        ]);
    }

    public function preview(CopyPreviewRequest $request, Tournament $tournament, PredictionImporter $importer): Response
    {
        $source = $request->sourcePool();
        $destination = $request->destinationPool();

        // The destination's entries keyed by user, so the "already populated" check is one query.
        $destinationEntries = $destination->entries()->get()->keyBy('user_id');

        $candidates = $source->entries()->with('user')->get()
            ->sortBy(fn (Entry $entry): string => $entry->user->name)
            ->values()
            ->map(function (Entry $sourceEntry) use ($importer, $destinationEntries): array {
                $destinationEntry = $destinationEntries->get($sourceEntry->user_id);

                return [
                    'user' => [
                        'id' => $sourceEntry->user->id,
                        'name' => $sourceEntry->user->name,
                        'email' => $sourceEntry->user->email,
                        'avatar' => $sourceEntry->user->avatar,
                    ],
                    'group_count' => $sourceEntry->groupPredictions()->count(),
                    'source_authored' => $sourceEntry->hasAuthoredBracket(),
                    'destination_joined' => $destinationEntry !== null,
                    'destination_populated' => $destinationEntry !== null && $importer->hasOwnPredictions($destinationEntry),
                ];
            })
            ->all();

        return Inertia::render('manage/copy-predictions-review', [
            'tournament' => $this->tournamentIdentity($tournament),
            'source' => $this->poolIdentity($source),
            'destination' => $this->poolIdentity($destination),
            // Whether knockout picks can transfer too, or only the group stage (different formats).
            'knockout_transfers' => $source->predictsKnockoutBracket() === $destination->predictsKnockoutBracket(),
            'candidates' => $candidates,
        ]);
    }

    public function commit(CopyCommitRequest $request, Tournament $tournament, PredictionImporter $importer, ScoreEngine $engine, RankSnapshotter $snapshotter): RedirectResponse
    {
        $source = $request->sourcePool();
        $destination = $request->destinationPool();
        $overwrite = $request->boolean('overwrite');

        $copied = 0;
        $skipped = 0;
        /** @var list<User> $overwritten */
        $overwritten = [];

        foreach ($request->users() as $user) {
            $destinationEntry = $destination->entries()->firstOrCreate(['user_id' => $user->id]);
            $hadPredictions = $importer->hasOwnPredictions($destinationEntry);

            // Leave a player who already predicted untouched unless the admin confirmed an overwrite.
            if (! $overwrite && $hadPredictions) {
                $skipped++;

                continue;
            }

            if ($importer->importAllCompatible($destinationEntry, $source)) {
                $copied++;

                if ($hadPredictions) {
                    $overwritten[] = $user;
                }
            } else {
                $skipped++;
            }
        }

        // Re-score the destination once, outside any write transaction (mirrors the backfill commit).
        $engine->recompute($destination);
        $snapshotter->snapshot($destination);

        // Only a replaced player had picks of their own changed — tell them an organizer did it.
        foreach ($overwritten as $user) {
            $user->notify(new PredictionsOverwrittenNotification($destination));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':copied imported, :skipped skipped.', ['copied' => $copied, 'skipped' => $skipped]),
        ]);

        return to_route('manage.copy.create', $tournament);
    }

    /**
     * @return array{name: string, slug: string}
     */
    private function tournamentIdentity(Tournament $tournament): array
    {
        return ['name' => $tournament->name, 'slug' => $tournament->slug];
    }

    /**
     * @return array{id: int, name: string, source: string, slug: string, accent: ?string, scoring_label: string}
     */
    private function poolIdentity(Pool $pool): array
    {
        return [
            'id' => $pool->id,
            'name' => $pool->name,
            'source' => $pool->source,
            'slug' => $pool->slug,
            'accent' => $pool->accent?->value,
            'scoring_label' => $pool->scoring_strategy->label(),
        ];
    }
}
