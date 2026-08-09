# ADR-011: php-fpm behind Caddy in two containers, rather than one embedded-PHP container

Bears on the Deployment section of the design, and on ADR-009.

This record was written later than the others. The design argued the hosting platform at
length and never argued the topology, and the gap only became visible when the question "why
two containers" was put directly.

## Decision

Two containers. `app` runs `php:8.5-fpm`, which speaks FastCGI; `web` runs `caddy:2-alpine`,
terminates TLS, serves the static assets and forwards PHP requests to `app` over port 9000.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| FrankenPHP — Caddy with PHP embedded, one container serving HTTPS and executing PHP in a single process, with no FastCGI hop | Would be the obvious choice on a greenfield start. Ruled out here by timing rather than merit: adopting it would mean validating a deployment shape nobody on this project has run, against an already-issued certificate whose storage layout would be assumed rather than known |
| nginx in place of Caddy | Caddy gives automatic HTTPS in two lines, which is ADR-009's reason too |
| Both processes in one container under a supervisor | Reintroduces process management Compose already does, and turns two independent restart policies into one |

## Both alternatives work at this scale, and the reason is not performance

This application is a 3x3 grid polled every two seconds by at most two browsers. Any of these
arrangements would serve it with room to spare, and an argument from throughput, or from static
assets not occupying a PHP worker, would be dressing rather than reasoning.

What the choice turns on is operability.

## Reason

- **It is the most battle-tested PHP deployment there is.** php-fpm behind a reverse proxy is
  the arrangement the overwhelming majority of PHP applications run on. Every failure mode has
  been hit and written up by other people, which is a property of the ecosystem rather than of
  this code.
- **It is the safer arrangement for AI-augmented work, which is how this project was built.** A
  generated Dockerfile in a conventional shape can be reviewed against thousands of prior
  examples; a generated one in a less common shape is harder to check and harder to be sure
  about. The same holds for whatever a maintainer searches for when it misbehaves.
- **It is easier to debug without deep PHP experience.** The operator of this instance has none.
  When something fails on the box, the value of the well-trodden path is not aesthetic — it
  decides whether an error message has answers behind it.
- **The certificate's lifecycle stays separate from the application's.** This is the one reason
  specific to this project rather than general advice. ADR-009 records that Let's Encrypt
  rate-limits per registered domain and that `sslip.io` is one domain shared with strangers, so
  the certificate is the component that cannot be cheaply replaced. Attached to `web`, it is
  untouched by every rebuild and restart of `app`.
