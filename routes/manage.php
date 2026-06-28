<?php

use App\Http\Controllers\EntryCopyController;
use App\Http\Controllers\EntryImportController;
use App\Http\Controllers\FixtureScheduleController;
use App\Http\Controllers\LiveControlController;
use App\Http\Controllers\ManageController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\ScoreReviewController;
use Illuminate\Support\Facades\Route;

// The system-admin area: tournament management independent of any pool. Gated by the
// `manage-tournament` ability, so admins reach Live Control, score review/approval and the
// fixture schedule without joining a pool.
Route::middleware(['auth', 'can:manage-tournament'])->prefix('manage')->name('manage.')->group(function () {
    Route::get('/', [ManageController::class, 'index'])->name('index');

    // Players: the web counterpart of the user:pre-register / user:set-email commands. Global
    // (tournament-less) like the hub, since a pre-registered player isn't scoped to one tournament.
    // {user} binds by id. Setting an email fully locks the account; all other edits are add-only.
    Route::get('players', [PlayerController::class, 'index'])->name('players.index');
    Route::post('players', [PlayerController::class, 'store'])->name('players.store');
    Route::get('players/{user}/edit', [PlayerController::class, 'edit'])->name('players.edit');
    Route::patch('players/{user}', [PlayerController::class, 'update'])->name('players.update');
    Route::patch('players/{user}/email', [PlayerController::class, 'setEmail'])->name('players.email');

    // Live Control: start matches, keep the live score, end a match (hands off to the proposal flow).
    Route::get('{tournament:slug}/live', [LiveControlController::class, 'index'])->name('live.index');
    Route::post('{tournament:slug}/live/fixtures/{fixture}/go-live', [LiveControlController::class, 'goLive'])->name('live.go-live');
    Route::patch('{tournament:slug}/live/fixtures/{fixture}/score', [LiveControlController::class, 'updateScore'])->name('live.score');
    Route::post('{tournament:slug}/live/fixtures/{fixture}/end', [LiveControlController::class, 'endMatch'])->name('live.end');

    Route::get('{tournament:slug}/scores', [ScoreReviewController::class, 'review'])->name('scores.review');
    Route::patch('{tournament:slug}/scores/fixtures/{fixture}', [ScoreReviewController::class, 'updateProposal'])->name('scores.proposal');
    Route::put('{tournament:slug}/scores/ordering', [ScoreReviewController::class, 'updateOrdering'])->name('scores.ordering');
    Route::post('{tournament:slug}/scores/approve', [ScoreReviewController::class, 'approve'])->name('scores.approve');

    Route::get('{tournament:slug}/schedule', [FixtureScheduleController::class, 'index'])->name('schedule.index');
    Route::patch('{tournament:slug}/fixtures/{fixture}/reschedule', [FixtureScheduleController::class, 'reschedule'])->name('fixtures.reschedule');

    // Backfill: for a player who couldn't get into the app to enter their predictions before the
    // lock, paste those predictions as JSON, review/correct the derived bracket, then commit and
    // re-score the pool. The pool is a validated field (not in the URL) like the rest of this
    // tournament-scoped area; deliberately bypasses the prediction lock.
    Route::get('{tournament:slug}/backfill', [EntryImportController::class, 'create'])->name('backfill.create');
    Route::post('{tournament:slug}/backfill/preview', [EntryImportController::class, 'preview'])->name('backfill.preview');
    Route::post('{tournament:slug}/backfill', [EntryImportController::class, 'commit'])->name('backfill.commit');

    // Copy predictions: bulk-import several players' predictions from one pool into a sibling pool of
    // the same tournament (e.g. an upfront pool into a phased/rolling one). Group scorelines always
    // copy; the knockout copies only between pools of the same bracket shape. Pick the pools, choose
    // whom to import, then commit and re-score the destination once. Bypasses the prediction lock.
    Route::get('{tournament:slug}/copy-predictions', [EntryCopyController::class, 'create'])->name('copy.create');
    Route::post('{tournament:slug}/copy-predictions/preview', [EntryCopyController::class, 'preview'])->name('copy.preview');
    Route::post('{tournament:slug}/copy-predictions', [EntryCopyController::class, 'commit'])->name('copy.commit');
});
