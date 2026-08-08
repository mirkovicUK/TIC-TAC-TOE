# ADR-001: Polling as the state-synchronisation transport

Bears on Requirements 8.1, 8.2, 8.5 and 12.11.

Requirement 12.11 mandates this record specifically: it asks the repository to carry a
decision record covering the choice of state-synchronisation transport, stating the
decision, the alternatives considered and the reason. That is why this trade-off is on
the record rather than implied by the code.

## Decision

The client polls `GET /games/{id}` through an Inertia partial reload — every 2 seconds
while the Game is live, every 5 seconds while it is terminal with no Rematch.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| WebSockets, via Laravel Reverb or a hosted service | A second runtime process, a second failure mode, a second thing to document and a second thing to secure |
| Server-sent events | Same persistent-connection cost, for latency no criterion asks for |
| Long polling | Holds a php-fpm worker open per client, and still needs a timeout policy |

## Reason

Two players and a nine-cell board produce at most nine state changes per game. A
persistent connection would buy latency the requirements do not ask for: Requirement 8.2
allows three seconds.

Polling has no server-side state, survives a restart trivially, and is testable without a
socket harness.

## In practice

The intervals are `LIVE_INTERVAL_MS = 2000` and `TERMINAL_INTERVAL_MS = 5000` in
`resources/js/hooks/useGamePolling.ts`. The hook declares two `usePoll` calls and runs one
at a time, because `usePoll` pins its interval at first render — a single call with a
computed interval would keep whichever rate the page first rendered at.

The 2-second cadence is also what exposed the defect recorded under ADR-004: two clients
polling continuously produce overlapping writes to whatever the rate limiter counts with,
which the database cache store could not survive.
