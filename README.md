# Remote Tic-Tac-Toe

Two-player tic-tac-toe across two browsers: one player creates a game, shares a join link, and
the two boards stay in step without either player refreshing. No accounts, no sign-up, no email.

**Hosted instance: <https://18-175-88-107.sslip.io/>**

[![CI](https://github.com/mirkovicUK/TIC-TAC-TOE/actions/workflows/ci.yml/badge.svg)](https://github.com/mirkovicUK/TIC-TAC-TOE/actions/workflows/ci.yml)

## What this is

A Laravel 13 application with an Inertia and React client, built to a written specification.
The game rules live in a framework-free domain layer that derives the board, whose turn it is
and the outcome from nothing but the list of moves — no database, no session, no HTTP. That
purity is what makes the exhaustive test affordable: the suite walks all 549,946 reachable move
sequences and asserts the 255,168 terminal ones.

Identity is a per-game token held in the server-side session, not a user account. State
synchronisation is polling — 2 seconds while a game is live, 5 once it is finished — chosen over
a persistent connection for the reasons in [ADR-001](docs/decisions/adr-001-polling-transport.md).

- [Prerequisites](#prerequisites)
- [Running it locally](#running-it-locally)
- [Commands](#commands)
- [The hosted instance](#the-hosted-instance)
- [No accounts, and what that costs](#no-accounts-and-what-that-costs)
- [Known limitations](#known-limitations)
- [Deleting expired games on a schedule](#deleting-expired-games-on-a-schedule)
- [AI tooling](#ai-tooling)
- [The rest of the documentation](#the-rest-of-the-documentation)
- [Licence](#licence)

## Prerequisites

| Requirement | Version | Notes |
| --- | --- | --- |
| PHP | 8.5 | Extensions: `mbstring`, `dom`, `xml`, `curl`, `intl`, `bcmath`, `fileinfo`, `tokenizer`, `sqlite3`, `pdo_sqlite` |
| Composer | 2.x | |
| Node.js and npm | 22 | Only to build the client assets |
| SQLite | bundled with PHP | The database is a file under `database/`; nothing to install or start |

Nothing else. There is no database server, no Redis, no queue worker and no external service to
sign up for. Verified against PHP 8.5.9, Composer 2.10.2, Node 22.19.0 and npm 10.9.3.

Two things are optional. Docker with Compose, if you want to run the production stack rather than
the development server, and a chromium download, if you want to run the browser test — see
[Commands](#commands) for both.

## Running it locally

```bash
composer setup
php artisan serve
```

Then open **<http://127.0.0.1:8000>**.

`composer setup` does the whole first-run sequence: `composer install`, copy `.env.example` to
`.env` if you have no `.env`, generate an `APP_KEY`, run the migrations — which creates the SQLite
file, since `migrate --force` touches a missing one — then `npm install --ignore-scripts` and
`npm run build`. It is safe to re-run; it will not overwrite an existing `.env`.

If you are editing the client, `composer dev` instead of `php artisan serve` runs the server, the
queue listener, the log tail and the Vite dev server together with hot reloading, on the same URL.

### Playing a game against yourself

The session is the identity, so a second tab is the *same* player. To see both sides:

1. Create a game and copy the join link.
2. Open that link in a different browser, or a private window — not another tab.
3. Play. Neither window needs refreshing.

### The container path

`compose.yaml` is the production stack: php-fpm and Caddy in two containers, brought up with
`docker compose up -d --build`. It is not a local development target as committed. It mounts
`deploy/Caddyfile.production`, which names the hosted hostname and would attempt certificate
issuance, and it declares an external `caddy-data` volume that a fresh machine does not have.
Running the stack locally means supplying your own Caddyfile naming a local address. For
development, the two commands above are the supported route.

## Commands

| Command | What it does |
| --- | --- |
| `composer setup` | First-run install: dependencies, `.env`, `APP_KEY`, migrations, asset build |
| `php artisan serve` | Serves the application at <http://127.0.0.1:8000> |
| `composer dev` | Server, queue listener, log tail and Vite watcher together |
| `composer test` | The test suite excluding the `browser` group — **298 tests, 8,394 assertions**, about 12 s |
| `composer test:browser` | The one end-to-end browser test — **1 test, 19 assertions**, about 8 s. Needs chromium; see below |
| `composer lint` | Formatting check, `pint --test`. Reports without writing |
| `composer lint:fix` | Applies the formatting |
| `composer analyse` | Static analysis: PHPStan level 8 over `app`, `database`, `tests`, then level max over the domain layer |
| `npx tsc --noEmit` | Type-checks the client. Vite strips types without checking them, so this is the only check of the props contract |

`composer test`, `composer test:browser`, `composer lint`, `composer analyse` and `npx tsc --noEmit`
were all run against this commit and all pass.

### The browser test needs chromium fetched first

```bash
npx playwright install chromium   # once
composer test:browser
```

**Do not skip that first line.** `composer setup` runs `npm install --ignore-scripts`, and the
`playwright` package ships no install hook, so nothing downloads a browser on a fresh clone — the
binaries live outside the project under `~/.cache/ms-playwright`. Without the download,
`composer test:browser` fails launching chromium rather than on an assertion, which reads like a
broken suite instead of a missing file. `composer test` excludes that group and needs nothing
beyond `composer setup`.

### What CI runs

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every push and every pull request,
in two jobs. `quality` runs `composer lint`, `composer analyse`, `npx tsc --noEmit` and the suite
excluding the browser group; `browser` runs the browser test and is gated behind `quality`. The
split is so a red tick tells you which kind of thing broke — the reasoning is in the workflow's own
comments and in [ADR-008](docs/decisions/adr-008-one-browser-test.md).

## The hosted instance

**<https://18-175-88-107.sslip.io/>** — a `t3.micro` in `eu-west-2`, one box running the
[`compose.yaml`](compose.yaml) stack. `GET /health` answers
`{"status":"ok","persistence":"reachable"}`.

**The scheme is HTTPS.** Caddy holds a real Let's Encrypt certificate for that hostname, valid to
4 November 2026, and plain HTTP answers `308` to the HTTPS address. The Player_Session cookie
therefore carries `Secure` in this deployment, which is what Requirement 10.11 asks for where the
application is served over HTTPS — so that criterion is met rather than waived. `HttpOnly` and
`SameSite=Lax` apply as well; `Lax` is deliberate, because a join link is a cross-site top-level
GET and `Strict` would drop the session on exactly that navigation. Plain HTTP was the recorded
fallback had issuance failed, and it was not needed. Local development leaves `Secure` off, since
a `Secure` cookie is never sent back over plain HTTP.

Shell access is AWS Systems Manager Session Manager only: no key pair exists and port 22 is not
open inbound. That is a deliberate decision rather than an omission —
[ADR-009](docs/decisions/adr-009-ec2-compose-caddy.md) carries the reasoning.

Deploying, redeploying and what survives a redeploy are in
[`docs/deploy-schedule-swap.md`](docs/deploy-schedule-swap.md); how the instance was provisioned is
in [`docs/aws-infra.md`](docs/aws-infra.md).

## No accounts, and what that costs

**Access to a game cannot be recovered after loss of the Player_Session.** The credential for a
game is a per-game, per-mark token that exists only inside the server-side session, and the only
thing linking a browser to that session is its cookie. Clear the cookies, lose the browser
profile, or open the game in a different browser, and there is no way back into that game — not
for you and not for an operator, because nothing else in the system knows who you were.

This follows directly from the deliberate absence of user accounts. Without accounts there is no
identity to recover through, no password reset and no email on file; the session *is* the
identity. What that buys is a game you can start and share in one click, with no sign-up and no
personal data stored at all. [ADR-005](docs/decisions/adr-005-per-game-tokens.md) records the
choice and its alternatives.

The join code is not a second way in. It claims the second seat once and is spent; presenting it
again cannot re-enter a game, because authorisation comes from the token and nothing else.

## Known limitations

Both are real, both were found while building, and both are stated with what the player *does*
see as well as what they do not.

| Limitation | What the player sees instead |
| --- | --- |
| The opponent-idle warning starts only after the first move | The steady "waiting for your opponent" indication, but no escalation after a minute |
| A rate-limited request shows nothing | A control that appears to do nothing until the window passes |

### The opponent-idle indication begins only once a move has been accepted

Requirement 9.4's "your opponent may have stopped playing" warning needs the time since the most
recent accepted move. The client receives that as `lastMoveAt`, and for a game with an empty move
list there is no such value — the only other origin, the moment the game became active, is
deliberately not part of the representation. So on an empty board the elapsed time is not merely
unknown, it is unrepresentable client-side, and the warning stays quiet.

The consequence: **a creator who shares a link, sees someone join, and never comes back leaves the
joiner waiting with no warning.** The joiner is not left with a blank screen — Requirement 9.3's
"waiting for your opponent" indication shows throughout, so they can see the application is
waiting on the other player. What they do not get is the escalation after 60 seconds that would
tell them the wait is no longer normal.

Requirement 9.4 was narrowed during task 6.5 to say so, rather than left quietly unmet. The
alternative — adding a "became active at" timestamp to the representation — was available and was
judged not worth a new prop, a server change and a second clock for a warning that a joiner
staring at an empty board they cannot play does not especially need. The amendment is recorded in
[`docs/ai-direction.md`](docs/ai-direction.md#requirements-narrowed-rather-than-met) under
"Requirements narrowed rather than met", where it sits with the scoping decisions rather than the
corrections, because nothing about the original wording was wrong.

### A rate-limited request shows the player nothing

Joining is limited to 20 requests a minute and moving to 60 (Requirements 10.6 and 10.7); game
creation carries the join threshold too, which no criterion asks for. Trip any one of them and the
request is refused with `429` — and **the client renders nothing at all.** The player sees a
control that appears to do nothing until the window passes, then works again.

There are two reasons, and together they mean there is nothing to render. The `429` comes from
Laravel's `ThrottleRequests` middleware before any controller runs, so `rate_limited` has no value
in the application's vocabulary — the string appears in no enum, no route and no client module. And
`resources/js` has no `onError` handler, no `router.on` listener and no branch on response status,
so there is nothing waiting to display it if it did.

**No acceptance criterion asks for the client half.** Requirements 10.6 and 10.7 oblige the
Game_Service to refuse the request, which it does and which the suite tests; Requirement 14.3
requires that test and excludes the forgery rejection outright. Nothing obliges the web client to
show the refusal. In ordinary two-player use the limits are also not reachable — a whole game is
nine moves. Found during task 12.1, which also corrected a design paragraph that had claimed
Inertia's error handling surfaced it.

## Deleting expired games on a schedule

Unauthenticated game creation would otherwise grow stored data without bound. A game waiting for
an opponent becomes eligible for expiry after 24 hours; any game becomes eligible 7 days after its
most recent move or state change. Nothing deletes on a timer — deletion happens only when the
command runs, so those times are lower bounds on retention rather than times of deletion
([ADR-007](docs/decisions/adr-007-retention-command.md)).

The command:

```bash
php artisan games:sweep
```

It prints three counts, and **three zeroes is a success rather than a no-op failure** — an empty
sweep is the ordinary case:

```
Games deleted: 0
Games deferred (a rematch survives): 0
Expiry records purged: 0
```

**The production means of deleting eligible games is this command on a host crontab.** There is no
scheduler process inside the application. On the hosted instance the entry lives on `ssm-user`'s
crontab and runs daily at 03:17:

```cron
17 3 * * * cd /srv/tic-tac-toe && docker compose exec -T app php artisan games:sweep 2>&1 | logger -t games-sweep
```

Install it without opening an editor, which is awkward over Session Manager:

```bash
( crontab -l 2>/dev/null; echo '17 3 * * * cd /srv/tic-tac-toe && docker compose exec -T app php artisan games:sweep 2>&1 | logger -t games-sweep' ) | crontab -
crontab -l
```

Two parts of that line are load-bearing. `-T` disables the pseudo-TTY: without it cron fails with
`the input device is not a TTY`, because cron has no terminal. And `logger -t games-sweep` sends
the output to the journal instead of mailing it to a local user nobody reads — read it back with
`journalctl -t games-sweep`. Outside a container the same schedule is the bare command:
`17 3 * * * cd /path/to/app && php artisan games:sweep`.

## AI tooling

**The tool was Claude Opus 5**, used through the AI-assisted spec-driven workflow in the Kiro IDE,
with sub-agents dispatched per task from a written implementation plan. It produced the first draft
of every artefact in this repository that is not Laravel's own scaffolding.

| Part of the work | What the tooling did | What the human did |
| --- | --- | --- |
| Specification | Wrote `requirements.md` in EARS form, `design.md` and `tasks.md`, revised across several review passes | Set the brief, read every criterion, ruled on each defect and amendment |
| Application code | Wrote the domain layer, services, HTTP layer, Inertia client and migrations | Directed task order, refused workarounds, ruled where two documents conflicted |
| Tests | Wrote the unit, feature, property-based, architecture and browser suites | Restored the falsification habit that caught the vacuous-assertion defect below |
| Infrastructure | Wrote the Dockerfile, `compose.yaml`, the Caddyfile and the CI workflow | Provisioned the instance and hostname, played the game that found the rate-limiter defect |
| Documentation | Wrote the decision records, the correction record and this README | Required that corrections be recorded at their true size, its own tool's included |

### Where the generated output was corrected or rejected

Four, chosen because in each the generated *reasoning* was confident, specific and wrong rather
than merely a rough first draft.

**Framework behaviour asserted from memory, three times in sequence, and wrong three times.** The
design claimed Laravel's forgery protection forces a session into existence, then that a
configuration flag would close the resulting gap, then added a test asserting the state of that
non-existent flag. Reading the vendor source of `PreventRequestForgery` settled it: a
`Sec-Fetch-Site: same-origin` request is accepted on the first line of `hasValidOrigin()`,
unconditionally, and the middleware short-circuits in tests before any of it runs. Requirement 10.9 was rewritten to
describe origin verification with token verification as the fallback — what the framework actually
does. A subclass forcing the token path on every request was considered and rejected.

**A CHECK constraint that would have broken rematch on its first insert.** The generated schema
required `x_token_hash IS NOT NULL` on any rematch row, four paragraphs below a flow that inserts a
rematch with *both* token slots null and mints each token when that player's session next appears.
Rejected and removed, with an explicit instruction in the task not to reintroduce it, because it
reads like an obvious invariant.

**Seventeen assertions that asserted nothing.** Tests written as
`expect($body)->not->toContain($secret, 'the response leaks the raw token')` looked like a check
with an explanatory message. Pest's `toContain()` is variadic and takes no message, so the sentence
was a second needle and the assertion passed unconditionally — including with a real token leak
left in place, which is how it was demonstrated. Worse than the tests was the explanation offered
for them: a confident, unmeasured account of the library that was the exact inverse of what its
source does, and which nearly closed the matter as a cosmetic wart. All seventeen were rewritten
and each falsified individually by an agent that had not written them.

**A retention sweep that two individually correct constraints made unimplementable.** One criterion
deleted a game as it became expired; the next required a command that deletes expired games. The
command's working set was permanently empty, and a test of it would have passed against an
implementation that did nothing. Restructured so eligibility is a state a game is treated as being
in and deletion happens only in the command.

Everything else, including the defects found by building rather than by reading — a mid-game 500
from the rate limiter's cache store, a UUIDv7 entropy figure that was right in general and wrong
where it was quoted, a design paragraph that sent a correctly guessed game id to the wrong row of
the visibility table — is in [`docs/ai-direction.md`](docs/ai-direction.md), written as the work
proceeded rather than reconstructed at the end.

## The rest of the documentation

| Document | What is in it |
| --- | --- |
| [`docs/decisions/`](docs/decisions/README.md) | One decision record per significant technical choice, each with its alternatives and reasons. Eleven of them |
| [`docs/ai-direction.md`](docs/ai-direction.md) | How the tooling was directed, and every place the generated output was wrong |
| [`docs/deploy-schedule-swap.md`](docs/deploy-schedule-swap.md) | Deploying the stack, verifying it, scheduling the sweep, and redeploying |
| [`docs/aws-infra.md`](docs/aws-infra.md) | How the instance, security group, role and hostname were provisioned |
| [`.kiro/specs/remote-tic-tac-toe/requirements.md`](.kiro/specs/remote-tic-tac-toe/requirements.md) | The acceptance criteria, in EARS form |
| [`.kiro/specs/remote-tic-tac-toe/design.md`](.kiro/specs/remote-tic-tac-toe/design.md) | The design, including the correctness properties the suite tests |
| [`.kiro/specs/remote-tic-tac-toe/tasks.md`](.kiro/specs/remote-tic-tac-toe/tasks.md) | The implementation plan, with what each task actually observed |

Two configuration files are worth reading for their comments rather than their values:
[`.env.example`](.env.example), where `CACHE_STORE=file` records a production failure and its
measurement, and [`compose.yaml`](compose.yaml), where the absence of a `ports:` key on the `app`
service is load-bearing.

## Licence

[MIT](LICENSE).
