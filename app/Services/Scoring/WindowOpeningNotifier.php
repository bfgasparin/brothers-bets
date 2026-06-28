<?php

namespace App\Services\Scoring;

use App\Enums\PhaseKey;
use App\Enums\PhaseType;
use App\Enums\PoolAccent;
use App\Enums\PredictionWindowStatus;
use App\Models\Pool;
use App\Notifications\PredictionWindowOpenedNotification;
use App\Services\Predictions\PredictionWindowResolver;

/**
 * After a result batch is approved, emails the players of a phased-bracket pool about every knockout
 * round whose prediction window just opened — a phase that was not {@see PredictionWindowStatus::Open}
 * before this approval and is now (its real participants have just been projected onto the fixtures).
 *
 * Idempotent by construction: the before/after diff is taken within a single approval, and a round's
 * Open → Locked transition is terminal, so re-approving a correction while a round is already open
 * re-sends nothing. No persistent "notified" flag is needed.
 */
class WindowOpeningNotifier
{
    public function __construct(
        private readonly PredictionWindowResolver $resolver = new PredictionWindowResolver,
    ) {}

    /**
     * @param  array<string, PredictionWindowStatus>  $before  the pool's per-phase window statuses captured before this approval
     */
    public function notifyOpenedRounds(Pool $pool, array $before): void
    {
        // Both phased and rolling (per-match) pools predict the official bracket round by round, so
        // each knockout round opens once its participants are projected. A per-match pool still emits
        // one "round open" email per round (matches lock per fixture, but the round opens together).
        if (! $pool->predictsOfficialBracket()) {
            return;
        }

        $after = $this->resolver->windows($pool);
        $accent = $pool->accent ?? PoolAccent::Pitch;

        foreach ($after as $key => $status) {
            // The group window opens at pool creation, never at approval, so it never notifies.
            if ($status !== PredictionWindowStatus::Open
                || ($before[$key] ?? null) === PredictionWindowStatus::Open
                || $key === PhaseKey::Group->value) {
                continue;
            }

            $phase = $pool->tournament->phases->firstWhere('key', PhaseKey::from($key));

            if ($phase === null || $phase->type !== PhaseType::Knockout) {
                continue;
            }

            $deadline = $this->resolver->lockAtFor($pool, $phase);

            foreach ($pool->entries()->with('user')->get() as $entry) {
                // Pass the phase key + canonical English name; the notification resolves the
                // localized round name inside toMail() under each recipient's preferred locale.
                $entry->user?->notify(new PredictionWindowOpenedNotification(
                    $pool->name,
                    $pool->slug,
                    $pool->source,
                    $accent,
                    $phase->key,
                    $phase->name,
                    $deadline,
                ));
            }
        }
    }
}
