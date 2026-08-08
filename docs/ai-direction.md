# How this project was directed, and where the generated output was wrong

This is the record Requirement 12.6 asks the repository to contain: the spec documents, and the corrections made to the generated output. Requirement 12.8's obligation sits on the README rather than here — it asks the README to state which tooling was used, for which parts, and to identify where the output was corrected or rejected. This file is the substance that statement points at.

It records how AI tooling was used to build Remote Tic-Tac-Toe, and — more usefully — the places where its output was wrong and had to be corrected. It is written as work proceeds rather than reconstructed at the end, because the detail that makes a correction log worth reading is the first thing lost to memory.

## Contents

- [How the tooling was used](#how-the-tooling-was-used)
- [Corrections](#corrections) — the spec-stage defects
  - [The spec-stage defects at a glance](#the-spec-stage-defects-at-a-glance) — summary table
  - [Which of these implicate the generated output, and which do not](#which-of-these-implicate-the-generated-output-and-which-do-not)
  - [Confidently wrong claims about framework behaviour](#confidently-wrong-claims-about-framework-behaviour)
  - [Self-contradictions within a single document](#self-contradictions-within-a-single-document)
  - [Requirements that specified impossible or vacuous behaviour](#requirements-that-specified-impossible-or-vacuous-behaviour)
  - [Gaps found by asking what was missing rather than what was wrong](#gaps-found-by-asking-what-was-missing-rather-than-what-was-wrong)
  - [Precision errors](#precision-errors)
- [Inconsistencies the implementation surfaced](#inconsistencies-the-implementation-surfaced) — found by building, not by reading
- [Decisions recorded rather than corrected](#decisions-recorded-rather-than-corrected)
- [Requirements narrowed rather than met](#requirements-narrowed-rather-than-met) — scoping decisions, not errors
- [Verification items settled on first run](#verification-items-settled-on-first-run) — the dependency and convention checks
- [Evidence rather than assertion, for the one part that had to be right](#evidence-rather-than-assertion-for-the-one-part-that-had-to-be-right)

## How the tooling was used

**The tool was Claude Opus 5, used through the AI-assisted spec-driven workflow in the Kiro IDE**, with sub-agents dispatched per task from a written implementation plan. It produced first drafts of every artefact here that is not Laravel's own scaffolding. Which parts, specifically:

| Part of the work | How the tooling was used | What the human did |
| --- | --- | --- |
| Specification | Generated `requirements.md` in EARS form, `design.md`, and `tasks.md`, each revised across several review passes | Set the brief, read every criterion, ruled on each defect and amendment recorded below |
| Implementation | Wrote all application code — domain layer, services, HTTP layer, Inertia client, migrations | Directed task order, refused workarounds, ruled where two documents conflicted |
| Tests | Wrote the unit, feature, property-based, architecture and browser suites | Restored the falsification habit that caught the vacuous-assertion defect |
| Infrastructure | Wrote the Dockerfile, `compose.yaml`, the Caddyfile and the CI workflow | Provisioned the instance and hostname, played the game that found the rate-limiter defect |
| Documentation | Wrote this file, and the decision records and README alongside it in the same group of tasks | Required that corrections be recorded at their true size, its own tool's included |

The workflow's value was not that the first draft was good. It was that a written specification is reviewable in a way that a half-built application is not, and almost every defect listed below was found by reading a document rather than by debugging code.

The exception is recorded under "Inconsistencies the implementation surfaced", and it is instructive for the opposite reason: some incompatibilities are invisible to review because neither half is wrong on its own. They only appear when something tries to satisfy both at once.

## Corrections

Grouped by the kind of failure, because the kinds are more instructive than the count.

One thing to note before the entries. The spec-stage corrections were all made *before* the specification was first committed, so `git log` on `requirements.md` shows the document arriving already corrected and none of the fixes below appears as a diff. There is nothing in version control to point at. That is exactly why this file exists, and it is also why a criterion number quoted for a pre-amendment draft — Requirement 13's in particular — need not line up with the numbering in the committed document.

### The spec-stage defects at a glance

Every row was caught by reading a document, before any code depended on it. The prose entries that follow give each one its detail; this table is for orientation.

| What the spec claimed | What was true | How it was caught |
| --- | --- | --- |
| CSRF protection forces a session into existence, so the IP-keyed rate-limit branch is unreachable | Laravel 13 accepts a `Sec-Fetch-Site: same-origin` request without touching the session | Read the middleware source |
| One configuration flag would close the resulting Requirement 10.9 gap, and a test could assert it | No such flag exists; the same-origin bypass is unconditional, and the middleware short-circuits in tests | Read the same source properly, after the first correction was itself wrong |
| A CHECK constraint required `x_token_hash IS NOT NULL` on a rematch | The rematch flow four paragraphs above inserted both token slots null | Checked the DDL against the flow |
| Property 10 held sequence contiguity "because each is a persisted constraint" | The schema section had already established contiguity is not schema-enforced | Read the property against the section it cited |
| Requirement 12's record must comprise three components, prompts included | Two were deliverable in the stated time budget; recorded as a scoping decision, not an error | Costed against the brief's few-hours budget |
| A rematch issues Player_Tokens to both players at creation | Only the requester's session is in the request; the absent player's browser cannot be written to | Asked who holds the session |
| Clients stop polling when a game is terminal with no rematch | True at the instant it is evaluated, so both clients stopped and neither could discover a rematch | Traced the condition at the moment it fires |
| One criterion deleted a Game as it expired; the next gave a command to delete expired Games | The command's working set was permanently empty | Read two adjacent criteria together |
| Concurrent moves at one Sequence_Index: exactly one is accepted | Said nothing about authorisation, so it obliged accepting one of two unauthenticated requests | Read it against Requirements 3 and 4 |
| Concurrent joins | Undefined; two visitors on one code could both be assigned the same mark | Asked what was missing, not what was wrong |
| Idle indication after 60 seconds with no move | Vacuously true on an empty board, so it fired on the viewer's own turn | Reasoned about an empty Move_List |
| A test suite | No criterion required one to exist; README and CI criteria were satisfiable by a command that ran nothing | Asked what was missing |
| Reverse-proxy deployment | `TrustProxies` never configured, collapsing a per-visitor rate limit into a global one | Asked what the deployment needed |
| "The completed winning line" | A single move can complete two lines at once, and the position is legally reachable | Reasoned about the exhaustive walk |
| 549,946 board positions | 549,946 move sequences; positions number 5,478 | Checked the noun against the figure |
| Volume named `caddy-data` in two Compose files shares one volume | Compose namespaces by project, so it would have been two volumes and two certificate issuances | Read the Compose semantics |

### Which of these implicate the generated output, and which do not

Worth separating, because a correction log that only catalogues other people's errors does not demonstrate reviewing AI output, which is what Requirement 12.8 asks for.

Most rows above are defects in a specification that the tooling itself drafted, so the whole table is in that sense self-implicating. But three entries go further, because in each the generated *reasoning* was confident, specific, and wrong — offered as settled fact rather than as a draft:

1. **The CHECK constraint** requiring a token slot that per-request rematch minting leaves null. The DDL and the flow were generated into the same document, four paragraphs apart, and the constraint was justified as protecting an invariant the flow made impossible.
2. **The Property 10 contradiction**, which asserted a persisted constraint that the same document had already established does not exist — and asserted it in a *correctness property*, the part of the design a reviewer trusts most.
3. **The CSRF inversion**, and the two further wrong claims built on top of it. This is the worst of the three: framework behaviour was asserted from memory three times, and each correction was itself partly wrong until the vendor source was read.

Two later entries have the same shape and are recorded in their own sections because they were found by building rather than reading: the [inverted account of Pest's `toContain()`](#seventeen-assertions-asserted-nothing-and-the-explanation-of-why-that-was-harmless-was-the-inverse-of-the-truth), which was *reassuring* and nearly closed a real defect, and the [74-bit UUIDv7 figure](#a-uuidv7-does-not-carry-74-random-bits-when-you-generate-two-in-the-same-millisecond) that was right in general and wrong where it was quoted.

### Confidently wrong claims about framework behaviour

The most persistent failure mode, and the one worth taking seriously. In each case the assertion was stated as fact, without qualification, and was wrong. Only reading the vendor source settled it.

**Laravel 13's forgery protection, three times in sequence.**

1. Claimed that CSRF protection forces a session into existence, and used that to argue the IP-keyed branch of the rate-limit subject was unreachable in practice. Wrong. Laravel 13's `PreventRequestForgery` accepts a `Sec-Fetch-Site: same-origin` request without touching the session at all, so no session is guaranteed.
2. Having been corrected, claimed the resulting requirement gap could be closed by "one configuration line" disabling a same-site flag. Also wrong. `PreventRequestForgery::$allowSameSite` already defaults to `false`, and it governs the broader `same-site` case. The `same-origin` bypass at the top of `hasValidOrigin()` is unconditional and no flag disables it.
3. On top of that, added a test asserting the state of the non-existent flag. Unreachable regardless: `handle()` calls `runningUnitTests()` before anything else, so the middleware short-circuits entirely in the test environment.

Resolved by reading `vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php`. `handle()` proceeds when any of five conditions holds, in order — read verb, running unit tests, route excepted, `hasValidOrigin()`, `tokensMatch()` — and `hasValidOrigin()` returns `true` on the first line of its body for `Sec-Fetch-Site: same-origin`, with nothing guarding it. Re-verified against the vendor source while completing this record; unchanged at `laravel/framework` v13.24.0.

**The outcome was to amend Requirement 10.9** rather than subclass the middleware. The criterion now reads that the Game_Service verifies the origin of a state-changing request and, *where that origin cannot be established as same-origin*, additionally requires a valid forgery token — origin verification first, token verification as the fallback, which is what the framework does. The rejected alternative was a subclass overriding `hasValidOrigin()` to force the token path on every request. The framework's origin check is set by the browser and cannot be forged cross-site, so it defends the case CSRF tokens exist to defend without depending on a secret threaded correctly through every form; overriding a deliberate framework security design to make an earlier draft's wording come out true was the weaker option. No application configuration follows from the amendment, which is why tasks 9.1 and 9.5 were reduced rather than expanded.

The lesson is narrow and worth stating plainly: framework behaviour was asserted from memory three times and was wrong three times, and each correction was itself partly wrong until the source was read.

**Inertia's `usePoll`, twice in one paragraph of the design.** Both halves of the design's polling block described the library doing something it does not do, and both were found the same way: by reading `node_modules` while implementing task 6.4 rather than by reviewing the paragraph again.

1. The illustrative snippet computed an interval from `game.state` and passed it to a single `usePoll`, as though changing the argument would change the rate. It does not. `usePoll`'s effect has an empty dependency array, and `Poll` assigns `this.interval` in its constructor and arms `setInterval(…, this.interval)` in `start()`, with no path that reassigns it — so the interval is pinned to first render. A page first rendered in a Terminal_State would poll at 5000 ms and, if its props later described a live Game, would sit outside Requirement 8.1's 2-second ceiling. The hook declares both polls and runs exactly one; the design's snippet now shows that form, so a later reader does not "simplify" it back into the defect.
2. The same paragraph justified leaving `keepAlive` at its default with "a hidden tab does not poll". The default sets a *throttle*: `isInBackground()` derives the flag from `document.hidden`, and `tick()` fires when `!this.throttle || this.cbCount % 10 === 0`, so a hidden tab still polls every tenth tick — one request per 20 s while live, per 50 s while terminal.

The second is the more interesting of the two, because the decision it was defending was correct and only the reason was false. Nothing in the implementation changes: `keepAlive` is still not passed. What changes is that the design now states the rate a forgotten tab actually costs instead of implying zero, which is the difference between a decision a reviewer can check and one they have to trust.

### Self-contradictions within a single document

Defects that survived generation because each half was locally plausible.

**A CHECK constraint contradicting the flow four paragraphs above it.** One of the generated `games` CHECKs required `x_token_hash IS NOT NULL` on any row with a `rematch_of_game_id`, on the reasoning that a Rematch always has a Creator. The rematch flow in the same design inserted a Rematch with *both* token slots null and minted each Player's token when that Player's session next presented a valid token for the preceding Game — the per-request scheme recorded in ADR-010, adopted precisely because a Rematch cannot write into the absent Player's browser. So the very first insert of a Rematch would have failed on the constraint, and rematch would have been broken outright rather than subtly.

The mark swap makes it worse than a missing default: Requirement 7.3 gives `X` to whoever held `O`, so the first requester may fill `o_token_hash` and leave `x_token_hash` null indefinitely. There is no ordering under which the constraint holds. Caught by checking the DDL against the flow rather than reading either alone, and the removal is recorded in task 4.1 with an explicit instruction not to reintroduce it — a constraint that reads as an obvious invariant is the kind a later reader adds back.

**A correctness property contradicting the schema section.** Property 10 claimed sequence-index contiguity held "against direct writes, because each is a persisted constraint" — after the schema section of the same design had established that contiguity is *not* schema-enforced, since a `moves` table holding sequence indices 0, 1, 2, 4, 5 satisfies both unique indexes and both range CHECKs. The schema section had been corrected in an earlier pass; the property still asserted the pre-correction position.

What makes it worth its own entry rather than a footnote is where it sat. A correctness property is the part of a design a reviewer reads to find out what is guaranteed, and this one named a guarantee that nothing enforced. Had it reached the test suite unamended it would have produced either an untestable assertion or a green one that proved nothing, which is the same failure mode as the seventeen vacuous assertions recorded further down.

**Volume sharing that would not have shared anything.** The plan had a placeholder container and the real stack mount "the same named `caddy-data` volume" to carry a TLS certificate across. Docker Compose namespaces volumes by project name, so the two invocation directories would have produced `deploy_caddy-data` and `tic-tac-toe_caddy-data` — two distinct volumes, a second certificate issuance against a rate limit shared with strangers, and a mitigation that looked correct while doing nothing. Fixed by making the volume external with a fixed name.

Group 13 has since shipped, and the fix held: `compose.yaml` declares `caddy-data` with `external: true` and `name: caddy-data`, alongside a project-scoped `sqlite-data` that deliberately does not. The two declarations sitting adjacent, with a comment saying why they differ, is what makes the distinction survive the next reader.

### Requirements that specified impossible or vacuous behaviour

**Rematch token issuance no implementation could satisfy.** The requirement had the Game_Service issue Player_Tokens to *both* Players at the moment a Rematch is created. Only the requesting Player's session is present in that request. There is no mechanism — none exists in HTTP — by which a response to one browser writes a session value into another, and the design has no accounts, no push channel and no server-side identity to attach a pending credential to. The criterion was not merely hard; nothing could have satisfied it.

Rewritten as Requirement 7.6: each Player's token for the Rematch is minted at the time *that* Player's session next presents a valid Player_Token for the preceding Game. Continuity of identity across a Rematch then rests solely on the token held for the preceding Game, which is Requirement 7.7, and the two criteria together are what ADR-010 records.

**A polling stop condition that was always true.** Clients stopped polling when a Game reached a Terminal_State and no Rematch was associated with it. At the moment a Game becomes terminal no Rematch can exist — a Rematch is created by a later request — so the condition was true for both clients the instant it began to be evaluated, both stopped, and neither could ever discover a Rematch the other created. This broke the one behaviour the brief names explicitly, and it would have broken it silently: each screen would simply have sat on a finished board.

The fix separates the two things the draft had conflated. Requirement 8.5 keeps terminal-with-no-Rematch as a *slower polling rate*, 5 seconds rather than 2, and Requirement 8.6 makes stopping conditional on something that actually terminates the interest — a Rematch having been discovered, or the viewer navigating away.

**A retention command with nothing to find.** One criterion deleted a Game at the moment it was marked expired; the next required a command that deletes every expired Game. The command's working set was therefore permanently empty, and a test of it would have passed against an implementation that did nothing. Restructured around a single actor and a single transition: eligibility is a state a Game is *treated as* being in (Requirements 13.1 and 13.2), deletion happens only in the command (13.3), and 13.5 says explicitly that the elapsed times are lower bounds on retention rather than times of deletion. The criterion numbers quoted in the plan for this defect are the pre-amendment ones and no longer correspond to the committed document.

**A concurrency guarantee contradicting the authorisation rules.** Requirement 5.3 said that when two concurrent move requests target the same Sequence_Index, exactly one must be accepted — and said nothing about either being authorised or valid. Read literally, two unauthenticated requests racing for the same index obliged the Game_Service to accept one of them, which contradicts the criteria in Requirements 3 and 4 that require rejecting both. It is the kind of defect that survives review because the sentence is about concurrency and the reader's attention is on the race. The criterion now opens by requiring that both requests satisfy every authorisation condition of Requirement 3 and every move-validity condition of Requirement 4 as evaluated against the state each observed, and only then guarantees that exactly one is accepted.

**An idle indication that fired on your own turn.** "No Move accepted for 60 seconds" is vacuously true on an empty Board, so the Creator was told their opponent may have stopped playing while the Application was in fact waiting for the Creator's own first Move. Closed by adding the Mark_To_Move clause to Requirement 9.4. The same vacuity had a second face on the other screen, which the implementation later surfaced and which is recorded under "Requirements narrowed rather than met" — one defect, two clauses needed.

### Gaps found by asking what was missing rather than what was wrong

**No requirement that a test suite exist.** The draft requirements specified that the README state the commands that run the automated test suites, and that CI run those suites and report failure — and nowhere required that any test be written. Both criteria were satisfiable by a README documenting a command that ran nothing and a CI job that ran it. For a brief whose stated purpose is executable evidence, the gap is the largest of the spec-stage defects by consequence, and it is invisible to a reviewer checking whether the criteria are *met*: they were.

Closed by adding the whole of Requirement 14, which names the artefacts rather than the activity — unit tests reaching the Rules_Engine without persistence, session or transport; the exhaustive enumeration with both counts asserted; feature tests for each distinct rejection outcome; the rate-limit boundary at the twentieth and twenty-first request; one end-to-end test driving two sessions; and the not-well-formed Move_List classes. Nine criteria, each falsifiable by looking in the repository.

**Concurrent joins undefined.** Concurrent *moves* were covered by two persisted uniqueness invariants on `moves`. Nothing covered two visitors submitting the same Join_Code at the same moment, and the join path is where it matters most, because both would have been assigned the mark `O` and each would have believed itself the Joiner. Closed by requiring the claim to be a conditional update whose affected-row count decides the outcome — the mechanism that later produced the `PlayerTokens` contradiction recorded below, which is a reasonable price for the invariant.

**`TrustProxies` never configured.** Behind a reverse proxy, php-fpm sees the proxy's address unless the middleware is configured to honour `X-Forwarded-For`. Without it the join rate limit of Requirement 10.6 collapses from twenty per visitor per minute into twenty per minute for the entire application — the first twenty visitors of a minute exhaust it for everyone. This would have passed every test in the suite, because the feature tests exercise the application directly rather than through a proxy, and it would have passed a manual check too, because one person testing alone never trips a limit they share with nobody.

Now `$middleware->trustProxies(at: '*')` in `bootstrap/app.php`, with the comment stating why the wildcard is safe here: port 9000 is never published, so the only thing that can reach php-fpm is Caddy in the same Compose network. The wildcard is a deliberate consequence of a deployment decision rather than a shortcut, which is the sort of thing worth writing down next to the code rather than only in a design document.

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

**What was built.** `mint()` returns a `MintedToken` and has no side effects; `remember()` is the session write alone; `issue()` survives as the composition of both, because `CreateGame` inserts a fresh row with no competing writer and therefore no losing path. `JoinGame` mints, runs its guarded statement, and calls `remember()` only on the winning branch — so no orphan credential exists because nothing wrote one, not because something cleaned one up. The design's skeleton was amended to match, including a note that task 7.1's `CreateRematch` would want the same primitives for the same reason. That prediction held: `CreateRematch::mintFor()` now mints, persists, then calls `remember()`, in that order, and its docblock states that it uses the two primitives rather than `issue()` because a session must not be told it holds a credential before the row that binds it exists.

**A smaller decision inside that one.** `mint()` returns a two-property value object rather than a raw string plus a public `hashOf()` helper. Both strings are `string` to PHP and to PHPStan, and `JoinGame` interpolates one of them into `SET o_token_hash = ?` — so a transposition would write the secret into the database, which is the exact disclosure Requirement 8.7 exists to prevent, at the exact point Requirement 3.1's binding is established, with no type checker objecting. Named properties make the mistake visible at the call site. The object's docblock is also explicit about what it does *not* protect: `readonly` prevents mutation, not disclosure, and `raw` is public, so `var_dump` or `json_encode` would print it. What protects the secret is that no instance is ever handed to a serialiser.

**Why this was surfaced at all.** Worth recording, because it is a property of how the work was directed rather than of the tooling. The standing instructions throughout were to reason about review feedback rather than accept it, to report before changing, and to say plainly when something in the spec was wrong or unimplementable rather than working around it silently. A sub-agent told to satisfy its brief would have shipped something that satisfied the brief. One told that flagging a contradiction is part of the job flagged it.

### A log record required by the design belonged to no task

Found the way the `PlayerTokens` conflict was: by building, not by reading. Each document was locally consistent and the gap only appeared when something had to satisfy all three.

The design's failure table requires a `game.invariant_violation` record on the path where the Rules_Engine returns `InvalidMoveList::Error` — 500, the Game_Id, no state change. Task 6.1 implemented that path and recorded the record as deferred, naming `GameEventLogger` "in 10.2 as its sole writer". Task 10.2's own bullets, and Requirement 10.3 behind them, enumerate exactly six events, and this is not one of them.

So the sub-agent implementing 10.2 was correct to leave it out and correct to say so. Had it split the difference — adding a seventh method quietly, or deleting the deferral comment — the record would have shipped either unrequested or unimplemented, and in both cases invisibly. It is now task 10.4, with the distinction stated in the task text: Requirement 10.3 mandates six Game lifecycle events, and this is a corruption report that the design alone asks for.

The general shape is worth naming, because a deferral is the one kind of comment that rots without anything failing. A note saying "task N owns this" is a claim about a document the writer has not re-read, and nothing checks it when task N is written to a narrower scope than the note assumed.

### The retention sweep was unimplementable, and the two constraints that blocked it were each correct

The most consequential defect found by building rather than reading, and the one that would have shipped as a silently broken cleanup job.

**What the three documents said.** The design's retention section had `SweepExpiredGames` run a transaction beginning "clear `rematch_of_game_id` on any rematch pointing at a game in the delete set", and task 11.1 repeated it. The `games` schema, in the same design and in the committed migration, carried two constraints on that column: `ON DELETE RESTRICT` on the self-reference, so a parent cannot be deleted while a rematch points at it, and `CHECK (join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL)`, the sole carrier of "every Game is reachable either by its Join_Code or as the rematch of a known Game".

**Why they cannot all hold.** A Rematch is inserted with `join_code = NULL` — it is reached by navigation, not by a code — so its `rematch_of_game_id` is the only thing satisfying the reachability CHECK. Clearing that column violates the CHECK; leaving it violates the foreign key. There is no third step. Both failures are `SQLSTATE[23000]` inside the sweep's transaction, so the whole run rolls back and **nothing is deleted at all**, not merely the affected pair.

And it is not a corner case. A Rematch's `last_activity_at` is set at creation and bumped by every Move, so it is always at or after its parent's — meaning the parent always becomes eligible first, and the window in which the sweep is broken is exactly however long the pair played the rematch. Any scheduled run landing in it fails wholesale.

**How it was handled, and by whom.** The sub-agent given task 11.1 stopped without writing a line, reported which two constraints deadlocked with file and line numbers, reproduced both failures against a throwaway database, and set out four candidate resolutions with the cost of each. It also noticed that the design's own justification for `RESTRICT` — "a missed step in the sweep surfaces as a loud constraint failure instead of silent data loss" — was now false as a diagnosis, since the violation is reachable with no step missed. That is the behaviour the standing instructions ask for, and it is worth naming: a sub-agent optimising for a green task would have found *some* way through, and every way through here is wrong.

The failure was then reproduced independently before anything was changed, because a claim that a schema is unimplementable is exactly the kind of claim that is easy to make and expensive to accept.

**The ruling, which was the human's.** Defer rather than clear: exclude from the delete set any Game whose Rematch survives, and collect it on a later run once the Rematch is eligible too, ordering each run's deletes children before parents since the reference is not `DEFERRABLE`. Requirement 13.5 licenses it directly — the thresholds are "lower bounds on retention rather than exact times of deletion" — and it needs no migration. The alternatives were rejected for stated reasons: dropping the reachability CHECK means a full SQLite table rebuild to weaken an invariant in order to make a cleanup path work, and assigning the orphaned Rematch a fresh Join_Code writes a join-shaped value onto an active Game for no user-facing reason.

The cost is that Property 17 stops being literally true as written. "The surviving Games are exactly those that are not Eligible_For_Expiry" now carries "together with those whose Rematch survives", and the property records why, along with the fact that the deferral is bounded rather than indefinite. Amending a correctness property is not free, and stating the amendment beside the property is the least bad way to pay for it.

**What makes this worth the length.** Neither constraint was wrong. The reachability CHECK is right, the `RESTRICT` is right, and the deletion step that sat between them was written without checking either — three documents each locally consistent, jointly describing an operation the database refuses. Nothing in reviewing any one of them would have shown it, and no test existed to fail, because the code that would have failed had not been written yet.

### The client's handling of a rate-limited request was described, and never built

Found by task 12.1, which had to observe what each of the eleven rejections actually produces and so could not accept the design's account of this one.

The design said, of the two rejections that come from framework middleware, that `rate_limited` and the 419 forgery rejection "are surfaced by the client's Inertia error handling", and its error table said the client "renders a 'too many requests' message". Neither is true. `resources/js` contains no `onError`, no `router.on` and no status branch anywhere, so a 429 and a 419 both fall through to Inertia's default and the player sees nothing. `resources/js/lib/outcomes.ts` omits `rate_limited` deliberately and says so, which is the one place the repository was already honest about it.

The related fact, established by search rather than assumed: `rate_limited` has no value anywhere in the application. The string appears in no enum, no route, no config and no client module — only in prose. The rejection *is* the 429, produced by `ThrottleRequests` before any controller runs. So Requirement 14.3's "each of those rejections returns its own distinct outcome value" cannot be satisfied literally for this one, and the test observes the status instead, asserting additionally that no outcome value is flashed — the assertion that fails if the application ever gains one and this account goes stale.

**No code was written to close it, and that is the decision rather than an oversight.** No criterion asks for the client half: Requirements 10.6 and 10.7 oblige the Game_Service to reject with a rate-limited outcome, which it does, and Requirement 14.3 excludes the forgery rejection from coverage entirely. The design now states what is implemented and records the consequence — a player who trips a limiter sees a button that appears to do nothing until the window passes — and task 15.1 carries it into the README's Known Limitations beside the opponent-idle one.

What makes it worth recording is the shape, which is the same as the `keepAlive` entry above and the opposite of most of this file: the *decision* was defensible and only the *description* was false. The design asserted an implementation that a reader would have had no reason to doubt, and the sub-agent that caught it was not reviewing the paragraph — it was trying to find out what the eleventh rejection returns.

### The rate limiter took the application down mid-game, and 298 tests could not see it

The most serious defect found in this project, and the only one found by *playing the
game* rather than by reading, building or testing. It would have shipped.

**What happened.** Two browsers, a real game against the containerised build. O won, and
at that moment X's screen turned into a 500. Nothing had been clicked. The game itself was
fine — `state=won`, `winning_mark=o`, six moves, the Version_Counter where it should be.

**The chain.** `.env.example` shipped `CACHE_STORE=database`, which is also Laravel's own
default, so the rate limiters' counters lived in the `cache` table of the same SQLite file
as the games and the sessions. Every request passes a `throttle:` middleware, and
`Illuminate\Cache\DatabaseStore::incrementOrDecrement()` performs the increment as a
SELECT followed by an UPDATE inside one transaction — with `lockForUpdate()` a no-op,
because SQLite has no `FOR UPDATE`. Polling is the design's transport (ADR-001) at 2000 ms
per player, so two clients issue overlapping increments continuously. One reads the
counter, the other commits first, and the first's UPDATE now holds a stale snapshot.

**The part that is genuinely counter-intuitive, and why the existing settings did not
save it.** WAL was on and a busy timeout was set — both verified inside the running
container, not assumed. Neither applies. SQLite returns SQLITE_BUSY for a stale-snapshot
upgrade *immediately*, because waiting cannot help: the transaction has to roll back and
be retried, and no amount of timeout changes that. Laravel does not retry. So the
exception surfaced as a 500 on a polling request, to the player who was not even acting.

This is the same class of error as the entries above, in a new place: `config/database.php`
sets `busy_timeout` and the design's error table reasons about it at length, and both
quietly assume that a busy timeout covers SQLITE_BUSY. It covers one kind and not this
one.

**A correction to this entry, and it is the orchestrator's own.** The paragraph above first
said `busy_timeout` "was 60 s — both verified inside the running container, not assumed",
and the figure was wrong. The sub-agent writing the decision records found it while drafting
ADR-004, because four other sources say 5 s: `config/database.php` sets
`env('DB_BUSY_TIMEOUT', 5000)` with no override anywhere in `compose.yaml`, `deploy/` or
`.env.example`; `HealthController`'s docblock says 5 s; `SqliteConnectionSettingsTest`
asserts exactly `5000`; and the design's ADR-004 says 5 s.

Measured against the deployed container to settle it, and both numbers turn out to be real:

```
PRAGMA busy_timeout   on a raw PDO connection   -> 60000
artisan db:show       Laravel's own connection  -> busy_timeout 5000, WAL, NORMAL
```

The 60,000 is PDO's own default — `PDO::ATTR_TIMEOUT` defaults to 60 seconds and
`pdo_sqlite` maps it onto `busy_timeout` — so the original "verification" opened its own
connection and measured the driver's default rather than the application's setting. **These
PRAGMAs are per-connection**, which is the same footgun ADR-004 names for foreign keys, and
the same connection confirms it: `PRAGMA foreign_keys` reports `0` there while Laravel's
connection has them on.

The substance of the entry is untouched — at 5 s or 60 s, a busy timeout does not apply to a
transaction that must roll back. But the claim was presented as a measurement, and it was a
measurement of the wrong thing. It is recorded here rather than silently edited because a
file about reviewing generated output has no business quietly fixing its own errors, and
because the lesson generalises: a probe that opens its own connection is not observing the
application's connection.

**Why the suite was blind to it, which is the more useful half of this entry.**
`phpunit.xml` sets `CACHE_STORE=array` and `SESSION_DRIVER=array`. So every one of the 298
tests — `RateLimitTest` included, which drives the limiter to its exact twentieth and
twenty-first request — exercises an in-memory counter and never touches the database cache
store at all. And the concurrency coverage Requirement 14.9 mandates is *sequential by
instruction*: two calls over one snapshot, no parallelism, no sleeps. That was the right
call for what it tests, and it means nothing in the suite ever issues two genuinely
concurrent HTTP requests. The defect sat in the intersection of two deliberate testing
decisions, each defensible alone.

**Measured, not argued.** 30 concurrent requests against the deployed images: 13 returned
500. After changing one environment variable: 0 at 30 concurrent, 0 at 60. The limiter was
then checked separately to confirm the fix had not simply disabled it — roughly 120
requests allowed and the rest refused with 429, which is the configured window.

**The tradeoff is stated rather than hidden.** `FileStore::increment` is an unlocked
read-then-write, so heavy concurrency can lose an increment and leave the limiter
marginally permissive; its write is `LOCK_EX` atomic, so nothing corrupts. That is the
right failure to prefer — one extra request admitted, rather than an error page for a
player whose turn it was. `array` was rejected outright: it is per-request, so nothing
accumulates and rate limiting would stop existing while appearing configured. A separate
SQLite file for the cache was rejected too, because the race is between concurrent
requests on the same counter and moving the file does not change it.

**What it says about the process.** Every layer of verification in this project passed:
level-8 static analysis, 8,394 assertions, an exhaustive 549,946-node walk, a browser test
driving two isolated sessions to a win. None of them could see this, because it needs two
clients acting at once against the real storage engine. The thing that found it was one
person playing one game and noticing a screen go blank.

### The 30-day purge boundary was specified three ways

Found in the same investigation, and much smaller, but recorded because the disagreement was between a requirement, the design and a committed code comment.

Requirement 13.4 retains an Expiry_Record "for at least 30 days ... and SHALL delete that Expiry_Record thereafter". The design said records are "deleted once older than 30 days" — strict, so one exactly 30 days old survives. The `expiry_records` migration comment said the sweep's closing statement is `DELETE FROM expiry_records WHERE deleted_at <= :thirty_days_ago` — inclusive, so one exactly 30 days old is deleted.

The requirement settles it: at exactly 30 days the record has been retained for at least 30 days, and "thereafter" is strictly after, so the comparison is strict and the migration comment was the wrong one. Corrected there rather than in the design.

The reason it is worth a note at all is the asymmetry it exposes, which is now stated in both places instead of left to be inferred: the two *eligibility* thresholds are inclusive, because Requirements 13.1 and 13.2 fire *when* an elapsed time is reached, while the *purge* is exclusive, because 13.4 grants a minimum retention. Two boundaries in one transaction, deliberately of opposite polarity, and previously nothing said so.

### A comment was specified about a rate limiter that the endpoint does not have

Task 13.2 asked for a Compose healthcheck on `/health` and for "a comment that it resolves to a different limiter key than a browser request". The comment would have been false.

`GET /health` carries no middleware at all. It is registered in the `then` callback of `withRouting()` in `bootstrap/app.php` rather than in `routes/web.php` specifically so the `web` group cannot reach it, and no `throttle:` is attached to it, so there is no limiter and therefore no key — not a different one. The claim is the kind that survives review easily, because a healthcheck probing a socket from inside the container genuinely does present a different client address, and "different limiter key" sounds like the consequence of that.

What is actually true was written instead: the probe never passes through Caddy, so `X-Forwarded-For` is absent and `REMOTE_ADDR` is 127.0.0.1, and what the probe establishes is that php-fpm accepts connections, the framework boots, and the persistence layer answers. Corrected in the task rather than in the design, which never made the claim.

The related finding is smaller and is recorded in the task: there was no way to make the probe at all, because php-fpm speaks FastCGI and nothing else and the image had no FastCGI client. That is why `libfcgi-bin` is now in the `app` stage, and why the healthcheck was tested against a container with its database file deleted rather than only against a working one.

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

Two acceptance criteria were amended rather than satisfied. They sit here, apart from the corrections, because the distinction matters to a reader: **nothing in this section was an error.** Above, a criterion was amended because it stated something false, impossible or vacuous, and the amendment made the document correct. Here, each criterion was coherent and satisfiable, and was narrowed because meeting it in full was judged not worth its cost. That is a scoping decision, and it is recorded so a reviewer can weigh the judgement rather than discover the shortfall.

Both amendments were made in the open and both state their consequence. Requirement 10.9's amendment, recorded among the corrections above, is deliberately *not* here: it belongs there because the wording it replaced described framework behaviour that does not exist.

### The opponent-idle indication now begins only once a Move has been accepted

Requirement 9.4 asked the Web_Client to indicate that an opponent may have stopped playing when a Game is `active`, the Mark_To_Move is not the viewer's, and no Move has been accepted for at least 60 seconds. Read literally, that is satisfiable on an empty Board: nobody has moved, so "no Move for 60 seconds" becomes true a minute after the join.

**It was surfaced by implementing it rather than by reading it.** Task 6.5's brief was to build the hook, and the sub-agent reported the case rather than resolving it: `lastMoveAt` is the most recent Move's timestamp and is null for an empty Move_List, so the elapsed time is not merely unknown but *unrepresentable* client-side — the only other origin, when the Game became `active`, is deliberately not part of the representation. The hook therefore stayed quiet, which matched the task text but left the criterion arguably unmet.

The ruling was to narrow the criterion: it now carries an "at least one Move has been accepted" clause, and the consequence is stated as a known limitation under a new Requirement 12.13, which task 15.1's README must carry. What the amendment buys is that the criterion now says what the implementation does, and says it for a reason a reviewer can check.

**The alternative was available and was not taken.** Adding a "became active at" timestamp to the representation would have satisfied the original wording, at the cost of a new column or a derived value, a new prop, a server change, and a second clock for the client to reason about — for a warning that a Joiner staring at an empty board they cannot play does not especially need. Requirement 9.3's "waiting for your opponent" indication shows throughout either way, so the Joiner is never left with a blank banner; what they lose is the escalation after a minute.

**Note the shape it shares with an entry above.** "An idle indication that fired on your own turn" records the same vacuity caught on the *other* screen: an early draft told the Creator their opponent may have stopped playing while the Application was in fact waiting on the Creator's own first Move. Closing that one added the Mark_To_Move clause; closing this one added the accepted-Move clause. The same defect had two faces and needed both.

### The AI-direction record comprises two components rather than three

Requirement 12.6 originally asked this documentation to comprise three things: the spec documents, the significant prompts issued, and the corrections made to the generated output. The prompts component was dropped against the brief's "no more than a few hours" budget, and the criterion now names only the two components actually delivered.

The reasoning is the part worth keeping. An unmet criterion that a reviewer can find by reading your own specification is worse than a narrower criterion honestly stated. The choice was between satisfying it cheaply and amending it openly; leaving it quietly unsatisfied was not one of the options.

**What was dropped and what was not.** A prompt log is the component whose cost scales with the length of the work and whose value to a reviewer is the lowest of the three: it records what was asked, not what was wrong, and the second is the thing a reviewer cannot reconstruct for themselves. The two components kept are the ones that carry the evidence — the spec documents, committed in full, and the corrections, which are this file. Requirement 12.8 is untouched and still in force: it obliges the README to state which tooling was used, for which parts of the work, and to identify where the generated output was corrected or rejected. This file supplies all three, and the entries where the generated *reasoning* was wrong — flagged near the top and detailed in the sections above — are what make it evidence rather than an inventory.

**This is the one amendment in this file that narrows a criterion about the record itself**, which is worth naming, because a documentation criterion is the easiest kind to quietly reinterpret. It is recorded as a scoping decision, not as a correction of an error: nothing about the three-component wording was false or impossible. It was simply more than the time budget bought.

## Verification items settled on first run

Recorded here so a reader can see which strategy the project actually uses rather than which one was planned. Of the six items, three were named in the design as verify-on-first-run risks that could force a fallback — Larastan, Eris, the node-count convention — and a fourth, the enumeration runtime, had four mitigations staged against it in advance. Naming them before starting is what made each one a short check rather than a mid-build surprise.

**Every version below was re-read out of `composer.lock` and `php -v` while completing this record, rather than carried across from the plan.** They agree with what task 1.2 recorded.

| Item | Recorded risk | Outcome | Fallback needed |
| --- | --- | --- | --- |
| Larastan against Laravel 13 | Might not resolve | `larastan/larastan` v3.10.0, `phpstan/phpstan` 2.2.8 | No |
| Eris on PHP 8.5 | Might resolve but not run | `giorgiosironi/eris` 1.1.0, running on PHP 8.5.9 | No |
| Pest 4 | — | `pestphp/pest` v4.7.8, Laravel and browser plugins | — |
| Requirement 10.9 | Might need a middleware subclass | Criterion amended; no configuration follows | Not applicable |
| Enumeration node count | Convention ambiguous | Root counts; 549,946 | Not applicable |
| Enumeration runtime | Might overrun the CI budget | About 5 s against a 60 s budget | No |

- **Larastan against Laravel 13** — resolved. Larastan v3.10.0 installs cleanly against `laravel/framework` v13.24.0 and brings `phpstan/phpstan` 2.2.8 with it. The recorded fallback (plain PHPStan over `App\Domain\TicTacToe` only) was not needed, so the project runs Larastan at level 8 over `app/`, `database/` and `tests/` plus level `max` over the domain namespace.
- **Eris on PHP 8.5** — resolved. Eris 1.1.0 installs and its generators run under PHP 8.5.9, verified by constructing a `ChooseGenerator` and drawing a value rather than by trusting Composer's resolution. The hand-picked-dataset fallback was not needed. Worth distinguishing the two checks: Composer resolving a package says only that its constraints are satisfiable, not that its code runs on the interpreter in use. `composer.json` requires `php: ^8.5` and `composer.lock` records that platform requirement, so there is no second interpreter for the check to have been run against.
- **Pest 4** — resolved. Pest v4.7.8, with the Laravel and browser plugins. The browser plugin is what makes Requirement 14.5 satisfiable without adding Dusk.
- **Requirement 10.9 against `PreventRequestForgery`** — resolved by amending the criterion, which is the outcome task 1.2 records as taken (its outcome 3) of those left open when the question was raised. The criterion now describes origin verification with token verification as the fallback, matching what the middleware does; the rejected alternative was a subclass overriding `hasValidOrigin()` to force the token path on every request. No application configuration follows, and the full account, including the two further wrong claims made along the way, is under "Confidently wrong claims about framework behaviour" above.
- **The enumeration node count convention** — resolved, and earlier than planned. It was recorded as open until task 3.6, but verifying task 3.2's rules engine meant walking the tree anyway, so the answer arrived with the engine rather than with the test that formalises it. **The convention adopted is that the root counts**: the node counter increments on entry to each node, the empty Move_List included, which gives exactly 549,946. A run reporting 549,945 has skipped the root — a harness bug with a known fix, not a rules defect and not a convention left open. Task 3.6 now states this rather than asking the implementer to choose, because the open version invited whoever got the right answer to go hunting for a discrepancy that does not exist.

  **The terminal count, 255,168, has no such latitude and the task says so.** The node count depends on a counting convention because "how many nodes are in this tree" is a question about the walk; whether a position is terminal is a question about the position, and the empty Move_List is unambiguously not terminal, so no convention can move the figure. A mismatch there means the engine and the independently written oracle disagree with the accepted combinatorial result, and the instruction is to stop and debug rather than adjust the expectation. Both counts are asserted in Requirement 14.2 itself, which is what makes them ground truth rather than a restatement of the implementation's own opinion.
- **The enumeration runtime** — measured, and the flagged risk did not materialise. The design named the exhaustive walk as the most likely thing to overrun the CI budget and staged four mitigations against a 60-second limit. The full walk, engine plus an independently written oracle in plain PHP with no optimisation, took about five seconds. The mitigations stay on record as contingency rather than being deleted, because naming them in advance is what made this a five-second check instead of a mid-build surprise.

## Evidence rather than assertion, for the one part that had to be right

This belongs here as the opposite case to everything above: not a claim that was wrong, but a claim that was checked hard.

The rules engine is the one component where a defect is both plausible and quiet. A storage bug loses a game. A rules bug tells a player they lost a game they won, and nothing else in the system objects.

So it is not verified by assertion. Every reachable position — all 549,946 move sequences, 255,168 of them terminal — is walked, and at each one the engine's verdict is compared against a **separately written** win oracle. The engine is never its own judge: had the walk asked the engine when to stop recursing, the properties would be tautologies and the exercise worthless.

Both counts are externally known combinatorial facts about tic-tac-toe, which is what makes them ground truth rather than a restatement of the implementation's own opinion. They matched exactly, with no disagreement on terminality, winning-line sets, move counts, mark-to-move or winner at any node.

One consequence worth recording for whoever writes the tests. Guard *ordering* inside the engine is deliberately not observable through its return value, because Requirement 11.5 mandates one uniform, detail-free rejection: a move list breaking two rules at once returns identical output whichever guard fired. The reachable property is "all five violation classes collapse to the same value", which is what the requirement actually asks for. An ordering assertion would have to reach inside the implementation to exist, and should not be written.
