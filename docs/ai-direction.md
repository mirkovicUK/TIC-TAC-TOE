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
