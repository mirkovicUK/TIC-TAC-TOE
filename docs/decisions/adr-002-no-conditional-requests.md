# ADR-002: No conditional requests and no not-modified responses

Bears on Requirements 8.3 and 8.4, and on Property 12.

## Decision

Every state request returns the full Game representation, irrespective of any
Version_Counter the client holds (Req 8.4). There is no ETag, no `If-None-Match` and no
304 path.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| A conditional response returning "unchanged" when the client's Version_Counter matches | Inertia's protocol has no not-modified path |
| A separate plain-JSON polling route outside Inertia, where conditional responses would be idiomatic | Two serialisation paths for the same nine-value board, to save a few hundred bytes |

## Reason

An XHR carrying the Inertia header expects a page object in reply, and a partial reload
returns a subset of props — not an empty body.

Inertia does have a `version` field of its own, with a 409 response, but that is *asset*
versioning for detecting a stale front-end build. It is an entirely different concern from
the Game's Version_Counter, and conflating the two would be a defect waiting to happen.

The second alternative was rejected on defect surface rather than on bytes. The
representation is small — a board is nine values — and maintaining two ways to serialise
it costs more than the transfer it saves.

The Version_Counter is still sent on every response (Req 8.3). It stays useful to the
client as a cheap change detector and to the tests as the contract for Property 12.
