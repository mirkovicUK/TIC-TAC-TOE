# AWS infrastructure runbook (spec task 2)

This provisions one EC2 instance in `eu-west-2` with an Elastic IP, a security group open only on 80 and 443, an instance profile carrying nothing but `AmazonSSMManagedInstanceCore`, Docker with the Compose plugin, and one Let's Encrypt certificate for `<eip-dashed>.sslip.io` in an external Docker volume. Every step is AWS CLI run by hand; nothing here is automated (ADR-009). It runs before the application exists because `sslip.io` is one registered domain shared by all its users, so an issuance can be refused because of strangers' usage, and a refusal is recoverable a week out but not on submission day.

Each fenced block opens with a comment saying which machine it runs on.

## Prerequisites

- `aws --version` reports 2.x, and `aws sts get-caller-identity` returns an account.
- Every command assumes `eu-west-2`. Set it once per session: `export AWS_DEFAULT_REGION=eu-west-2`.
- `session-manager-plugin` is a separate install from the CLI and `aws ssm start-session` fails without it:

```bash
# Local machine.
curl -fsSL "https://s3.amazonaws.com/session-manager-downloads/plugin/latest/ubuntu_64bit/session-manager-plugin.deb" -o /tmp/session-manager-plugin.deb
sudo dpkg -i /tmp/session-manager-plugin.deb
session-manager-plugin --version
```

- `deploy/Caddyfile` and `deploy/compose.placeholder.yaml` must be on `main` before Part 2, because Part 2 clones the public repo onto the instance to get them. The commit that accompanies this runbook satisfies that.

## Decisions

| Decision | Reason |
| --- | --- |
| Region `eu-west-2` | UK company, UK reviewer; change it consistently everywhere if you change it at all. |
| `t3.micro`, x86_64 | The image is built on the box and the dev machine is amd64, so matching removes a class of architecture-specific build failure. |
| Ubuntu 24.04 LTS amd64, resolved from the SSM public parameter | AMI ids are region-specific and change with every Canonical rebuild, so never hardcode one. |
| Root volume 20 GB gp3 | Images plus build layers, with headroom for a rebuild that does not prune first. |
| IMDSv2 required | Closes the SSRF path to the instance role's credentials, and this instance carries a role. |
| No key pair, no inbound 22 | Shell is Session Manager only, so there is no private key to leak and the most-scanned port is absent. |
| `/srv/tic-tac-toe` | The design's `games:sweep` crontab entry already assumes this path. |
| Hostname `<eip-dashed>.sslip.io` | No registration, no DNS record; resolves to the address embedded in the name. |

Swap: 1 GiB of RAM is enough to run the stack but `npm run build` at task 13 can be OOM-killed (symptom: Vite dies with `Killed`). If that happens, add swap and rebuild.

```bash
# Session Manager shell, on the instance. Only if the image build is OOM-killed.
sudo fallocate -l 1G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
free -h
```

## Cost

- A `t3.micro`, a 20 GB gp3 volume and one Elastic IP in `eu-west-2`. Whether any of it is free is account-specific, so check your own Billing and Cost Management → Free tier page rather than a figure in a document.
- An Elastic IP is charged whenever it is not associated with a running instance, and since 2024 every public IPv4 address carries an hourly charge regardless.
- So stopping the instance while keeping the address is the worst of the three options: no compute, still paying for volume and address. Either leave it running until reviewed, or [tear it down](#teardown).

## Part 1 — task 2.1: infrastructure

Keep one terminal open for the whole of Part 1. The steps chain shell variables so nothing is transcribed twice, and step 8 writes them to a gitignored file so a new terminal can re-source them.

### 1. Session variables

```bash
# Local machine.
export AWS_DEFAULT_REGION=eu-west-2
export NAME=tic-tac-toe
aws sts get-caller-identity --query Account --output text
```

### 2. IAM role and instance profile

The console creates the instance profile for you and the CLI does not, so this is four calls rather than two.

```bash
# Local machine.
cat > /tmp/ec2-trust.json <<'EOF'
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"ec2.amazonaws.com"},"Action":"sts:AssumeRole"}]}
EOF

aws iam create-role --role-name "$NAME-ssm" \
  --assume-role-policy-document file:///tmp/ec2-trust.json
aws iam attach-role-policy --role-name "$NAME-ssm" \
  --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
aws iam create-instance-profile --instance-profile-name "$NAME-ssm"
aws iam add-role-to-instance-profile --instance-profile-name "$NAME-ssm" --role-name "$NAME-ssm"
```

Verify: `aws iam list-attached-role-policies --role-name "$NAME-ssm" --query 'AttachedPolicies[].PolicyName'` returns exactly `["AmazonSSMManagedInstanceCore"]` and nothing else. The role is the blast radius on a box deliberately exposed to the internet, so anything extra widens it for no need.

### 3. Security group

```bash
# Local machine.
export VPC_ID=$(aws ec2 describe-vpcs --filters Name=is-default,Values=true \
  --query 'Vpcs[0].VpcId' --output text)
export SG_ID=$(aws ec2 create-security-group --group-name "$NAME-sg" \
  --description "HTTP and HTTPS only; shell via Session Manager" \
  --vpc-id "$VPC_ID" --query GroupId --output text)

aws ec2 authorize-security-group-ingress --group-id "$SG_ID" --ip-permissions \
  'IpProtocol=tcp,FromPort=80,ToPort=80,IpRanges=[{CidrIp=0.0.0.0/0}],Ipv6Ranges=[{CidrIpv6=::/0}]' \
  'IpProtocol=tcp,FromPort=443,ToPort=443,IpRanges=[{CidrIp=0.0.0.0/0}],Ipv6Ranges=[{CidrIpv6=::/0}]'
```

Verify (**NEGATIVE**):

```bash
aws ec2 describe-security-groups --group-ids "$SG_ID" \
  --query 'SecurityGroups[0].IpPermissions[].FromPort'
```

Expect `[80, 443]`. Anything containing 22 is wrong. 80 is needed as well as 443 for the ACME HTTP-01 challenge and the HTTP-to-HTTPS redirect. Leave egress at the default allow-all, because the SSM Agent dials *out* and that outbound channel is the only way in.

### 4. AMI, from the SSM public parameter

```bash
# Local machine.
export AMI_ID=$(aws ssm get-parameter \
  --name /aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id \
  --query 'Parameter.Value' --output text)
echo "$AMI_ID"
```

Resolving it beats hardcoding because AMI ids are region-specific and change with every Canonical rebuild.

### 5. User data

```bash
# Local machine.
cat > /tmp/user-data.sh <<'EOF'
#!/bin/bash
# Insurance only. On a 24.04 AMI the SSM Agent is already present and this is a no-op.
snap wait system seed.loaded
snap list amazon-ssm-agent >/dev/null 2>&1 || snap install amazon-ssm-agent --classic
snap start amazon-ssm-agent 2>/dev/null || true
EOF
```

User data can run before snapd has finished seeding, and while that is true both `snap list` and `snap install` fail with `too early for operation, device not yet seeded`, so the guard falls through to an install that also fails and the insurance is defeated in exactly the case it exists for. `snap wait system seed.loaded` blocks until snapd is ready. It matters because with no key pair and no inbound 22, an absent agent means the only remedy is terminate and relaunch.

### 6. Launch

```bash
# Local machine.
export IID=$(aws ec2 run-instances \
  --image-id "$AMI_ID" \
  --instance-type t3.micro \
  --security-group-ids "$SG_ID" \
  --iam-instance-profile "Name=$NAME-ssm" \
  --metadata-options 'HttpTokens=required,HttpEndpoint=enabled' \
  --block-device-mappings '[{"DeviceName":"/dev/sda1","Ebs":{"VolumeSize":20,"VolumeType":"gp3","DeleteOnTermination":true}}]' \
  --user-data file:///tmp/user-data.sh \
  --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=$NAME}]" \
  --query 'Instances[0].InstanceId' --output text)
echo "$IID"

aws ec2 wait instance-running --instance-ids "$IID"
```

- There is no `--key-name` argument. That is the deliberate omission, not an oversight.
- IAM is eventually consistent, so `run-instances` immediately after step 2 can fail with `Invalid IAM Instance Profile name`; wait about ten seconds and re-run the same command, because it is not a mistake in the arguments.
- `/dev/sda1` is the root device on Canonical's Ubuntu AMIs; if the block device mapping is rejected, confirm with `aws ec2 describe-images --image-ids "$AMI_ID" --query 'Images[0].RootDeviceName' --output text`.

Verify:

```bash
aws ec2 describe-instances --instance-ids "$IID" --query \
  'Reservations[0].Instances[0].{state:State.Name,type:InstanceType,keyName:KeyName,profile:IamInstanceProfile.Arn,imds:MetadataOptions.HttpTokens}'
```

Expect `state: running`, `type: t3.micro`, `keyName: null` (**NEGATIVE**), a profile ARN ending `/tic-tac-toe-ssm`, and `imds: required`.

### 7. Elastic IP

```bash
# Local machine.
export ALLOC_ID=$(aws ec2 allocate-address --domain vpc \
  --tag-specifications "ResourceType=elastic-ip,Tags=[{Key=Name,Value=$NAME}]" \
  --query AllocationId --output text)
aws ec2 associate-address --instance-id "$IID" --allocation-id "$ALLOC_ID"
export EIP=$(aws ec2 describe-addresses --allocation-ids "$ALLOC_ID" \
  --query 'Addresses[0].PublicIp' --output text)
export HOST=$(echo "$EIP" | tr '.' '-').sslip.io
echo "$EIP  ->  $HOST"
```

The dashed form is used because it is a single DNS label and cannot be misread as a subdomain of a numeric label.

### 8. Save the variables

```bash
# Local machine.
cat > deploy/.provisioned.env <<EOF
export AWS_DEFAULT_REGION=eu-west-2
export NAME=$NAME
export SG_ID=$SG_ID
export IID=$IID
export ALLOC_ID=$ALLOC_ID
export EIP=$EIP
export HOST=$HOST
EOF
```

Gitignored, because it identifies one provisioned instance. A new terminal picks the session back up with `source deploy/.provisioned.env`, and teardown reads the same file.

### 9. Wait for the managed node

```bash
# Local machine.
aws ssm describe-instance-information \
  --filters "Key=InstanceIds,Values=$IID" \
  --query 'InstanceInformationList[0].{ping:PingStatus,platform:PlatformName,agent:AgentVersion}'
```

Expect `ping: Online`; allow two or three minutes. Still absent after five means one of three things:

1. The profile is not attached: check `aws ec2 describe-instances --instance-ids "$IID" --query 'Reservations[0].Instances[0].IamInstanceProfile'`.
2. Egress is broken, so the agent cannot reach the SSM endpoints on outbound 443.
3. The agent is absent. This one cannot be diagnosed without a shell, which is why step 5 exists, and the remedy is terminate and relaunch.

### 10. Confirm DNS

```bash
# Local machine.
dig +short "$HOST"
```

Expect the Elastic IP. Run it locally rather than on the instance, because you are testing public DNS.

### 11. Install Docker, unattended, via Run Command

Run Command beats an interactive install because it runs as root, is idempotent, and returns a status you can read.

```bash
# Local machine.
cat > /tmp/install-docker.sh <<'EOF'
#!/bin/bash
set -euo pipefail
apt-get update
apt-get install -y ca-certificates curl git jq
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu noble stable" > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
mkdir -p /srv/tic-tac-toe
docker --version
docker compose version
EOF

python3 -c 'import json; print(json.dumps({"commands": [open("/tmp/install-docker.sh").read()]}))' > /tmp/ssm-params.json

export CMD_ID=$(aws ssm send-command \
  --instance-ids "$IID" \
  --document-name AWS-RunShellScript \
  --comment "docker, compose plugin, git, deploy directory" \
  --parameters file:///tmp/ssm-params.json \
  --query 'Command.CommandId' --output text)
echo "$CMD_ID"
```

Run those two blocks exactly as written: the quoted heredoc delimiter keeps `$(dpkg --print-architecture)` literal so it expands on the instance, and the `python3` line is there to get the script into JSON with correct escaping rather than fighting shell quoting inside `--parameters`. `noble` is 24.04's codename and changes with the release.

**`jq` is a declared dependency, not incidental.** It was already present on this box, so the install line is belt-and-braces — but the `DeployTicTacToe` SSM document (`deploy/ssm/DeployTicTacToe.json`) reads container labels with it and exits 69 without it. It cannot use `docker inspect --format` instead, because SSM interprets a doubled curly brace as its own parameter substitution and a Go template would break the document. `flock`, also used by that document, is in `util-linux` and always present.

**Nothing in this step may reference `ssm-user`, and that is the whole reason it is split from step 13.** The SSM Agent creates that account lazily, when the first Session Manager session opens — not at boot. A `usermod -aG docker ssm-user` here fails with `user 'ssm-user' does not exist`, exit status 6, and `set -euo pipefail` then abandons the rest of the script, so the deploy directory and the version checks silently never happen. `mkdir` is safe because it names no user; the `chown` that goes with it waits for step 13.

```bash
# Local machine.
aws ssm get-command-invocation --command-id "$CMD_ID" --instance-id "$IID" \
  --query '{status:Status,out:StandardOutputContent,err:StandardErrorContent}'
```

Expect `status: Success` with both versions in `out`. It may report `InProgress` for a minute or so; re-run the same command.

### 12. Open a session once, to bring `ssm-user` into existence

```bash
# Local machine — opens an interactive shell on the instance.
aws ssm start-session --target "$IID"
```

```bash
# Session Manager shell, on the instance.
whoami
exit
```

Expect `whoami` to print `ssm-user`. This step is doing two jobs. It is the **gate**: with no key pair and no port 22 there is no other way in, every deploy command at spec task 13 runs through this path, and if it does not open, stop and fix it now rather than discovering it during the deployment — the remedy is terminate and relaunch, which is cheap here and expensive later. It is also what *creates* the account that step 13 modifies, so it cannot be skipped as a formality even if you are confident the shell works.

`session-manager-plugin` is a separate install from the AWS CLI; see Prerequisites if this fails before connecting.

### 13. Group membership and directory ownership

Now that the account exists, the two things that need it.

```bash
# Local machine.
cat > /tmp/post-session.sh <<'EOF'
#!/bin/bash
set -euo pipefail
if id -u ssm-user >/dev/null 2>&1; then
  usermod -aG docker ssm-user
  chown ssm-user: /srv/tic-tac-toe
  id ssm-user
else
  echo "ssm-user absent — open a session (step 12) first, then re-run this step"
  exit 1
fi
EOF

python3 -c 'import json; print(json.dumps({"commands": [open("/tmp/post-session.sh").read()]}))' > /tmp/ssm-params2.json

export CMD_ID2=$(aws ssm send-command \
  --instance-ids "$IID" \
  --document-name AWS-RunShellScript \
  --comment "docker group, deploy directory ownership" \
  --parameters file:///tmp/ssm-params2.json \
  --query 'Command.CommandId' --output text)

aws ssm get-command-invocation --command-id "$CMD_ID2" --instance-id "$IID" \
  --query '{status:Status,out:StandardOutputContent,err:StandardErrorContent}'
```

Expect `status: Success` and an `id` line listing `docker` among the groups. The `id -u` guard is there so running this out of order tells you what to do instead of failing with a bare `exit status 6`.

`chown ssm-user:` with the trailing colon sets the group to that user's login group without assuming a group named `ssm-user` exists, and it is deliberately not `$(id -u):$(id -g)` — Run Command runs as root, so that would hand the directory to root and quietly defeat the step.

Tradeoff accepted: membership of `docker` is equivalent to root, since anything in that group can start a container mounting the host filesystem. That is tolerable here because the only route to this shell is an IAM-authorised Session Manager session, and anyone who can reach it already holds credentials that could attach a more permissive role.

### 14. Verify in a *new* session

The session from step 12 predates the group change and will not have it. Group membership is fixed at login, so this must be a freshly opened session or `docker ps` fails on the socket while looking as though step 13 did not work.

```bash
# Local machine.
aws ssm start-session --target "$IID"
```

```bash
# Session Manager shell, on the instance — a freshly opened one.
docker ps
id
ls -ld /srv/tic-tac-toe
df -h /
sudo ss -ltnp | grep 9000 || echo "9000 not listening — correct"
```

Expect an empty container table with headers rather than a permission error on `/var/run/docker.sock`; `docker` among the groups in `id`; `/srv/tic-tac-toe` owned by `ssm-user`; `/` showing about 19–20G, because 8G means the block device mapping did not apply and `npm run build` will fill the disk at task 13; and the 9000 line printing the "correct" message. The design's `*` trusted-proxy range is only safe while php-fpm is unreachable from the host, so port 9000 stays unpublished.

A new terminal will not have the shell variables. `source deploy/.provisioned.env` first, or pass the instance id literally.

## Part 2 — task 2.2: the certificate

No application is involved. Caddy answers the ACME challenge itself from a pulled `caddy:2-alpine` image, so all this needs is a hostname and port 80.

### 1. External volume, by hand

```bash
# Session Manager shell, on the instance.
docker volume create caddy-data
docker volume ls | grep caddy-data
```

Compose prefixes project-scoped volumes with the invoking directory's name, so `deploy/` and the repository root would produce two different volumes and the certificate would not carry across to task 13. `external: true` with a fixed name cannot be prefixed.

### 2. Clone

```bash
# Session Manager shell, on the instance.
cd /srv/tic-tac-toe
git clone https://github.com/mirkovicUK/TIC-TAC-TOE.git .
cd deploy && ls
```

The trailing `.` clones into the existing empty directory; git refuses only a non-empty target.

### 3. Set the hostname, on an untracked copy

```bash
# Session Manager shell, on the instance, in /srv/tic-tac-toe/deploy.
cp Caddyfile Caddyfile.local
grep -q '<elastic-ip-dashed>' Caddyfile.local || echo "PLACEHOLDER NOT FOUND — check the committed Caddyfile"
sed -i '/^[^#]/ s/<elastic-ip-dashed>/203-0-113-5/' Caddyfile.local   # substitute your dashed IP
grep -v '^#' Caddyfile.local
```

The copy is untracked, so task 13's `git pull` is a clean fast-forward with nothing to stash, which is the whole reason for it. The `grep -q` guard is there because `sed` exits 0 whether or not it substituted anything. Run the `cp` before `docker compose up`: a bind mount whose host path does not exist makes Docker create a directory there, and Caddy then fails on a directory where it wants a file.

### 4. Up, and watch ACME

```bash
# Session Manager shell, on the instance, in /srv/tic-tac-toe/deploy.
docker compose -f compose.placeholder.yaml up -d
docker compose -f compose.placeholder.yaml logs -f
```

Success is `obtaining new certificate`, `trying issuer acme-v02...`, `served key authentication`, then `certificate obtained successfully`. A 429 naming `too many certificates already issued for: sslip.io` sends you to [Fallbacks](#fallbacks); Caddy retries with backoff, so a moving log is not progress. `Ctrl-C` detaches without stopping the container.

### 5. Confirm HTTPS

```bash
# Local machine.
curl -sSI "https://$HOST"
```

Expect `HTTP/2 200` and no TLS error. On failure, `curl -v "https://$HOST" 2>&1 | head -20` shows which stage of the handshake broke. Open it in a browser too, because a padlock with no interstitial is the actual requirement and the browser is what the reviewer uses.

### 6. Confirm the cert is in the volume

```bash
# Session Manager shell, on the instance.
docker run --rm -v caddy-data:/data alpine ls -R /data/caddy/certificates
```

Expect a directory for the ACME endpoint, a directory for the hostname inside it, and `.crt`, `.key` and `.json` inside that. HTTPS working proves an issuance happened, not that it landed where task 13 will look.

### 7. Confirm no project prefix

```bash
# Session Manager shell, on the instance.
docker volume ls | grep caddy
```

Expect exactly one line, named `caddy-data`. A `deploy_caddy-data` here means the declaration was not external and task 13 will issue a second time on submission day.

### 8. Down, without `-v`

```bash
# Session Manager shell, on the instance, in /srv/tic-tac-toe/deploy.
docker compose -f compose.placeholder.yaml down
```

Never `down -v`: the volume is the certificate and its private key, and although `-v` spares external volumes the habit transfers to task 13 where `sqlite-data` is not external.

### 9. Record the outcome

Write the hostname and the scheme down outside the instance. Three things consume it: task 13.2's production Caddyfile, the README's hosted URL (Req 12.4), and `SESSION_SECURE_COOKIE` (Req 10.11).

## Fallbacks

1. **`nip.io`, on a 429.** Separate registered domain, separate bucket. Check `dig +short <eip-dashed>.nip.io` first rather than spending an attempt, then `sed -i '/^[^#]/ s/sslip\.io/nip.io/' Caddyfile.local` and `docker compose -f compose.placeholder.yaml up -d --force-recreate`. Target `Caddyfile.local`, not `Caddyfile`.
2. **Plain HTTP with `SESSION_SECURE_COOKIE=false`.** Breaks no criterion, because Requirement 10.11 conditions `Secure` on being served over HTTPS. The README must state it as a decision.
3. **Register a domain, about £10.** Its own rate-limit bucket, which removes the problem rather than routing around it.

## Teardown

Order matters: the stack stops, the instance goes, then the address, then the group, then the role.

```bash
# Local machine.
source deploy/.provisioned.env
aws ec2 terminate-instances --instance-ids "$IID"
aws ec2 wait instance-terminated --instance-ids "$IID"
aws ec2 release-address --allocation-id "$ALLOC_ID"
aws ec2 delete-security-group --group-id "$SG_ID"
aws iam remove-role-from-instance-profile --instance-profile-name "$NAME-ssm" --role-name "$NAME-ssm"
aws iam delete-instance-profile --instance-profile-name "$NAME-ssm"
aws iam detach-role-policy --role-name "$NAME-ssm" --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
aws iam delete-role --role-name "$NAME-ssm"
```

Bring the stack down first with `cd /srv/tic-tac-toe && docker compose down` in a session shell. Terminating takes the root volume with it, and the Docker volumes live on that volume, so `caddy-data` and the SQLite database need no separate removal step. Releasing the address is the step that stops the meter, and the security group cannot be deleted until the instance is gone. Do not tear down before the submission has been reviewed, because releasing the address loses the hostname the certificate certifies.

## Checklist

- [ ] **NEGATIVE:** `keyName: null` in the `describe-instances` output
- [ ] **NEGATIVE:** the SG's `FromPort` list is `[80, 443]`, with no 22
- [ ] **NEGATIVE:** `sudo ss -ltnp | grep 9000` finds nothing
- [ ] `list-attached-role-policies` returns only `AmazonSSMManagedInstanceCore`
- [ ] `imds: required` and a profile ARN ending `/tic-tac-toe-ssm`
- [ ] `describe-instance-information` shows `ping: Online`
- [ ] `whoami` in the Session Manager shell returns `ssm-user`
- [ ] `docker ps` in a session opened **after** step 13 prints an empty table, not a socket permission error
- [ ] `id` lists `docker` among `ssm-user`'s groups
- [ ] `ls -ld /srv/tic-tac-toe` shows it owned by `ssm-user`, not root
- [ ] `df -h /` shows a root filesystem of about 19–20G, not 8G
- [ ] `dig +short "$HOST"` from the **local** machine returns the Elastic IP
- [ ] `https://$HOST` loads in a **browser** with a padlock and no interstitial
- [ ] `.crt`, `.key` and `.json` present under `/data/caddy/certificates` in `caddy-data`
- [ ] `docker volume ls | grep caddy` shows exactly one line, `caddy-data`, with no project prefix


cd /home/ubuntu/Desktop/tic-tac-toe && source deploy/.provisioned.env

aws ssm start-session --target "$IID"
