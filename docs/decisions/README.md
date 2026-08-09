# Decision records

One file per significant technical choice, each stating the decision, the alternatives
considered and the reason for the choice. That is what Requirement 12.7 asks for, and it is
the structure every file here follows.

**ADR-001 is the record Requirement 12.11 mandates specifically**: a decision record covering
the choice of state-synchronisation transport, with its alternatives and its reason.

## The records

| ADR | Decision | In one line |
| --- | --- | --- |
| [001](adr-001-polling-transport.md) | Polling as the state-synchronisation transport | 2 seconds while live, 5 while terminal, rather than a persistent connection nine state changes do not need |
| [002](adr-002-no-conditional-requests.md) | No conditional requests and no not-modified responses | Inertia's protocol has no 304 path, and a nine-value board is not worth a second serialiser |
| [003](adr-003-framework-free-domain.md) | A framework-free domain layer with derived Marks | Purity is what makes the 549,946-node walk affordable; parity is what removes a `mark` column |
| [004](adr-004-sqlite-on-a-named-volume.md) | SQLite on a named volume | Nine writes per game do not justify a second database container. Its settings did not prevent a production failure |
| [005](adr-005-per-game-tokens.md) | Per-game, per-mark tokens instead of accounts | 256 bits bound to one `(Game_Id, Mark)` slot, so the Join_Code is never the credential |
| [006](adr-006-two-concurrency-mechanisms.md) | Two concurrency mechanisms, each matched to its race | A unique index for the move race, a guarded UPDATE for the join race |
| [007](adr-007-retention-command.md) | A retention command rather than an enforced TTL | The command is the product, the crontab is deployment. The sweep as first specified could not be implemented |
| [008](adr-008-one-browser-test.md) | Exactly one browser test | Requirement 14.5 asks for one, and browser automation is the cost paid on every push |
| [009](adr-009-ec2-compose-caddy.md) | EC2 with Docker Compose and Caddy | One box, one Compose file both locally and in production, and a shared certificate rate limit with four mitigations |
| [010](adr-010-rematch-tokens-per-request.md) | Rematch tokens are minted per request, not at creation | The server cannot write a token into the absent player's browser |
| [011](adr-011-php-fpm-behind-caddy.md) | php-fpm behind Caddy in two containers | Operability, not performance — and the record says the choice was inherited rather than decided |
| [012](adr-012-continuous-deployment.md) | Continuous deployment through GHCR and SSM Run Command | Supersedes ADR-009's no-CD section. One fixed document instead of a root shell, and ADR-009's rejection of a stored SSH key still holds |

## How to read them

Each file has the same four or five headings: the decision, the alternatives considered, the
reason, and where there is something to say, what happened in practice and what would change
the decision.

The "in practice" sections are the ones worth reading later. Five decisions were exercised or
corrected after the design was written, and those notes carry the facts rather than the
intentions: **ADR-004**, where WAL and a busy timeout did not prevent a mid-game 500;
**ADR-007**, where two individually correct constraints made the specified sweep impossible;
**ADR-009**, where the certificate mitigation depended on a volume declaration that would not
have worked; **ADR-005**, where the credential class had to be split to avoid writing a token
for a slot the request had lost; and **ADR-012**, where four defects were caught by review
before the first deployment and one only by running it.

**ADR-012 supersedes part of ADR-009** — its "No continuous deployment, deliberately" section,
and only that section. ADR-009 is left as written rather than edited, because what it rejected
(an SSH private key in repository secrets) is still rejected, and the record of that reasoning
is worth more than a tidy document.

Correction history for the project as a whole lives in [`../ai-direction.md`](../ai-direction.md),
not here. These files record decisions.
