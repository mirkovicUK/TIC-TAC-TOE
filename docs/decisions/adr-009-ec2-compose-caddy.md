# ADR-009: EC2 with Docker Compose and Caddy

Bears on Requirements 10.11, 12.4 and 12.9.

## Decision

A single EC2 instance with an Elastic IP, Docker Compose, Caddy for TLS, and the image built
on the box. TLS needs a hostname and an Elastic IP is not one, so Caddy is configured for
`<elastic-ip-dashed>.sslip.io`, which resolves to the address without registering a domain.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| A PaaS free tier | The local Compose file and the hosted instance would then be different things, free to drift |
| ECS or Fargate | Task definitions, a registry and a load balancer, for one container pair |
| Kubernetes | Would cost more of the budget than the whole application |
| nginx in place of Caddy | Caddy supplies automatic HTTPS in two lines of configuration |

## Reason

The same Compose file runs locally and in production, so the README's local instructions and
the hosted instance cannot drift apart.

`sslip.io` supplies the hostname the certificate needs without a domain purchase, which is
what lets Let's Encrypt issue a real certificate and makes `SESSION_SECURE_COOKIE=true`
honest (Req 10.11).

Orchestration platforms would cost more of the budget than the application they hosted.

## The certificate risk, and what is done about it

Let's Encrypt applies its issuance limit **per registered domain**, and `sslip.io` is a
single registered domain shared by everyone who uses it. The bucket is shared and can be
exhausted by strangers.

The probability is low; the failure mode is what matters. An exhausted limit means the hosted
instance serves a browser TLS interstitial on the one link a reviewer clicks, which reads
considerably worse than plain HTTP.

Four mitigations, in the order they apply:

1. **`nip.io` as a documented fallback.** A separate registered domain, and therefore a
   separate rate-limit bucket. If issuance against `<elastic-ip>.sslip.io` is refused, retry
   against `<elastic-ip>.nip.io`. Caddy needs one line changed.
2. **Provision the certificate several days before submission, not on submission day.** The
   strongest mitigation, and it is scheduling rather than design. A certificate lasts 90 days
   and only one successful issuance is needed, so a refusal with a week of slack is
   recoverable and a refusal on the day is not. It only helps if the certificate *survives*
   into the real deployment: the placeholder Caddy and the `web` service must mount a volume
   that is `external` with the fixed name `caddy-data` at `/data`, not merely one declared
   under the same name in both files. Compose prefixes project-scoped volumes with the
   invoking directory's name, and the two stacks are invoked from different directories, so
   otherwise the later `docker compose up` starts with empty storage, issues a second time,
   and the slack is spent for nothing.
3. **A plain-HTTP fallback.** If TLS cannot be obtained at all, serve HTTP with
   `SESSION_SECURE_COOKIE=false`. This breaks no criterion: Requirement 10.11 conditions the
   `Secure` attribute on being served over HTTPS, and `HttpOnly` and `SameSite` are
   unaffected.
4. **Registering a real domain**, at around ten pounds, removes the problem outright. Worth
   weighing against the time spent reasoning about a shared rate limit, given that the hosted
   link is the deliverable's first impression.

## Considered and rejected: Let's Encrypt IP address certificates

These reached general availability on 15 January 2026 and would remove the wildcard DNS
service from the path entirely by certifying the Elastic IP directly.

They are not the default here because IP address certificates must use the short-lived
profile and are valid for roughly six days. Renewal would therefore have to succeed
unattended perhaps ten times between submission and the moment a reviewer clicks the link. A
90-day certificate that issues once is operationally safer for this purpose than a 6-day
certificate that must keep renewing on an instance nobody is watching.

Source: [Let's Encrypt: short-lived and IP address certificates are generally
available](https://letsencrypt.org/2026/01/15/6day-and-ip-general-availability.html).

## No continuous deployment, deliberately

Deploys are manual, over a Systems Manager Session Manager shell.

If CD were in scope, the shape would be GitHub's OIDC provider assuming an AWS role — no
stored credentials — driving the deploy through SSM Run Command. The version that puts an SSH
private key in repository secrets is worse than having no pipeline at all: it creates a
durable, exfiltratable credential to production in exchange for saving a command that is run
perhaps three times in the deliverable's life.

CI is in scope for exactly that reason. Tests, static analysis and formatting run on every
push, where the payoff is per-commit (Req 12.9).

## In practice

Mitigation 2 was exercised. The certificate for `18-175-88-107.sslip.io` was obtained by a
placeholder Caddy container before the application existed, and the application deployment
later found it in the `caddy-data` volume and contacted Let's Encrypt not at all — verified by
listing the volume's contents and by grepping Caddy's logs for an issuance that did not
happen.

The external-volume half of that mitigation was a correction rather than the original plan.
The first version had both stacks declare a volume named `caddy-data` and assumed that was
enough, which would have produced `deploy_caddy-data` and `<repo>_caddy-data` — two distinct
volumes, a second issuance on submission day, and a mitigation that looked correct while doing
nothing.

`sqlite-data` is deliberately *not* external, and the asymmetry is the point: it is mounted
only by the real stack, and if it were lost `php artisan migrate` rebuilds it. `caddy-data`
holds something whose replacement depends on a rate limit shared with strangers.
