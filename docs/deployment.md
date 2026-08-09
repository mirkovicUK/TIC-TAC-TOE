# Deploying this application

Setup that cannot be automated, an ordinary push, deploying a chosen tag by hand, rolling back,
and what to do when the pipeline itself is broken.

- [The setup steps only you can run](#the-setup-steps-only-you-can-run)
- [An ordinary push](#an-ordinary-push)
- [Deploying a chosen tag by hand](#deploying-a-chosen-tag-by-hand)
- [Rolling back by hand](#rolling-back-by-hand)
- [Break glass: when the document itself is broken](#break-glass-when-the-document-itself-is-broken)
- [What survives a deployment](#what-survives-a-deployment)
- [When something goes wrong](#when-something-goes-wrong)
- [Reclaiming disk](#reclaiming-disk)
- [Cost note](#cost-note)

## The setup steps only you can run

| # | Step | When | Where |
| --- | --- | --- | --- |
| 1 | Create the GitHub OIDC provider | Any time. Safe now | AWS, your laptop |
| 2 | Create the `production` environment, restricted to `main` | Any time. Safe now | GitHub settings |
| 3 | Create the deployment role | After tasks 1.1 and 1.2 write the policy files | AWS, your laptop |
| 4 | Register the `DeployTicTacToe` SSM document | After task 1.3 writes it, before task 6.1 lands | AWS, your laptop |
| 5 | Add the role ARN as a repository variable | Straight after step 3 | GitHub settings |
| 6 | Seed `release.env` on the instance | **Before task 4.1 lands.** Miss this and the site stops | Session Manager |
| 7 | Make both GHCR packages public | After the first `publish` run, before task 6.1 lands | GitHub settings |
| 8 | Re-register the document, now it has a `diagnose` mode | Before the first deploy | AWS, your laptop |
| 9 | Add `--env-file` to the sweep crontab | **Before or immediately after the first deploy.** Miss it and the nightly sweep dies quietly | Session Manager |

All nine are done on this deployment. Facts they use: account `811362454196`, region `eu-west-2`,
instance `i-0c6bab4bc4644e760`, repository `mirkovicUK/TIC-TAC-TOE`, deployment branch `main`.

---

### Step 1 — Create the GitHub OIDC provider

**When:** any time.

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env

aws iam create-open-id-connect-provider \
  --url https://token.actions.githubusercontent.com \
  --client-id-list sts.amazonaws.com
```

Expect: JSON containing an ARN ending `:oidc-provider/token.actions.githubusercontent.com`. No
thumbprint argument is needed.

```bash
aws iam list-open-id-connect-providers
```

Expect: the new provider listed.

---

### Step 2 — Create the `production` environment, restricted to `main`

**When:** any time.

In GitHub: **Settings → Environments → New environment**, named `production`. Then:

- **Deployment branches** → *Selected branches* → add `main`, and nothing else
- Leave **required reviewers** and **wait timer** off

The trust policy in step 3 scopes to `environment:production`, which carries no branch
information, so this rule is the entire branch boundary.

---

### Step 3 — Create the deployment role

**When:** after tasks 1.1 and 1.2 have written `deploy/iam/deployment-role-trust-policy.json` and
`deploy/iam/deployment-role-permissions-policy.json`.

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env

aws iam create-role \
  --role-name tic-tac-toe-deploy \
  --description "Assumed by GitHub Actions on main to deploy over SSM" \
  --assume-role-policy-document file://deploy/iam/deployment-role-trust-policy.json \
  --max-session-duration 3600

aws iam put-role-policy \
  --role-name tic-tac-toe-deploy \
  --policy-name tic-tac-toe-deploy-ssm \
  --policy-document file://deploy/iam/deployment-role-permissions-policy.json
```

Check the trust policy:

```bash
aws iam get-role --role-name tic-tac-toe-deploy \
  --query 'Role.AssumeRolePolicyDocument' | grep -i 'sub\|aud'
```

Expect `StringEquals` with the full value, and no `*` anywhere in it:

```
repo:mirkovicUK@105384880/TIC-TAC-TOE@1325118189:environment:production
```

The numeric ids are required. The documented form `repo:<owner>/<repo>:environment:<name>` is
accepted by AWS and then denies every assumption.

Note the role ARN from the output. It is needed for step 5.

---

### Step 4 — Register the deploy document

**When:** after task 1.3 has written `deploy/ssm/DeployTicTacToe.json`, before task 6.1 lands.

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env

aws ssm create-document \
  --name DeployTicTacToe \
  --document-type Command \
  --document-format JSON \
  --content file://deploy/ssm/DeployTicTacToe.json

aws ssm describe-document --name DeployTicTacToe \
  --query 'Document.{Name:Name,Status:Status,Owner:Owner,Format:DocumentFormat}'
```

Expect: `Status` of `Active`, `Owner` `811362454196`.

The document accepts a `ReleaseTag` matching `^[0-9a-f]{40}$` and a `Mode`, and nothing else. It
needs `jq` and `flock` on the instance; without `jq` it exits **69**.

Every future change to the deploy script needs a manual update — the deployment role has no
`ssm:UpdateDocument`, so editing the JSON and pushing changes nothing:

```bash
aws ssm update-document \
  --name DeployTicTacToe \
  --document-version '$LATEST' \
  --document-format JSON \
  --content file://deploy/ssm/DeployTicTacToe.json
```

---

### Step 5 — Add the role ARN as a repository variable

**When:** straight after step 3.

In GitHub: **Settings → Secrets and variables → Actions → Variables → New repository variable**.

- Name: `AWS_DEPLOY_ROLE_ARN`
- Value: `arn:aws:iam::811362454196:role/tic-tac-toe-deploy`

A **variable**, not a secret.

---

### Step 6 — Seed `release.env` on the instance

**When: before task 4.1 lands.** Once `compose.yaml` uses `${RELEASE_TAG:?…}`, Compose refuses to
start anything without that variable.

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env
aws ssm start-session --target "$IID"
```

```bash
# Session Manager shell, on the instance.
cd /srv/tic-tac-toe
git rev-parse HEAD          # the SHA currently checked out and running

printf 'RELEASE_TAG=%s\nPREVIOUS_RELEASE_TAG=\n' "$(git rev-parse HEAD)" > deploy/release.env
chmod 600 deploy/release.env
cat deploy/release.env
```

Expect: two lines, the first a 40-character SHA, the second empty.

`PREVIOUS_RELEASE_TAG` is deliberately empty. The first deployment that fails its health check
reports that no fallback was possible and leaves itself running.

`deploy/release.env` is gitignored and lives only on the instance, like `deploy/app.env`.

---

### Step 7 — Make both GHCR packages public

**When:** after the `publish` job has run once, before task 6.1 lands.

Both arrived public on the first publish: a package first pushed from a public repository with
`GITHUB_TOKEN` inherits that visibility. Confirm from your laptop, with no credentials in play:

```bash
SHA=$(git rev-parse HEAD)
for img in app web; do
  T=$(curl -sS "https://ghcr.io/token?service=ghcr.io&scope=repository:mirkovicuk/tic-tac-toe-$img:pull" | jq -r .token)
  printf '%s: ' "$img"
  curl -sS -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $T" \
    -H 'Accept: application/vnd.oci.image.manifest.v1+json' \
    "https://ghcr.io/v2/mirkovicuk/tic-tac-toe-$img/manifests/$SHA"
done
```

Expect `200` for both. A `404` means private *or* no such tag — check the SHA is the full 40
characters first.

If either is private: **Package settings → Danger Zone → Change visibility → Public**.

The instance holds no registry credential, so both packages must stay public.

---

### Step 8 — Re-register the document

**When:** before the first deploy. The copy in Systems Manager is the one that runs.

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env

aws ssm update-document \
  --name DeployTicTacToe \
  --document-version '$LATEST' \
  --document-format JSON \
  --content file://deploy/ssm/DeployTicTacToe.json

aws ssm describe-document --name DeployTicTacToe \
  --query 'Document.{Status:Status,Default:DocumentVersion,Latest:LatestVersion}'
```

Expect `Default: 1, Latest: 2`. `update-document` creates a version and leaves the default where
it was, so `SendCommand` would still execute version 1. Promote it:

```bash
aws ssm update-document-default-version \
  --name DeployTicTacToe --document-version 2

aws ssm describe-document --name DeployTicTacToe \
  --query 'Document.{Status:Status,Default:DocumentVersion,Latest:LatestVersion}'
```

Expect `Status: Active` with `Default` and `Latest` both `2`. Then confirm the new mode is in the
default version:

```bash
aws ssm get-document --name DeployTicTacToe \
  --query 'Content' --output text | jq -r '.parameters.Mode.allowedValues'
```

Expect three values, including `diagnose`.

`diagnose` is read-only: it prints `release.env`, `docker compose ps -a`, the last 50 lines of
`app`, the last 20 of `web` and `df -h`, then exits 0.

---

### Step 9 — Add `--env-file` to the sweep crontab

**When:** before or immediately after the first deploy. Nothing warns you about this one.

Compose interpolates `compose.yaml` before *anything*, including `exec`, so the existing entry now
fails every night with an interpolation error, visible only in `journalctl -t games-sweep`.

Check which machine you are on first — `crontab` exists on both, and the output does not
distinguish them:

```bash
# Local machine.
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env
aws ssm start-session --target "$IID"
```

```bash
# Session Manager shell, on the instance. Expect `ip-172-31-...`, not your laptop.
hostname
crontab -l 2>/dev/null | grep -c 'games:sweep'    # 0 means there is nothing to replace
```

Write the entry, still on the instance:

```bash
# Session Manager shell, on the instance.
( crontab -l 2>/dev/null | grep -v 'games:sweep'
  echo '17 3 * * * cd /srv/tic-tac-toe && docker compose --env-file deploy/release.env exec -T app php artisan games:sweep 2>&1 | logger -t games-sweep'
) | crontab -

crontab -l
```

Do not pipe `crontab -l` through a filter into `crontab -`: if the filter fails it writes nothing
and installs an *empty* crontab. The form above cannot, because the `echo` runs regardless.

`crontab -l` printing nothing does not mean the file is absent — Ubuntu hides the three header
lines it writes itself. To be certain:

```bash
# Session Manager shell, on the instance.
sudo cat /var/spool/cron/crontabs/ssm-user
```

Then prove it under the environment cron gives it — no TTY, almost no `PATH`:

```bash
cd /srv/tic-tac-toe
env -i HOME=/home/ssm-user PATH=/usr/local/bin:/usr/bin:/bin bash -c \
  'cd /srv/tic-tac-toe && docker compose --env-file deploy/release.env exec -T app php artisan games:sweep 2>&1 | logger -t games-sweep'
echo "exit=$?"
journalctl -t games-sweep --no-pager | tail -3
```

Expect `exit=0` and a sweep line in the journal.

---

## An ordinary push

```bash
composer lint && composer analyse && npx tsc --noEmit && npm run build
./vendor/bin/pest --exclude-group=browser
git commit && git push origin main
```

Then watch the Actions tab: `quality` → `browser` → `publish` → `deploy`. If the new release does
not answer within two minutes, the pipeline puts the previous tag back and marks the run **red**.

Check a green run:

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env
curl -s "https://$HOST/health"
```

What is running on the instance:

```bash
cat /srv/tic-tac-toe/deploy/release.env
```

`Mode=diagnose` shows the whole picture without opening a shell:

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env

CMD=$(aws ssm send-command --document-name DeployTicTacToe --instance-ids "$IID" \
  --parameters "ReleaseTag=$(git rev-parse HEAD),Mode=diagnose" \
  --query 'Command.CommandId' --output text)

aws ssm get-command-invocation --command-id "$CMD" --instance-id "$IID" \
  --query 'StandardOutputContent' --output text
```

---

## Deploying a chosen tag by hand

Any tag ever published can be redeployed. GHCR keeps every pair; the instance keeps the current
and previous ones, so an older tag is pulled again.

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env

CMD=$(aws ssm send-command \
  --document-name DeployTicTacToe \
  --instance-ids "$IID" \
  --timeout-seconds 600 \
  --parameters "ReleaseTag=<40-char-sha>,Mode=deploy" \
  --query 'Command.CommandId' --output text)

aws ssm get-command-invocation --command-id "$CMD" --instance-id "$IID" \
  --query '{Status:Status,Code:ResponseCode,Out:StandardOutputContent,Err:StandardErrorContent}'
```

**No health gate runs.** The gate lives in the workflow, not the document, so a deployment sent
this way is not checked and not reverted. Check it yourself:

```bash
curl -s "https://$HOST/health"
```

`Mode=deploy` advances `PREVIOUS_RELEASE_TAG` to whatever was running. Confirm the tag exists in
the registry first if unsure — a pull failure aborts before anything is recreated:

```bash
T=$(curl -sS "https://ghcr.io/token?service=ghcr.io&scope=repository:mirkovicuk/tic-tac-toe-app:pull" | jq -r .token)
curl -sS -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $T" \
  -H 'Accept: application/vnd.oci.image.manifest.v1+json' \
  "https://ghcr.io/v2/mirkovicuk/tic-tac-toe-app/manifests/<40-char-sha>"
```

---

## Rolling back by hand

The pipeline reverts automatically when a health gate fails. To revert for another reason, send
the same document the pipeline sends:

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env

CMD=$(aws ssm send-command \
  --document-name DeployTicTacToe \
  --instance-ids "$IID" \
  --timeout-seconds 600 \
  --parameters "ReleaseTag=$(git rev-parse HEAD),Mode=fallback" \
  --query 'Command.CommandId' --output text)

aws ssm get-command-invocation --command-id "$CMD" --instance-id "$IID" \
  --query '{Status:Status,Out:StandardOutputContent,Err:StandardErrorContent}'
```

**You do not name the tag to restore.** `Mode=fallback` ignores `ReleaseTag` and reads
`PREVIOUS_RELEASE_TAG` from `release.env` on the instance. `ReleaseTag` is still required — SSM 2.2
has no conditional parameters — so pass any valid SHA.

That mode leaves `PREVIOUS_RELEASE_TAG` alone, so a second fallback restores the same tag. If
nothing was ever recorded, the document exits **71** and leaves the current stack running.

Do **not** run `docker compose down -v`. One of those volumes holds the TLS certificate.

Do **not** run `php artisan migrate:rollback`. The `down()` on the games migration is
`Schema::dropIfExists('games')` — it would delete every game and move. A bad migration is fixed by
a new migration.

---

## Break glass: when the document itself is broken

Use **only** when `DeployTicTacToe` cannot run — a bad `update-document`, or a corrupted
`release.env`. It skips the lock, the pull check, the `--wait` gate and the revision assertion.

This is not a fallback for AWS being down: it needs a Session Manager shell, which is the same
dependency `send-command` uses.

```bash
# Local machine.
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env
aws ssm start-session --target "$IID"
```

```bash
# Session Manager shell, on the instance.
cd /srv/tic-tac-toe
cat deploy/release.env                                  # note both values before changing anything

git fetch --depth 1 origin <sha> && git checkout --quiet --detach <sha>

printf 'RELEASE_TAG=%s\nPREVIOUS_RELEASE_TAG=%s\n' <sha> <previous-sha> > deploy/release.env
chmod 600 deploy/release.env

docker compose --env-file deploy/release.env up -d --wait
docker compose --env-file deploy/release.env ps
curl -s https://18-175-88-107.sslip.io/health
```

`--env-file` on **every** `docker compose` command, including `ps` and `exec`.

To restore the document itself, from your laptop:

```bash
aws ssm update-document --name DeployTicTacToe --document-version '$LATEST' \
  --document-format JSON --content file://deploy/ssm/DeployTicTacToe.json
aws ssm update-document-default-version --name DeployTicTacToe --document-version <n>
```

Both commands are needed.

---

## What survives a deployment

| | |
| --- | --- |
| **Survives** | Both volumes: the SQLite database with its games, moves and `sessions` table, and Caddy's certificate. Players stay in their games |
| **Does not survive, by design** | The rate-limit counters, which live in the container's own filesystem. Everyone starts from a clean limit |
| **Never change** | `APP_KEY`, in `deploy/app.env` on the instance. Regenerating it invalidates every session cookie, and with no accounts to recover through every player in a game in progress is locked out permanently |
| **Not backed up** | The SQLite file is in a Docker volume on one instance's root EBS volume. Nothing copies it anywhere. If that volume is lost, every game and move is gone |

### Downtime

`docker compose up -d` destroys both containers and creates new ones. Requests in flight are
dropped and there is a gap of a second or two; `web` answers 502 while `app` restarts, migrates
and rebuilds its caches. The client polls every 2 seconds, so a player sees a brief stall.

A pull that fails, or a container that will not become healthy, leaves the previous version
serving.

### Verifying the lifecycle records

Requirement 10.3's records go to `app`'s stdout as JSON. The vocabulary is `move.accepted`,
`move.rejected` and `rematch.created` — **not** `game.move_accepted`.

```bash
# Session Manager shell, on the instance, in /srv/tic-tac-toe.
docker compose --env-file deploy/release.env logs --no-color app | grep -c '"message":"move.accepted"'
docker compose --env-file deploy/release.env exec -T app php -r \
  '$p=new PDO("sqlite:/var/www/html/database/database.sqlite"); echo $p->query("select count(*) from moves")->fetchColumn(), PHP_EOL;'
```

The two should agree, allowing for log rotation — `json-file` is capped at 10 MB × 3 files.

---

## When something goes wrong

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Error: Not authorized to perform sts:AssumeRoleWithWebIdentity`, twelve retries | Trust policy `sub` does not match, step 1 skipped, or the job is missing `environment: production` | Read the subject GitHub presented out of CloudTrail (below) and make the condition equal it. This repository's tokens carry immutable numeric ids |
| `MalformedPolicyDocument` creating the role | Trust policy omits the `sub` condition AWS requires for a shared provider | Add the `sub` condition to the policy file |
| A deploy ran from a branch other than `main` | The `production` environment has no deployment-branch restriction — step 2 | Restrict it to `main` only |
| `AccessDeniedException` on `ssm:SendCommand` | Permissions policy missing the instance ARN or the document ARN — it needs both | Re-apply step 3's `put-role-policy` |
| `InvalidDocument` on `SendCommand` | The document is not registered — step 4 | `aws ssm create-document` |
| A change to the deploy script has no effect | The document was not updated | `aws ssm update-document`, step 4 |
| Deploy fails pulling the image | Packages still private — step 7 | Set both to Public |
| `RELEASE_TAG must be set` | `release.env` missing or empty — step 6 | Recreate it on the instance |
| `detected dubious ownership` | A `git` command ran as root in `/srv/tic-tac-toe` | Use `sudo -u ssm-user` |
| Health gate fails, previous tag restored, run red | Working as designed | Read `docker compose logs app` on the instance |
| Every deploy fails on a migration that "already exists" | A migration applied part way. Laravel opens no transaction for SQLite | Fix by hand on the instance, then keep migrations to one change each |
| Site up but unstyled, run red | The two images are from different commits | Republish; do not revert |

Read the subject GitHub actually sent:

```bash
aws cloudtrail lookup-events \
  --lookup-attributes AttributeKey=EventName,AttributeValue=AssumeRoleWithWebIdentity \
  --max-results 1 \
  --query 'Events[0].CloudTrailEvent' --output text | jq -r '.userIdentity.userName'
```

Then:

```bash
aws iam update-assume-role-policy --role-name tic-tac-toe-deploy \
  --policy-document file://deploy/iam/deployment-role-trust-policy.json
```

The deploy document exits with a distinct code per failure:

| Code | Meaning |
| --- | --- |
| 64 | The resolved tag is not a 40-character hex SHA |
| 65 | `release.env` is missing — step 6 |
| 66 | Under 2 GiB free after reclaiming images |
| 67 | A container's `org.opencontainers.image.revision` label does not match the deployed tag |
| 68 | A service is not running after `up --wait` |
| 69 | `jq` is not installed |
| 70 | Another deployment holds the lock |
| 71 | `Mode=fallback` with no `PREVIOUS_RELEASE_TAG` recorded |

For anything else:

```bash
docker compose ps
docker compose logs --tail=60
```

---

## Reclaiming disk

The instance keeps only the deploying and retained image pairs; the document deletes the rest by
name on every deployment. Under ordinary use there is nothing to reclaim.

**`docker image prune -f` is the wrong command** — it removes only *dangling* images, and every
image here carries a tag. `prune -a` is worse: it removes whatever no running container uses,
which is the retained pair the rollback depends on.

```bash
# Session Manager shell, on the instance.
docker system df
```

Two things accumulate outside that logic:

- **BuildKit cache.** Left over from when images were built on the instance. Nothing builds there
  now. Was 1.85 GB; cleared once with `docker builder prune -f`.
- **`tic-tac-toe-app:latest` and `tic-tac-toe-web:latest`.** The last locally built pair, outside
  the two GHCR repositories so the reclaim never sees them, about 184 MB shared.
  `docker rmi tic-tac-toe-app:latest tic-tac-toe-web:latest`.

Leave `caddy:2-alpine` — `compose.placeholder.yaml` refers to it.

A failed deployment's images are **not** reclaimed at the time: the reclaim runs before `up`, so
the failing containers still hold them and `docker rmi` refuses. The next ordinary deployment
removes them.

Use `docker system df`, not `docker image ls`, for real figures: `image ls` counts shared layers
once per image, so seven images listing at ~3.1 GB occupy 1.18 GB.

---

## Cost note

The instance and its Elastic IP bill continuously whether or not you deploy. GHCR storage and
transfer are free for public packages.

**Teardown** is in [`docs/aws-infra.md`](aws-infra.md). Bring the stack down first with
`cd /srv/tic-tac-toe && docker compose --env-file deploy/release.env down` (never `-v`), then
follow that document. Releasing the Elastic IP is the step that stops the meter.
