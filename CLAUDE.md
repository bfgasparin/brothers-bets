# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> The `<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/nightwatch (NIGHTWATCH) - v1
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `vendor/bin/sail artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `vendor/bin/sail artisan test --compact`.
- To run all tests in a file: `vendor/bin/sail artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `vendor/bin/sail artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>

=== environment override (manual, not Boost-managed) ===

# Execution Environment

- The Claude Code agent runs **inside** the Sail Docker container (hostname-based, working dir `/var/www/html`). PHP, Composer, Artisan, and Node are all directly available on `PATH`.
- DO NOT prefix commands with `vendor/bin/sail`. Run them directly instead. This OVERRIDES the `=== sail rules ===` section above.
    - Artisan: `php artisan migrate`
    - Tests: `php artisan test --compact`
    - Composer: `composer install`
    - Node: `npm run dev`
    - Pint: `vendor/bin/pint --dirty --format agent`

=== project: Brothers Bets — World Cup prediction platform (manual, not Boost-managed) ===

# Project Overview

A football (soccer) prediction platform. A **Tournament** is the shared real-world competition (its phases/groups/fixtures and official results); a **Pool** is a playable contest (a "bolão") over a tournament, owning its own scoring strategy/config, prediction lock, `source` (the person/group that created it, e.g. "Wagner Figueiredo"), and an optional paid buy-in. Many pools can run over one tournament, differing in scoring and in whether the whole knockout bracket is predicted **upfront** or **each round as it opens**. A user **joins** a pool (creating their **Entry**) — joining is the prerequisite for predicting — then predicts fixture outcomes; an upfront pool's group-stage scores are resolved into a full knockout bracket, while a phased pool predicts each real knockout match-up as its participants become known. A pool may charge an `entry_price`: buy-ins accumulate a prize pot split per place after a house cut (`app/Services/Pools/PrizePot.php`; payment itself is external). Stack: Laravel 13 + Inertia v3 + React 19 (SPA, no client router), MySQL (local) / SQLite (CI), Tailwind v4, Wayfinder. Platform brand "Brothers Bets" (pitch-green + gold). The seeder builds **two** pools over the "World Cup 2026" tournament: "Bolão Copa - FF&A" (`world-cup-2026-ffa`, source "Wagner Figueiredo", upfront-bracket) and "Bolão dos Brothers - Copa" (`world-cup-2026-brothers`, source "Bruno Gasparin", phased-bracket).

> Scoring lives in `app/Services/Scoring` and reads `Pool.scoring_strategy`/`scoring_config`. The per-strategy rules come from `ScoringRulesFactory` + `Strategies/` (`ScoringRules` base, `UpfrontBracketRules`, `PhasedBracketRules`). Approving an official-result batch (`ApproveScoreBatch`) is a **tournament** action that cascades to **every pool** of that tournament — re-scoring each pool's entries (`ScoreEngine`), snapshotting its leaderboard ranks (`RankSnapshotter`), re-deriving the tournament's lifecycle status, and emailing players about milestones and rank moves. `scores:fetch`/`live:advance` read results from a `ScoreProvider` (`Providers/ManualScoreProvider`|`SimulatedScoreProvider`). The leaderboard **timeline** — `Matchday`/`MatchdayCatalog`/`MatchdayLeaderboard`/`MatchdayLeaderboardView` — reconstructs how each board looked per matchday (derived, never persisted; *not* a scoring strategy).

# Common Commands

Run everything **directly** (the agent is inside the container — no `vendor/bin/sail` prefix; see Execution Environment above).

- All tests: `php artisan test --compact`
- One file: `php artisan test --compact tests/Feature/PredictionControllerTest.php`
- By name: `php artisan test --compact --filter=test_name`
- Full gate (lint + format + types + tests): `composer ci:check` — or `composer test` (= `config:clear` → `@lint:check` (Pint `--parallel --test`) → `artisan test`). **CI runs two workflows: `tests.yml` runs `./vendor/bin/phpunit` directly and does NOT run Pint; `lint.yml` runs Pint + Prettier + ESLint.** So a change can pass `tests.yml` while failing `composer test`/`lint.yml`.
- PHP lint/format: `vendor/bin/pint --dirty --format agent` (never `--test`)
- Frontend: `npm run types:check` (`tsc --noEmit`) · `npm run lint:check` · `npm run format:check` · `npm run build` · `npm run dev`
- Everything for local dev (server + queue + Pail logs + Vite): `composer dev`
- Seed full tournament: `php artisan db:seed` (UserSeeder + WorldCup2026Seeder + a `test@example.com` user) or just `php artisan db:seed --class=WorldCup2026Seeder` (idempotent, transactional)
- Import flag SVGs into `public/flags/`: `php artisan flags:import` (needs teams seeded first + network access to flagcdn.com)
- Regenerate Wayfinder route/action helpers: `php artisan wayfinder:generate` (also runs automatically on `npm run build` via the `wayfinder()` Vite plugin)
- Other custom artisan commands (`app/Console/Commands`): `scores:fetch [tournament?]` (propose results for ended fixtures via the Manual or Simulated score provider, into a draft `ScoreBatch`), `live:advance [tournament?]` (advance the live feed — take due fixtures live off a `ScoreProvider`, tick their live scores, close ended boards; **never writes an official result**), `tournament:simulate` (end-to-end demo data — play out a tournament; can leave the `--me` user empty in one pool to exercise cross-pool import), `dev:clock` (move a dev "now" forward), `user:create` / `user:pre-register` / `user:set-email`.
- Scoring config (`config/scoring.php`): `prediction_lock_buffer_minutes` (default 60), `match_duration_minutes` (default 150 — how long after kickoff a fixture counts as finished), `simulated_provider` (default false — when true, `scores:fetch` invents plausible results locally instead of waiting on an admin).

Tests run against a **separate `testing` database**, not `laravel` (`phpunit.xml` sets `DB_DATABASE=testing` but leaves `DB_CONNECTION` unset → MySQL locally, SQLite in CI via `.env.example`). `RefreshDatabase` rebuilds the schema per test, including Unit tests under `tests/Unit/Services/Predictions`. Admin email(s) come from the `ADMIN_EMAILS` env var.

# Architecture

## Domain model (`app/Models`, `app/Enums`)

Two sub-trees meet at `Fixture`: the **structure** (`Tournament → Phase`/`Group → Fixture → Team`, authored by seeders/admins) and the **predictions** (`User → Entry → Group`/`KnockoutPrediction → Fixture`, authored by players, scoped to a `Pool`).

- `Tournament` owns the competition structure + a lifecycle `status` that is **auto-derived** from its fixtures: `Tournament::deriveStatus()` maps fixture states to `Upcoming→InProgress→Completed`, and `syncStatus()` persists a change (idempotent and **bidirectional** — rescheduling the only live fixture back to the future reverts to Upcoming) and fires `TournamentStatusChanged`. There is **no manual transition endpoint or guarded state machine**; `syncStatus()` is run after a result batch is approved. `Pool` belongs to a `Tournament` and owns its `name` (the primary, verbatim identity — see i18n), `source` (the secondary differentiator), `scoring_strategy`/`scoring_config`/`predictions_lock_at`/`accent` (`PoolAccent`) + the paid-join fields (`entry_price`/`currency`/`house_fee_percentage`/`prize_structure`) + its `entries()`. Both carry a `slug`; **pool routes bind `{pool:slug}`** (a `Pool`, reaching structure via `$pool->tournament`) while the **Live Center and `manage.*` routes bind `{tournament:slug}`** — there is no `getRouteKeyName()` override anywhere. `Pool::acceptsPredictions()` (via `Pool::predictionsLockAt()`) **derives** the group-stage lock from the tournament's first group kickoff minus a buffer (`config('scoring.prediction_lock_buffer_minutes')`, default 60), unless an explicit `predictions_lock_at` override is set (which wins verbatim, ignoring the buffer; null override + no scheduled kickoff = closed). It is **deliberately independent of the tournament's `status`**. Phased-bracket pools instead lock each knockout round the same buffer before that round's first kickoff (`PredictionWindowResolver`); `Pool::usesPhasedPredictionWindows()`/`predictsKnockoutBracket()` key off `scoring_strategy`. Joining is tracked by the existence of an `Entry`; separately, `Pool::briefedUsers()` (the `pool_briefing_views` pivot) records who has opened the one-time "how it works" briefing. The seeded pools set no lock override, so they derive; the seeded tournament keeps the slug `world-cup-2026`, its pools `world-cup-2026-ffa` and `world-cup-2026-brothers`.
- `Fixture` is the central match. Group fixtures carry `home_team_id`/`away_team_id`/`group_id`. The knockout bracket is wired two ways on the fixture row: **placeholder labels** (`home/away_placeholder_label`, e.g. "Winner Group A", "3rd Group A/B/C/D") for Round-of-32 slots fed from group standings, and **feeder FKs** (`home/away_feeder_fixture_id` + `home/away_feeder_outcome` = `FeederOutcome::Winner|Loser`) for R16+ ("the winner/loser of match N"). `FeederOutcome::Loser` wires the third-place play-off.
- `GroupPrediction` stores only a scoreline (`home_goals`/`away_goals`, both required). `KnockoutPrediction` is richer because participants are unknown at prediction time: `predicted_home/away_team_id`, nullable goals, and the load-bearing `advancing_team_id`.
- `Entry` belongs to a `Pool` and is unique per `(pool_id, user_id)`; each prediction is unique per `(entry_id, fixture_id)`. **`User` has no `entries()` relation — traverse from the `Entry` side.** Structure is reached via `$entry->pool->tournament` (e.g. in `BracketResolver`). `User::isAdmin()` is config-driven (`config('admin.emails')`), not a DB column/role.
- **Results, leaderboard & tie models:** a `ScoreProposal` is one admin-proposed (or auto-fetched) fixture result; proposals are grouped into a `ScoreBatch` (`BatchStatus`/`ProposalStatus`) that an admin approves to make results official. `LeaderboardStanding` holds one row per `(entry, LeaderboardCategory)` carrying `rank`/`previous_rank` for movement. Unresolvable ties get a manual ordering: `EntryGroupOrdering` (a player ordering a tie inside their own predicted standings, scoped by `OrderingScope`) and `TournamentGroupOrdering` (an admin ordering a tie in the official results).
- **Live scoreboard:** `FixtureLiveState` holds the in-progress score for one fixture (`LiveStatus` = `Scheduled`/`Live`/`Ended`), kept **entirely separate** from the official result columns — live data never affects the official leaderboard until a `ScoreBatch` is approved. Briefing-seen is recorded via an **atomic `insertOrIgnore`** on the `pool_briefing_views` pivot (not read-then-write) so a concurrent first-time view can't poison a Postgres transaction.
- Enums (all string-backed, `app/Enums`): `TournamentStatus` (lifecycle-only `Upcoming→InProgress→Completed`, **auto-derived** from fixtures — no guarded transitions), `ScoringStrategy` (exactly two cases — `UpfrontBracket`/`PhasedBracket`; the `Matchday*` services are a leaderboard *timeline*, not a third strategy), `PhaseType` (`Group`/`Knockout` — the discriminator picking which prediction model applies), `PhaseKey` (finer stage label), `PredictionWindowStatus` (per-phase window state for phased pools), `FeederOutcome`, `FixtureStatus`, `BatchStatus`/`ProposalStatus` (results pipeline), `LeaderboardCategory`, `OrderingScope` (`WithinGroup`/`Thirds`), `LiveStatus` (`Scheduled`/`Live`/`Ended` — live scoreboard lifecycle), `PoolAccent` (UI accent), `Sport`. There is **no `EntryStatus`**.

## Prediction / bracket engine (`app/Services/Predictions`)

`BracketResolver::resolve(Entry)` is the authoritative engine that turns group-stage score predictions into a resolved knockout bracket:

1. Builds a `GroupStandings` per group from predicted scores (`TeamStanding` accumulates W/D/L/GF/GA). Ranking implements the FIFA 2026 tie-break order made **fully deterministic** — where FIFA would draw lots it falls back to the `group_team` pivot `position` (seed) or `Group.sort_order`.
2. `rankThirds()` picks the best 8 of 12 third-placed teams; `assignThirds()` delegates to `ThirdPlaceAllocation::assign()` — a hand-transcribed **495-row lookup of FIFA Annex C** (validated by `ThirdPlaceAllocationTest`) mapping the qualifying groups to the fixed R32 slots `[74,77,79,80,81,82,85,87]`.
3. Resolves each knockout slot (placeholder labels via regex, or feeder lookups) in a single phase-ordered pass so downstream feeders see upstream results, returning a `ResolvedBracket`.

`BracketResolver::persist(Entry)` writes resolved teams onto `KnockoutPrediction` rows and **loops up to 6 passes to a fixed point, clearing a user's `advancing_team_id` + scores when an upstream change makes the picked team no longer present** — i.e. editing group scores can cascade-wipe downstream knockout picks. The advancing team is always **derived server-side from the score** (`UpdateKnockoutPredictionsRequest`); the client's pick is only honored on a draw and validated against engine-resolved slots. This whole self-derived cascade applies to **upfront** pools only.

**Phased** pools don't self-derive: `PredictionWindowResolver` computes a `PredictionWindowStatus` per knockout round (opening as that round's real participants become known, closing the buffer before its first kickoff), and `OfficialBracketProjector` fills the official participants onto knockout fixtures as rounds complete, so players predict the real match-ups directly with no cascade. `KnockoutSlotResolver` resolves slot participants and the "straddling thirds" cut; manual tie resolutions flow through `ManualTieOrdering`/`DefaultTieOrdering`/`TieState`. Separately, `PredictionImporter` (`import`/`eligibleSources`/`shouldSuggest`) copies a user's **own** picks from a sibling pool of the same tournament into the destination's currently-open window(s); the wizard receives `import_sources`/`should_suggest_import`.

## HTTP & auth (`routes`, `app/Http`, `app/Actions/Auth`)

- Routes: `web.php` (public `home` + the only unauthenticated POST `login.code.send`; it `require`s the others), `onboarding.php`, `pools.php`, `manage.php`, `settings.php`, `console.php` (just `inspire`). Middleware is appended to the `web` group in `bootstrap/app.php`. `pools.php` (`auth`-gated, the app core) covers the **public Live Center** (`live.index` landing → routes to whatever tournament is live; `live.show` per-tournament), pool index/show/join (`pools.join`), briefing-seen (`pools.briefing.seen`), leaderboard, and the prediction wizard (`pools.predict.edit`/`.group`/`.knockout`/`.ordering`/`.import`). The **admin block lives in a separate `manage.php`** under the `manage` URL + `manage.` name prefix, gated by `can:manage-tournament` and **tournament-scoped** (reachable without joining a pool): `manage.index`, **Live Control** (`manage.live.index`/`.go-live`/`.score`/`.end`), score review/approval (`manage.scores.review`/`.proposal`/`.ordering`/`.approve`), and the schedule (`manage.schedule.index`/`manage.fixtures.reschedule`).
- **Passwordless login via emailed 6-digit code, layered on Fortify.** `FortifyServiceProvider` rebinds Fortify's `LoginRequest` to `LoginCodeRequest` and points `authenticateUsing()` at `VerifyLoginCode`. Codes live in the **cache** (hashed, key `login-code:{email}`, 10-min TTL, invalidated after 5 attempts) — no DB table. `LoginCodeController@store` returns the same response whether or not the email exists (anti-enumeration — don't "fix" this). **Passkeys (WebAuthn)** run alongside via `laravel/chisel` (Fortify's `passkeys` feature only — no password/registration/2FA/verification; a dedicated `passkeys` rate limiter allows 10/min).
- Predictions: `PredictionController` (`edit`/`updateGroupStage`/`updateKnockout`/`updateOrdering`/`import`); the shared `PredictionRequest` base authorises an authenticated user **who has joined** the pool (`isJoinedBy`) **and** when `acceptsPredictions()`, then resolves their existing `Entry` via `firstOrFail` (the entry is created at join time, not lazily). `edit` redirects non-members to `pools.show`.
- Admin results pipeline: `ScoreReviewController` lets an admin edit a draft batch's proposed scores, reorder tie clusters, and approve — approval runs `ApproveScoreBatch` (apply proposals → `ScoreEngine::recompute` per pool → `RankSnapshotter` → `Tournament::syncStatus` → `LeaderboardNotifier` emails). `FixtureScheduleController` reschedules a not-yet-finished fixture into one of the tournament's known venues (`Tournament::venueTimezones()`). The `manage-tournament` Gate (`AppServiceProvider`) maps to `User::isAdmin()`. `TournamentStatusChanged` is fired by `syncStatus()` but has **zero listeners** (future hook).
- Live scoring: an admin opens a fixture in **Live Control** (`LiveControlController` — `index`/`goLive`/`updateScore`/`endMatch`) and edits goals on `FixtureLiveState` during the match (`app/Services/Live`: `GoLive`, `UpdateLiveScore`, `EndLiveMatch`, `CloseLiveScoreboard`, `HasLiveMatches`). `LiveFeed` (the `live:advance` command) is the **automated** replacement — each pass takes due fixtures live, ticks scoreboards to the `ScoreProvider` scoreline, and closes ended boards; it **never writes an official result** (the final is proposed via `scores:fetch`/`FetchScores` for admin approval). The public Live Center (`LiveController` — `index`/`show`) renders it; `app/Services/Scoring/LiveProjection` computes a pool's **projected** leaderboard from current live scores ("if every live match ended now…") without persisting.
- Other controllers: `PoolController` (`index`/`show`/`join`/`markBriefingSeen`/`leaderboard`), `ManageController` (admin landing), `OnboardingController` (the wizard), and `Settings\ProfileController`/`Settings\SecurityController`/`Settings\LanguageController`.
- `HandleInertiaRequests` shares `auth.user`, `auth.isAdmin`, `timezone`, `locale` + a cached `translations` bag (see Internationalization), `sidebarOpen`, and `joinedPools` (the user's pools for the sidebar, each with a `needsAttention` dot, built query-light by `App\Services\Pools\JoinedPools`) on every request; `HandleAppearance` shares the `appearance` cookie to Blade for SSR theming. The `needsAttention`/completion UI is fed by `AttentionSummary`/`CompletionSummary`/`PredictionAttention` (`app/Services/Pools`), and `PlayerComparison` (`app/Services/Predictions`) powers the compare strip/dock.

## Frontend (`resources/js`, `resources/css/app.css`)

- Inertia v3 SPA, **React Compiler enabled** (`babel-plugin-react-compiler` in `vite.config.ts`). Pages in `resources/js/pages/` (`pools/{index,show,predict,leaderboard}`, `live/{index,show}`, `manage/{index,live,schedule,scores}`, `onboarding/wizard`, `settings/{profile,security,language,appearance}`, `auth/login`, `welcome`); `@/*` aliases `resources/js/*`.
- **Layouts are assigned centrally** in `resources/js/app.tsx` via `createInertiaApp({ layout })` keyed on page name: `welcome` and `onboarding/wizard` get **no layout** (`null`), `auth/*` → `AuthLayout`, `settings/*` → nested `[AppLayout, SettingsLayout]`, everything else (including `pools/index`) → `AppLayout` (there is no `HubLayout`). Pages pass per-page config (breadcrumbs/title) via a **static `.layout` property** on the component, not props. The sidebar switches to tournament-context nav based on a server-shared `pool` prop. `app.tsx` also calls `router.flushAll()` on every `navigate` so prefetch caches never replay a stale sidebar after a join or prediction change.
- **Frontend → backend calls go through Wayfinder generated code** in `resources/js/{routes,actions,wayfinder}` (regenerate with `wayfinder:generate`; don't hand-edit). Import named routes from `@/routes/*` (e.g. `pools.predict.edit(slug)`, returning `{url, method}` + `.url()`/`.form()`) and controller actions from `@/actions/*`. Never hardcode URLs.
- UI: `components/ui/*` are shadcn-style Radix wrappers **re-skinned to the Brothers Bets brand** (`bg-brand-gradient` pitch-green, `bg-gold-gradient`, pill `Button`s) — don't assume stock shadcn classes; `chip.tsx` is custom. Design tokens + custom utilities (`.hero` header banner, `.card-elevated`, `.shadow-glow`) live in `resources/css/app.css`'s `@theme`/`@layer components`. Fonts: Fredoka (`font-display`), Plus Jakarta Sans (body).
- Domain components: `fixtures.tsx` (date/tz formatters + group/knockout cards), `standings-table.tsx` (flags a genuine `tied` cluster with an `=` marker), `score-stepper.tsx` (emits `''` for "not predicted" — **load-bearing**: empty scores are filtered out of the auto-save payload), `flag.tsx`, `leaderboard-row.tsx`, `pool-identity.tsx` (the canonical **name-led** pool lockup — `name` headline + `source · tournament · scoring` subline — on every in-pool surface and the sidebar), `import-predictions-dialog.tsx` (cross-pool import), the compare strip/dock (player comparison + photos), tie-resolution/tie-order panels, and the rebranded `app-logo`/`app-logo-icon` (BB monogram + gold wordmark). The prediction wizard (`pages/pools/predict.tsx`) **auto-saves on a 700ms debounce** via `router.put` and uses `useRef` mirrors so the debounced flush reads current state despite the React Compiler.
- **Times are stored UTC and rendered in the user's tz** from `usePage().props.timezone` (a cookie set by `use-timezone.ts`). Never shift times client-side. `usePage().props` is globally typed via the module augmentation in `resources/js/types/global.d.ts`.

## Internationalization (i18n & language)

The app is bilingual (English + Brazilian Portuguese), default locale **`en`**. **English source strings ARE the translation keys** — a missing key renders the English verbatim, so English needs no lang files. Supported locales live in `config('app.supported_locales')` (code → autonym, e.g. `pt_BR` → `Português (Brasil)`).

- **Per-request locale** is set by `App\Http\Middleware\SetLocale` (registered in `bootstrap/app.php` **before `HandleInertiaRequests`**) via `App\Support\LocaleResolver::resolve()`: an authenticated user's explicit `users.locale` → the browser's `Accept-Language` device language (mapped to a supported locale; `pt-BR`/`pt` → `pt_BR`) → `app.fallback_locale`. `User implements HasLocalePreference` (`preferredLocale()` returns `locale`), so **queued notifications auto-localize to the recipient** (null = app default). `users.locale` is nullable = "use the device language".
- **Backend strings** use `__()` (validation, flash, notification subjects, and the enum labels in `ScoringStrategy`/`LeaderboardCategory`/`TournamentStatus`). Translations: `lang/pt_BR.json` (the UI/email/flash bag, keyed by the English source string) + namespaced `lang/pt_BR/{countries,phases,venues,brackets}.php` (domain data) + framework `validation/auth/passwords/pagination.php`. `HandleInertiaRequests` shares `locale` + a cached, mtime-keyed `translations` bag (the JSON + namespaces).
- **Frontend**: `useTranslation()` (`hooks/use-translation.ts`) reads the shared `translations` prop → `t`/`tChoice`/`tCountry`/`tPhase`/`tVenue`/`tBracket`. Domain DATA translates at **display time** (DB stays canonical English): countries by team `code`, phases by `phase_key`, venues, and knockout placeholder labels via `lib/placeholder-label.ts` (`formatPlaceholderLabel(label, tBracket)`). Dates are locale-aware via `lib/locale.ts` `getActiveLocale()` (from the server-rendered `<html lang>`; `initializeLocale()` in `app.tsx`). Static `.layout` titles/breadcrumbs are English keys translated in their render-time consumers (`breadcrumbs.tsx`, `auth-split-layout.tsx`) — **never call `t()` at module load**.
- **NEVER translate** the brand "Brothers Bets", a pool's `name`, or its `source` — all are verbatim user data (a pool name like "Bolão Copa - FF&A" is shown as-is in every locale). A pool reads **name-first**: the (verbatim) `name` is the headline, with `{source} · {translated tournament name}` as secondary context (the shared `BuildsPoolIdentity` payload carries `tournament_name`; `PoolIdentity` builds the subline). Emails/subjects lead with the `name` too (e.g. "You're top of :pool"), with the source as a quiet "by :source" tag — no possessive. Money is already pt-BR (`lib/money.ts`).
- **Changing language**: Settings → Language (`Settings\LanguageController`, routes `language.edit`/`.update`, `pages/settings/language.tsx` + `language-selector.tsx`; the picker writes `users.locale`, "Device language" = null, then full-reloads). `user:create`/`user:pre-register` take an optional `--locale` (shared `App\Concerns\ResolvesLocaleOption`) so a pre-registered user's login-code email lands in their language. Notifications must resolve localized text **inside `toMail()`** (e.g. round name from `phase_key`, board label from the `LeaderboardCategory` enum) — not pre-compute it in a service.
- **Tests run in English** (the env default `en`; assertions use the English source strings). pt-BR behaviour is covered in `tests/Feature/I18n/` (`LocalizationTest`, `LocaleResolutionTest`) + `tests/Feature/Settings/LanguageUpdateTest`. **Adding a UI string** = wrap it (`t()` frontend / `__()` backend) and add the English→pt-BR pair to `lang/pt_BR.json`. **Gotcha:** the suite asserts English copy, so running with `APP_LOCALE=pt_BR` breaks locale-sensitive tests — keep the test env at `en`.

## Mobile UI conventions (`resources/js`, `resources/css/app.css`)

Mobile-first: desktop keeps a left sidebar, mobile does not. These are responsive **shared** pieces — change the shared component, don't add per-page mobile variants:

- **Tab/filter strips → `SegmentedTabs`** (`components/ui/segmented-tabs.tsx`): equal-width when items fit, single-line scroll + `.edge-fade-x` when they don't. Don't hand-roll `overflow-x-auto` pill rows.
- **Modals are already responsive**: the shared `DialogContent` (`components/ui/dialog.tsx`) is a bottom sheet that slides up below `sm`, a centred modal at `sm+` — every `<Dialog>` is a mobile sheet for free. Standalone bottom sheets reuse `components/ui/sheet.tsx` `side="bottom"` (pool switcher; fixtures Filters sheet).
- **Mobile nav (no sidebar)**: `mobile-top-nav.tsx` (floating pool switcher + avatar) + `pool-tab-bar.tsx` (floating bottom pill), both `md:hidden`, mounted in `layouts/app/app-sidebar-layout.tsx`; `app-sidebar-header.tsx` is desktop-only (`hidden md:flex`). `manage-tab-bar.tsx` mirrors `pool-tab-bar.tsx` for the three admin screens (token `--manage-tab-bar-h`/`.has-manage-tab-bar`), and `nav-manage.tsx` mirrors `nav-main.tsx` for the sidebar. Live pages use `live-badge.tsx`/`countdown-band.tsx`.
- **Offset tokens** in `app.css`: `--top-nav-h`/`--pool-tab-bar-h`, **gated to `@media (max-width:767px)`** so desktop = `0` (drop the gate → phantom desktop padding). Reserve space with `pt-[var(--top-nav-h)]`/`pb-[var(--pool-tab-bar-h)]` on `AppContent`; fixed/sticky bottom elements clear the floating bar via `bottom-[var(--pool-tab-bar-h)]`. iOS safe area: `viewport-fit=cover` in `app.blade.php` + `env(safe-area-inset-*)`.
- **Responsive table/list**: full table `hidden sm:block` + compact mobile list `sm:hidden` with tap-to-expand (`standings-table.tsx`, the `PrizePanel` 3-up grid, `pools/index` rows).
- **Breakpoints**: prefer CSS `sm:`/`md:`/`lg:` for show-hide (SSR-safe); use `useIsMobile()` (`hooks/use-mobile.tsx`, 768px) only when JS behaviour must differ. `cn` is `tailwind-merge`, so a responsive override (`hidden sm:inline-flex`) cleanly beats a base class.
- **Verifying frontend/mobile changes** — there is **no JS test framework** (no Vitest/Jest/Playwright): run `npm run build` (also runs the React Compiler + Tailwind), `npm run types:check`, `npm run lint`/`format` (`:check` to verify), and assert props via PHPUnit `AssertableInertia`. `tests/Feature/Pools/PoolNavContextTest.php` pins the `pool`-prop contract the bottom nav relies on.

## Seeding & tests (`database`, `tests`)

- `WorldCup2026Seeder` is the heart of the data model — idempotent and transactional, it builds the tournament, **two pools** over it (`Bolão Copa - FF&A` upfront-bracket, `Bolão dos Brothers - Copa` phased-bracket — both source-tagged), 7 phases, 12 groups, 48 teams (with pivot positions), 72 group fixtures, and 32 knockout fixtures (two-pass: create slots, then wire feeders) from class-constant schedules. **Schedule times are Eastern Time and converted to UTC** in `kickoff()`. `scoring_config` is duplicated verbatim in `PoolFactory` — keep them in sync.
- `TeamFactory` codes deliberately contain a digit (e.g. `A7Q`) so factory codes never collide with the seeder's real all-letter ISO codes on the unique `code` column. Factories also cover the results/leaderboard models (`ScoreBatchFactory`, `ScoreProposalFactory`, `LeaderboardStandingFactory`).
- PHPUnit only (no Pest). Two concerns in `tests/Concerns`: the **`InteractsWithPredictions` trait** is the canonical way to build predictions — `seedOrderScores()` (better-seeded team wins 1-0), `predictAllGroups()`, `advanceAllHome()` (fills the whole bracket by repeatedly winning home + re-running `persist()`); **`InteractsWithOfficialResults`** drives the results pipeline (propose and approve official scores so leaderboards/scoring can be asserted). Prediction and scoring tests `seed(WorldCup2026Seeder::class)` in `setUp()` and assert via `AssertableInertia`.
