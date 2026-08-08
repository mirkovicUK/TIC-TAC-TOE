# Implementation Plan: Remote Tic-Tac-Toe

## Overview

The order of this list is the point of it. The brief allows "no more than a few hours" and demands three artefacts: a public repository, a hosted running instance, and documentation. The sequencing below is chosen so that:

1. **Risk discovery is first.** The three verify-on-first-run items recorded in the design (Larastan against Laravel 13, Eris on PHP 8.5, the enumeration node-count convention) are settled in tasks 1.2 and 3.6, where a fallback costs minutes.
2. **The domain layer precedes anything needing a database.** It has no framework dependencies, it is what a reviewer reads first, and the exhaustive walk is the strongest evidence in the suite. Building it early also *measures* the enumeration runtime early, which the design flags as the most likely thing to overrun the CI budget.
3. **Task 8 is the point at which the deliverable becomes viable.** Everything before it enables the submission; everything after it improves the submission. The four behaviours the brief names — create, join, play, end-of-game signalling, and a subsequent game — are all complete by task 7.
4. **The TLS certificate is provisioned out of code order**, in task 2, because it is a waiting-period dependency rather than deployment work (ADR-009).

Language and stack are fixed by the design: PHP 8.5 / Laravel 13 on the server, TypeScript + React 19 via Inertia v2 on the client, Pest 4 and Vitest for tests. No language question arises.

---

## Tasks

- [x] 1. Toolchain resolution, risk discovery, and repository skeleton
  - [x] 1.1 Scaffold the application and make the repository real
    - Create a fresh Laravel 13 application at the repository root, keeping the existing `the-skills-network/` and `.kiro/` directories
    - Install the Inertia v2 + React 19 + TypeScript starter scaffolding (`resources/js/pages`, `resources/js/components`, Vite config, `app.tsx` root)
    - Confirm the runtime is PHP 8.5 and that `php artisan --version` reports Laravel 13
    - Initial commit; create the public remote and push
    - _Requirements: 12.5_

  - [x] 1.2 Resolve the dev toolchain and settle the two dependency risks now
    - `composer require --dev` Pest 4, Larastan, Eris (`giorgiosironi/eris`), Pint
    - **Verify Larastan resolves against Laravel 13.** If it does not, drop it and configure plain PHPStan at level `max` over `App\Domain\TicTacToe` only (see Notes, item 1)
    - **Verify Eris resolves and runs on PHP 8.5** with a one-line throwaway property. If it does not, record the Pest-dataset fallback (see Notes, item 2)
    - `npm install` and confirm `npm run build` succeeds; install Playwright's chromium browser for the single browser test
    - **The `PreventRequestForgery` question is settled — do not repeat this investigation.** It was carried out by reading `vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php`, and outcome 3 was taken. The findings: `handle()` proceeds when any of these holds, in order — read verb, running unit tests, route in the except array, `hasValidOrigin()` true, `tokensMatch()` true — and otherwise throws `TokenMismatchException`; `hasValidOrigin()` returns true unconditionally on `Sec-Fetch-Site: same-origin`, with no flag guarding it; `$allowSameSite` defaults to `false` and governs only the broader `same-site` value; `$originOnly` defaults to `false`, and enabling it would make non-same-origin requests throw instead of falling through to the token check and would suppress the `XSRF-TOKEN` cookie
    - **Requirements 10.9 and 10.10 were amended** to describe origin verification with token verification as the fallback, and the design updated to match. The rejected alternative was a subclass overriding `hasValidOrigin()` to force the token path on every request. The correction is recorded in `docs/ai-direction.md`
    - **No application configuration follows from this** — the framework defaults already satisfy the amended criteria — so tasks 9.1 and 9.5 are correspondingly reduced
    - Record the outcome of the two remaining checks in the AI-direction record started in 1.3, alongside the forgery outcome already recorded there, so the reader knows which strategy the suite uses
    - _Requirements: 10.9, 12.3_

  - [x] 1.3 Commit the spec documents and open the AI-direction record
    - Commit `.kiro/specs/remote-tic-tac-toe/requirements.md`, `design.md` and this `tasks.md` to the repository
    - Have `docs/ai-direction.md` record how the AI tooling was directed — the spec documents it produced and the corrections made to its output — and append to it as work proceeds. **The file already exists and is committed**, holding the corrections grouped by kind of failure, the decisions recorded rather than corrected, and the verification items settled on first run, so this step is satisfied by extending that file rather than writing one from scratch
    - _Requirements: 12.5, 12.6_

  - [x] 1.4 Configure the SQLite connection and the session store
    - In `config/database.php` on the `sqlite` connection set `foreign_key_constraints => true` (issues `PRAGMA foreign_keys = ON` per connection — SQLite disables them per connection by default), `journal_mode => WAL`, `synchronous => NORMAL`, `busy_timeout => 5000`
    - Set the session driver to `database` in the same SQLite file, and `SESSION_LIFETIME` to 30 days so a Player_Session outlives the 7-day retention window
    - _Requirements: 9.1, 13.2_
    - _Design: ADR-004_

- [x] 2. Provision the hosted hostname and TLS certificate — do this at least one week before submission
  - Out of code order on purpose. ADR-009 records that Let's Encrypt rate-limits per *registered* domain and `sslip.io` is one registered domain shared by all its users, so issuance can be refused because of strangers' usage. A refusal with a week of slack is recoverable; a refusal on submission day is not. Nothing in group 3 onwards depends on this.

  - [x] 2.1 Stand up the instance and fix the hostname
    - Launch the EC2 instance, install Docker and the Compose plugin, allocate an Elastic IP and associate it
    - Security group exposes only 80 and 443 inbound. **Port 22 is not opened, and no key pair is created**
    - Attach an instance profile carrying the `AmazonSSMManagedInstanceCore` managed policy, and confirm the instance appears as a managed node in Systems Manager
    - **Obtain a shell via Session Manager and verify it works before going further.** Every later `docker compose` command in task 13.3 and the `games:sweep` crontab entry runs through it, so a broken session path blocks the deployment
    - Do **not** publish port 9000; this is the condition the `*` trusted proxy range in task 9.2 depends on
    - Why: it removes the most heavily scanned port on the internet from the attack surface, and it is a deliberate security decision rather than the console default. Worth a line in the README (task 15.1)
    - Record `<elastic-ip>.sslip.io` as the hosted URL and confirm it resolves to the Elastic IP
    - _Requirements: 12.4_
    - _Design: ADR-009_

  - [x] 2.2 Obtain the certificate now, against a placeholder site
    - Create a `deploy/` directory in the repository holding the placeholder `Caddyfile` (the hostname plus a `respond` directive is enough for ACME to complete) and a minimal `compose.placeholder.yaml`. Committing them means task 13.2's production Caddyfile evolves from a known-working file rather than being written from scratch, and the volume is declared in one place
    - **First, create the volume out of band: `docker volume create caddy-data`.** Creating it by hand rather than letting Compose create it is what keeps it outside any Compose project's namespace
    - Run a minimal Caddy container serving a static placeholder for `<elastic-ip>.sslip.io` and let it complete an ACME issuance; confirm HTTPS loads without an interstitial
    - **`compose.placeholder.yaml` must declare `caddy-data` as an *external* volume with that fixed name (`external: true`, `name: caddy-data`) and mount it at `/data`.** Caddy stores the issued certificate, its private key and the ACME account state there
    - **Naming the volume `caddy-data` in both files is not enough.** Compose namespaces volumes by project name, which defaults to the invoking directory, so a plain `caddy-data` declaration materialises as `<project>_caddy-data`. The placeholder runs from `deploy/` and the real stack from the repository root, so the two would resolve to `deploy_caddy-data` and `<repo>_caddy-data` — two distinct volumes, and a mitigation that looks correct while doing nothing. `external: true` is what pins both declarations to the one pre-created volume
    - **Task 13.2's `web` service must mount that same external `caddy-data` volume at `/data`.** This is what makes mitigation 2 of ADR-009 real rather than apparent: without the shared volume the later `docker compose up` brings up a `web` service with empty storage and performs a *second* issuance against the shared `sslip.io` rate-limit bucket, on submission day, at the one moment a refusal cannot be recovered from — and the week of slack is spent for nothing
    - After issuance succeeds, verify the certificate is in the volume — `docker volume inspect`, or list `/data/caddy/certificates` from a throwaway container — so the carry-across is confirmed rather than assumed. **Then confirm with `docker volume ls` that exactly one volume named `caddy-data` exists, with no project prefix.** A `deploy_caddy-data` appearing in that listing is the early warning that the declaration was not external, and it is far cheaper to catch here than on submission day
    - If issuance is refused, retry against `<elastic-ip>.nip.io` — a separate registered domain and therefore a separate rate-limit bucket. One line of Caddyfile changes
    - If TLS cannot be obtained at all, serve plain HTTP with `SESSION_SECURE_COOKIE=false`. This breaks no criterion: Requirement 10.11 conditions the Secure attribute on the Application being served over HTTPS
    - Record which hostname and which scheme were obtained; task 13.2 and the README consume that decision
    - **ACME issuance SUCCEEDED and the certificate is in the `caddy-data` volume, as planned.** Confirmed by the operator; the hostname is `18-175-88-107.sslip.io` (recorded in the untracked `deploy/.provisioned.env`) and the scheme is **HTTPS**. Two consequences follow and neither should be re-derived later: task 13.2 mounts that same external volume so the certificate is reused rather than re-requested against the shared `sslip.io` rate-limit bucket, and it sets `SESSION_SECURE_COOKIE=true` in the hosted environment — which is what makes Requirement 10.11's `Secure` attribute apply rather than merely be conditioned away. Task 15.1's README states HTTPS and needs no fallback account of a plain-HTTP exposure
    - The scheme was not written down at the time and had to be asked for, which is the small process lesson worth keeping: `https://` on that host refuses on 443 today because step 8 brought the placeholder stack down, so the outcome was unrecoverable from the repository alone. Tick the `docs/aws-infra.md` checklist, or record the scheme beside the hostname, when a step's outcome is consumed by three later tasks
    - _Requirements: 10.11, 12.4_
    - _Design: ADR-009_

- [x] 3. The domain layer: `App\Domain\TicTacToe` (independent of group 4)
  - [x] 3.1 Write the seven domain types
    - `Mark` (with `forSequenceIndex`, `opponent`), `Move`, `MoveList` (`empty`, `fromCellIndices`, `fromMoves`, `append`, `count`, `cellIndices`, iterable), `WinningLine` (eight cases, `cells()`, `all()`), `Board`, `Outcome`, `Analysis`, `InvalidMoveList`
    - `Move` carries plain integers and `MoveList::fromMoves()` accepts ill-formed input verbatim — well-formedness is checked by the engine, not asserted by the type system, because Requirements 11.5 and 14.8 require the engine to be *handed* bad lists
    - No `Illuminate\*`, no models, no session, no request anywhere in the namespace
    - _Requirements: 4.1, 11.1, 11.4, 11.9_

  - [x] 3.2 Implement `RulesEngine::analyse` as a single pass with five ordered guards
    - One entry point returning `Analysis|InvalidMoveList`; guards in the order length, move-after-a-win, `sequenceIndex !== position`, cell range, repeated cell — each returning the same uniform `InvalidMoveList::Error` and deriving nothing
    - `completedLinesFor` returns **every** line the mark now occupies, not the first; the double-line position `X0 O1 X2 O3 X6 O5 X8 O7 X4` is reachable in legal play
    - The win check sits at the *top* of the iteration, so a list whose last move completes a line is well formed and a list with any move after it is not
    - `markToMove` is parity of the list length and is defined in terminal states too (Req 4.1 is unconditional)
    - Map `Outcome` to `App\Games\GameState` outside the domain; `waiting_for_opponent` is not a domain concept
    - _Requirements: 4.1, 6.2, 11.1, 11.2, 11.3, 11.5, 11.7, 11.8_
    - _Properties: 1, 2, 3, 4, 5_

  - [x] 3.3 Write the independent win oracle `tests/Unit/Domain/Support/LineOracle.php`
    - A deliberately naive, test-only implementation that checks the eight lines against a plain board array and returns terminality plus **all** lines held by a mark
    - **The engine must never be its own judge.** If the enumeration asked the engine when to stop recursing, Properties 3 and 4 would be tautologies
    - Keep it small enough to review by eye — that is the entire basis for trusting it. Hoist its line table into a static array (mitigation 1 of the runtime budget)
    - _Requirements: 14.2_

  - [x] 3.4 Write `RulesEngineTest` unit examples
    - Mark parity over sequence indices 0..8 (Req 11.4 is definitional under this design and is covered here rather than by a property)
    - Empty list, first move, each of the eight winning lines, the nine-move draw, and the double-winning-line position pinned explicitly so a reviewer sees it without reading the enumeration
    - Extends a plain PHPUnit `TestCase`; no framework boot, no database, no session, no HTTP
    - _Requirements: 11.4, 14.1_

  - [x] 3.5 Write `IllFormedMoveListTest` over the five violation classes
    - **Property 5: Ill-formed Move_Lists are rejected uniformly**
    - Repeated Cell_Index, Cell_Index outside 0..8, Sequence_Index gap, length above nine, a Move following a Move that completes a Winning_Line — each asserted to return exactly `InvalidMoveList::Error` with nothing derived
    - Use Eris for the unbounded shapes if it resolved; otherwise Pest datasets over the hand-picked sample described in Notes, item 2
    - **Validates: Requirements 11.5, 14.8**

  - [x] 3.6 Write `EnumerationTest` — the exhaustive walk
    - **Properties 1, 2, 3, 4** checked at every node of the reachable game tree, depth-first from the empty Move_List, with the oracle from 3.3 deciding terminality and the winning-line set
    - Assert `terminals === 255_168`. **This count carries no convention ambiguity** — a mismatch means the engine and the oracle disagree with the accepted combinatorial result. Stop and debug; do not adjust the expectation
    - Assert `nodes === 549_946`. **The convention is settled: the root counts.** Increment the node counter on entry to each node, the empty Move_List included; 549,946 is the count under that convention, established by a full walk. A first run reporting 549,945 means the root was not counted — a harness bug with a known fix, not a rules defect and not a convention left to the implementer. Fix the root accounting and leave the engine alone; do not adjust the expectation. Stated this plainly so that a correct 549,946 is not mistaken for a discrepancy worth hunting
    - The walk has been measured: engine plus independent oracle, plain PHP, recursive closure, no optimisation, approximately 5 s on the development machine (PHP 8.5.9, amd64). That was a throwaway verification harness rather than the committed Pest test, so the committed figure may differ somewhat — the point is that it sits an order of magnitude inside the 60 s budget, not that it is exactly 5 s. The mitigations stay on record as contingency, in the same order should an overrun appear: static line table (done in 3.3), carry a plain board alongside the Move_List through the recursion, split the walk into its own CI job, then accept the longer job. Naming them in advance is what turned the runtime question into a short check rather than a mid-build surprise
    - **Sampling a subset of the tree is not a mitigation and is not acceptable** — Requirement 14.2 requires exhaustive enumeration, and the two counts are what make the walk a check against external ground truth
    - **Validates: Requirements 14.2, 11.2, 11.3, 11.7, 11.8, 6.1, 6.2, 6.3, 6.4**

  - [x] 3.7 Write `ArchitectureTest`
    - **Property 6: The domain layer is pure**
    - Assert `App\Domain\TicTacToe` references no `Illuminate\*`, no `App\Models\*` and no `App\Http\*`, and that the domain unit tests extend a plain PHPUnit `TestCase` rather than booting the framework
    - **Validates: Requirements 11.1, 11.9, 14.1**

- [x] 4. Persistence schema and models (independent of group 3)
  - [x] 4.1 Migration for `games`
    - Columns per the design: `id` (UUIDv7 text primary key), `join_code`, `state`, `winning_mark`, `version_counter` default 0, `x_token_hash`, `o_token_hash`, `rematch_of_game_id` (self reference, `ON DELETE RESTRICT`), timestamps, `last_activity_at`
    - The seven CHECKs exactly as listed, including `join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL` and the one-directional `state <> 'waiting_for_opponent' OR o_token_hash IS NULL`
    - **Do NOT add a CHECK requiring `x_token_hash IS NOT NULL` on a rematch.** A rematch is inserted with both token slots NULL and tokens are minted per request (ADR-010); the mark swap means the first requester may fill `o_token_hash` while `x_token_hash` stays NULL. Such a constraint was present in an earlier draft and is recorded as removed
    - Unique indexes on `join_code` and on `rematch_of_game_id`; index on `(state, last_activity_at)` for the sweep
    - `id` derives from no database sequence
    - _Requirements: 1.2, 1.4, 6.1, 6.2, 7.4, 7.8, 8.3, 13.1, 13.2_

  - [x] 4.2 Migration for `moves`
    - `id`, `game_id` (`ON DELETE CASCADE`), `cell_index`, `sequence_index`, `created_at`
    - **No `mark` column and no `mark` CHECK.** Mark is the parity of Sequence_Index; a stored mark could only agree with the unique index or corrupt it
    - `CHECK (cell_index BETWEEN 0 AND 8)`, `CHECK (sequence_index BETWEEN 0 AND 8)`, unique `(game_id, sequence_index)`, unique `(game_id, cell_index)` — the two indexes give Requirements 5.1, 5.2 as persisted invariants and cap the Move_List at nine rows by pigeonhole
    - Append-only: no `updated_at`, no update path
    - _Requirements: 5.1, 5.2, 5.6, 11.4_
    - _Properties: 10_

  - [x] 4.3 Migration for `expiry_records`
    - `game_id` primary key, `deleted_at`, index on `deleted_at`. No Move_List, no Join_Code, no Player_Token, no foreign key
    - _Requirements: 13.3, 13.4_

  - [x] 4.4 Models and the observed-state value object
    - `Game`, `Move`, `ExpiryRecord` Eloquent models; `App\Games\GameState` enum with the `Outcome` → `GameState` mapping from the design
    - `GameSnapshot`: the game row, its `MoveList` and its `Analysis` as observed by one request — the type that makes task 6.1's purity invariant expressible
    - _Requirements: 6.1, 9.1_

  - [x] 4.5 Write a schema-constraint test
    - Assert both unique indexes reject a duplicate, that a tenth move is impossible, and that contiguity from zero is *not* persisted (rows 0,1,2,4,5 are accepted by the schema) so the application-delivered half of Property 10 is visibly distinguished from the persisted half
    - **Not optional, and not to be re-marked optional.** This is the only assertion of Requirement 5.6 anywhere in the plan — the concurrency halves (5.8, 6.8) touch 5.1 and 5.2 behaviourally through the conflict outcome and 6.6 covers 4.2, but nothing else asserts the nine-Move cap — and Requirement 14 mandates no test for it, so skipping it leaves the criterion with no coverage at all. It is also the only place Property 10's persisted-versus-application-delivered split is demonstrated, at roughly fifteen lines
    - **Property 10: The persisted Move_List is always well formed**
    - **Validates: Requirements 5.1, 5.2, 5.6**

- [x] 5. Create a Game and join a Game
  - [x] 5.1 Implement `PlayerTokens`
    - 32 bytes from `random_bytes()` rendered as hex (256 bits, above the 128-bit floor); `issue()` stores `hash('sha256', $raw)` in the game row's mark slot and puts the raw value in `session('player_tokens.'.$game->id)`
    - `resolve()` compares with `hash_equals()` against the two slots of *that game's* row, so a token minted for another game cannot match — the binding is enforced by storage location, not by a claim inside the token
    - No token value is ever rendered into HTML, a prop, or a JSON body
    - _Requirements: 3.1, 3.2, 3.8, 8.7_
    - _Properties: 8_

  - [x] 5.2 Implement `CreateGame`
    - Insert with `state = 'waiting_for_opponent'`, empty Move_List, `version_counter = 0`, UUIDv7 id, `last_activity_at` set; issue the X token via 5.1
    - Join_Code: 50 bits from `random_bytes()` rendered as ten Crockford base32 characters displayed `XXXXX-XXXXX`; uniqueness enforced by the index from 4.1
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_

  - [x] 5.3 Implement `GameResolver` and the `ResolveActingPlayer` middleware
    - Implement the seven-row visibility table verbatim, including the two rows that answer identically so a tokenless caller cannot distinguish "was a Game" from "never was"
    - Runs before any move-validity or lifecycle check and short-circuits; returns `not_authorised` identically for absent, unrecognised, and bound-elsewhere tokens; returns `game_expired` only for a session presenting a valid token, and `not_recognised` otherwise
    - _Requirements: 3.3, 3.4, 3.9, 3.10, 9.6, 13.6, 13.7, 13.8_
    - _Properties: 7_

  - [x] 5.4 Implement `JoinGame` as a conditional UPDATE
    - Single `UPDATE ... WHERE id = ? AND state = 'waiting_for_opponent' AND o_token_hash IS NULL`, setting `state`, `o_token_hash`, `version_counter = version_counter + 1`, `last_activity_at`; the affected-row count decides the outcome — 1 claims the slot, 0 is `game_full`
    - Compute the hash before the statement; if the update loses, discard the raw token so no orphan credential exists
    - Short-circuits first: a session already holding a valid token for the game gets the game back with its bound mark, no second player, state and Version_Counter unchanged (this covers the creator pasting their own code); an unmatched code is `not_recognised`
    - Normalise the submitted code (upper-case, strip hyphens, fold Crockford-ambiguous characters) before lookup; an unparseable code is `not_recognised` like any other
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7_
    - _Properties: 13_
    - _Design: ADR-006_

  - [x] 5.5 Implement `GameRepresentation`
    - The one serialiser producing `props.game` in the exact shape the design lists; `board`, `markToMove`, `winningLines` and the terminal result come from `Analysis`, `state`/`version`/`winningMark`/`rematchGameId` from the row
    - `joinCode` and `joinUrl` only while `waiting_for_opponent`; no token value anywhere; the whole prop omitted for any request that fails `GameResolver`
    - Returned in full on every state request irrespective of any version the client presents — no ETag, no 304 path
    - _Requirements: 6.3, 6.7, 7.12, 8.3, 8.4, 8.7_
    - _Properties: 11_
    - _Design: ADR-002_

  - [x] 5.6 Wire the routes, controllers and entry pages
    - `GET /`, `POST /games`, `GET /join/{join_code?}`, `POST /join`, `GET /games/{game}` with `ResolveActingPlayer`
    - `Home.tsx` (create form + join form), `Join.tsx` (prefilled from a Join_Link), `NotAPlayer.tsx` keyed by outcome for 403/404/410, `JoinCodePanel.tsx` showing the code and a copyable Join_Link while waiting
    - Denial-of-visibility outcomes render an Inertia error page carrying only the outcome; join-form rejections are a 303 back to `/join` with the outcome flashed
    - _Requirements: 1.6, 1.7, 2.2, 2.3, 3.7, 9.6_

  - [x] 5.7* Write `CreateGameTest` and `JoinGameTest`
    - Creation assigns X and a token; the join happy path flips to `active` and assigns O; the creator's own code returns X unchanged; an unmatched code and a full game are rejected
    - _Requirements: 1.1, 1.5, 2.1, 2.4, 2.5_

  - [x] 5.8 Write the join-race half of `ConcurrencyTest` — sequential, no parallelism, no sleeps
    - **Property 13**
    - Create a waiting Game, call `JoinGame` from session A then session B; A gets `O`, B gets `game_full` by the affected-row count taking the loser path naturally
    - Previously the first half of task 12.3. It depends only on `JoinGame` (5.4), so it belongs beside the code it guards rather than six waves later
    - Not optional; Requirement 14.9 mandates it
    - **Validates: Requirements 2.7, 14.9**

- [ ] 6. Play a Game: moves, and end-of-game signalling
  - [x] 6.1 Implement `SubmitMove`
    - **Delivered as `SubmitMove`, `MoveAccepted`, `MoveOutcome` and `SubmitMoveMechanismTest`.** `MoveResult` is the union `MoveAccepted|MoveOutcome` written inline: PHP has no union type aliases, and a marker interface would give a rejection a supertype it does not need and let a caller hold an unnarrowed result. Two things named below are deliberately deferred rather than done — the `move.accepted` / `move.rejected` / `game.finished` / `game.invariant_violation` log records, which belong to `GameEventLogger` in 10.2 as its sole writer, and the mapping of `CorruptMoveListException` to a 500, which is the framework default and needs no code
    - **The no-re-query invariant is a convention, not a structural guarantee — do not let a later reader believe otherwise.** `$observed->game` is a live Eloquent model on a live connection, `final readonly` pins the reference and not the model's state, and `$game->refresh()` at the top of `handle()` compiles and runs while changing no *outcome* in any single-request scenario. It is guarded in exactly two places: the query-log assertions in `SubmitMoveMechanismTest` (one INSERT and one UPDATE on the accepted path, an empty statement log on every rejection) and task 6.8. Both docblocks were corrected to say this after an earlier draft claimed the parameter made a re-read impossible
    - Signature `handle(GameSnapshot $observed, Mark $actingMark, mixed $cellIndex): MoveResult`
    - **Invariant: a pure function of `($observed, $actingMark, $cellIndex)` that SHALL NOT re-query the database for Game state.** Every guard reads `$observed` only; nothing between the first guard and the insert issues a `SELECT`. This is load-bearing twice: in production it makes Requirement 5.3's exclusivity a persisted invariant settled by the unique index rather than a checked-then-hoped-for one, and in the suite it is what makes the sequential test of Requirement 14.9 a faithful model of the concurrent case. A re-read would turn the second call into `not_your_turn`, silently retire the conflict path, and move the production guarantee into application code that cannot enforce it
    - Guards in order: `waiting_for_opponent` → `game_not_started`; terminal → `game_ended`; `markToMove !== $actingMark` → `not_your_turn` (any `mark` in the payload ignored outright); non-integer, out of range, or occupied → `invalid_move`. Turn ownership is checked before cell validity, on purpose
    - Insert with `sequence_index = count($observed->moveList)`; a unique violation on either index → `conflict`. Then, in the same transaction, re-analyse and `UPDATE games SET state, winning_mark, version_counter = version_counter + 1, last_activity_at`
    - `InvalidMoveList` returned to the service is unreachable by Requirement 11.6 and is treated as corruption: 500 plus a `game.invariant_violation` record, never mapped to `invalid_move`
    - _Requirements: 3.5, 3.6, 3.9, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 5.3, 5.4, 6.2, 6.4_
    - _Properties: 9, 12, 14_

  - [x] 6.2 Add the move route and its controller
    - `POST /games/{game}/moves` with `ResolveActingPlayer`; `cell_index` deliberately **not** validated by a Form Request, so a bad cell yields `invalid_move` rather than a 422 validation payload
    - Rejections of an authorised player's action answer 303 to the game page with the outcome flashed, so the following GET carries the outcome together with the current Game_State, Move_List and Version_Counter
    - _Requirements: 4.4, 5.4, 5.5_

  - [x] 6.3 Build the game page and board
    - `Game.tsx` rendering from props with almost no local state; `StatusBanner.tsx`, `Cell.tsx` (occupant, winning-line highlight, `aria-label`), `OutcomeMessage.tsx` with `lib/outcomes.ts`
    - **`Board.tsx`'s disabled condition is `!isYourTurn || state !== 'active'`, and both halves are required.** `markToMove` is total over Move_List length by Requirement 4.1, so it is defined in terminal states too: on a board X won at sequence 4, `markToMove` is `O` and `isYourTurn` alone would leave a finished board clickable. The server would answer `game_ended` harmlessly, but the UI would appear to accept the click and then flash an error. The single browser test stops at the win assertion and would not catch this
    - Winning cells are the flattened `winningLines`, so a double line highlights both; the turn banner shows `markToMove` plus whether it is yours, only while `active`
    - _Requirements: 1.7, 6.5, 6.6, 6.7, 9.3_
    - _Properties: 11_

  - [x] 6.4 Implement `useGamePolling`
    - 2000 ms while `waiting_for_opponent` or `active`, 5000 ms while terminal with no rematch, via Inertia v2 `usePoll` partial reloads (`only: ['game']`)
    - Stop when a rematch is discovered; `usePoll` stops on unmount, which covers navigating away. Leave `keepAlive` at its default so a hidden tab does not poll
    - _Requirements: 8.1, 8.2, 8.5, 8.6_
    - _Design: ADR-001_

  - [x] 6.5 Implement `useOpponentIdle`
    - Ticks every 5 s; returns true when the game is `active`, `isYourTurn` is false, and `now - lastMoveAt >= 60s`. Under the threshold the banner shows the waiting-for-opponent indication. No server involvement — `lastMoveAt` is already a prop
    - _Requirements: 9.3, 9.4_

  - [x] 6.6 Write `SubmitMoveTest` — through the HTTP surface
    - **No longer optional, and the reason is Requirement 3.6.** Task 6.1 shipped `SubmitMoveMechanismTest`, which covers the four guards, their order, the absence of any `SELECT`, both unique indexes mapping to `conflict`, and the corruption rollback — so the service-level mechanism is already guarded and must not simply be re-asserted here. What it cannot cover is that a `mark` supplied in the request payload is ignored outright: `SubmitMove::handle()` takes the acting Mark as a parameter and has no payload in scope, so within that file the claim is structural and unfalsifiable. Substituting `Mark::forSequenceIndex($sequenceIndex)` for `$actingMark` leaves the whole of `SubmitMoveMechanismTest` green — verified — because the turn guard makes the two provably equal. **This task is the only place in the plan where Requirement 3.6 has an assertion at all**, and skipping it would leave the criterion with no coverage anywhere, which is the same reason 4.5 and 9.5 are not optional
    - Post a Move as the Player whose turn it is not, with a payload naming their *own* Mark, and assert the outcome is `not_your_turn`; post as the Player to move with a payload naming the *opponent's* Mark, and assert the Move is recorded under the token's Mark. Both are needed: the first shows a payload Mark cannot grant a turn, the second that it cannot change the Mark recorded
    - Post `cell_index` as `'4'`, as `'banana'` and as an array, and assert `invalid_move` rather than a 422 — the assertion that task 6.2's controller hands the decoded value over uncast rather than through `->integer()` or a Form Request
    - The win transition across **all eight** Winning_Lines and the double-diagonal position, each asserting `state`, `winning_mark` and the full `winningLines` set; the nine-move draw. `SubmitMoveMechanismTest` pins one line and the draw only
    - Each rejection asserted to leave the Move_List, Game_State, winning Mark and Version_Counter untouched, and to arrive with the current state alongside the outcome (Req 5.5) — which is a claim about the redirect and therefore only makeable here
    - **Property 9: Rejected requests change nothing** and **Property 12: the Version_Counter increments exactly once per committed state-changing operation**
    - **Validates: Requirements 3.6, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 5.5, 6.2, 6.4**

  - [ ] 6.7* Write Vitest tests for the two hooks and the rendering criteria
    - `useGamePolling`: interval selection, stop on rematch discovery, stop on unmount. `useOpponentIdle`: quiet at 59 s, indicating at 61 s, and **quiet with a null `lastMoveAt` however long has elapsed** — the amended Requirement 9.4's clause, and the case that would otherwise warn the Joiner on an empty board
    - Component assertions for the join-code panel, the draw and win banners, the turn indication, and the board's disabled condition in a terminal state
    - _Requirements: 1.6, 1.7, 6.5, 6.6, 6.7, 8.1, 8.5, 8.6, 9.3, 9.4_

  - [x] 6.8 Write the move-conflict half of `ConcurrencyTest` — sequential, no parallelism, no sleeps
    - **Property 14**
    - Read *one* `GameSnapshot`, then call `SubmitMove` twice from that same snapshot with different cells. Both derive `sequence_index = n`; the first commits and the second violates the unique index
    - **Assert the Move_List went from n to n+1, not merely that the second outcome was `conflict`.** The move-count assertion is the more important of the two: "exactly one accepted" is the actual guarantee of Requirement 5.3, and it holds whichever rejection path the second call takes — so it catches a re-read creeping into `SubmitMove` or a lost unique index, which a loose outcome assertion would let through
    - **This is one of the two mechanical guards on 6.1's no-re-query purity invariant**, which is why it sits here rather than in group 12. An earlier draft of this bullet claimed it was the only one and that a re-read "would pass every other test in the suite"; that was measured while writing this task and is false. Adding `$game->refresh()` to `handle()` fails this test *and* eighteen cases in `SubmitMoveMechanismTest` — its no-`SELECT` query-log assertion and every rejection's empty-statement-log assertion — which is exactly the two guards `SubmitMove`'s own docblock names. What remains true, and is this task's reason for existing, is that 6.6's scenarios are all single-request and every one of them still passes under a re-read that returns the state the snapshot already holds. Note also that the move-count assertion does *not* catch a re-read: the list still goes n → n+1, one Move accepted and one refused, with the wrong vocabulary. The count assertion catches a lost unique index; the `conflict` assertion catches the re-read
    - Previously the second half of task 12.3. Not optional; Requirement 14.9 mandates it
    - **Validates: Requirements 5.1, 5.2, 5.3, 5.4, 5.5, 14.9**

- [x] 7. Subsequent Game (Rematch)
  - [x] 7.1 Implement `CreateRematch` and its route
    - Reject a non-terminal preceding game with `invalid_state`; a session with no token for the preceding game is already `not_authorised` from `GameResolver`
    - In a transaction: find the rematch by `rematch_of_game_id`; if absent insert one with `state = 'active'`, empty Move_List, `join_code = NULL`, `version_counter = 0`, **both token slots NULL**, and increment the *preceding* game's Version_Counter so the opponent's next poll sees it. A unique-index violation means a concurrent request won — catch it and re-read
    - **Mint the requesting session's token at request time**, bound to `$precedingMark->opponent()`; the swap is derived, never stored. Minting is not a state-changing operation for versioning and does not touch `last_activity_at` on the preceding game
    - Idempotent: any number of requests from either player converge on the one rematch, and the preceding Move_List is untouched
    - `POST /games/{game}/rematch` with `ResolveActingPlayer`
    - _Requirements: 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.9, 7.10, 7.11, 7.14, 7.15_
    - _Properties: 15_
    - _Design: ADR-010_

  - [x] 7.2 Build `RematchControl.tsx`
    - Presented to both players while the game is terminal, and reused as the "go to rematch" control once `rematchGameId` is present
    - **Both are a POST to `/games/{game}/rematch`, not a link.** A plain link would land the second player on a game for which their session holds no token, and be refused — the token is minted by the POST
    - _Requirements: 7.1, 7.13_
    - _Design: ADR-010_

  - [x] 7.3* Write `RematchTest`
    - Both players converge on one rematch in either order; marks are swapped; each session receives its token at its own request; the preceding game's Move_List survives and its Version_Counter went up by exactly one; a non-terminal game is `invalid_state`; a tokenless session is `not_authorised`
    - **Property 15: A Rematch is unique, swapped, and entered by presenting the preceding token**
    - **Validates: Requirements 7.2, 7.3, 7.4, 7.5, 7.6, 7.8, 7.9, 7.14, 7.15**

- [x] 8. MILESTONE — the deliverable is viable from here. Ensure all tests pass, ask the user if questions arise.
  - At this point all four behaviours the brief names are implemented and covered: create a game, join it from a second session, play it with each side seeing the other's move without refreshing, end-of-game signalling, and a subsequent game
  - Run the full suite and the enumeration; commit and push. If the budget ran out here, the repository plus tasks 2 and 15 would still be a coherent submission
  - **Everything below this line improves the submission rather than enabling it.** Groups 9 to 11 satisfy the operational requirements; group 12 completes the mandated verification; groups 13 to 15 produce the hosted instance and the documentation

- [x] 9. Application security and rate limiting
  - [x] 9.1 Confirm the forgery-protection defaults are in force and add no configuration
    - The amended Requirements 10.9 and 10.10 are satisfied by Laravel 13's defaults: `PreventRequestForgery` verifies origin first and falls through to token verification. Task 1.2 settled this against the vendor source; there is nothing to configure
    - Confirm neither `PreventRequestForgery::allowSameSite()` nor `PreventRequestForgery::useOriginOnly()` is called anywhere in `bootstrap/app.php` or a service provider
    - **`useOriginOnly(true)` must not be enabled.** It would make every non-same-origin request throw rather than fall through to the token check, and would suppress the `XSRF-TOKEN` cookie Inertia relies on. Someone reading the middleware later may mistake it for the stricter and therefore better option; for this application it is not
    - Add a one-line comment in `bootstrap/app.php` recording that the defaults are deliberate and pointing at the design's HTTP surface section, so the absence of configuration reads as a decision rather than an omission
    - _Requirements: 10.9, 10.10_

  - [x] 9.2 Configure `TrustProxies` to trust the `web` container
    - Trusted range `*`. Justification to record in the ADR and in the code comment: Compose does not fix its subnet unless it is declared and `TrustProxies` matches on IPs and CIDRs rather than service names, and `*` is acceptable **only** because port 9000 is not published — the one thing on the network that can reach php-fpm is the `web` container. Publishing 9000 or adding a second service able to reach `app` invalidates the reasoning
    - Without this, `join`, `create-game` and `state` all collapse into single global buckets keyed on Caddy's address; `move` is unaffected because it is keyed on the token hash
    - _Requirements: 10.6, 10.8_
    - _Design: Deployment, trusted proxy range_

  - [x] 9.3 Set the Player_Session cookie attributes
    - `HttpOnly`, `SameSite=Lax`, and `Secure` only where the Application is served over HTTPS (`SESSION_SECURE_COOKIE` follows the outcome of task 2.2). `Lax` suffices because every state change is a same-site POST and the only cross-site entry point is a Join_Link, which is a top-level GET
    - _Requirements: 10.11_

  - [x] 9.4 Define the four named rate limiters and attach them to the routes
    - `join` 20/60s per Rate_Limit_Subject, `move` 60/60s per presented token hash, `state` 120/60s per subject, `create-game` 20/60s per subject (the last is beyond the requirements and is flagged in code as deliberate)
    - Limiter keys use a hash of the session id, not the id itself, so session identifiers reach neither cache keys nor logs. Rate_Limit_Subject is the session where one exists and the IP otherwise
    - `GET /health` carries no middleware at all — no session, no CSRF, no throttle — which is a deliberate acceptance recorded in the design
    - _Requirements: 10.6, 10.7, 10.8_
    - _Properties: 20_

  - [x] 9.5 Write `MiddlewareConfigurationTest`
    - Its own file, because it tests middleware configuration rather than rate-limit behaviour
    - Set `X-Forwarded-For` and assert the resolved client address is the forwarded value rather than the test client's. **This test is coupled to the `*` trusted range from 9.2** — a feature test has no real peer, so the framework supplies a loopback address, and the header is honoured only if the trusted range includes loopback. The test and the range are one decision, not two
    - A companion forgery assertion was considered and dropped: there is no flag to read, and `handle()` calls `runningUnitTests()` before both the origin and the token checks, so the middleware short-circuits entirely in the test environment and nothing about that path is observable from a feature test. Requirement 14.3's exclusion therefore stands on its own, and the attempt is recorded in `docs/ai-direction.md`
    - Not marked optional: it remains the only mechanical guard on the `TrustProxies` configuration, which no behavioural test would notice
    - _Requirements: 10.6, 10.8_

  - [x] 9.6 Write `RateLimitTest` for the join boundary
    - **Property 20: Conforming polling is never rate limited**
    - `array` cache driver so the window is deterministic; twenty join requests asserted *not* rate limited in a loop, then one assertion that the twenty-first is; assert the rejected request changed no Game state
    - Also assert state requests issued for a full window at the rate Requirement 8 demands are never rate limited
    - **Validates: Requirements 10.6, 10.7, 10.8, 14.4**

- [x] 10. Observability
  - [x] 10.1 Implement the Health_Endpoint
    - `GET /health`, no middleware, plain JSON, one persistence query per request; success status reserved for the reachable case, `503` with `{"status":"error","persistence":"unreachable"}` otherwise; answers within 1 second either way
    - **Remove the `health: '/up'` argument from `withRouting()` in `bootstrap/app.php` as part of this task.** The scaffold registered it and the design settled on `/health`, so leaving it in ships two health routes — the framework's renders HTML, answers JSON only when asked, and reports `up`/`down` without ever querying the persistence layer. The argument has to come out rather than be worked around: `withRouting()` registers the health route *before* the `web` group, so a same-URI route in `routes/web.php` would never match and the framework's route cannot be shadowed from the route file
    - _Requirements: 10.1, 10.2_

  - [x] 10.2 Implement `GameEventLogger` and the JSON log channel
    - Monolog channel with `JsonFormatter` to `stderr`, so `docker logs` and any collector see the same lines
    - Six events, one record each: `game.created`, `game.joined`, `move.accepted`, `move.rejected`, `game.finished`, `rematch.created`, with the fields the design tabulates. Typed arguments rather than an array, so a token or a join code cannot be passed by accident
    - A Monolog processor strips any context key matching `token`, `join_code` or `secret` as a second line of defence. For a rejected move whose cell index was not an integer, log the JSON-encoded raw value, truncated
    - Call the logger from `CreateGame`, `JoinGame`, `SubmitMove` (both outcomes, plus the terminal transition) and `CreateRematch`
    - _Requirements: 10.3, 10.4, 10.5_
    - _Properties: 19_

  - [x] 10.3* Write `HealthTest` and `LoggingTest`
    - Health: reachable and unreachable branches, and that the success status is not returned for the unreachable case
    - Logging: exactly one record per event with the required fields, move records carrying mark, cell, sequence and outcome, and no issued Player_Token or Join_Code value anywhere in the output produced while exercising every action
    - Also cover the `game.invariant_violation` record of task 10.4, which is otherwise pinned by nothing: deleting the `report` hook from `bootstrap/app.php` leaves the whole suite green. Assert it separately from the six rather than folding it into their count, since it is not one of Requirement 10.3's events
    - **Property 19: Log records carry the required fields and no secrets**
    - **Validates: Requirements 10.1, 10.2, 10.3, 10.4, 10.5**

  - [x] 10.4 Emit the `game.invariant_violation` record
    - **This task exists because the record had no owner.** The design's failure table requires it on the `InvalidMoveList::Error` path — "500, log `game.invariant_violation` with the Game_Id, no state change" — and task 6.1 recorded it as deferred to "`GameEventLogger` in 10.2 as its sole writer". But 10.2's own bullets and Requirement 10.3 enumerate exactly six events and this is not among them, so 10.2 correctly did not add it and left the design's requirement unimplemented. Surfaced by the sub-agent that implemented 10.2
    - A seventh `GameEventLogger` method taking the Game_Id and nothing else. It is not one of Requirement 10.3's six mandated events and must not be presented as one — it reports corruption, not a Game lifecycle event, and no requirement asks for it. The design does
    - Emitted where `CorruptMoveListException` is reported, not from inside `SubmitMove`'s transaction: the exception is thrown inside it precisely so the insert rolls back, and a record written there would survive a rollback whose whole purpose is the "no state change" half of the design's row
    - The 500 itself is the framework default and needs no code
    - _Design: the failure table row for `RulesEngine` returning `InvalidMoveList::Error`_

- [x] 11. Retention and expiry
  - [x] 11.1 Implement `SweepExpiredGames` and the `games:sweep` command
    - Eligibility in one query over the `(state, last_activity_at)` index: never-joined and created over 24 hours ago, OR no accepted move or state change for 7 days
    - **Then exclude any Game whose Rematch is not itself in the delete set, and do NOT clear `rematch_of_game_id`.** An earlier draft had the sweep clear the back-reference, and that is unimplementable: a Rematch has `join_code = NULL`, so `CHECK (join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL)` is satisfied only by `rematch_of_game_id`, and setting it to NULL violates the CHECK while leaving it violates the `ON DELETE RESTRICT`. Reproduced both ways before the plan was changed. Because a Rematch's `last_activity_at` is always at or after its parent's, the parent always becomes eligible first, so a sweep in that window would have rolled back and deleted *nothing at all*. A deferred parent is collected on the first run after its Rematch is eligible too, which Requirement 13.5 licenses — the thresholds are lower bounds on retention, not exact times of deletion
    - In one transaction: insert an Expiry_Record per game, delete the games **children before parents** (moves cascade), then delete Expiry_Records older than 30 days. The reference is not `DEFERRABLE`, so SQLite enforces it per row and the order is load-bearing. Report counts; exit non-zero only on failure. A `RESTRICT` violation now means the deferral or the ordering is wrong, and should fail loudly
    - The purge boundary is **strict** — `deleted_at < :thirty_days_ago` — because Requirement 13.4 retains a record for "at least 30 days", so one exactly 30 days old survives. This is the opposite polarity from the two eligibility thresholds, which are inclusive because Requirements 13.1 and 13.2 fire *when* the elapsed time is reached
    - Nothing in the read path consults eligibility — an eligible but unswept game is an ordinary playable game
    - `last_activity_at` is *not* bumped when a rematch is created, or a finished game would outlive the 7-day threshold
    - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5_
    - _Properties: 17_
    - _Design: ADR-007_

  - [x] 11.2 Write `SweepExpiredGamesTest`
    - **Property 17: The sweep deletes exactly the eligible Games**
    - Mixed population with a travelled clock: assert the survivor set, one Expiry_Record per deleted game holding only the id and the deletion time, no `moves` rows left for a deleted game, the 30-day purge of records, and that an eligible-but-unswept game still accepts a Move and returns its ordinary representation
    - Cover the deferral of 11.1 as its own case: an eligible parent whose Rematch survives is retained, and is deleted on a later run once the Rematch is eligible too. Also pin the purge boundary at exactly 30 days, where the strict comparison is the whole of the distinction
    - **Validates: Requirements 13.1, 13.2, 13.3, 13.4, 13.5, 14.7**

- [x] 12. Complete the mandated verification suite
  - Requirement 14.9's concurrency coverage is deliberately **not** in this group. Task 12.3 was split in two and both halves moved earlier so each sits next to the code it guards: the join race is now task **5.8** (beside 5.7, after `JoinGame`) and the move conflict is now task **6.8** (beside 6.6, after `SubmitMove`). Both remain non-optional. The 12.3 slot is left vacant rather than renumbered so existing references still resolve

  - [x] 12.1 Write `OutcomeVocabularyTest`
    - **Property 16: Rejection outcomes are pairwise distinct**
    - A dataset of the eleven outcomes — `not_authorised`, `not_your_turn`, `invalid_move`, `game_not_started`, `game_ended`, `conflict`, `game_full`, `not_recognised`, `game_expired`, `invalid_state`, `rate_limited` — one scenario each, asserting the expected value and that the eleven observed values are pairwise distinct. The CSRF rejection is excluded by Requirement 14.3
    - **Validates: Requirements 2.2, 2.3, 3.3, 3.5, 4.3, 4.4, 4.5, 4.6, 5.4, 7.10, 7.11, 10.6, 13.6, 13.7, 13.8, 14.3**

  - [x] 12.2 Write `VisibilityTest`
    - **Property 7: Authorisation precedes validity and denies all visibility**
    - Every Game_State × every route naming a Game_Id, for a session with no token and a session holding a token bound elsewhere: assert no Board, no Move_List, no Game_State, no Mark_To_Move and no token value in the response, and that the three failure modes are indistinguishable
    - **Validates: Requirements 3.3, 3.4, 3.7, 3.9, 3.10, 8.7, 9.6, 14.6**

  - [x] 12.4* Write `RepresentationTest`
    - **Property 11: The representation is the derivation**
    - Board, Mark_To_Move, Outcome and winning lines equal `RulesEngine::analyse` over the persisted Move_List; `isYourTurn == (markToMove == yourMark)`; the persisted `winning_mark` equals the derived winner; the Version_Counter is present on every response; the rematch id appears once a rematch exists; and the response is identical whatever version value the request presents
    - **Validates: Requirements 6.3, 6.7, 7.12, 8.3, 8.4**

  - [x] 12.5 Write `PlayAGameTest` — exactly one browser test
    - Two isolated browser contexts: one creates a Game and reads the Join_Code from the page, the other joins with it, they alternate moves to a win, and both assert the winning Mark and the highlighted line appear with no manual refresh — which is also the observational check on Requirement 8.2's three-second budget
    - **Exactly one, on purpose — not a suite.** Playwright is the slowest and most brittle part of CI, and a second browser test buys coverage the feature suite already has at a cost paid on every push (ADR-008)
    - Verify on the first run that the two sessions have independent cookie jars; if `visit()` shares a context, create the second session through the exposed Playwright context API. Assert the two sessions resolve to different Marks, so a shared session fails loudly
    - Tag it `browser` so the quality job can exclude it
    - **The cookie-jar question is settled and should not be re-investigated.** `visit()` does give each page its own Playwright context — `PendingAwaitablePage::buildAwaitablePage()` calls `$browser->newContext()` — and a context is the cookie boundary, so the two pages present no cookie to each other. That was still not enough, and the different-Marks assertion caught it: the plugin serves every request from ONE booted application, so `SessionManager` hands `StartSession` the same `Store` and handler each time, `Store::loadSession()` merges the handler's data into attributes already in memory (`array_replace`, `Illuminate/Session/Store.php:116`), and `DatabaseSessionHandler::$exists` latches true after the first write so later sessions are UPDATEd by id and never INSERTed. Under php-fpm one process serves one request and neither arises. The fix is the test-only global middleware `Tests\Browser\Support\FreshSessionStorePerRequest`, calling `Session::forgetDrivers()` at the top of every request. Removing it makes the joiner resolve to X and the test fail loudly, which was confirmed by running it that way
    - **Two consequences worth knowing.** `tests/Browser` had to be registered as a testsuite in `phpunit.xml`, because a directory in no testsuite is never collected and a `--group=browser` selection would find nothing. And the `playwright` npm package was missing entirely — task 1.2 installed chromium builds under `~/.cache/ms-playwright` but never the package the plugin shells out to — so it is pinned at `1.62.1`, whose chromium revision `1234` is already cached and therefore needs no download
    - **The Requirement 8.2 observation is bounded by the assertion retry budget, 5 s by default, not by 3 s.** So this test shows the opponent's Move arrives unaided within one poll; the 3-second criterion is met by the 2000 ms interval `useGamePolling` sets, which is where it is actually pinned. Do not describe this test as proof of the 3-second bound
    - `composer test` runs `php artisan test` with no group filter, so it now needs a browser. Task 15.1 should say so, or add the exclusion there
    - _Requirements: 14.5_

- [x] 13. Containerisation and deployment
  - [x] 13.1 Write the multi-stage `Dockerfile`
    - Target `app`: `php:8.5-fpm`, `composer install --no-dev`, required extensions, entrypoint running `php artisan migrate --force` then `php-fpm`
    - Target `web`: `caddy:2-alpine` with `public/` and the Vite build output copied in from the app build stage, so no shared code volume is needed
    - Assets built at image build time (`npm ci && npm run build`); no Node runtime ships to production
    - **Delivered as four stages** — `vendor` → `assets` → `app` → `web` — plus `.dockerignore` and `docker/app-entrypoint.sh`. The two build stages are discarded, so neither Composer nor any Node runtime reaches production. `app` 637 MB, `web` 63.4 MB
    - **The requirement citation was wrong and is corrected here.** This task cited `12.2`, which is "the README SHALL state the commands that start the Application locally" — a documentation criterion that task 15.1 satisfies and a Dockerfile does not. Requirement 12 has no containerisation criterion at all; all thirteen are documentation, CI and decision records. Containerisation is ADR-009's chosen *means* to Requirement 12.4's publicly hosted instance, not a requirement in its own right
    - **Verified by running it, not by building it.** Both images built, then exercised as a pair on a throwaway Docker network with a minimal Caddyfile: `GET /health` returned `{"status":"ok","persistence":"reachable"}` in 163 ms, `GET /` rendered the `Home` Inertia component referencing the image's own assets, Caddy served the CSS from its own copy, and `POST /games` with `Sec-Fetch-Site: same-origin` returned 303 to a real UUIDv7 game that landed in SQLite as `waiting_for_opponent`. The `game.created` record from task 10.2 appeared on stderr as JSON, which is what `docker logs` will show
    - **Two comment claims in the first draft were wrong and were corrected after measuring.** The CSS figures cited for the Tailwind `@source` inside `vendor` (41.91 kB / 38.94 kB) had been measured against a dirty local Blade cache; on a cleared cache it is 17.33 kB against 11.94 kB, so the structural conclusion held and the numbers did not. And the `mkdir storage/framework/views` was justified as required, when removing the directory entirely produces a byte-identical stylesheet — it is insurance, and now says so
    - **The asset build is not reproducible across machines, and that is a finding for whoever cares.** `resources/css/app.css` carries `@source '../../storage/framework/views/*.php'`, so the stylesheet depends on which Blade views a machine happens to have rendered: 45 stale entries locally inflated it from 17.33 kB to 41.91 kB. The image is unaffected because that cache is always empty in a build. The image's 14.95 kB is a strict SUBSET of a clean host build's 17.33 kB — 146 selectors against 162, nothing of its own — and all 16 extras are generic single-word utilities (`absolute`, `static`, `table`, `filter`, `container`, `relative`) that Tailwind emits because those words occur in PHP, tests and markdown that `.dockerignore` excludes. Scanner false positives, not styles the application uses. Narrowing that `@source` would make builds reproducible and is application source rather than this task's
    - _Requirements: 12.4 (indirectly, as ADR-009's means)_
    - _Design: ADR-009, ADR-011_

  - [x] 13.2 Write `compose.yaml` and the production Caddyfile
    - Services `app` and `web`, `restart: unless-stopped`, named volume `sqlite-data` mounted at the database directory (the `sessions` table lives in the same file, so a Player_Session survives a restart)
    - Declare `caddy-data` as an **external** volume with the fixed name `caddy-data` (`external: true`, `name: caddy-data`) and mount it at `/data` on the `web` service. **This is the same volume created in task 2.2**, so the certificate obtained days earlier is reused rather than re-requested; a plain named declaration would resolve to `<repo>_caddy-data` and silently start from empty storage
    - The asymmetry between the two volumes is deliberate, so do not "tidy" them into agreement: `sqlite-data` stays project-scoped because only this stack mounts it and its contents are rebuildable with `php artisan migrate`, whereas `caddy-data` is external because it crosses a project boundary and its contents cannot be regenerated on demand — re-issuance depends on a rate limit shared with strangers
    - **Port 9000 is not published, and the `app` service declares no `ports:` key at all** — the justification for the `*` trusted range in 9.2 depends on it. Task 9.2 confirmed against the vendor source that `'*'` expands to `['0.0.0.0/0', '::/0']` (`TrustProxies::setTrustedProxyIpAddressesToTheCallingIp()`), so the application will believe `X-Forwarded-For` *and* `X-Forwarded-Proto` from any peer able to open a FastCGI connection. Nothing in the suite fails if this is violated — 9.5's assertion passes either way — so the constraint holds only if it is honoured here. A `ports: - "9000:9000"` line added for local debugging and left in is the specific way this gets lost
    - **`CACHE_STORE=file` on the `app` service, and it must not be `database`.** Found by playing the game against the 13.1 images: X's poll returned a 500 the moment O won. Not game logic — the rate limiter. Laravel's database cache store increments as SELECT-then-UPDATE in one transaction with `lockForUpdate()` a no-op on SQLite, so two players polling every 2 s collide, the loser's UPDATE meets a stale snapshot, and SQLite returns SQLITE_BUSY *immediately* rather than waiting out `busy_timeout`. 13 failures in 30 concurrent requests; zero after the change, with rate limiting still enforcing its boundary. `.env.example` carries the full reasoning
    - **The Caddyfile needs a `root` override inside `php_fastcgi`, and this is the one real consequence of task 13.1 copying `public/` in rather than sharing a volume.** Caddy serves static files from `/srv/public`, its own copy, but php-fpm resolves `SCRIPT_FILENAME` in *its* filesystem where the document root is `/var/www/html/public`. Without the override Caddy sends `/srv/public/index.php`, which does not exist in the `app` container, and every PHP request fails. Found by smoke-testing the two images together during 13.1; the shape that works is:
      ```
      root * /srv/public
      php_fastcgi app:9000 {
          root /var/www/html/public
      }
      file_server
      ```
    - Compose healthcheck hitting `/health` through the local FPM socket. **The comment this task asked for was about a limiter key that does not exist, and is corrected here rather than written.** `GET /health` carries no middleware at all — it is registered in the `then` callback of `withRouting()` in `bootstrap/app.php` precisely so the `web` group cannot reach it, and no `throttle:` is attached — so the probe resolves to no limiter key at all, not a different one. What is true and is stated instead: the probe never passes through Caddy, so `X-Forwarded-For` is absent and `REMOTE_ADDR` is 127.0.0.1, and what it establishes is that php-fpm accepts connections, the framework boots and the persistence layer answers
    - **`libfcgi-bin` had to be added to the `Dockerfile`'s `app` stage, which is the one change 13.2 made to 13.1's deliverable.** php-fpm speaks FastCGI and nothing else, and the image carries `curl` but no FastCGI client, so there was no way to probe `/health` from inside the container. The alternative was to healthcheck through `web`, which would make `app`'s health depend on Caddy. The invocation is asserted at build time with `command -v cgi-fcgi`, for the same reason as the two `php -m` greps beside it
    - **The healthcheck was shown capable of failing, not merely of passing.** `cgi-fcgi` exits 0 whenever the FastCGI exchange completes, a 503 included, so the body decides: probed healthy against a migrated database (`{"status":"ok","persistence":"reachable"}`, grep exit 0), then probed again with the database file removed from the running container (`{"status":"error","persistence":"unreachable"}`, 503, grep exit 1)
    - **APP_KEY reaches the container through an untracked `deploy/app.env` read by `env_file:`, with `deploy/app.env.example` committed as the template.** It is the only secret in the deployment; everything else is in `environment:` in the open, where it can be reviewed. `/deploy` is excluded from the Docker build context in full rather than by naming the three files in it, so the exclusion survives a fourth being added. The template states the constraint that matters: generate the key once and never regenerate it, because a Player_Token lives only in the session and the session is reached only through the cookie that key encrypts (ADR-005, Req 12.10)
    - **`depends_on` uses `service_started`, not `service_healthy`, and the healthcheck is not a reason to change it.** Caddy renews the certificate on its own schedule, so gating its start on the application's health would let a database problem cost the certificate — the one component ADR-009 identifies as not cheaply replaceable. Started first, Caddy answers 502 while `app` comes up, which is a page a player can retry rather than a refused connection with no TLS behind it
    - **Both services state `json-file` rotation at `max-size: 10m`, `max-file: 3`, because Docker's default is not to rotate at all.** Unbounded logs on the same 20 GB volume that holds every image and build layer, and the rate has no natural end: `useGamePolling` stops only once a Rematch exists, so a finished game left open in a tab keeps polling at the 5-second ceiling indefinitely. Measured per Inertia poll, `web` writes 886 bytes and `app` 62 — Caddy's access log is one JSON object per request carrying headers, fourteen times the php-fpm access line — so two players on a live game at 1 request per second is about 82 MB of log text a day. 30 MB per service is roughly nine hours of that on `web` and five days on `app`, which is the right asymmetry: the Requirement 10.3 lifecycle records are in `app`'s stream. Verified applied rather than merely written, with `docker inspect` reporting `map[max-file:3 max-size:10m]` on both containers. Caddy redacts `Cookie` and `Set-Cookie` to `REDACTED` by default, checked against a real request, so the encrypted session cookie does not reach the access log
    - **`name: tic-tac-toe` is set explicitly** so the project name comes from the file rather than from the invoking directory. Everything Compose namespaces by it — the `sqlite-data` volume and what task 13.3's `docker compose exec` crontab entry resolves — would otherwise change if the repository were cloned to a differently named path
    - **Verified by running the stack, not by reading the file.** `docker compose config` resolves `caddy-data` to the unprefixed external name and `sqlite-data` to `tic-tac-toe_sqlite-data`, with no `ports:` key on `app`; `caddy validate` adapts the production Caddyfile and reports the automatic HTTP→HTTPS redirect. Then brought up locally behind a throwaway override that swapped only the hostname and port, since the real Caddyfile would have attempted ACME against an address this workstation does not hold: `app` reached `(healthy)`, `/health` returned 200 through Caddy, `GET /` rendered, `POST /games` created a UUIDv7 game, the `game.created` JSON appeared in `docker logs app`, `ss -ltn` found nothing on 9000, and 30 concurrent state requests returned **30 × 200** where `CACHE_STORE=database` had produced 13 × 500
    - Caddyfile for `18-175-88-107.sslip.io`, the hostname obtained in task 2.2, with automatic HTTPS. **Issuance succeeded and the certificate is in the external `caddy-data` volume, so `SESSION_SECURE_COOKIE=true` here** — the fallbacks (`nip.io`, plain HTTP) were not taken and need noting only as the contingency they remained
    - _Requirements: 9.1, 10.11, 12.2, 12.4_
    - _Design: ADR-009_

  - [x] 13.3 Deploy and schedule the sweep
    - **The command-by-command runbook is `docs/deploy-schedule-swap.md`**, written against the instance's actual state rather than from the repository: the clone at `/srv/tic-tac-toe` and the `caddy-data` certificate are already in place, and 911 MiB of RAM with no swap is why swap is step 2 rather than a troubleshooting note
    - `docker compose up -d --build` on the instance; confirm the app answers over the hostname from 2.1 and that `/health` reports the persistence layer reachable
    - **NEGATIVE check, after the stack is up: `sudo ss -ltnp | grep 9000` finds nothing, and `docker compose ps` shows no published port on `app`.** This is the only point at which 9.2's trusted-proxy precondition is actually observed rather than asserted — `docs/aws-infra.md` carries the same check for the pre-deployment instance, and this is its post-deployment twin
    - Add the host crontab entry `17 3 * * * cd /srv/tic-tac-toe && docker compose exec -T app php artisan games:sweep`; no scheduler process runs inside the application
    - **Deployed and verified. `https://18-175-88-107.sslip.io/` is live.** `/health` returns `{"status":"ok","persistence":"reachable"}` over HTTP/2 in 62 ms, `/` renders, and `http://` answers 308 to `https://`. The certificate is the one task 2.2 obtained — Let's Encrypt, `CN = 18-175-88-107.sslip.io`, `notBefore Aug 6 13:10`, so the external `caddy-data` volume did carry it across and no second issuance was made against the shared `sslip.io` bucket. Caddy's only ACME traffic was `got renewal info`, which is ARI rather than an issuance
    - **The negative check passed: `sudo ss -ltnp | grep 9000` finds nothing, and `docker compose ps` shows `app | 9000/tcp` with no host binding.** Port 22 does appear in `ss` — that is `sshd` running locally, closed by the security group rather than by the host, which is worth knowing before the output is misread. This is the only point at which task 9.2's trusted-proxy precondition is observed rather than asserted
    - **The crontab entry was verified the way cron will run it, not merely installed.** Running the exact line under `env -i` with `PATH=/usr/bin:/bin` as `ssm-user` and no TTY exited 0 and put the three counts in the journal under the `games-sweep` tag. `-T` is what makes that work; without it cron fails with `the input device is not a TTY`. It is on `ssm-user`'s crontab, the account in the `docker` group, and on no other user's. Three zeroes from a manual run is the correct result: the thresholds are lower bounds, so nothing minutes old is eligible
    - **All six lifecycle records of Requirement 10.3 were confirmed in the deployed stack, and the count reconciles: 22 `move.accepted` records against 22 rows in `moves`**, plus 3 `rematch.created`, 3 `game.finished`, 2 `move.rejected`, 1 `game.joined`, 1 `game.created`. No 5xx, no `game.invariant_violation`, no PHP fatal, no fpm worker warning; 350 × 200, 36 × 303 and 3 × 403 across the run, and the 403s are ordinary visibility refusals. No key named `token`, `player_token` or `join_code` appears in the stream at all — `RedactSecrets` is the backstop, not the mechanism
    - **The first check of those records used the wrong pattern and briefly looked like a defect in the application.** Grepping `"message":"game\.[a-z_]*"` reports four of the six, because the vocabulary is `move.accepted`, `move.rejected` and `rematch.created` rather than `game.move_accepted` and `game.rematch_created`. The runbook is corrected to match all three prefixes and to reconcile the `move.accepted` count against the `moves` table, which is the check that would actually catch a record failing to emit
    - **Where the data went, checked rather than assumed.** Game state is 160 KB of SQLite on the `tic-tac-toe_sqlite-data` volume (4 games, 22 moves, 11 sessions after a play session with rematches); the log records are `json-file` files on the root disk, 65 KB for `app` against 833 KB for `web`, which is the 62-versus-886 bytes per request measured before deployment showing up in production; the rate-limit counters are 380 KB of file cache inside the container and deliberately not on a volume. **The `cache` table holds 0 rows, which is the production confirmation that the rate limiter is off the SQLite cache store** — a climbing count there would mean the mid-game 500 was back
    - **Step 10 of the runbook was wrong and is corrected there.** It named `docker image prune -f`, which reclaimed 0 B: on a first deploy nothing is dangling, and the accumulation on a build-on-the-box deployment is BuildKit's build cache — 1.85 GB of the 5.9 GB used, cleared by `docker builder prune -f` and untouched by `image prune`. Keeping it is the better default, since it is what makes the next `--build` fast
    - Swap was added before the build rather than after a failure: 911 MiB of RAM with none configured, and the asset build is what gets OOM-killed. `free` reported 99 MiB of swap in use once the stack was running, so the headroom was used
    - **Check disk headroom, and prune after a rebuild.** `--build` on the instance leaves the previous images dangling, and the `app` image alone is 637 MB, so repeated deploys are a larger claim on the 20 GB root volume than the logs task 13.2 capped. `df -h /` before and after, then `docker image prune -f`. `t3.micro` is 1 GiB of RAM and `npm run build` inside the image build can be OOM-killed — the symptom is Vite reporting `Killed` — for which `docs/aws-infra.md` carries the swapfile step
    - _Requirements: 12.4, 12.12_

- [x] 14. Static analysis, formatting, and CI (may proceed alongside any group after 1.2)
  - [x] 14.1 Configure Pint and static analysis
    - `pint --test` in check mode; Larastan at level 8 over `app/`, `database/` and `tests/` plus level `max` over `App\Domain\TicTacToe`. The recorded fallback — plain PHPStan at level `max` over the domain namespace alone, had 1.2 found Larastan unresolvable — was not taken; Larastan 3.10 resolved
    - **Level 8 covers `database/` and `tests/`, not `app/` alone.** This corrects the task's own scope rather than extending the requirement: `paths` is not a leniency setting, PHPStan does not open files outside it at all, so `app/` alone left roughly two thirds of the project's PHP unanalysed. `tests/` is now the largest tree — 1,886 lines against `app/`'s 567 and `database/`'s 309 — and holds real logic rather than assertions in the enumeration walk, the architecture checker and the Eris properties, while `database/factories/` carries the fixtures every feature test depends on from group 5 onwards, where a type error surfaces as a confusing test failure instead of an honest analyser complaint. The original wording predates the test suite, when `tests/` held two scaffold placeholders; nothing in Requirement 12.3 limited the scope
    - **Two configuration files, because PHPStan 2.2 supports exactly one `level` per config and has no per-path override**: `phpstan.neon` at level 8 over the three trees, `phpstan-domain.neon` at level `max` over `app/Domain/TicTacToe`. `composer analyse` runs both, so a single command still covers the requirement. The tiering was verified rather than assumed — the same file returning an explicitly `mixed` value as `string` is clean at level 8 and fails under `max`
    - No baseline, no `ignoreErrors`, no suppressions anywhere: level 8 over all three trees passes as it stands, so a later failure is a regression to fix rather than something to record
    - No `pint.json`. The default `laravel` preset is what the project wants and the whole project satisfies it; a config file restating the default would make a future preset change read as a project decision
    - `tests/Unit/ExampleTest.php` deleted — Laravel's `assertTrue(true)` placeholder, and the only level-8 error in `tests/`. `tests/Feature/ExampleTest.php` kept, because its `GET /` 200 assertion is currently the only check that the application boots and Inertia renders; group 5's route tests supersede it rather than this task deleting it now
    - `bootstrap/app.php`'s pre-existing `ordered_imports` failure fixed by running Pint over it rather than hand-editing. It had been failing since the scaffold and would have turned CI red on the `quality` job's first run
    - _Requirements: 12.3, 11.9_

  - [x] 14.2 Add the `quality` CI job
    - On push and on pull request: `composer install`, `pint --test`, static analysis, `pest --exclude-group=browser` (which includes the enumeration). Composer and npm caches keyed on their lock files. Fails the workflow if any check fails
    - If task 3.6 measured the enumeration beyond the budget, split it into its own job here rather than reducing its coverage
    - _Requirements: 12.9_

  - [x] 14.3 Add the `browser` CI job
    - `npm ci`, `npm run build`, `npx playwright install --with-deps chromium`, `pest --group=browser`, in its own job so a browser flake never masks a domain regression
    - **Sequenced after task 12.5, not with the rest of group 14.** `PlayAGameTest` is the only `browser`-tagged test, so until it exists the job runs an empty selection: either it fails the workflow, or it passes vacuously. A job that passes vacuously while claiming to run browser tests is worse than no job at all, because nobody investigates a green tick. It also pays a chromium download on every push to test nothing. 14.1 and 14.2 stay early — config problems are cheaper to find against fifty lines than five hundred, and a trivially passing first run of Pint and static analysis costs nothing
    - _Requirements: 12.9_

- [ ] 15. Documentation and records
  - [ ] 15.1 Write the README
    - Prerequisites; the commands that start the application locally and the URL it is reachable at; the commands that run each test suite, the static analysis and the formatting check; the URL of the hosted instance
    - Which AI tooling was used, for which parts, and where its output was corrected or rejected
    - That access to a Game cannot be recovered after loss of the Player_Session, and that this follows from the deliberate absence of user accounts
    - That the instance has no inbound SSH and shell access is via Systems Manager Session Manager — a deliberate decision, not an omission. One line; ADR-009 carries the reasoning
    - How to invoke `games:sweep` on a schedule as the production means of deleting eligible Games, with the crontab line from 13.3
    - **A known limitation (Req 12.13): the opponent-idle indication begins only once a Move has been accepted, so a Creator who joins a Game and never returns leaves the Joiner waiting with no warning.** Requirement 9.4 was narrowed to say so during task 6.5 — `lastMoveAt` is absent for an empty Move_List and the only other origin is not part of the representation — and the amendment is recorded in `docs/ai-direction.md` under "Requirements narrowed rather than met". Requirement 9.3's waiting indication still shows throughout, so state what the Joiner does see as well as what they do not
    - **Two test commands, and the browser one has a prerequisite.** `composer test` excludes the `browser` group and needs nothing beyond `composer setup`; `composer test:browser` runs the one browser test and needs chromium fetched first with `npx playwright install chromium`. State that step explicitly — `composer setup` runs `npm install --ignore-scripts` and the `playwright` package ships no install hook, so nothing downloads a browser on a fresh clone, and without the instruction `composer test:browser` fails in a way that looks like a broken suite rather than a missing download
    - **A second known limitation: a rate-limited request shows the Player nothing.** `rate_limited` has no value in the application's vocabulary — the string is in no enum, no route and no client module — because the 429 comes from `ThrottleRequests` before any controller runs, and `resources/js` has no `onError`, no `router.on` and no status branch, so nothing renders it. A Player who trips the join or move limiter sees a button that appears to do nothing until the window passes. No criterion asks for the client half: 10.6 and 10.7 oblige the Game_Service, and 14.3 excludes the forgery rejection outright. Found during task 12.1, which also corrected the design paragraph that had claimed Inertia's error handling surfaced it
    - **The scheme the hosted instance actually serves is HTTPS** (task 2.2: issuance succeeded, certificate in the `caddy-data` volume), so state that plainly and note that the Player_Session cookie carries `Secure` in consequence. The plain-HTTP account below was the contingency and is retained only so a reader can see it was considered: WHERE that outcome had been plain HTTP, an honest account of the exposure would have been required rather than only the citation — Requirement 10.11 conditions the Secure attribute on the Application being served over HTTPS, so the criterion is *met* rather than waived; `HttpOnly` and `SameSite=Lax` still apply; and what is actually at risk is that the session cookie travels in clear, carrying a per-Game play token with no account behind it and a retention window of at most seven days. State that bounded exposure plainly instead of citing the conditional and moving on
    - _Requirements: 10.11, 12.1, 12.2, 12.3, 12.4, 12.8, 12.10, 12.12, 12.13_

  - [ ] 15.2 Write the decision records under `docs/decisions/`
    - One file per ADR 001 to 011, each stating the decision, the alternatives considered and the reason. ADR-001 (polling as the state-synchronisation transport) is the record Requirement 12.11 mandates specifically
    - ADR-011 (php-fpm behind Caddy rather than one embedded-PHP container) was written during task 13.1, when the question "why two containers" was put directly and the design turned out to argue the hosting platform but never the topology. Carry its two unusual paragraphs across rather than tidying them away: the one declining to claim the unpublished port 9000 as a benefit of the split, and the one recording that the choice was inherited from the technology table rather than decided
    - Include in ADR-009 the certificate risk and its four mitigations, and the rejection of Let's Encrypt IP-address certificates on renewal-frequency grounds
    - _Requirements: 12.7, 12.11_

  - [ ] 15.3 Complete the AI-direction record
    - Finish `docs/ai-direction.md`: the spec documents and the corrections made to the generated output — including the outcomes of the two dependency checks recorded in 1.2 (Larastan 3.10 and Eris 1.1 both resolved and run on PHP 8.5.9, so neither fallback was needed), the Requirement 10.9 outcome also recorded in 1.2 (outcome 3 was taken and the criterion has been amended), and any enumeration-count convention adopted in 3.6
    - **Enumerate the spec-stage defects the review process caught**, so they are captured while fresh rather than reconstructed from memory at the end: the test suite not being required to exist at all; rematch token issuance specified in a way no implementation could satisfy; the polling stop condition that was always true at the moment it was evaluated, so both clients stopped polling and neither could discover a rematch; Requirement 13.3 deleting a Game at the moment 13.4's command was supposed to find it; concurrent joins being undefined; the concurrency guarantee in 5.3 contradicting the authorisation rules in Requirements 3 and 4; the idle indication firing on the viewing Player's own turn; a CHECK constraint requiring a token slot that per-request rematch minting leaves null; Property 10 claiming contiguity as a persisted constraint after the schema section had established it was not; the missing `TrustProxies` configuration; the incorrect claim that CSRF forces a session into existence, which Laravel 13's `Sec-Fetch-Site` behaviour inverts; the follow-on claim that a single configuration flag would close the resulting Requirement 10.9 gap, which was wrong because no such flag exists, together with the unimplementable test assertion built on top of it; and the amendment of Requirement 12.6 itself, dropping the prompts component as disproportionate to the stated time budget — recorded as a scoping decision rather than a correction of an error, so a reader can tell the two apart
    - **The entries where the generated reasoning was confidently wrong and had to be corrected — the CHECK constraint, the Property 10 contradiction, the CSRF inversion — are the ones that actually evidence Requirement 12.8.** A correction log containing only other people's errors does not demonstrate reviewing AI output
    - _Requirements: 12.6, 12.8_

- [ ] 16. Final checkpoint - Ensure all tests pass, ask the user if questions arise.
  - Full suite, static analysis, formatting, both CI jobs green, hosted instance answering over the hostname from task 2

---

## Notes

### The three verify-on-first-run items — none of these is a blocker

These are recorded as notes rather than tasks because each has a fallback that costs minutes. A dependency that will not resolve is not a reason to stop.

1. **Larastan against Laravel 13** (checked in task 1.2). If it does not resolve: drop it and run plain PHPStan at level `max` over `App\Domain\TicTacToe` only — viable precisely because that namespace has no framework dependencies — with the rest of `app/` covered by Pint and the test suite. Record the fallback so the CI job never becomes the reason the deliverable stalls.
2. **Eris on PHP 8.5** (checked in task 1.2). If it does not resolve or does not support 8.5: express the unbounded properties as Pest datasets over a deliberately chosen bounded sample — boundary values, empty and maximal strings, non-integer and non-scalar payload values, and ill-formed Move_Lists covering each of the five well-formedness classes. **No property obligation in the design is dropped**; only the generation strategy changes, from random to hand-picked. The exhaustive enumeration is unaffected either way, because it depends on no generator library at all.
3. **The enumeration node count** (measured in task 3.6). 549,946 versus 549,945 is a root-counting convention — whether the empty Move_List is a node. Fix the harness's accounting, record the convention adopted, and do not touch the Rules_Engine. The terminal count 255,168 has no such latitude: a mismatch there means the engine and the independent oracle disagree with the accepted combinatorial result, and the two counts read together localise the fault (too few terminals inflates the node count, too many shortens it).

### Optional sub-tasks

`*` marks a sub-task that can be skipped for a faster MVP. Test sub-tasks are marked optional **except** where Requirement 14 makes the test itself part of the deliverable — 14.1, 14.2, 14.3, 14.4, 14.5, 14.6, 14.7, 14.8 and 14.9 — except `MiddlewareConfigurationTest`, which is the only mechanical guard on two configuration decisions no behavioural test would notice, except the schema-constraint test in 4.5, which is the only coverage Requirement 5.6 has anywhere in the plan, and except `SubmitMoveTest` in 6.6, which is the only coverage Requirement 3.6 has anywhere in the plan. Those are requirements of the artefact, not conveniences.

The common shape of the three exceptions is worth naming, because it is the test to apply before starring anything else: a test is not optional when it is the *sole* assertion of a criterion, and that is easy to miss when the criterion looks structurally guaranteed by the code. Requirement 5.6's nine-Move cap and Requirement 3.6's ignored payload Mark both look self-evident from the implementation — the pigeonhole on two unique indexes in one case, a parameter with no payload in scope in the other — and in both cases the assertion that would catch a regression lives nowhere else.

### The midpoint

Task 8 is the line. Groups 1 to 7 produce a working two-player game covering every behaviour the brief names. Groups 9 to 15 satisfy the operational requirements, complete the mandated verification, and produce the hosted instance and documentation.

### Out of scope

No task here creates accounts, chat, spectators, leaderboards, AI opponents, turn timers, WebSockets, session recovery, or board sizes other than 3x3. The requirements record why each is absent.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2", "2.1"] },
    { "id": 2, "tasks": ["1.3", "1.4", "2.2", "3.1", "4.1", "14.1"] },
    { "id": 3, "tasks": ["3.2", "4.2", "14.2"] },
    { "id": 4, "tasks": ["3.3", "3.4", "4.3"] },
    { "id": 5, "tasks": ["3.5", "3.6", "4.4"] },
    { "id": 6, "tasks": ["3.7", "4.5", "5.1"] },
    { "id": 7, "tasks": ["5.2", "5.3"] },
    { "id": 8, "tasks": ["5.4", "5.5"] },
    { "id": 9, "tasks": ["5.6", "6.1"] },
    { "id": 10, "tasks": ["5.7", "5.8", "6.2", "6.3"] },
    { "id": 11, "tasks": ["6.4", "6.5", "7.1"] },
    { "id": 12, "tasks": ["6.6", "6.7", "6.8", "7.2"] },
    { "id": 13, "tasks": ["7.3", "9.1", "9.3", "10.1"] },
    { "id": 14, "tasks": ["9.2", "9.4", "10.2", "11.1"] },
    { "id": 15, "tasks": ["9.5", "9.6", "10.3", "10.4", "11.2"] },
    { "id": 16, "tasks": ["12.1", "12.2", "12.4"] },
    { "id": 17, "tasks": ["12.5", "13.1"] },
    { "id": 18, "tasks": ["13.2", "14.3", "15.1", "15.2"] },
    { "id": 19, "tasks": ["13.3", "15.3"] }
  ]
}
```
