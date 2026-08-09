# How this project was directed

- [How the tooling was used](#how-the-tooling-was-used)
- [How the work was executed](#how-the-work-was-executed)
- [Corrections](#corrections)
- [Decisions recorded rather than corrected](#decisions-recorded-rather-than-corrected)

## How the tooling was used

**The tool was Claude Opus 5**, used through an AI-assisted spec-driven workflow, with
sub-agents dispatched per task from a written implementation plan. It produced first drafts of
every artefact here that is not Laravel's own scaffolding.

| Part of the work | How the tooling was used | What the human did |
| --- | --- | --- |
| Specification | Generated `requirements.md` in EARS form, `design.md`, and `tasks.md`, each revised across several review passes | Set the brief, read every criterion, ruled on each defect and amendment |
| Implementation | Wrote all application code — domain layer, services, HTTP layer, Inertia client, migrations | Directed task order, refused workarounds, ruled where two documents conflicted |
| Tests | Wrote the unit, feature, property-based, architecture and browser suites | Restored the falsification habit that caught the vacuous-assertion defect |
| Infrastructure | Wrote the Dockerfile, `compose.yaml`, the Caddyfile and the CI workflow | Provisioned the instance and hostname, played the game that found the rate-limiter defect |
| Documentation | Wrote this file, and the decision records and README alongside it | Required that corrections be recorded at their true size, its own tool's included |

## How the work was executed

Once the task plan was written, the tooling did the implementation work. The shape of it:

- **One worker agent per task.** An orchestrator agent read the plan and dispatched a
  sub-agent for each task in turn.
- **No parallel execution, deliberately.** The plan supports running a wave of independent
  tasks concurrently. It was not used. This stack was new to the operator, and a wave of
  parallel workers produces more output at once than a reviewer new to the stack can review
  properly.
- **A commit before each task.** The orchestrator snapshotted the repository first, so every
  task started from a known state and any single task could be reverted on its own.
- **The worker was constrained.** One task only. Write access limited to the files that task
  needed. Reading was unrestricted — a worker could read anything in the repository to
  understand what it was changing.
- **Instructions came from a steering file**, not restated per task.
- **Every worker produced a report.** The orchestrator reviewed it and investigated the claims
  in it rather than accepting them. Reports stay in the session history for a human to read
  afterwards.
- **Test tasks had to prove the test works.** A worker implementing a test was required to
  show the test actually asserts the required behaviour — break the thing it guards, observe
  the failure, restore it — not merely that the suite is green.

The human role after the plan was written: read the worker reports, read the orchestrator's
review of them, and decide the points the orchestrator flagged as needing a human decision.

## Corrections

Grouped by the kind of failure.

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

### Framework behaviour asserted from memory

Laravel 13 is recent, and parts of it were missing from the model's knowledge. Several claims
about framework behaviour were asserted from memory and were wrong. That was expected rather
than surprising, and it was handled by rule: where framework behaviour mattered, the answer
came from the vendor source or from a web search, not from memory.

The standing instruction covered the general case. A worker that found the framework behaving
differently from what the design assumed had to stop, report the mismatch, explain the problem
and propose a fix — not work around it silently. Requirement 10.9 was rewritten that way, after
reading `PreventRequestForgery` settled what the middleware actually does.

## Decisions recorded rather than corrected

Choices where the reasoning matters more than the outcome. Each has a decision record under
[`decisions/`](decisions/README.md).

| ADR | Decision |
| --- | --- |
| [001](decisions/adr-001-polling-transport.md) | Polling rather than WebSockets for state synchronisation |
| [004](decisions/adr-004-sqlite-on-a-named-volume.md) | SQLite on a named volume, with WAL, a busy timeout and foreign keys on |
| [005](decisions/adr-005-per-game-tokens.md) | Per-game, per-mark tokens instead of user accounts |
| [007](decisions/adr-007-retention-command.md) | A retention command rather than an enforced TTL |
| [008](decisions/adr-008-one-browser-test.md) | Exactly one browser test |
| [009](decisions/adr-009-ec2-compose-caddy.md) | One EC2 instance with Docker Compose and Caddy |
| [011](decisions/adr-011-php-fpm-behind-caddy.md) | php-fpm behind Caddy in two containers |
| [012](decisions/adr-012-continuous-deployment.md) | Continuous deployment through GHCR and SSM Run Command |
