# ADR-012: Continuous deployment through GHCR and SSM Run Command

Bears on Requirements 1 to 9 of the `continuous-deployment` spec.

**Supersedes the "No continuous deployment, deliberately" section of
[ADR-009](adr-009-ec2-compose-caddy.md)**, and only that section. What no longer holds is manual
deploys and building the image on the instance. What still holds, and is honoured here, is
ADR-009's rejection of an SSH private key in repository secrets — that shape was never built.

## Decision

CI builds both images and pushes them to GitHub Container Registry tagged with the commit SHA.
`compose.yaml` names those images rather than building them. Deployment is one Systems Manager
document, invoked by a GitHub Actions job that assumes an AWS role by OIDC. A health gate on the
runner decides whether the deployment stands, and a failed gate redeploys the previously recorded
tag.

No credential to AWS is stored anywhere. No credential to GHCR exists on the instance, because both
packages are public.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| SSH key in repository secrets | A durable, exfiltratable credential to production. ADR-009 rejected it and that still stands |
| `ssm:SendCommand` on `AWS-RunShellScript` | The caller supplies the commands, so it is arbitrary root execution. Scoping the resource constrains *where* a command runs, not *what* it is |
| Building on the instance, as before | A `t3.micro` with 911 MiB needed a swap file to survive `npm run build`, and what shipped was never the artefact CI tested |
| `latest` as well as the SHA tag | A moving tag makes "what is deployed" unanswerable and gives a mistyped variable somewhere to land |
| Blue/green or a second instance | An ALB and two instances, for a demonstration project on one box |
| Snapshotting the database before each deploy | Declined explicitly. Recorded in the requirements with its consequence: there is no backup, and losing the volume loses every game |

## Reason

**The registry, not the box.** The images CI tested are the images that run. Building on the
instance meant the deployed artefact was a *rebuild* of the tested commit on different hardware with
a different Blade cache — measurably different CSS, as the Dockerfile records. It also put a
ten-minute compile on the critical path of every deploy and needed a swap file to complete at all.

**A custom document, not a shell.** `DeployTicTacToe` fixes its own commands and accepts two
parameters: `ReleaseTag`, constrained to `^[0-9a-f]{40}$`, and `Mode`. The deployment role may run
that document and nothing else — no `StartSession`, no EC2 action, no IAM action. The constraint has
an ongoing cost, and it is the point rather than an oversight: the role is granted no
`ssm:UpdateDocument`, so changing the deploy script means running `aws ssm update-document` by hand.
A pipeline that can rewrite its own permitted script is constrained by nothing.

**The gate runs on the runner, over the public URL.** That also establishes that TLS terminates and
that `web` reaches `app`, neither of which a probe over the local FastCGI socket would show. It
requires two healthy responses at least five seconds apart, because the outgoing container can still
answer during recreation and one success may have come from the version being replaced.

**Environment scoping, not a branch condition.** GitHub's OIDC issuer is shared by every GitHub
account — it has no tenancy segment, unlike Vercel's `oidc.vercel.com/<team>`, and a per-tenant
issuer is a GitHub Enterprise Cloud feature. AWS therefore classifies GitHub Actions as a *shared*
provider whose tenancy claim is `sub`, and refuses a trust policy that omits it. The subject scopes
to `environment:production`, which carries no branch information, so the branch restriction moves to
the environment's deployment-branch rule — set to `main` alone. GitHub applies that rule *before*
issuing a token, which is stronger than AWS refusing one afterwards.

## What this deliberately does not claim

**It is not zero-downtime.** `docker compose up -d` recreates both containers; requests in flight
are dropped and there is a gap of a second or two. One instance and one container pair cannot do
better without a second of each.

**The fallback restores images, not schema.** A migration that ran stays run. That is why
`composer check:migrations` rejects a destructive or multi-table migration in CI, and why removing a
column is expand-and-contract across four deployments with the drop done by hand.

**One table per migration bounds the blast radius; it does not make a migration atomic.** On SQLite,
Laravel opens no transaction — `Migrator::runMigration()` gates on `supportsSchemaTransactions()` and
`SQLiteGrammar` inherits `$transactions = false`. `CREATE TABLE` followed by its indexes is one table
and passes the check, and a failure between them still leaves the table without indexes.

**After a fallback there is no second rollback target.** The current and previous tags end up equal,
so the pointer is spent until the next successful deployment. The invariant that is protected is
narrower: the *failed* tag never becomes the fallback target.

**`ssm:GetCommandInvocation` is granted on `*` and cannot be narrowed.** The service authorization
reference defines no `command` resource type, so there is no ARN to name. It reads status and output
and can perform no action.

**The fallback still needs GitHub reachable.** The images are usually already on the instance, but
the document checks out the previous commit for `compose.yaml` and the Caddyfile. If GitHub were
down entirely the fallback would fail at `git fetch`.

## In practice

Seven deployments, all through the pipeline. Verified on the instance rather than inferred from a
green tick: both containers running the GHCR images, both `(healthy)`, both carrying
`org.opencontainers.image.revision` equal to the deployed SHA, `release.env` advancing correctly,
and 14 games and 80 moves surviving the switch from build-on-the-box to pull-from-registry.

**The fallback was drilled deliberately.** A commit removing the `root /var/www/html/public`
override from `php_fastcgi` was pushed, which makes Caddy send a `SCRIPT_FILENAME` that exists in
the `web` container and not in `app`. Every PHP request failed while static files kept serving. The
document succeeded — both healthchecks stayed green, because `app`'s speaks FastCGI directly and
`web`'s hits Caddy's admin API — so the *gate* was what failed, which is the path nothing else
exercises. The previous pair went back, its gate passed, and the run went red with the site up.

Two things that drill showed which reasoning had not:

- the failed pair was **not** reclaimed, because the reclaim runs before `up` and the failing
  containers still held those images; `docker rmi` refused and the `|| true` on that line is what
  stopped the fallback dying over it
- a throwing migration, which the plan had suggested as the failure to use, **cannot** be used at
  all: migrations run in the test suite, so `quality` fails and `deploy` never starts

**Four defects were found by review before the first run, each of which would have failed silently:**
`docker image prune -f` reclaims nothing when every image is tagged, so the disk guard was
decorative and `prune -a` would have deleted the fallback pair; writing `release.env` before Compose
succeeded would have recorded a never-ran tag as the next fallback target; `up -d` exits 0 for a
container that dies two seconds later; and reading the revision label without comparing it proved
nothing.

**One defect was found only by running it.** The trust policy matched the documented subject
`repo:owner/repo:environment:production`. GitHub now embeds immutable account and repository ids in
the default claim for new repositories, so the real subject is
`repo:mirkovicUK@105384880/TIC-TAC-TOE@1325118189:environment:production`. The documented form is
valid JSON, AWS accepts it, and then denies every assumption with what reads as a missing
permission. CloudTrail logs the presented subject in `userIdentity.userName` on a denied
`AssumeRoleWithWebIdentity`; one lookup settled what twelve identical retries in the workflow log
could not.
