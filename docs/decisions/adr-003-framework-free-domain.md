# ADR-003: A framework-free domain layer with derived Marks

Bears on Requirements 11.4, 14.1 and 14.2.

## Decision

`App\Domain\TicTacToe` holds the rules as pure functions over a Move_List. A Move's Mark is
derived from its Sequence_Index parity, and there is no `mark` column in the database.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Rules in an Eloquent model, or in a service using the query builder | Every rules test would need a database, which is what makes an exhaustive walk unaffordable |
| A stored board array | A second representation of the same facts, to be kept in step with the Move_List |
| A stored `mark` per move | A stored value that can disagree with the sequence that determines it |

## Reason

Purity is what makes the exhaustive enumeration affordable: 549,946 reachable move
sequences, walked in memory with no framework and no I/O.

Derivation removes the possibility of a stored Mark disagreeing with the sequence it comes
from. The unique `(game_id, sequence_index)` index already fixes parity for every row, so
the Mark carries no information the index does not already pin down.

## In practice

The walk was run and compared against a separately written win oracle at every node —
549,946 sequences, of which 255,168 are terminal, both externally known combinatorial facts
about tic-tac-toe rather than restatements of the implementation's own opinion. They matched
exactly, with no disagreement on terminality, winning-line sets, move counts, mark-to-move
or winner.

`.github/workflows/ci.yml` records the walk at 9.8 s of a 10.4 s suite, comfortably inside
the design's 60-second budget, so none of the staged runtime mitigations was needed.
