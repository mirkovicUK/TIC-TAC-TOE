# ADR-008: Exactly one browser test

Bears on Requirements 14.5 and 12.9.

## Decision

One end-to-end browser test. Everything else is unit or feature level.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| A browser test per user story | Buys coverage the feature suite already provides, at a cost paid on every push |
| No browser test at all | Requirement 14.5 asks for one, and nothing else drives two real sessions through a real browser |

## Reason

Requirement 14.5 asks for one test driving two Player_Sessions to a Terminal_State, and one
is what the deliverable gets.

Browser automation is the slowest and least reliable part of CI. A second test would add
flake risk to every push in exchange for assertions the feature tests already make against
the same code.

## In practice

`tests/Browser/PlayAGameTest.php` is the whole selection, tagged `->group('browser')`, so
`vendor/bin/pest --group=browser` is a one-test run rather than a suite and
`--exclude-group=browser` covers everything else.

CI splits it into its own job, gated behind `quality`. The split buys no wall-clock time and
costs some — each job starts from an empty VM — but it keeps the two red ticks disjoint: a red
`browser` with a green `quality` reads as automation wobble, a red `quality` reads as
something broken. A single tick carrying both meanings is a tick nobody investigates.

Pest 4's browser plugin is what makes Requirement 14.5 satisfiable without adding Dusk.
