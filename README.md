# Remote Tic-Tac-Toe

Two-player tic-tac-toe across two browsers: one player creates a game, shares a join link, and
the two boards stay in step without either player refreshing. No accounts, no sign-up, no email.

**Hosted instance: <https://18-175-88-107.sslip.io/>**

[![CI](https://github.com/mirkovicUK/TIC-TAC-TOE/actions/workflows/ci.yml/badge.svg)](https://github.com/mirkovicUK/TIC-TAC-TOE/actions/workflows/ci.yml)

## What this is

A Laravel 13 application with an Inertia and React client, built to a written specification.

The game rules live in a framework-free domain layer that derives the board, whose turn it is and
the outcome from nothing but the list of moves — no database, no session, no HTTP. That purity is
what makes the exhaustive test affordable: the suite walks all 549,946 reachable move sequences
and asserts the 255,168 terminal ones.

Identity is a per-game token held in the server-side session, not a user account. State
synchronisation is polling — 2 seconds while a game is live, 5 once it is finished — chosen over a
persistent connection for the reasons in [ADR-001](docs/decisions/adr-001-polling-transport.md).

- [Prerequisites](#prerequisites)
- [Running it locally](#running-it-locally)
- [Commands](#commands)
- [The hosted instance](#the-hosted-instance)
- [Deployment](#deployment)
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

Two things are optional: Docker with Compose, to run the production stack rather than the
development server, and a chromium download, to run the browser test. See
[Commands](#commands) for both.

## Running it locally

```bash
composer setup
php artisan serve
```

Then open **<http://127.0.0.1:8000>**.

`composer setup` runs the whole first-run sequence: `composer install`, copy `.env.example` to
`.env` if you have no `.env`, generate an `APP_KEY`, run the migrations — which creates the SQLite
file, since `migrate --force` touches a missing one — then `npm install --ignore-scripts` and
`npm run build`. It is safe to re-run and will not overwrite an existing `.env`.

If you are editing the client, `composer dev` runs the server, the queue listener, the log tail
and the Vite dev server together with hot reloading, on the same URL.

### Playing a game against yourself

The session is the identity, so a second tab is the *same* player. To see both sides:

1. Create a game and copy the join link.
2. Open that link in a different browser, or a private window — not another tab.
3. Play. Neither window needs refreshing.

### The container path

`compose.yaml` is the production stack: php-fpm and Caddy in two containers. **It builds nothing.**
Both services name published images by commit SHA:

```yaml
image: ghcr.io/mirkovicuk/tic-tac-toe-app:${RELEASE_TAG:?RELEASE_TAG must be set}
```

So `docker compose up -d --build` has nothing to act on, and `up` without `RELEASE_TAG` refuses to
start rather than guessing. That is deliberate — see
[ADR-012](docs/decisions/adr-012-continuous-deployment.md).

It is not a local development target either way. It mounts `deploy/Caddyfile.production`, which
names the hosted hostname and would attempt certificate issuance, and it declares an external
`caddy-data` volume a fresh machine does not have. Running it locally would need a published tag,
your own Caddyfile naming a local address, and that volume created by hand. For development, the
two commands above are the supported route.

## Commands

| Command | What it does |
| --- | --- |
| `composer setup` | First-run install: dependencies, `.env`, `APP_KEY`, migrations, asset build |
| `php artisan serve` | Serves the application at <http://127.0.0.1:8000> |
| `composer dev` | Server, queue listener, log tail and Vite watcher together |
| `composer test` | The test suite excluding the `browser` group — **307 tests, 8,415 assertions**, about 26 s |
| `composer check:migrations` | Rejects a migration that cannot be safely deployed. See [`database/migrations/README.md`](database/migrations/README.md) |
| `composer test:browser` | The one end-to-end browser test — **1 test, 19 assertions**, about 7 s. Needs chromium; see below |
| `composer lint` | Formatting check, `pint --test`. Reports without writing |
| `composer lint:fix` | Applies the formatting |
| `composer analyse` | Static analysis: PHPStan level 8 over `app`, `database`, `tests`, then level max over the domain layer |
| `npx tsc --noEmit` | Type-checks the client. Vite strips types without checking them, so this is the only check of the props contract |

All of those were run against this commit and all pass. The two commands at the top of
[Running it locally](#running-it-locally) were also checked from a fresh `git clone` into an empty
directory: `composer setup`, `php artisan serve`, then creating a game over HTTP and reading the
join code back off the rendered page. `composer test` in that clone reported the same 307 tests
and 8,415 assertions.

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

### What the pipeline runs

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) has four jobs. Two run on every push and
every pull request:

- **`quality`** — `composer lint`, `composer analyse`, `composer check:migrations`, `npx tsc --noEmit`
  and the suite excluding the browser group
- **`browser`** — the one browser test, gated behind `quality`

Two run only on `main`, and they are the deployment:

- **`publish`** — builds both images and pushes them to GHCR tagged with the commit SHA
- **`deploy`** — deploys that tag to the instance and health-gates it

See [Deployment](#deployment). On a branch or a pull request `github.ref` is not `refs/heads/main`,
so both are skipped and nothing is published.

## The hosted instance

**<https://18-175-88-107.sslip.io/>** — a `t3.micro` in `eu-west-2`, one box running the
[`compose.yaml`](compose.yaml) stack. `GET /health` answers
`{"status":"ok","persistence":"reachable"}`.

**The scheme is HTTPS.** Caddy holds a Let's Encrypt certificate for that hostname, valid to
4 November 2026, and plain HTTP answers `308` to the HTTPS address. The Player_Session cookie
therefore carries `Secure` in this deployment, which is what Requirement 10.11 asks for where the
application is served over HTTPS, so that criterion is met rather than waived. `HttpOnly` and
`SameSite=Lax` apply as well. `Lax` is deliberate: a join link is a cross-site top-level GET, and
`Strict` would drop the session on exactly that navigation. Plain HTTP was the recorded fallback
had issuance failed, and it was not needed. Local development leaves `Secure` off, since a `Secure`
cookie is never sent back over plain HTTP.

Shell access is AWS Systems Manager Session Manager only: no key pair exists and port 22 is not
open inbound. That is a deliberate decision rather than an omission —
[ADR-009](docs/decisions/adr-009-ec2-compose-caddy.md) carries the reasoning.

How the instance was provisioned is in [`docs/aws-infra.md`](docs/aws-infra.md).

## Deployment

**A push to `main` deploys.** There is no manual step and no button.

```
push → quality → browser → publish → deploy → health gate
```

`publish` builds both images on the runner and pushes them to GHCR tagged with the commit SHA.
`deploy` assumes an AWS role by GitHub OIDC — no key pair, no stored AWS credential — and sends one
Systems Manager document, `DeployTicTacToe`, which pulls that pair and recreates both containers.
Nothing is built on the instance.

The outcome shows in the Actions tab. The `deploy` job logs the instance's own output, the poll
count and the final health status.

### When a deployment does not serve

After the containers come up, the runner polls `https://18-175-88-107.sslip.io/health` over public
HTTPS and needs **two** healthy responses at least 5 seconds apart within 120 seconds. Two rather
than one because the outgoing container can still answer during recreation.

If that gate fails, the workflow redeploys the previously recorded tag, health-gates that, and
**marks the run red either way**. A red run with the site up is the pipeline working: your commit
did not deploy, and a green tick would say it had.

The failed tag is never recorded as the rollback target — only a successful deploy advances that
pointer. This path was exercised deliberately once rather than only reasoned about: a commit that
broke the FastCGI document root was pushed, the gate failed, the previous pair went back, and the
run went red.

Note the coupling: after a fallback the current and previous tags are equal, so there is no second
rollback target until the next successful deployment.

### Schema changes move forward only

**Migrations must be additive, one table at a time.** `composer check:migrations` enforces it in
`quality`, so a migration that drops or renames a column fails CI before anything is published.

The reason is not tidiness. On SQLite, Laravel opens **no transaction** around a migration, so one
that does two things and dies between them leaves the database half-changed *and* unrecorded — the
next deploy re-runs it, hits "already exists", and every deploy after that fails identically until
someone repairs it by hand.

The rollback does not help: **it restores images, never the schema.** Removing a column is
expand-and-contract across four deployments, with the drop done by hand at the end.
[`database/migrations/README.md`](database/migrations/README.md) has the sequence and the one trap
the checker cannot catch.

### There is no backup of the database

The SQLite file lives in a Docker volume on one instance's root EBS volume. Nothing copies it
anywhere — no snapshot, no S3, no dump. Deployments do not touch it, and seven have not lost a
game, but **if that volume is lost every game and move is gone permanently.**

That was a deliberate scoping decision for a demonstration project, recorded rather than
overlooked. Volumes are also why `docker compose down -v` is the one command never to run on that
box: `-v` deletes them, taking both the database and the TLS certificate.

Deploying a specific tag by hand, rolling back, and what to do when the pipeline itself is broken
are in [`docs/deployment.md`](docs/deployment.md).

## No accounts, and what that costs

**Access to a game cannot be recovered after loss of the Player_Session.** The credential for a
game is a per-game, per-mark token that exists only inside the server-side session, and the only
thing linking a browser to that session is its cookie. Clear the cookies, lose the browser profile,
or open the game in a different browser, and there is no way back into that game — not for you and
not for an operator, because nothing else in the system knows who you were.

This follows directly from the deliberate absence of user accounts. Without accounts there is no
identity to recover through, no password reset and no email on file; the session *is* the identity.
What that buys is a game you can start and share in one click, with no sign-up and no personal data
stored at all. [ADR-005](docs/decisions/adr-005-per-game-tokens.md) records the choice and its
alternatives.

The join code is not a second way in. It claims the second seat once and is spent; presenting it
again cannot re-enter a game, because authorisation comes from the token and nothing else.

## Known limitations

Both are real, both were found while building, and both are stated with what the player *does* see
as well as what they do not.

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
"waiting for your opponent" indication shows throughout, so they can see the application is waiting
on the other player. What they do not get is the escalation after 60 seconds that would tell them
the wait is no longer normal.

Requirement 9.4 was narrowed during task 6.5 to say so, rather than left quietly unmet. The
alternative — adding a "became active at" timestamp to the representation — was available and was
judged not worth a new prop, a server change and a second clock for a warning that a joiner staring
at an empty board they cannot play does not especially need. Requirement 12.13 carries the
limitation, which is why it is stated here rather than left to be discovered.

### A rate-limited request shows the player nothing

Joining is limited to 20 requests a minute and moving to 60 (Requirements 10.6 and 10.7); game
creation carries the join threshold and state polling is capped at 120, neither of which any
criterion asks for. Trip any one of them and the request is refused with `429` — and **the client
renders nothing at all.** The player sees a control that appears to do nothing until the window
passes, then works again.

## Deleting expired games on a schedule

Unauthenticated game creation would otherwise grow stored data without bound. A game waiting for an
opponent becomes eligible for expiry after 24 hours; any game becomes eligible 7 days after its
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
17 3 * * * cd /srv/tic-tac-toe && docker compose --env-file deploy/release.env exec -T app php artisan games:sweep 2>&1 | logger -t games-sweep
```

## AI tooling

**The tool was Claude Opus 5**, used through an AI-assisted spec-driven workflow, with sub-agents
dispatched per task from a written implementation plan. It produced the first draft of every
artefact in this repository that is not Laravel's own scaffolding.

| Part of the work | What the tooling did | What the human did |
| --- | --- | --- |
| Specification | Wrote `requirements.md` in EARS form, `design.md` and `tasks.md`, revised across several review passes | Set the brief, read every criterion, ruled on each defect and amendment |
| Application code | Wrote the domain layer, services, HTTP layer, Inertia client and migrations | Directed task order, refused workarounds, ruled where two documents conflicted |
| Tests | Wrote the unit, feature, property-based, architecture and browser suites | Restored the falsification habit that caught the vacuous-assertion defect |
| Infrastructure | Wrote the Dockerfile, `compose.yaml`, the Caddyfile and the CI workflow | Provisioned the instance and hostname, played the game that found the rate-limiter defect |
| Documentation | Wrote the decision records, the correction record and this README | Required that corrections be recorded at their true size, its own tool's included |

## The rest of the documentation

| Document | What is in it |
| --- | --- |
| [`docs/decisions/`](docs/decisions/README.md) | One decision record per significant technical choice, each with its alternatives and reasons. Eight of them |
| [`docs/ai-direction.md`](docs/ai-direction.md) | How the tooling was directed, and every place the generated output was wrong |
| [`docs/deployment.md`](docs/deployment.md) | The one deployment document: setup steps, an ordinary push, deploying a tag by hand, rolling back, break glass, what survives, exit codes |
| [`docs/aws-infra.md`](docs/aws-infra.md) | How the instance, security group, role and hostname were provisioned |
| [`database/migrations/README.md`](database/migrations/README.md) | The additive-schema rules and the expand-and-contract sequence |
| [`.kiro/specs/continuous-deployment/`](.kiro/specs/continuous-deployment/requirements.md) | The pipeline's own spec: requirements, design and plan |
| [`.kiro/specs/remote-tic-tac-toe/requirements.md`](.kiro/specs/remote-tic-tac-toe/requirements.md) | The acceptance criteria, in EARS form |
| [`.kiro/specs/remote-tic-tac-toe/design.md`](.kiro/specs/remote-tic-tac-toe/design.md) | The design, including the correctness properties the suite tests |
| [`.kiro/specs/remote-tic-tac-toe/tasks.md`](.kiro/specs/remote-tic-tac-toe/tasks.md) | The implementation plan, with what each task actually observed |

## Licence

[MIT](LICENSE), covering the code and documentation in this repository. It does not
extend to third-party dependencies, each of which carries its own licence — the Laravel
framework and the packages under `vendor/` and `node_modules/` are the largest of them.
