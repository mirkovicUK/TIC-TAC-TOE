# Deploying the stack, and scheduling the sweep

Task 13.3, step by step. Every command is copy-pasteable and says what it should print, so a
step that goes wrong is obvious rather than something you notice three steps later.

`docs/aws-infra.md` covers how the instance was built. This file covers only what happens
from here: swap, pull, key, build, verify, schedule.

## The state this file was written against

Checked on the instance before writing, not assumed:

| Fact | Value |
| --- | --- |
| Instance | `i-0c6bab4bc4644e760`, `t3.micro`, `eu-west-2` (London) |
| Hostname | `18-175-88-107.sslip.io` → Elastic IP `18.175.88.107` |
| Shell | Session Manager only. No key pair exists and port 22 is not open inbound |
| Repository | Already cloned at `/srv/tic-tac-toe`, owned by `ssm-user` |
| `caddy-data` volume | Exists, and holds the issued certificate for the hostname |
| Containers | None running. The task 2.2 placeholder was brought down |
| Memory | 911 MiB total, **no swap** |
| Disk | 19 G root, 3.1 G used, 16 G free |
| Docker | 29.7.2, Compose 5.4.0 |

Two consequences worth reading before you start. The certificate already exists, so nothing
in this run should contact Let's Encrypt — if you see an ACME request in Caddy's logs,
something is wrong with the volume and step 5 is where to look. And 911 MiB with no swap is
not enough headroom for the asset build with confidence, which is why swap is step 2 rather
than a troubleshooting note.

Total time: about 20 minutes, most of it the image build.

---

## 1. Open a shell on the instance

Run this **on your laptop**. Both tools it needs — the AWS CLI and `session-manager-plugin` —
are already installed.

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env
aws ssm start-session --target "$IID"
```

Sourcing rather than typing the identifiers is the better habit and is why this file uses it:
`.provisioned.env` exports `IID`, `AWS_DEFAULT_REGION=eu-west-2`, `EIP` and `HOST`, so the
instance id cannot be mistyped, `--region` is unnecessary, and `$HOST` is available for the
verification steps later. It is gitignored, so it exists only on your machine.

If that file is ever lost, the literal equivalent is
`aws ssm start-session --target i-0c6bab4bc4644e760 --region eu-west-2`.

Then, in the session:

```bash
whoami
id
docker ps
ls -ld /srv/tic-tac-toe
```

Expected: `ssm-user`; `docker` among the groups in `id`; an empty container table with just
headers; and the directory owned by `ssm-user`.

If `docker ps` says `permission denied` on `/var/run/docker.sock`, your session predates the
group change — type `exit` and open a new one.

Everything from here runs in this session unless a step says otherwise.

---

## 2. Add swap, before the build rather than after it fails

The asset build runs `npm ci` and Vite inside the image build. On 911 MiB with no swap the
kernel can kill it, and the symptom is unhelpful: Vite prints `Killed` and the build fails
with no explanation of why.

```bash
sudo fallocate -l 1G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
free -h
```

Expected: `free -h` now shows a `Swap:` row of `1.0Gi` total. The `/etc/fstab` line is what
makes it survive a reboot; without it a restart silently returns you to no swap.

---

## 3. Pull the new commits

```bash
cd /srv/tic-tac-toe
git pull
git log --oneline -3
ls -l compose.yaml deploy/Caddyfile.production deploy/app.env.example
```

Expected: the top commit is `Cap the container logs, which Docker does not do by default`,
and all three files listed exist.

Do **not** use `sudo git`. The directory belongs to `ssm-user`, and git run as root refuses
it with `detected dubious ownership`.

If `git pull` reports a conflict on `deploy/Caddyfile.local`, that file should not be tracked
— send me the output rather than resolving it, because it would mean something about the
2.2 setup differs from what is recorded.

---

## 4. Confirm the certificate is where Compose expects it

This is the check that costs nothing and prevents the one failure that cannot be undone in a
hurry. `compose.yaml` declares `caddy-data` as an external volume, so if it were missing,
Compose would either refuse to start or Caddy would start with empty storage and request a
second certificate against a rate-limit bucket shared with every other user of `sslip.io`.

```bash
docker volume ls | grep caddy
docker run --rm -v caddy-data:/data alpine ls /data/caddy/certificates/acme-v02.api.letsencrypt.org-directory/18-175-88-107.sslip.io
```

Expected: exactly one volume named `caddy-data` with **no project prefix**, and three files —
`18-175-88-107.sslip.io.crt`, `.key` and `.json`.

A volume called `deploy_caddy-data` or `tic-tac-toe_caddy-data` in that listing is the warning
sign. Stop and ask if you see one.

---

## 5. Create the APP_KEY file

`deploy/app.env` is gitignored, so it is not in the clone and nothing brought it here. It has
to be made once, on this box.

```bash
cd /srv/tic-tac-toe
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" > deploy/app.env
chmod 600 deploy/app.env
wc -c deploy/app.env
```

Expected: exactly 60 bytes. `openssl rand -base64 32` produces exactly what
`php artisan key:generate` does — 32 random bytes, base64-encoded, which is the key length
AES-256-CBC needs. Doing it this way avoids needing the image before the image is built.

**Generate this once and never regenerate it.** A Player_Token exists only inside the
server-side session, and the only thing linking a browser to its session is the cookie this
key encrypts. Rotate it and every player is locked out of every game in progress, with no
accounts to recover through. It also does not need to match the key on your laptop; the two
environments share nothing.

Sanity check that it is not tracked:

```bash
git status --porcelain deploy/
```

Expected: no output at all. Any line mentioning `app.env` means it is not being ignored — stop
and tell me.

---

## 6. Build and start

```bash
cd /srv/tic-tac-toe
docker compose up -d --build
```

This builds both images on the box — Composer, then npm and Vite, then php-fpm and Caddy.
Expect **8 to 15 minutes** on two vCPUs. It is normal for it to sit quietly on
`composer install` and on `npm ci`.

Expected at the end: `Container tic-tac-toe-app-1 Started` and
`Container tic-tac-toe-web-1 Started`.

If it fails with `Killed` during `npm run build`, swap did not take — go back to step 2 and
check `free -h`, then re-run this command.

If it fails with `env file .../deploy/app.env not found`, step 5 did not complete.

---

## 7. Verify the stack

### On the instance

```bash
docker compose ps
```

Expected: `app` showing `(healthy)` and `web` showing `Up`. The health status takes up to a
minute after start, because the healthcheck has a 40-second grace period covering the
entrypoint's migration.

If `app` shows `(unhealthy)`, get the reason before anything else:

```bash
docker inspect --format '{{json .State.Health}}' tic-tac-toe-app-1 | head -c 800
docker compose logs app --tail=40
```

### The negative check — this is the one that matters

```bash
sudo ss -ltnp | grep 9000 || echo "9000 not listening — correct"
docker compose ps --format '{{.Service}} {{.Ports}}'
```

Expected: the `correct` message, and `app` showing `9000/tcp` **without** any `0.0.0.0:` or
`:::` prefix. A published 9000 would mean anything reaching this host can speak FastCGI to
php-fpm, and because the application trusts `X-Forwarded-For` from any peer, the IP-keyed
half of the rate limiter could be spoofed away. Nothing in the test suite can detect this;
this command is the only place it is actually observed.

You will also see port 22 listening. That is `sshd` running locally, and it is fine — the
security group does not allow 22 inbound, which is what closes it.

### From your laptop

In a second terminal, on your laptop. A new terminal has none of the variables, so source the
file again first.

```bash
cd ~/Desktop/tic-tac-toe && source deploy/.provisioned.env
curl -s "https://$HOST/health"
curl -sI "https://$HOST/" | head -5
curl -sI "http://$HOST/" | head -3
```

Expected: `{"status":"ok","persistence":"reachable"}`; then `HTTP/2 200`; then a `308`
redirect to `https://`. No certificate warning from curl — if you get one, the certificate
was re-issued rather than reused and step 4 is where the explanation is.

### Confirm no new certificate was requested

```bash
docker compose logs web | grep -iE 'obtain|acme|certificate' | head -20
```

Expected: nothing about obtaining a certificate. Lines about certificate *maintenance* are
normal — that is Caddy checking expiry on the one it already has.

---

## 8. Play a real game

The suite covers this, but nothing has yet driven the deployed stack through two browsers.

1. Open `https://18-175-88-107.sslip.io/` in a browser and create a game.
2. Copy the join link.
3. Open it in a **different browser or an incognito window** — not another tab. The session is
   the identity, so the same session rejoins as X and you will be playing yourself.
4. Play to a win, then use the rematch control.

Then check the mandated records arrived:

```bash
docker compose logs app | grep -o '"message":"game\.[a-z_]*"' | sort | uniq -c
```

Expected: counts for `game.created`, `game.joined`, `game.move_accepted`, `game.finished` and
`game.rematch_created`. These are Requirement 10.3's lifecycle records, on stderr as one JSON
object per line, and no Player_Token or Join_Code should appear anywhere in that stream.

Spot-check that last point:

```bash
docker compose logs app | grep -cE '"(token|join_code|player_token)":"[^"]' || echo "no secrets in the log — correct"
```

---

## 9. Schedule the sweep

Run it once by hand first, so a cron failure later is unambiguous.

```bash
cd /srv/tic-tac-toe
docker compose exec -T app php artisan games:sweep
```

Expected three lines, all likely zero on a fresh deployment:

```
Games deleted: 0
Games deferred (a rematch survives): 0
Expiry records purged: 0
```

Zeroes are a success, not a no-op failure: the retention thresholds are lower bounds, so an
empty sweep is the ordinary case.

Now install the crontab entry. `crontab -e` opens an editor, which is awkward over Session
Manager, so append it non-interactively:

```bash
( crontab -l 2>/dev/null; echo '17 3 * * * cd /srv/tic-tac-toe && docker compose exec -T app php artisan games:sweep 2>&1 | logger -t games-sweep' ) | crontab -
crontab -l
systemctl is-active cron
```

Expected: the line echoed back by `crontab -l`, and `active` from the last command. If cron is
not active, `sudo systemctl enable --now cron`.

Notes on the shape of that line. `-T` disables the pseudo-TTY, without which the command fails
under cron with `the input device is not a TTY`. The `logger -t games-sweep` sends output to
the journal instead of mailing it to a local user nobody reads; check it later with
`journalctl -t games-sweep`. And no scheduler process runs inside the application — the cadence
lives here on the host deliberately (ADR-007).

Verify the line works as written, rather than trusting that it parses:

```bash
cd /srv/tic-tac-toe && docker compose exec -T app php artisan games:sweep 2>&1 | logger -t games-sweep
journalctl -t games-sweep --no-pager | tail -5
```

Expected: the three counts appear in the journal. If `journalctl` prints nothing, prefix it
with `sudo` — reading the system journal depends on group membership and is not worth
debugging here.

---

## 10. Check the disk, and prune

Each `--build` leaves the previous images dangling, and the `app` image is about 640 MB. This
is a larger claim on the 19 G volume than the logs, which are capped at 30 MB per service.

```bash
df -h /
docker system df
docker image prune -f
df -h /
```

Expected: reclaimed space on the second `df`, and plenty free either way on the first deploy.
Worth repeating after every future rebuild.

---

## When it is done

Tell me and I will tick 13.3 in `tasks.md` with what was actually observed, the same way the
other tasks record it. Group 15 is then the README, which needs the hosted URL this step
proves works.

## If something goes wrong

Send me the output of the failing command plus the two below, which are almost always what I
would ask for next:

```bash
docker compose ps
docker compose logs --tail=60
```

Known failure shapes, so you can recognise them:

| Symptom | Cause |
| --- | --- |
| Build dies at `npm run build`, prints `Killed` | Out of memory. Swap missing — step 2 |
| `env file ... deploy/app.env not found` | Step 5 not done |
| `external volume "caddy-data" not found` | Wrong instance, or the volume was removed |
| Certificate warning in the browser | Caddy started with empty storage and re-issued. Stop and ask; this one has a rate limit behind it |
| `502 Bad Gateway` | `web` is up and `app` is not. `docker compose logs app` |
| `the input device is not a TTY` | A `docker compose exec` without `-T` under cron |
| `detected dubious ownership` from git | You used `sudo git`. Don't |

## Teardown, when you are finished with the instance

```bash
cd /srv/tic-tac-toe && docker compose down
```

**Never `down -v`.** That deletes the volumes, and one of them holds a certificate whose
replacement depends on a rate limit shared with strangers. `docs/aws-infra.md` carries the
full teardown, including releasing the Elastic IP — which is the step that stops the meter.
