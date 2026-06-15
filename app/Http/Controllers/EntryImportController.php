<?php

namespace App\Http\Controllers;

use App\Http\Requests\Manage\BackfillCommitRequest;
use App\Http\Requests\Manage\BackfillPreviewRequest;
use App\Models\Entry;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Notifications\PredictionsOverwrittenNotification;
use App\Services\Predictions\Import\PredictionJsonImporter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin backfill of a user's predictions for an upfront-bracket pool from a pasted JSON blob.
 *
 * The flow mirrors the score-review pattern (render → review/correct → commit): {@see create()}
 * shows the paste form, {@see preview()} renders a non-persisting review of the derived bracket with
 * discrepancy flags, and {@see commit()} writes the admin-reviewed values and re-scores the pool.
 * Tournament-scoped like the rest of the manage area; the pool is a validated field, not in the URL.
 */
class EntryImportController extends Controller
{
    public function create(Tournament $tournament): Response
    {
        // Users whose entry in any of this tournament's pools was backfilled "as authored" — so the
        // picker can badge whose accounts had the group-classification problem.
        $authoredUserIds = Entry::query()
            ->whereIn('pool_id', $tournament->pools()->select('id'))
            ->whereNotNull('authored_bracket_at')
            ->pluck('user_id')
            ->flip();

        return Inertia::render('manage/backfill', [
            'tournament' => $this->tournamentIdentity($tournament),
            'pools' => $tournament->pools()->orderBy('name')->get()
                ->map(fn (Pool $pool): array => $this->poolIdentity($pool))
                ->all(),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'avatar_path'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'has_authored_import' => $authoredUserIds->has($user->id),
                ])
                ->all(),
        ]);
    }

    public function preview(BackfillPreviewRequest $request, Tournament $tournament, PredictionJsonImporter $importer): Response
    {
        $pool = $request->pool();
        $user = $request->targetUser();
        $entry = $this->entryFor($pool, $user);

        $parsed = $importer->parse($pool, $request->payload());

        return Inertia::render('manage/backfill-review', [
            'tournament' => $this->tournamentIdentity($tournament),
            'pool' => $this->poolIdentity($pool),
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'preview' => $importer->preview($entry, $parsed, $request->literal()),
            'thirds_team_ids' => $parsed->thirdsTeamIds,
            'group_standings_team_ids' => $parsed->groupStandings,
            // The raw blob, so the review screen can re-preview in the other bracket mode without a re-paste.
            'json' => $request->input('json'),
        ]);
    }

    public function commit(BackfillCommitRequest $request, Tournament $tournament, PredictionJsonImporter $importer): RedirectResponse
    {
        $pool = $request->pool();
        $user = $request->targetUser();
        $entry = $this->entryFor($pool, $user);

        // Enforce the "empty entry only" rule unless the admin has explicitly confirmed an overwrite.
        $overwriting = $importer->hasExistingPredictions($entry);

        if ($overwriting && ! $request->boolean('overwrite')) {
            return back()->withErrors([
                'overwrite' => __(':name already has predictions in this pool. Confirm the overwrite to replace them.', ['name' => $user->name]),
            ]);
        }

        $importer->commit($entry, $request->correctedImport());

        // Only an overwrite changes picks the player already made — let them know an organizer did it.
        if ($overwriting) {
            $user->notify(new PredictionsOverwrittenNotification($pool));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Predictions imported and points updated for :name.', ['name' => $user->name])]);

        return to_route('manage.backfill.create', $tournament);
    }

    /**
     * The user's entry in the pool, created silently if they have not joined — a player who couldn't
     * get into the app to enter their own predictions before the lock is still treated as a
     * participant. Unlike a self-service join, this sends no "you joined" notification.
     */
    private function entryFor(Pool $pool, User $user): Entry
    {
        return $pool->entries()->firstOrCreate(['user_id' => $user->id]);
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
