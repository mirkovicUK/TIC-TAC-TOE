# ADR-009: EC2 with Docker Compose and Caddy

Bears on Requirements 10.11, 12.4 and 12.9.

> **Partly superseded by [ADR-012](adr-012-continuous-deployment.md).** The image is no longer
> built on the instance and deploys are no longer manual — both images are built in CI and pulled
> from GHCR. Everything about the instance, the hostname, the certificate and the volume asymmetry
> is unchanged and still current.

## Decision

A single EC2 instance with an Elastic IP, Docker Compose and Caddy for TLS. TLS needs a hostname
and an Elastic IP is not one, so Caddy is configured for `<elastic-ip-dashed>.sslip.io`, which
resolves to the address without registering a domain.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| A PaaS free tier | The local Compose file and the hosted instance would then be different things, free to drift |
| ECS or Fargate | Task definitions, a registry and a load balancer, for one container pair |
| Kubernetes | Would cost more of the budget than the whole application |
| nginx in place of Caddy | Caddy supplies automatic HTTPS in two lines of configuration |
| A Let's Encrypt IP address certificate for the Elastic IP | Must use the short-lived profile — valid roughly six days — so renewal would have to succeed unattended perhaps ten times before a reviewer clicks the link. A 90-day certificate that issues once is safer on an instance nobody is watching. [Source](https://letsencrypt.org/2026/01/15/6day-and-ip-general-availability.html) |

## Reason

The same Compose file runs locally and in production, so the README's local instructions and
the hosted instance cannot drift apart.

`sslip.io` supplies the hostname the certificate needs without a domain purchase, which is
what lets Let's Encrypt issue a real certificate and makes `SESSION_SECURE_COOKIE=true`
honest (Req 10.11).

Orchestration platforms would cost more of the budget than the application they hosted.

## In practice

The certificate for `18-175-88-107.sslip.io` was obtained by a placeholder Caddy container
before the application existed, and the application deployment later found it in the
`caddy-data` volume — verified by listing the volume's contents and by grepping Caddy's logs
for an issuance that did not happen.

`sqlite-data` is deliberately *not* external, and the asymmetry is the point: it is mounted
only by the real stack, and if it were lost `php artisan migrate` rebuilds it. `caddy-data`
holds something whose replacement depends on a rate limit shared with strangers.
