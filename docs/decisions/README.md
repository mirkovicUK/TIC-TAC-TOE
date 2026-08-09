# Decision records

One file per significant technical choice, each stating the decision, the alternatives
considered and the reason for the choice. That is what Requirement 12.7 asks for.

**ADR-001 is the record Requirement 12.11 mandates specifically**: a decision record covering
the choice of state-synchronisation transport, with its alternatives and its reason.

## The records

| ADR | Decision | In one line |
| --- | --- | --- |
| [001](adr-001-polling-transport.md) | Polling as the state-synchronisation transport | 2 seconds while live, 5 while terminal, rather than a persistent connection nine state changes do not need |
| [004](adr-004-sqlite-on-a-named-volume.md) | SQLite on a named volume | Nine writes per game do not justify a second database container |
| [005](adr-005-per-game-tokens.md) | Per-game, per-mark tokens instead of accounts | 256 bits bound to one `(Game_Id, Mark)` slot, so the Join_Code is never the credential |
| [007](adr-007-retention-command.md) | A scheduled retention command rather than an enforced TTL | The command is the product, the crontab is deployment |
| [008](adr-008-one-browser-test.md) | Exactly one browser test | Requirement 14.5 asks for one, and browser automation is the cost paid on every push |
| [009](adr-009-ec2-compose-caddy.md) | EC2 with Docker Compose and Caddy | One box, one Compose file both locally and in production, and a hostname from `sslip.io` so TLS is real |
| [011](adr-011-php-fpm-behind-caddy.md) | php-fpm behind Caddy in two containers | Operability, not performance |
| [012](adr-012-continuous-deployment.md) | Continuous deployment through GHCR and SSM Run Command | One fixed SSM document instead of a root shell, and no stored credential to AWS or GHCR |

ADR-012 partly supersedes ADR-009: the image is no longer built on the instance and deploys are
no longer manual. ADR-009 carries a note saying so.

## How to read them

Each file states the decision, the alternatives considered and the reason. Where a decision was
later exercised or corrected, an "In practice" section carries what actually happened —
**ADR-009** is the one worth reading, where the certificate was issued by a placeholder
container before the application existed and the real deployment found it already in the volume.

How the AI tooling was directed, and the corrections made to its output, is in
[`../ai-direction.md`](../ai-direction.md). These files record decisions.
