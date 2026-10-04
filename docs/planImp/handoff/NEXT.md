# Next: move the planning and testing docs into `docs/planImp/`

**Written:** 2026-09-13 · **Status:** not started

Housekeeping, not code. The repo root has accumulated project-management markdown
and Jon wants it in one obvious place. This is a `git mv` job — the browser-testing
session can write files to the machine but not move or delete them, so it has to be
you.

## The move

```
TESTING_AND_FIXES.md   ->  docs/planImp/TESTING_AND_FIXES.md
SMALL_FIXES.md         ->  docs/planImp/SMALL_FIXES.md
OPEN_QUESTIONS.md      ->  docs/planImp/OPEN_QUESTIONS.md
docs/handoff/          ->  docs/planImp/handoff/        (README.md, NEXT.md, REPORT.md)
```

Use `git mv` so the history follows. Take `docs/handoff/` with them — keeping the
loop's own files beside the documents they feed is the point of the folder.

**Leave alone:** `README.md`, `DEPLOYMENT.md`, `CLAUDE.md` (the four files a
newcomer or a deploy needs at the root), and `docs/mossfield_plan.md`.

Judgement call for you: `WORK_IN_PROGRESS.md`, `FULFILMENT_PLAN.md` and
`SECURITY_NEXT_STEPS.md` are the same kind of document and predate this work. If
they belong in `planImp` too, move them and say so; if they're serving a different
purpose, leave them and say why.

## Update every reference

Search the repo, don't just fix the ones listed here.

**`CLAUDE.md`** has at least four (lines ~10, ~16, ~72, ~633 at time of writing):
the docs-map entry for `TESTING_AND_FIXES.md`, the whole **Handoff Convention**
block, the `ResetTradingData` note, and the free-stock invariant paragraph.

The handoff convention block is the one that matters most — **"do the next handoff"
stops working if its paths are stale**, so check that block reads correctly before
you finish.

**`docs/planImp/handoff/README.md`** has a file-ownership table that says
"(repo root)" for the three documents. Fix those.

The three documents cross-reference each other and themselves by bare filename
(`SMALL_FIXES.md`, `TESTING_AND_FIXES.md`) — those still resolve inside the same
folder, so only fix the ones that carry a path.

## Add an index

`docs/planImp/README.md`, short. What each file is for and when to reach for it:

- **`TESTING_AND_FIXES.md`** — the permanent record. One section per audit, newest
  first, every finding naming the test that pins it. Also how to run the suites and
  how to reset the dev database.
- **`OPEN_QUESTIONS.md`** — design decisions not yet made. Things the app has no
  opinion about, rather than things that are broken. Needs Jon before code.
- **`SMALL_FIXES.md`** — deferred polish, batched on purpose. Includes a
  judgement-needed section that is Jon's, not anyone else's.
- **`handoff/`** — the working channel between the browser-testing session and
  Claude Code. `README.md` has the protocol.

Say in one line why the folder exists: these are how the app is being reviewed and
improved, as distinct from how it works (`CLAUDE.md`) or how it ships
(`DEPLOYMENT.md`).

## Verify before you finish

- `git status` shows renames, not deletes plus adds
- nothing anywhere still points at the old paths (grep the whole repo, `.md` and
  code both)
- the suite still passes — `ResetTradingData` and its test reference
  `TESTING_AND_FIXES.md` in a comment or message

Nothing to check in the browser for this one; say so in the report rather than
inventing a checklist.
