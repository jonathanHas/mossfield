# Handoff protocol

A file-based channel between the browser-testing session (Claude in Cowork, which
can drive the app in Chrome and read files here, but cannot run commands on this
machine) and Claude Code (which can run commands but cannot use the app).

Passing work through files instead of the chat keeps both conversations short and
leaves a record that outlives either session.

## The loop

1. **Cowork writes `NEXT.md`** — the brief for the next piece of work.
2. **Jon says "read docs/planImp/handoff/NEXT.md and do it"** to Claude Code. (Once the
   convention is in `CLAUDE.md`, "do the next handoff" is enough.)
3. **Claude Code writes `REPORT.md`** when finished — replacing whatever was there.
4. **Jon says "done"** to Cowork, which reads `REPORT.md`, verifies the work
   through the browser, records the outcome in `TESTING_AND_FIXES.md`, and writes
   the next `NEXT.md`.

Jon's messages become two or three words in each direction. Neither side pastes
prompts or output.

## File ownership

Single writer per file. No conflicts, no merge decisions.

| File | Written by | Read by |
| --- | --- | --- |
| `NEXT.md` | Cowork | Claude Code |
| `REPORT.md` | Claude Code | Cowork |
| `../TESTING_AND_FIXES.md` (`docs/planImp/`) | both, one section each | both |
| `../SMALL_FIXES.md` (`docs/planImp/`) | Cowork proposes, Claude Code ticks | both |
| `../OPEN_QUESTIONS.md` (`docs/planImp/`) | either, one question each | both |

`NEXT.md` and `REPORT.md` are scratch — each cycle overwrites the last. The
permanent record is `TESTING_AND_FIXES.md`, which is where findings, fixes and
verifications are written up properly.

`OPEN_QUESTIONS.md` holds design decisions that haven't been made — things the app
has no opinion about, rather than things that are broken. A question goes there
instead of into a brief when it needs Jon's judgement before anyone writes code.
When one is settled, record the decision in that file and move the implementation
into `NEXT.md`.

## What `REPORT.md` should contain

Written for a reader who cannot see the diff and cannot run the suite. Keep it to
what changes the next decision:

- **Status** — done / partially done / blocked, and against which brief.
- **What changed** — files touched, with `git diff --stat` output pasted in.
  The stat is what tells Cowork the shape and size of the change.
- **What to verify in the browser** — the specific URLs, actions and expected
  results. This is the most useful section: it's the next session's checklist.
- **Decisions taken** — anything the brief left open, and the reasoning.
- **Disagreements** — anything in the brief that was wrong, and what was done
  instead. Say so plainly; the brief is written by someone looking at the app
  from the outside and is wrong often enough to matter.
- **Test results** — new tests added, full suite pass/fail count.
- **Dev database state** — anything the work left behind, since the browser
  session will be looking at the same database.

## Conventions

- Claude Code never edits `NEXT.md`; Cowork never edits `REPORT.md`.
- Neither file is a log. History belongs in `TESTING_AND_FIXES.md`.
- If `NEXT.md` is stale or already done, say so in `REPORT.md` rather than
  inventing work.
