# Plan / Implement protocol

Two Claude Code sessions share this folder. They never share context, so the
files here are the only channel between them.

| Role        | Session      | Owns             | Never touches                        |
|-------------|--------------|------------------|--------------------------------------|
| Planner     | Fable 5.1    | `plan.md`        | Application code, `implemented.md`   |
| Implementer | Opus         | `implemented.md` | `plan.md`                            |

Both sessions read `planimp.md` (this file), `plan.md`, `implemented.md`, and
the project `CLAUDE.md` before doing anything. Neither commits, deploys, or
runs anything under `deploy/bin/` unless `plan.md` says so explicitly.

This loop sits beside the browser-testing handoff in `handoff/` (Cowork writes
`NEXT.md`, Claude Code writes `REPORT.md`). They don't conflict: a handoff
brief can become a plan, and a plan's browser checks can become a brief. The
permanent record for both is `TESTING_AND_FIXES.md`.

## The loop

```
Planner writes plan.md            status: READY
        │
        ▼
Implementer works, writes implemented.md     status: IN PROGRESS → DONE | BLOCKED
        │
        ▼
Planner reviews implemented.md + git diff, updates plan.md
        │
        ├─ status: ACCEPTED   → user archives both files, next task starts fresh
        └─ status: READY (revision N+1) → back to Implementer
```

The `Status:` line at the top of each file is the handshake. Check it before
you start. Do not act on a `plan.md` that is `DRAFT`, and do not review an
`implemented.md` that is still `IN PROGRESS`.

**The review comes from a session that did not do the work.** Normally the
Planner's; if the Planner session is gone, a fresh session reading
`planimp.md` — never the Implementer session reviewing its own
`implemented.md` (cycle 11's revision 2 was reviewed that way at the user's
request; the Planner re-verified it afterwards and it held, but the check
only means something when it is independent).

### Kickoff prompts for the user

Planner session:

```
Read docs/planImp/planimp.md. You are the Planner. <describe the task>
```

Implementer session:

```
Read docs/planImp/planimp.md. You are the Implementer. Implement docs/planImp/plan.md.
```

Planner review:

```
Read docs/planImp/planimp.md. You are the Planner. Review docs/planImp/implemented.md.
```

## Planner (Fable)

Your job is to think, not to type code. Spend the effort on reading the
codebase, finding the real constraints, and writing a plan the Implementer
can follow without guessing.

Do:

- Read the relevant code, migrations, tests, and docs before writing anything.
  Every file you name in the plan must exist, or be explicitly marked `(new)`.
- Run read-only commands freely: `git log`, `grep`, `php artisan route:list`,
  `php artisan tinker` with queries, `php artisan test --filter=...`.
- Write steps that are small, ordered, and each have a concrete check.
  "Add the column" is not a step. "Add nullable `loaded_at` timestamp to
  `orders` via a new migration; `php artisan migrate` runs clean and
  `Schema::hasColumn('orders','loaded_at')` is true" is a step.
- State what is out of scope. The Implementer will otherwise decide for you.
- Name the invariant behind a constraint so the Implementer doesn't
  "simplify" it away. In this app that is usually stock arithmetic: the
  free-stock expression (`remaining − (allocated − fulfilled) − maturing`)
  must be identical at all eight sites, `on hand + dispatched + cut +
  converted = produced` must hold, and anything that deletes rows under a
  batch item needs a guard, not a cascade. `TESTING_AND_FIXES.md` →
  *Invariants worth keeping* is the list; cite it.
- Include short code snippets only where wording would be ambiguous.
  Snippets illustrate intent; the Implementer owns the final code.
- When reviewing, read the actual diff (`git diff`, `git status`), not just
  `implemented.md`. Check every acceptance criterion yourself. Rerun the
  verification commands.
- Read `implemented.md` to the end before accepting. `## Deviations` and
  `## Notes for Planner` come last and are where the Implementer records what
  the plan got wrong and what it noticed but did not touch. Every note gets an
  explicit decision in the `## Review` section: fixed now (as new steps in a
  bumped Revision, or a follow-up plan), deferred with a reason, or rejected
  with a reason. A review that does not mention the notes is not finished.
- Two Alpine facts every plan touching Blade should respect: never use an
  Alpine `@` shorthand that is also a Blade directive (`@error`, `@class`, …;
  write `x-on:error`); and `x-show` hides an element but still evaluates its
  other bindings, so anything nullable behind an `x-show` guard needs `?.` in
  `:src`, `:alt` and handlers, or an `x-if` template instead.
- On acceptance, the Planner writes the cycle up in `TESTING_AND_FIXES.md`
  (one section, newest first, every finding naming the test that pins it) and
  updates `RESUME.md`. Those are records, not application code.

Don't:

- Edit application code, even for a "trivial" fix. Put it in the plan.
- Write `implemented.md`. If it is wrong or missing, say so in the review
  section of `plan.md`.
- Leave open questions in a `READY` plan. Resolve them (ask the user, or
  decide and record the decision) or mark the plan `DRAFT`.

When the work is accepted, set `Status: ACCEPTED` and tell the user to
archive: `mkdir -p docs/planImp/archive/YYYY-MM-DD-<slug>` and move both
`plan.md` and `implemented.md` there. The next task starts with empty files.

A small fix that must not wait for the current cycle can run beside it as a
side plan: `docs/planImp/plan-<slug>.md`, reported in
`docs/planImp/implemented-<slug>.md`, reviewed and archived like any cycle.
The side plan names the files it touches so the two implementer sessions
cannot collide; the kickoff prompt names the file.

Something found after a cycle is accepted (a browser check, a later thought)
goes in `docs/planImp/findings/YYYY-MM-DD-<slug>.md`: what happened, why, the
options, a recommendation, and what it does not affect. The Planner folds it
into the next plan (or a short follow-up cycle) and moves the finding file into
that cycle's archive folder on acceptance. A finding never edits code.

A manual check in the browser exercises the action, not just the render:
click the result, submit the form, tap the button, and watch the console.

A plan the user wants to put aside before it is implemented goes to
`docs/planImp/parked/YYYY-MM-DD-<slug>/plan.md` with `Status: PARKED` and a
line saying how to resume. Resuming means copying it back to `plan.md`,
re-checking its Context against the code, and setting `Status: READY`.

### `plan.md` template

```markdown
# <Task title>

Status: DRAFT | READY | ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: YYYY-MM-DD

## Goal
One paragraph. What changes for the user of the app, and why.

## Context
What the Planner learned from the codebase that the Implementer needs:
relevant models, services, routes, existing patterns to copy, gotchas.

## Constraints
- Business rules and invariants that must hold (cite the doc).
- Things that must not change (public routes, existing data, API shapes).
- Commit / deploy policy for this task (default: do not commit).

## Out of scope
Explicit list. The Implementer must not do these even if tempting.

## Steps
### 1. <Short name>
Files: `app/...`, `database/migrations/... (new)`
What: exact change.
Check: command or observation that proves it worked.

### 2. ...

## Verification
Commands to run at the end, in order, with expected outcomes.
Example: `php artisan test --filter=ChilledRunTest` → all pass.

## Risks
Where this could go wrong and what to watch for.

## Review
(Planner fills this in after reading implemented.md and the diff.)
- Criterion-by-criterion pass/fail.
- Deviations: each accepted or rejected, with a reason.
- Notes for Planner: each one fixed now, deferred (why), or rejected (why).
- Anything to redo, as new numbered steps in a bumped Revision.
```

## Implementer (Opus)

Your job is to execute the plan faithfully and report exactly what happened.
The plan was written by a session that read the code carefully. Trust it,
but verify each step's check before moving on.

Do:

- Start by recording the baseline in `implemented.md`: `git rev-parse --short HEAD`
  and the output of `git status --short`. The working tree may already be
  dirty; the reviewer needs to know which changes are yours.
- Set `Status: IN PROGRESS` immediately, then update `implemented.md` after
  each step, not at the end. If the session dies, the Planner should still be
  able to see how far you got.
- Follow the steps in order. Run every `Check:` and paste the real output
  (trimmed) into `implemented.md`. "Verified" with no evidence is not
  verified.
- Run the full `## Verification` section at the end and record the output,
  including failures.
- A file the plan marks `(new)` is still checked before you write it. Look
  first; if it exists, extend it and say so in Deviations.
- Deviate from the plan only when a step is impossible or clearly wrong as
  written. Make the smallest deviation that unblocks you, and record it
  under `## Deviations` with the reason. If the deviation would change
  behaviour the plan cares about, stop instead and set `Status: BLOCKED`.
- Set `Status: DONE` only when every step is done and verification ran.
  Otherwise `BLOCKED` with a clear statement of what is needed.

Don't:

- Touch anything under `## Out of scope`, or anything the plan doesn't
  mention. Noticed something worth fixing? Put it in `## Notes for Planner`.
- Edit `plan.md`. Disagreements go in `implemented.md`.
- Commit, push, or deploy unless the plan's Constraints section says to.
- Mark a step done because the code "should" work. Run the check.
- Delete or rewrite existing tests to make verification pass. Report the
  failure and set `BLOCKED`. (A plan may itself instruct a test change —
  then it is a step, not a shortcut.)

### `implemented.md` template

````markdown
# <Task title> — implementation

Status: IN PROGRESS | DONE | BLOCKED
Plan revision: 1
Implementer: Opus
Date: YYYY-MM-DD

## Baseline
HEAD: <short sha>
Pre-existing dirty files:
<git status --short output, or "clean">

## Steps
### 1. <Short name> — done | skipped | blocked
Changed: `app/...`
Check output:
```
<trimmed real output>
```

### 2. ...

## Deviations
What was done differently from the plan and why. "None" if none.

## Verification
Each command from the plan's Verification section with its actual result.

## Files changed
`git status --short` at the end, with pre-existing dirty files marked.

## Notes for Planner
Things noticed but not acted on. Questions. Suggested follow-ups.
````

## Why the split

Fable is the stronger reasoner and the more expensive session. Spending it on
reading the codebase, catching the constraint nobody mentioned, and reviewing
the diff is where it pays off. Opus is fast and reliable at executing a
well-specified plan. The protocol only works if the plan carries everything
the Implementer needs and the report carries everything the reviewer needs,
because neither session can ask the other a question.
