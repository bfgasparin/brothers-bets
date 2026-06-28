<?php

namespace Database\Seeders;

use App\Models\Pool;
use App\Models\Tournament;
use Illuminate\Database\Seeder;

/**
 * Adds ONLY the Rolling Predictions pool to the existing World Cup 2026 tournament — the safe way to
 * introduce the pool on a live database. Unlike {@see WorldCup2026Seeder}, it never re-seeds the
 * tournament, fixtures, or the other pools, so it cannot revert an admin's reschedules, scoring/prize
 * tuning, or the tournament's derived status. It writes exactly one row (idempotent via the slug),
 * and no-ops when the tournament isn't present, so it is harmless to run anywhere.
 *
 * Run with: php artisan db:seed --class='Database\Seeders\AddRollingPoolSeeder'
 */
class AddRollingPoolSeeder extends Seeder
{
    public function run(): void
    {
        $tournament = Tournament::where('slug', 'world-cup-2026')->first();

        if ($tournament === null) {
            return;
        }

        Pool::updateOrCreate(
            ['slug' => 'world-cup-2026-brothers-2'],
            WorldCup2026Seeder::rollingPoolAttributes($tournament->id),
        );
    }
}
