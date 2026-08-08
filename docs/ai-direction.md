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

**Inertia's `usePoll`, twice in one paragraph of the design.** Both halves of the design's polling block described the library doing something it does not do, and both were found the same way: by reading `node_modules` while implementing task 6.4 rather than by reviewing the paragraph again.

1. The illustrative snippet computed an interval from `game.state` and passed it to a single `usePoll`, as though changing the argument would change the rate. It does not. `usePoll`'s effect has an empty dependency array, and `Poll` assigns `this.interval` in its constructor and arms `setInterval(…, this.interval)` in `start()`, with no path that reassigns it — so the interval is pinned to first render. A page first rendered in a Terminal_State would poll at 5000 ms and, if its props later described a live Game, would sit outside Requirement 8.1's 2-second ceiling. The hook declares both polls and runs exactly one; the design's snippet now shows that form, so a later reader does not "simplify" it back into the defect.
2. The same paragraph justified leaving `keepAlive` at its default with "a hidden tab does not poll". The default sets a *throttle*: `isInBackground()` derives the flag from `document.hidden`, and `tick()` fires when `!this.throttle || this.cbCount % 10 === 0`, so a hidden tab still polls every tenth tick — one request per 20 s while live, per 50 s while terminal.

The second is the more interesting of the two, because the decision it was defending was correct and only the reason was false. Nothing in the implementation changes: `keepAlive` is still not passed. What changes is that the design now states the rate a forgotten tab actually costs instead of implying zero, which is the difference between a decision a reviewer can check and one they have to trust.

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

**A writer's five-second wait attributed to a read-only health probe.** The design's error table said of `SQLITE_BUSY` that `busy_timeout` waits up to 5 s and that beyond it "the Health_Endpoint begins reporting the persistence layer unreachable". Read as an account of one `/health` request, that is a response arriving after five seconds, which Requirements 10.1 and 10.2 forbid on both branches. The intended reading was a steady state of contention, and the row now says so: the 5 s is a writer's wait, and under WAL a reader takes its read mark without waiting on a writer, so the probe's own read never sits on that path. Surfaced by the sub-agent implementing task 10.1, which had to satisfy the 1-second bound and could not do it from the row as written. The WAL and `busy_timeout` settings were then read out of `config/database.php` rather than taken on trust, and both branches of the endpoint were exercised against a missing file, a corrupt file and a schema-less file before the row was amended.

**A design decision left in the future tense after it had happened.** The same paragraph read "goes when the endpoint is implemented (task 10.1)" and "until then the repository has two health routes" after task 10.1 had removed the argument. Both clauses became false while the substance — why `/health` won over the scaffolded `/up` — stayed correct. Retensed rather than deleted, which is the standing rule for a justification that has outlived its dating.

## Inconsistencies the implementation surfaced

Everything above was found by reading. This one was found by building, and so were the three that follow it. It is recorded separately because the failure mode is different: no document was wrong, two documents were individually right and jointly unsatisfiable, and no amount of re-reading either one would have shown it.

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

### A log record required by the design belonged to no task

Found the way the `PlayerTokens` conflict was: by building, not by reading. Each document was locally consistent and the gap only appeared when something had to satisfy all three.

The design's failure table requires a `game.invariant_violation` record on the path where the Rules_Engine returns `InvalidMoveList::Error` — 500, the Game_Id, no state change. Task 6.1 implemented that path and recorded the record as deferred, naming `GameEventLogger` "in 10.2 as its sole writer". Task 10.2's own bullets, and Requirement 10.3 behind them, enumerate exactly six events, and this is not one of them.

So the sub-agent implementing 10.2 was correct to leave it out and correct to say so. Had it split the difference — adding a seventh method quietly, or deleting the deferral comment — the record would have shipped either unrequested or unimplemented, and in both cases invisibly. It is now task 10.4, with the distinction stated in the task text: Requirement 10.3 mandates six Game lifecycle events, and this is a corruption report that the design alone asks for.

The general shape is worth naming, because a deferral is the one kind of comment that rots without anything failing. A note saying "task N owns this" is a claim about a document the writer has not re-read, and nothing checks it when task N is written to a narrower scope than the note assumed.

### A UUIDv7 does not carry 74 random bits when you generate two in the same millisecond

Smaller than the above, and included because the shape of the error is common: a figure that is correct in general, quoted in a context where it is not.

The design's `GameResolver` section justified a visibility decision with this: *"A Game_Id is a UUIDv7 with roughly 74 random bits, so there is nothing to enumerate and no id to test that was not already known."* The arithmetic behind 74 is right — RFC 9562's UUIDv7 is 48 bits of millisecond timestamp, 4 of version, 12 of `rand_a`, 2 of variant, 62 of `rand_b`, and 12 + 62 = 74.

Implementing task 5.2 measured it. Eight ids generated inside one millisecond:

```
019fdc9a-b9f3-7246-9d3b-80a9161cc692
019fdc9a-b9f3-7246-9d3b-80a9168db064
019fdc9a-b9f3-7246-9d3b-80a9175ef476
019fdc9a-b9f3-7246-9d3b-80a917cde78c
...
```

Seven of thirty-two hex nibbles varied. About 28 bits, not 74 — and the varying part *increments* rather than being redrawn, because the RFC permits a monotonic counter in `rand_a` and `Str::uuid7()` uses one. Two games created in the same millisecond have ids that are close together and partly predictable from one another.

The claim was measured because a sub-agent reported the discrepancy in passing rather than adopting the design's figure, and it was then checked independently before the design was changed. The correction is not that the design chose the wrong id type: Requirement 1.2 asks for "a cryptographically secure random source **or a time-ordered random source**" and forbids deriving any part from "a monotonically increasing **database** sequence", and UUIDv7 satisfies both clauses exactly. Requirement 1.2 also asks for "non-sequential and non-guessable", and that half is now stated as partly unmet within a millisecond rather than glossed.

**What makes this worth an entry is that the correction improved the argument rather than weakening it.** The paragraph's conclusion — that the visibility decision closes no meaningful hole — was resting on the id being hard to guess, which turns out to be partly false. It now rests on the Game_Id not being a credential at all: guessing one correctly yields `not_authorised`, because authorisation comes from the Player_Token and nothing else, and that token is 256 bits from `random_bytes()` with no counter and no structure. That was always the design's actual position. The wrong justification had been doing work the right one does better.

### The corrected Game_Id paragraph sent a correctly guessed id to the wrong row

Smaller than the two above, and included for its shape: the fix for one defect carried a fresh factual error, and what caught it was the implementation of the next task.

**What the two halves said.** `GameResolver` implements a seven-row visibility table deciding whether a request may see a Game. Row 5 is "a Game row exists and the request presents no token bound to it" → `not_authorised`. Row 6 is "no token presented, no Game row, an Expiry_Record exists" → `not_recognised`, and row 7 is the same without the Expiry_Record and answers identically. The prose justifying the table — the paragraph rewritten when the 74-bit claim recorded above was withdrawn — now rested the argument on a Game_Id not being a credential, so that guessing one correctly gains nothing. In saying so it stated that a correctly guessed id "reaches **row 6**".

**Why they cannot both hold.** A correctly guessed Game_Id is, by definition, an id whose row exists. That is row 5, `not_authorised`. Rows 6 and 7 are the cases where there is no Game row at all, and that is where an *incorrectly* guessed id lands — row 6 if a tombstone happens to exist for that id, row 7 if not, both `not_recognised`. The prose had the two cases the wrong way round while the table had them right, and neither half looks wrong read alone.

**How it was handled, and by whom.** The sub-agent implementing task 5.3 was building the seven-row table out of that same design section and could not reconcile the prose with the table it was implementing. It reported the discrepancy rather than implementing either reading — the right call, because picking one would have buried the error in code instead of surfacing it. The human confirmed that the table was authoritative and the prose wrong, and had the design amended in `26c8d88`. The wrong row number had entered in `b49eae5` — the same commit that corrected the 74-bit figure recorded in the entry immediately above, so the correction and the error it introduced arrived together.

**What makes this worth an entry.** The error was harmless to the code, because the implementation followed the table. But it sat in the *justification*, which is the part a reviewer reads to decide whether the design is trustworthy, and a security argument that names the wrong outcome is not a small blemish even when nothing downstream depends on it. The mechanism that caught it was also not review: the wrong row number did not change the paragraph's conclusion, because both rows refuse the request, so a reader checking whether the *argument* held would pass it without ever checking whether the row number was right. What caught it was something trying to build from it, which is the same mechanism as every other entry in this section.

This entry is one turn of that same screw. It was first committed defining row 6 as the row with no Expiry_Record, which is row 7's definition — an entry about a misnamed row, misnaming a row. It was caught the way the original was: by reading the design's seven-row table line by line against the prose, rather than reading the prose and finding it plausible.

### Seventeen assertions asserted nothing, and the explanation of why that was harmless was the inverse of the truth

The most useful entry here, and the least flattering. The defect in the tests is ordinary. What followed it was not: a confidently stated, unmeasured, and exactly inverted account of a library's behaviour, offered as reassurance that nothing needed doing.

**What the two halves said.** Four test files asserted that secrets never appear in responses, in this form:

```php
expect($body)->not->toContain($secret, 'the response leaks the raw token');
```

Read as English that says the body does not contain the secret, with a sentence explaining what a failure means. Pest's `toContain()` takes no message argument. Its signature is `toContain(mixed ...$needles)`, so the explanation was not a message — it was a second needle.

**Why they cannot both hold.** Measured against the Pest source: in `vendor/pestphp/pest/src/Mixins/Expectation.php` the positive form loops the needles and calls `assertStringContainsString` on each with no message argument, so it passes only if **all** of them are present, and in `vendor/pestphp/pest/src/Expectations/OppositeExpectation.php` `not` passes whenever the positive form throws anywhere. So `not->toContain($secret, $message)` means "at least one of these two strings is absent". The message sentence never appears in a JSON payload. **The assertion therefore passed unconditionally, whether or not the secret leaked.** Seventeen assertions of this shape, across four files, were the evidence for the two requirements that most needed evidence: Requirement 8.7, that no Player_Token value appears anywhere, and Requirement 3.10, that no game state appears in a rejection.

Before that measurement the orchestrator had told the human the opposite — that the negated form asserts both strings are absent, therefore stricter than intended, therefore incapable of a false pass, therefore a legibility wart to tidy at leisure. Stated as fact, twice, without measuring anything. It was the exact inverse of what the library does.

**How it was handled, and by whom.** A sub-agent implementing task 5.4 noticed the variadic signature in passing, used `str_contains()` in the tests it was writing, and flagged the existing ones. The misdiagnosis above is what nearly closed the matter there. What reopened it was not a code review. The human asked why the falsification step — break the code, watch the test fail, restore it — had drifted out of the process, and asked for the outstanding items to be cleared. Measuring the claim was a consequence of restoring that habit.

All seventeen were then converted and each falsified individually: break the specific thing the assertion guards, observe the failure, confirm the intended message is visible, restore the break. An independent verifier that had not written the change produced the decisive demonstration — it left a real raw-token leak in `PlayerTokens::issue()`, reverted **only** the test file to its previous form, and ran it. The test passed. A live credential leak, and the assertion written to catch exactly that did not notice.

The conversion is `56a00d8`, which also added four non-empty-haystack guards to `GameResolverTest`, one per rendering of a rejection — an empty haystack contains nothing, so those four searches would otherwise have passed without looking at anything.

**What makes this worth an entry.** Four things, and the first is the least comfortable.

**Two AI failures, of different kinds, and the second was worse.** Writing the fragile assertion is an ordinary mistake of the sort a suite exists to absorb. Explaining it away with a confident, unmeasured, inverted account of the library was the failure that nearly ended the matter — and the wrong diagnosis was *reassuring*, which is precisely what made it dangerous. Had it been accepted, seventeen vacuous assertions would have stayed in a suite whose entire claim is that it tests what it says it tests.

**What caught it was a question about method, not about code.** Nobody spotted the semantics, the human included. The human noticed that a *habit* had lapsed — that sub-agents had stopped breaking code to confirm a test could fail — and asked why. That recovered a defect no amount of reading the assertions would have found, because they read perfectly well; the sentence in the second argument even makes them read better than the correct form does.

**Why a green suite was not evidence.** Every one of these seventeen assertions had been green from the day it was written, which is exactly what a vacuous assertion looks like. There is no way to distinguish a test that passes because the system is correct from one that passes because it asks nothing, except by making the system wrong and checking that the test objects.

**The process change that followed**, kept as a standing rule rather than a one-off. For each new test: break the specific thing it guards, watch it fail, restore. Then a second agent that did not write the change re-verifies independently, re-deriving some of the falsifications itself. The verifier is a different agent for a stated reason — the author of a test is the worst available judge of whether it can fail, having already decided what it means.

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

## Requirements narrowed rather than met

Two acceptance criteria were amended rather than satisfied, and they belong here as scoping decisions rather than among the corrections above.

### The opponent-idle indication now begins only once a Move has been accepted

Requirement 9.4 asked the Web_Client to indicate that an opponent may have stopped playing when a Game is `active`, the Mark_To_Move is not the viewer's, and no Move has been accepted for at least 60 seconds. Read literally, that is satisfiable on an empty Board: nobody has moved, so "no Move for 60 seconds" becomes true a minute after the join.

**It was surfaced by implementing it rather than by reading it.** Task 6.5's brief was to build the hook, and the sub-agent reported the case rather than resolving it: `lastMoveAt` is the most recent Move's timestamp and is null for an empty Move_List, so the elapsed time is not merely unknown but *unrepresentable* client-side — the only other origin, when the Game became `active`, is deliberately not part of the representation. The hook therefore stayed quiet, which matched the task text but left the criterion arguably unmet.

The ruling was to narrow the criterion: it now carries an "at least one Move has been accepted" clause, and the consequence is stated as a known limitation under a new Requirement 12.13, which task 15.1's README must carry. What the amendment buys is that the criterion now says what the implementation does, and says it for a reason a reviewer can check.

**The alternative was available and was not taken.** Adding a "became active at" timestamp to the representation would have satisfied the original wording, at the cost of a new column or a derived value, a new prop, a server change, and a second clock for the client to reason about — for a warning that a Joiner staring at an empty board they cannot play does not especially need. Requirement 9.3's "waiting for your opponent" indication shows throughout either way, so the Joiner is never left with a blank banner; what they lose is the escalation after a minute.

**Note the shape it shares with an entry above.** "An idle indication that fired on your own turn" records the same vacuity caught on the *other* screen: an early draft told the Creator their opponent may have stopped playing while the Application was in fact waiting on the Creator's own first Move. Closing that one added the Mark_To_Move clause; closing this one added the accepted-Move clause. The same defect had two faces and needed both.

### The AI-direction record comprises two components rather than three

Requirement 12.6 originally asked this documentation to comprise three things: the spec documents, the significant prompts issued, and the corrections made to the generated output. The prompts component was dropped against the brief's "no more than a few hours" budget, and the criterion now names only the two components actually delivered.

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
