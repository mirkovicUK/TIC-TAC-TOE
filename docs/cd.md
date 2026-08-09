# Continuous deployment: the steps only you can run

The pipeline cannot create its own permission to exist, and it cannot click a button in
GitHub's settings. Everything else in `.kiro/specs/continuous-deployment/tasks.md` is a file
somebody writes; the five steps below are yours, and three of them have to happen at a
particular moment or the deployment breaks.

Read the **When** column first. The order is not a suggestion.

| # | Step | When | Where |
| --- | --- | --- | --- |
| 1 | Create the GitHub OIDC provider | Any time. Safe now | AWS, your laptop |
| 2 | Create the deployment role | After tasks 1.1 and 1.2 write the policy files | AWS, your laptop |
| 3 | Add the role ARN as a repository variable | Straight after step 2 | GitHub settings |
| 4 | Seed `release.env` on the instance | **Before task 4.1 lands.** Miss this and the site stops | Session Manager |
| 5 | Make both GHCR packages public | After the first `publish` run, before task 6.1 lands | GitHub settings |

Facts these steps use, all verified: account `811362454196`, region `eu-west-2`, instance
`i-0c6bab4bc4644e760`, repository `mirkovicUK/TIC-TAC-TOE`, deployment branch `main`.

---

## Step 1 — Create the GitHub OIDC provider

**When:** any time. Nothing depends on it yet and it changes nothing that is running.

This registers GitHub as an identity provider your AWS account will accept tokens from. Your
account currently has only a Vercel provider, so this is the first for GitHub.

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env

aws iam create-open-id-connect-provider \
  --url https://token.actions.githubusercontent.com \
  --client-id-list sts.amazonaws.com
```

Expected: JSON containing an ARN ending
`:oidc-provider/token.actions.githubusercontent.com`.

No thumbprint argument is needed — AWS manages GitHub's. Confirm it took:

```bash
aws iam list-open-id-connect-providers
```

You should see the new provider alongside the existing Vercel one.

---

## Step 2 — Create the deployment role

**When:** after tasks 1.1 and 1.2 have written `deploy/iam/deployment-role-trust-policy.json`
and `deploy/iam/deployment-role-permissions-policy.json`. The role is created *from* those
files, so they must exist first.

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

Then check the trust policy is as tight as intended, because this is the one thing here with a
security consequence:

```bash
aws iam get-role --role-name tic-tac-toe-deploy \
  --query 'Role.AssumeRolePolicyDocument' | grep -i 'sub\|aud'
```

You are looking for `StringEquals` with the full value
`repo:mirkovicUK/TIC-TAC-TOE:ref:refs/heads/main` and **no `*` anywhere in it**. A `StringLike`
with `repo:mirkovicUK/TIC-TAC-TOE:*` would let a pull request from a fork assume this role and
deploy. AWS rejects a `sub` condition that is *only* a wildcard, but it will happily accept one
that is merely too broad — so this check is on you, not on AWS.

Note the role ARN from the output. You need it for step 3.

---

## Step 3 — Add the role ARN as a repository variable

**When:** straight after step 2.

In GitHub: **Settings → Secrets and variables → Actions → Variables → New repository
variable**.

- Name: `AWS_DEPLOY_ROLE_ARN`
- Value: `arn:aws:iam::811362454196:role/tic-tac-toe-deploy`

A **variable**, not a secret. It names a role that only this repository's `main` branch can
assume, so it is not sensitive, and a variable stays readable in the workflow file's context
where a secret would be masked in logs for no benefit.

---

## Step 4 — Seed `release.env` on the instance

**When: before task 4.1 lands.** This is the step with teeth. Once `compose.yaml` uses
`${RELEASE_TAG:?…}`, Compose refuses to start anything without that variable — correct
behaviour, and an outage if the file is not already there.

Open a shell on the instance:

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env
aws ssm start-session --target "$IID"
```

Find the commit the running stack was built from, then write the file:

```bash
cd /srv/tic-tac-toe
git rev-parse HEAD          # the SHA currently checked out and running

printf 'RELEASE_TAG=%s\nPREVIOUS_RELEASE_TAG=\n' "$(git rev-parse HEAD)" > deploy/release.env
chmod 600 deploy/release.env
cat deploy/release.env
```

Expected: two lines, the first carrying a 40-character SHA, the second empty.

`PREVIOUS_RELEASE_TAG` is deliberately empty. There is no earlier published image to fall back
to yet, and the pipeline handles that case explicitly — the first deployment that fails its
health check will report that no fallback was possible and leave itself running, rather than
stopping the stack.

`deploy/release.env` is gitignored and lives only on the instance, like `deploy/app.env`.

---

## Step 5 — Make both GHCR packages public

**When:** after the `publish` job has run once and before task 6.1 lands. The packages cannot
be made public until they exist, and the deploy job cannot pull them until they are public.

**This is the step most likely to break your first deployment.** New GHCR packages inherit
private visibility, and the instance holds no registry credential by design. The symptom is a
pull failure that does not obviously say "permissions".

Sequence:

1. Land task 5.1 and push to `main`. Watch `publish` go green.
2. Go to your GitHub profile → **Packages**. You should see `tic-tac-toe-app` and
   `tic-tac-toe-web`.
3. For **each** one: **Package settings → Danger Zone → Change visibility → Public**.
4. Confirm from your laptop, with no credentials in play:

```bash
docker pull ghcr.io/mirkovicuk/tic-tac-toe-app:$(git rev-parse HEAD)
```

If that pull works without `docker login`, the instance will manage it too.

---

## After the five steps: pushing is the whole workflow

```bash
composer lint && composer analyse && npx tsc --noEmit && npm run build
./vendor/bin/pest --exclude-group=browser
git commit && git push origin main
```

Then watch the Actions tab. `quality` → `browser` → `publish` → `deploy`, and the deploy job
health-gates the result. If the new release does not answer within two minutes, the pipeline
puts the previous tag back and marks the run **red**. A red run with the site up is the
pipeline working.

What to check on a normal green run:

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env
curl -s "https://$HOST/health"
```

And on the instance, what is actually running:

```bash
cat /srv/tic-tac-toe/deploy/release.env
```

---

## Rolling back by hand

The pipeline reverts automatically when a health gate fails. If you need to revert for another
reason — a defect that is serving fine but wrong — do it on the instance:

```bash
cd /srv/tic-tac-toe
cat deploy/release.env                      # note both values

# put the previous tag in place, keeping the record of what it replaced
sed -i "s/^RELEASE_TAG=.*/RELEASE_TAG=<previous-sha>/" deploy/release.env
docker compose --env-file deploy/release.env up -d
curl -s https://18-175-88-107.sslip.io/health
```

Do **not** run `docker compose down -v`. The `-v` removes volumes, and one of them holds the
TLS certificate whose replacement depends on a Let's Encrypt rate limit shared with every other
user of `sslip.io`.

Do **not** run `php artisan migrate:rollback`. Rolling an image back does not roll the database
back, and the `down()` method on the games migration is `Schema::dropIfExists('games')` — it
would delete every game and move. Schema changes move forward only; a bad migration is fixed by
a new migration.

---

## When something goes wrong

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Error: Not authorized to perform sts:AssumeRoleWithWebIdentity` | Trust policy `sub` does not match, or step 1 was skipped | Compare the `sub` value against `repo:mirkovicUK/TIC-TAC-TOE:ref:refs/heads/main` exactly |
| `AccessDeniedException` on `ssm:SendCommand` | Permissions policy missing the instance ARN or the document ARN — it needs both | Re-apply step 2's `put-role-policy` |
| Deploy fails pulling the image | Packages still private — step 5 | Set both to Public |
| `RELEASE_TAG must be set` | `release.env` missing or empty — step 4 | Recreate it on the instance |
| `detected dubious ownership` | A `git` command ran as root in `/srv/tic-tac-toe` | The deploy script must use `sudo -u ssm-user`; do the same by hand |
| Health gate fails, previous tag restored, run red | Working as designed | Read `docker compose logs app` on the instance |
| Every deploy now fails on a migration that "already exists" | A migration applied part way. Laravel opens no transaction for SQLite | Fix by hand on the instance, then keep migrations to one change each |
| Site up but unstyled, run red | The two images are from different commits | Republish; do not revert |

For anything else, the two commands worth having before asking:

```bash
docker compose ps
docker compose logs --tail=60
```

---

## Cost note

The instance and its Elastic IP bill continuously whether or not you deploy. `docs/aws-infra.md`
carries the teardown, and releasing the address is the step that stops the meter. GHCR storage
and transfer are free for public packages, so the registry adds nothing.
