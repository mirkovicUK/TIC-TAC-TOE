---
inclusion: manual
---

# How tasks in this project get executed

The working agreement for implementing `.kiro/specs/remote-tic-tac-toe/tasks.md`, written down so it survives a lost context. Pull it in with `#task-execution-workflow` at the start of a session.

The shape of it: the main agent orchestrates and never implements. One sub-agent per task, one task per sub-agent. The sub-agent proves its own work; the main agent then re-verifies independently and only then commits.

## Before dispatching

1. **The working tree must be clean.** Commit whatever is outstanding first. A dirty tree makes it impossible to tell the sub-agent's changes from someone else's, which is the whole basis of the review that follows.
2. **Mark the task in progress** — `[ ]` → `[-]` in `tasks.md`. The orchestrator owns every status marker; sub-agents are told explicitly not to touch them.
3. **Read the task text and its neighbours** so the scope fence in the prompt can name the adjacent tasks by number.

## The dispatch prompt

Sub-agent name is `spec-task-execution`. Every prompt carries these seven parts, and dropping any of them has cost us something at least once:

1. **The task text verbatim**, including its `_Requirements:` and `_Properties:` lines.
2. **A scope fence.** "Work on task N and nothing else", then name the adjacent tasks explicitly and say what belongs to them. A sub-agent left to infer scope will helpfully build the next three tasks badly.
3. **What to read first.** The requirement numbers, the relevant design section, the production classes involved, and the existing files whose conventions it must match. Say "match the established conventions, do not introduce a new pattern".
4. **Implementation requirements** in imperative form, including anything load-bearing that looks like it could be tidied away.
5. **The assertion-validity rules** (below).
6. **The inconsistency rule.** If the task text, design, requirements, prop shape or existing code disagree in any way, STOP and report precisely which documents and lines conflict, rather than resolving it. This has surfaced real defects in the spec repeatedly; sub-agents that quietly work around a contradiction destroy that signal.
7. **Verification commands and "do NOT commit"**. The orchestrator commits.

## Assertion-validity rules, quoted into every prompt that writes a test

A passing test is not evidence. The test must be shown capable of failing.

- **Fixture-first.** Build the adversarial state from data the test controls and show the assertion distinguishes right from wrong.
- **Mutation is the last resort**, allowed only when a claim cannot be proven otherwise — typically when the production code's own type makes the defect invisible from inside. Then: record the exact change, revert it exactly, and prove it with `git diff app/` showing no output.
- **Non-vacuity guards inside the test.** Assert the preconditions hold before asserting the outcome, so it cannot pass against an empty, refused or unreachable response.
- **No vacuous assertions.** Nothing asserting a value against itself, nothing asserting data the test just wrote rather than what the code produced, no mocking the subject.
- **Never weaken an assertion or change production behaviour to make a test pass.** A test that reveals a defect is a finding to report, not an obstacle.
- **Leave nothing behind.** No `dd()`, `dump()`, `console.log`, commented-out experiment or scratch file. Scratch work belongs in `/tmp` or is deleted.

## Check whether the comments you are relying on have expired

Add this to every dispatch prompt, and check it on review. A docblock that justifies a decision by what did not exist at the time has a shelf life, and nothing fails when it runs out.

The pattern is specific enough to grep for: `does not exist yet`, `until task`, `once task X exists`, `arrives at task`, `task N.x`, `is still absent`. When a task completes something another file was waiting on, that file's justification becomes false while its decision usually stays right — so **rewrite it to keep the decision and state the current reason**, rather than bumping a task number or deleting the paragraph. If the decision no longer holds either, that is a finding to report.

Two rounds of this have already been needed: three production docblocks dating the rate limiters to "task 10.x" after 9.4 attached them, and five test docblocks justifying themselves by the absence of routes, `CreateRematch` and `RematchControl` after all three shipped. One of those five was worse than stale — it *predicted* that task 5.7 would extend `JoinGameTest`, and 5.7 touched a different file entirely. A prediction that turned out wrong is worth correcting visibly, with the correction stated, rather than edited into looking like it was right all along.

Why this is written so heavily: a batch of seventeen assertions once asserted nothing at all, and the explanation of why that was harmless was itself inverted. `docs/ai-direction.md` records it.

## When the sub-agent reports back

Do not take the report on trust. It is a claim, and the following are cheap:

1. **`git status --short --untracked-files=all`** — only the intended files, no scratch artefacts.
2. **`git diff app/ database/ config/ routes/`** — empty if the task was test-only, or confined to the intended files otherwise. This is how a claimed revert is confirmed.
3. **Grep for leftovers**: `dd(`, `dump(`, `var_dump`, `console.log`, `scratch`, `TODO`.
4. **Spot-check the load-bearing claims against the source.** If the sub-agent says a library behaves a certain way, read the library. Framework behaviour has been asserted from memory and been wrong more often than any other failure mode in this project.
5. **Re-verify one or two mutations yourself** where the claim matters most and the cost is low. Revert exactly.
6. **Read the new test for vacuity** rather than only running it.

## The check set

Run all of it before every commit. This is what CI runs, in this order:

```
composer lint          # Pint
composer analyse       # PHPStan level 8 over app/database/tests, then max over the domain
npx tsc --noEmit       # esbuild strips types without checking them; the build alone proves nothing
npm run build          # feature tests render the root view and need the Vite manifest
./vendor/bin/pest --exclude-group=browser
```

A useful trick for comment-only or refactor-only changes: if the built bundle hash is unchanged, the emitted code is unchanged.

## Committing

1. Mark the task `[x]`.
2. **Stage only the intended files.** Never `git add .` — unrelated work has been sitting in the tree more than once.
3. **Write the message with a heredoc**, not `-m`:

```
git commit -F - <<'MSG'
Subject in the imperative, under 70 characters

Body: what the task was, and the one or two decisions a reader would
otherwise have to reverse-engineer.
MSG
```

Chained `-m` flags have already produced one commit whose entire body collapsed into the subject line, complete with a literal `" -m "`. It is public history now.

4. **One logical change per commit.** Task work and an unrelated edit found in the tree go in separate commits.
5. **Do not push unless asked.** When asked, confirm the branch and check no secret-bearing file is tracked (`.env` and `deploy/.provisioned.env` are gitignored; only `.env.example` is tracked).

## When a sub-agent reports an inconsistency

Investigate it yourself rather than accepting or dismissing it. Read the cited lines and the cited source. Then:

- **If the code is right and a spec document is wrong**, correct the document and record the correction in `docs/ai-direction.md` under the matching heading. Requirement 12.8 asks for exactly this record, and the corrections are the most useful thing in it.
- **If the requirement itself needs a ruling** (it asks for something unrepresentable, or two criteria conflict), put it to the user rather than deciding it inside an implementation.
- **If it is a decision rather than an error**, it belongs in `docs/decisions/`.

## Two recurring nuisances

**The editor overwrites `tasks.md` markers.** If `tasks.md` is open, its buffer can write back over a status change, and the file then shows as modified with the marker reverted. Re-apply it and check `git status` before committing.

**The task tools are not always available.** When `taskUpdate` and `taskList` cannot be called, edit the `[ ]` / `[-]` / `[x]` marker directly with a string replacement. The workflow is otherwise unchanged.
