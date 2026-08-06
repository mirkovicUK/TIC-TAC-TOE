# How this project was directed, and where the generated output was wrong

Satisfies Requirements 12.6 and 12.8.

This file records how AI tooling was used to build Remote Tic-Tac-Toe, and — more usefully — the places where its output was wrong and had to be corrected. It is written as work proceeds rather than reconstructed at the end, because the detail that makes a correction log worth reading is the first thing lost to memory.

## How the tooling was used

The specification was produced through an AI-assisted, spec-driven workflow: a requirements document in EARS form, a technical design, and an implementation plan, each reviewed and revised before the next was written. Implementation follows the plan.

The workflow's value was not that the first draft was good. It was that a written specification is reviewable in a way that a half-built application is not, and every defect listed below was found by reading a document rather than by debugging code.

## Corrections

Grouped by the kind of failure, because the kinds are more instructive than the count.

### Confidently wrong claims about framework behaviour

The most persistent failure mode, and the one worth taking seriously. In each case the assertion was stated as fact, without qualification, and was wrong. Only reading the vendor source settled it.

**Laravel 13's forgery protection, three times in sequence.**

1. Claimed that CSRF protection forces a session into existence, and used that to argue the IP-keyed branch of the rate-limit subject was unreachable in practice. Wrong. Laravel 13's `PreventRequestForgery` accepts a `Sec-Fetch-Site: same-origin` request without touching the session at all, so no session is guaranteed.
2. Having been corrected, claimed the resulting requirement gap could be closed by "one configuration line" disabling a same-site flag. Also wrong. `PreventRequestForgery::$allowSameSite` already defaults to `false`, and it governs the broader `same-site` case. The `same-origin` bypass at the top of `hasValidOrigin()` is unconditional and no flag disables it.
3. On top of that, added a test asserting the state of the non-existent flag. Unreachable regardless: `handle()` calls `runningUnitTests()` before anything else, so the middleware short-circuits entirely in the test environment.

Resolved by reading `vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php`. The outcome was to amend Requirement 10.9 rather than subclass the middleware — the framework's origin check is set by the browser and cannot be forged cross-site, so it defends the case CSRF tokens exist to defend without depending on a secret threaded correctly through every form. Overriding a deliberate framework security design to make an earlier draft's wording come out true was the weaker option.

The lesson is narrow and worth stating plainly: framework behaviour was asserted from memory three times and was wrong three times, and each correction was itself partly wrong until the source was read.

### Self-contradictions within a single document

Defects that survived generation because each half was locally plausible.

**A CHECK constraint contradicting the flow four paragraphs above it.** The schema required `x_token_hash IS NOT NULL` on a rematch, while the rematch flow in the same document inserted rematches with both token slots null and minted tokens per request. The insert would have failed immediately. Caught by checking the DDL against the flow rather than reading either alone.

**A correctness property contradicting the schema section.** Property 10 claimed sequence-index contiguity held "against direct writes, because each is a persisted constraint" — after the schema section had established that contiguity is *not* schema-enforced and rows carrying 0, 1, 2, 4, 5 satisfy every constraint. The schema section had been corrected; the property had not been updated to match.

**Volume sharing that would not have shared anything.** The plan had a placeholder container and the real stack mount "the same named `caddy-data` volume" to carry a TLS certificate across. Docker Compose namespaces volumes by project name, so the two invocation directories would have produced two distinct volumes, a second certificate issuance against a rate limit shared with strangers, and a mitigation that looked correct while doing nothing. Fixed by making the volume external with a fixed name.

### Requirements that specified impossible or vacuous behaviour

**Rematch token issuance no implementation could satisfy.** The requirement had the service issue Player_Tokens to *both* players when a rematch is created. Only the requesting player's session is present in the request; there is no way to write a token into the absent player's browser. Rewritten so each token is minted when that player's session next presents a valid token for the preceding game.

**A polling stop condition that was always true.** Clients stopped polling when a game reached a terminal state and no rematch was associated with it. At the moment a game becomes terminal no rematch can exist, so the condition was always true and *both* clients stopped — meaning neither could ever discover a rematch the other created. This broke the one behaviour the brief names explicitly.

**A retention command with nothing to find.** One criterion deleted a game at the moment it was marked expired; the next required a command that deletes every expired game. The command's working set was permanently empty. Restructured around a single actor and a single transition.

**A concurrency guarantee contradicting the authorisation rules.** The requirement said that when two concurrent move requests target the same sequence index, exactly one must be accepted — without requiring either to be authorised. Read literally it obliged the service to accept one of two unauthenticated requests, contradicting the criteria that require rejecting both.

**An idle indication that fired on your own turn.** "No move accepted for 60 seconds" is vacuously true on an empty board, so a player was told their opponent may have stopped playing while waiting for their own first move.

### Gaps found by asking what was missing rather than what was wrong

**No requirement that a test suite exist.** The requirements specified that the README state the commands to run the tests and that CI run them and fail on failure — and never that any test be written. Both criteria were satisfiable by a README documenting a command that ran nothing.

**Concurrent joins undefined.** Concurrent *moves* were covered by two persisted uniqueness invariants. Two visitors submitting the same join code simultaneously could both be assigned the same mark.

**`TrustProxies` never configured.** Behind a reverse proxy, php-fpm sees the proxy's address unless the middleware is configured to honour `X-Forwarded-For`. Without it the join rate limit collapses from twenty per visitor per minute into twenty per minute for the entire application. This would have passed every test in the suite, because the feature tests exercise the application directly rather than through a proxy.

**A CI job sequenced before the tests it runs.** The browser-test job was scheduled thirteen waves before the only browser test was written, so it would have run an empty selection — either failing the build or, worse, passing vacuously while appearing to verify something.

### Precision errors

**Winning lines described in the singular.** Two criteria referred to "the completed winning line". A single move can complete two lines at once, and the position is reachable in legal play: `X0, O1, X2, O3, X6, O5, X8, O7, X4` completes both diagonals on X's final move. Found by reasoning about what the exhaustive enumeration would encounter. Both criteria are now plural.

**Game-tree figures described as positions.** The enumeration's node count, 549,946, counts reachable move sequences, not distinct board positions — of which there are 5,478. The figure was right and the noun was wrong.

## Decisions recorded rather than corrected

Not everything below was an error; these are choices where the reasoning matters more than the outcome, and each has a decision record under `docs/decisions/`.

- Polling rather than WebSockets for state synchronisation
- No conditional or not-modified responses, because Inertia's protocol has no such path
- A framework-free domain layer with marks derived from sequence parity and no `mark` column
- Per-game, per-mark tokens instead of user accounts
- Two distinct concurrency mechanisms, matched to two differently shaped races
- A retention command rather than an enforced TTL
- Exactly one browser test
- No continuous deployment, and what would be built if there were

## A requirement narrowed rather than met

One acceptance criterion was amended rather than satisfied, and it belongs here as a scoping decision rather than among the corrections above. Requirement 12.6 originally asked this documentation to comprise three things: the spec documents, the significant prompts issued, and the corrections made to the generated output. The prompts component was dropped against the brief's "no more than a few hours" budget, and the criterion now names only the two components actually delivered.

The reasoning is the part worth keeping. An unmet criterion that a reviewer can find by reading your own specification is worse than a narrower criterion honestly stated. The choice was between satisfying it cheaply and amending it openly; leaving it quietly unsatisfied was not one of the options. Requirement 12.8 — recording where the generated output was wrong — is untouched and still in force, and this file satisfies it.

## Verification items settled on first run

Recorded here so a reader can see which strategy the project actually uses rather than which one was planned. Three of the four items were flagged in advance as things that could force a fallback; naming them before starting is what made each one a short check rather than a mid-build surprise.

- **Larastan against Laravel 13** — resolved. Larastan 3.10 installs cleanly and `phpstan` reports 2.2.8. The recorded fallback (plain PHPStan over `App\Domain\TicTacToe` only) was not needed.
- **Eris on PHP 8.5** — resolved. Eris 1.1 installs and its generators run under PHP 8.5.9, verified by constructing a `ChooseGenerator` and drawing a value rather than by trusting Composer's resolution. The hand-picked-dataset fallback was not needed. Worth distinguishing the two checks: Composer resolving a package says only that its constraints are satisfiable, not that its code runs on the interpreter in use.
- **Pest 4** — resolved. Pest 4.7.8, with the Laravel and browser plugins. The browser plugin is what makes Requirement 14.5 satisfiable without adding Dusk.
- **Requirement 10.9 against `PreventRequestForgery`** — resolved by amending the criterion, as described above. No application configuration follows.
- **The enumeration node count convention** — resolved, and earlier than planned. It was recorded as open until task 3.6, but verifying task 3.2's rules engine meant walking the tree anyway, so the answer arrived with the engine rather than with the test that formalises it. The root counts: incrementing on entry to each node, the empty move list included, gives exactly 549,946, and 549,945 means the root was skipped. Task 3.6 now states the convention instead of asking the implementer to choose one — the open version invited whoever got the right answer to go hunting for a discrepancy that does not exist.
- **The enumeration runtime** — measured, and the flagged risk did not materialise. The design named the exhaustive walk as the most likely thing to overrun the CI budget and staged four mitigations against a 60-second limit. The full walk, engine plus an independently written oracle in plain PHP with no optimisation, took about five seconds. The mitigations stay on record as contingency rather than being deleted, because naming them in advance is what made this a five-second check instead of a mid-build surprise.

## Evidence rather than assertion, for the one part that had to be right

This belongs here as the opposite case to everything above: not a claim that was wrong, but a claim that was checked hard.

The rules engine is the one component where a defect is both plausible and quiet. A storage bug loses a game. A rules bug tells a player they lost a game they won, and nothing else in the system objects.

So it is not verified by assertion. Every reachable position — all 549,946 move sequences, 255,168 of them terminal — is walked, and at each one the engine's verdict is compared against a **separately written** win oracle. The engine is never its own judge: had the walk asked the engine when to stop recursing, the properties would be tautologies and the exercise worthless.

Both counts are externally known combinatorial facts about tic-tac-toe, which is what makes them ground truth rather than a restatement of the implementation's own opinion. They matched exactly, with no disagreement on terminality, winning-line sets, move counts, mark-to-move or winner at any node.

One consequence worth recording for whoever writes the tests. Guard *ordering* inside the engine is deliberately not observable through its return value, because Requirement 11.5 mandates one uniform, detail-free rejection: a move list breaking two rules at once returns identical output whichever guard fired. The reachable property is "all five violation classes collapse to the same value", which is what the requirement actually asks for. An ordering assertion would have to reach inside the implementation to exist, and should not be written.
