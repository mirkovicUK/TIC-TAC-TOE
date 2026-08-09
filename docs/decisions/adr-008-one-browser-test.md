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

Browser automation is the slowest and least reliable part of CI. A second test would add
flake risk to every push in exchange for assertions the feature tests already make against
the same code.
