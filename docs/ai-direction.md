# How this project was directed, and where the generated output was wrong

Satisfies Requirements 12.6 and 12.8.

This file records how AI tooling was used to build Remote Tic-Tac-Toe, and — more usefully — the places where its output was wrong and had to be corrected. It is written as work proceeds rather than reconstructed at the end, because the detail that makes a correction log worth reading is the first thing lost to memory.

## How the tooling was used

The specification was produced through an AI-assisted, spec-driven workflow: a requirements document in EARS form, a technical design, and an implementation plan, each reviewed and revised before the next was written. Implementation follows the plan.

The workflow's value was not that the first draft was good. It was that a written specification is reviewable in a way that a half-built application is not, and almost every defect listed below was found by reading a document rather than by debugging code.

The exception is recorded under "Inconsistencies the implementation surfaced", and it is instructive for the opposite reason: some incompatibilities are invisible to review because neither half is wrong on its own. They only appear when something tries to satisfy both at once.

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

## Inconsistencies the implementation surfaced

Everything above was found by reading. This one was found by building, and it is the only entry of its kind so far. It is recorded separately because the failure mode is different: no document was wrong, two documents were individually right and jointly unsatisfiable, and no amount of re-reading either one would have shown it.

### `PlayerTokens` could not satisfy both the design and task 5.4

**What the two halves said.** The design specified a three-method credential class, of which the relevant one was:

```php
/** Mints a token, stores its hash on the game's mark slot, puts the raw value in the session. */
public function issue(Game $game, Mark $mark): void;
```

Task 5.4, describing how a second player claims the O slot, said:

> Compute the hash before the statement; if the update loses, discard the raw token so no orphan credential exists

**Why they cannot both hold.** `JoinGame` claims the O slot with a conditional `UPDATE ... WHERE id = ? AND state = 'waiting_for_opponent' AND o_token_hash IS NULL`, and the affected-row count is what decides between claiming the slot and answering `game_full`. The hash has to be *inside* that statement, so it must exist before the statement runs. But whether the request won is only known after it runs. `issue()` writes the session unconditionally, so by the time the outcome is known the credential already exists in the browser's session — and "discard the raw token" has become a retraction rather than an absence. An orphan credential would exist, briefly, and its non-existence would depend on a cleanup step running.

Neither statement is wrong. The design's surface was simply under-specified for a caller with a losing path, and nothing about reading either document reveals that, because the incompatibility lives in the interaction.

**How it was handled, and by whom.** The sub-agent implementing task 5.1 hit the contradiction, and did not resolve it. It implemented the signature exactly as the design specified, recorded the tension in the code's own docblock, and reported that task 5.4 would need a decision between two options — splitting `issue()`, or having `JoinGame` retract the session key on the losing path. That restraint was the correct behaviour and is worth naming: inventing a third option and quietly shipping it would have left the design describing a class that no longer existed.

The decisions that followed were the human's, and there were three.

**First, that it be resolved before proceeding rather than carried.** The gap could have been deferred to task 5.4, which is where it would have bitten. It was not, and that is the more disciplined choice — a known contradiction carried through four intervening tasks is a contradiction that gets rediscovered under time pressure.

**Second, a rejected proposal, and the reasoning that rejected it.** The initial instinct was to implement task 5.4 out of order, on the ground that building the thing that has the problem is the surest way to settle it. That is usually right and was wrong here: task 5.4 *normalises* join codes, while task 5.2 *generates* them, so implementing the joiner first would have forced the alphabet, the ambiguous-character folding and the display format to be decided inside the consumer and then matched by the producer. It would also have meant building "join a game" before "create a game" existed, with tests hand-assembling rows the real creation path would later produce differently. The proposal was withdrawn once that cost was stated.

**Third, which of the two fixes to take.** The options were a structural one — separate minting from remembering, so the session is never written on a losing path — and a procedural one, where `JoinGame` writes the session and then forgets the key if the update loses. The procedural option is functionally adequate: it is all one request, single-threaded, and no other request observes the intermediate state. The structural one was chosen anyway, on the stated ground that a guarantee holding by construction beats one holding because someone remembered the cleanup. That is the same reasoning that produced the token scheme's location-binding in the first place, applied consistently.

**What was built.** `mint()` returns a `MintedToken` and has no side effects; `remember()` is the session write alone; `issue()` survives as the composition of both, because `CreateGame` inserts a fresh row with no competing writer and therefore no losing path. `JoinGame` mints, runs its guarded statement, and calls `remember()` only on the winning branch — so no orphan credential exists because nothing wrote one, not because something cleaned one up. The design's skeleton was amended to match, including a note that task 7.1's `CreateRematch` will want the same primitives for the same reason.

**A smaller decision inside that one.** `mint()` returns a two-property value object rather than a raw string plus a public `hashOf()` helper. Both strings are `string` to PHP and to PHPStan, and `JoinGame` interpolates one of them into `SET o_token_hash = ?` — so a transposition would write the secret into the database, which is the exact disclosure Requirement 8.7 exists to prevent, at the exact point Requirement 3.1's binding is established, with no type checker objecting. Named properties make the mistake visible at the call site. The object's docblock is also explicit about what it does *not* protect: `readonly` prevents mutation, not disclosure, and `raw` is public, so `var_dump` or `json_encode` would print it. What protects the secret is that no instance is ever handed to a serialiser.

**Why this was surfaced at all.** Worth recording, because it is a property of how the work was directed rather than of the tooling. The standing instructions throughout were to reason about review feedback rather than accept it, to report before changing, and to say plainly when something in the spec was wrong or unimplementable rather than working around it silently. A sub-agent told to satisfy its brief would have shipped something that satisfied the brief. One told that flagging a contradiction is part of the job flagged it.

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
