# Report — move the planning and testing docs into `docs/planImp/`

**Written:** 2026-09-13 · **Against:** `NEXT.md` "move the planning and testing docs into `docs/planImp/`" · **Status: done.**

Housekeeping only. Suite unchanged: 508 passing + the 7 known Breeze failures; `pint --test` clean.

## The move

```
TESTING_AND_FIXES.md   ->  docs/planImp/TESTING_AND_FIXES.md
SMALL_FIXES.md         ->  docs/planImp/SMALL_FIXES.md
OPEN_QUESTIONS.md      ->  docs/planImp/OPEN_QUESTIONS.md
docs/handoff/          ->  docs/planImp/handoff/         (README.md, NEXT.md, REPORT.md)
WORK_IN_PROGRESS.md    ->  docs/planImp/WORK_IN_PROGRESS.md   (judgement call — moved)
FULFILMENT_PLAN.md     ->  docs/planImp/FULFILMENT_PLAN.md    (judgement call — moved)
SECURITY_NEXT_STEPS.md     stays at the root                  (judgement call — left)
```

Plus a new `docs/planImp/README.md` index (what each file is for, when to reach for it, one line on why the folder exists), which also records why `SECURITY_NEXT_STEPS.md` isn't in it.

## Disagreement: `git mv` only applied to two of the files

The brief asks for `git mv` so history follows. It does for `WORK_IN_PROGRESS.md` and `FULFILMENT_PLAN.md` — `git status` shows `R` for both. But **`TESTING_AND_FIXES.md`, `SMALL_FIXES.md`, `OPEN_QUESTIONS.md` and `docs/handoff/` have never been committed** — they were untracked (`??`) at their old paths, created during the last few days on top of a working tree that hasn't been committed since `989574f`. `git mv` refuses untracked files ("not under version control"), so those were moved with plain `mv`. There is no history to lose: they show as `??` at the new paths, exactly as they did at the old ones. When this working tree is next committed they'll enter history at `docs/planImp/…` from the start, which is the better outcome anyway.

So the verification line "git status shows renames, not deletes plus adds" reads as: two `R` renames, four `??` untracked-at-new-path, zero deletes. Nothing is missing.

## The judgement call

- **Moved `WORK_IN_PROGRESS.md`** — "current development state and what needs attention when resuming work". That is a planning document by definition.
- **Moved `FULFILMENT_PLAN.md`** — a phased roadmap with checkboxes for the Claude Design fulfilment handoff. Same kind of thing as the others.
- **Left `SECURITY_NEXT_STEPS.md` at the root** — it started life as a tracking doc, but what it is *used for* now is the token-rotation runbook: `DEPLOYMENT.md` (§ token rotation, and its risks list) and `README.md` both point at it by that name, and `CLAUDE.md`'s docs map calls it "tracking doc + token-rotation runbook". A deployer reading `DEPLOYMENT.md` at the root shouldn't have to go into the review-and-improve folder for a runbook. Noted in the planImp index.

## References updated

Searched the whole repo (`.md`, `.php`, `.js`, `.blade.php`, `.json`; `vendor/` and `node_modules/` excluded). Nothing outside `docs/planImp/` points at an old path any more.

- **`CLAUDE.md`** — docs map (new `docs/planImp/` entry with the index, `TESTING_AND_FIXES.md` and `WORK_IN_PROGRESS.md` re-pathed, the other three named), the **Handoff Convention** block (every path now `docs/planImp/handoff/…` — re-read after editing; it's what "do the next handoff" runs on), the `ResetTradingData` note, the free-stock invariant paragraph.
- **`docs/planImp/handoff/README.md`** — the file-ownership table now says `../TESTING_AND_FIXES.md` (`docs/planImp/`) etc. instead of "(repo root)"; the "read docs/handoff/NEXT.md and do it" line re-pathed.
- **`docs/planImp/OPEN_QUESTIONS.md`** — "move the implementation into `handoff/NEXT.md`" (relative, same folder).
- **Code comments** — 10 docblocks that cited `TESTING_AND_FIXES.md` by bare name (`app/Models/BatchItem.php` ×2, the `wheel_weight_kg` migration, and eight test classes) now cite `docs/planImp/TESTING_AND_FIXES.md`; one test cited `SMALL_FIXES` and now cites the path. Cosmetic, but the brief said grep code too.
- **Left as they were, deliberately:** bare-filename cross-references *between* the three documents (`SMALL_FIXES.md` ↔ `TESTING_AND_FIXES.md`) — they resolve inside the same folder, as the brief anticipated. `docs/planImp/handoff/NEXT.md` itself (never edited by Claude Code).

One correction to the brief: `ResetTradingData` and `ResetTradingDataTest` don't actually reference `TESTING_AND_FIXES.md` anywhere — the command's docblock explains itself and the test references the command's constants. Nothing to update there; the suite passes regardless.

## What changed

```
R  FULFILMENT_PLAN.md -> docs/planImp/FULFILMENT_PLAN.md
RM WORK_IN_PROGRESS.md -> docs/planImp/WORK_IN_PROGRESS.md      (RM: the earlier uncommitted edits ride along)
?? docs/planImp/TESTING_AND_FIXES.md, SMALL_FIXES.md, OPEN_QUESTIONS.md, handoff/{README,NEXT,REPORT}.md   (mv — never committed)
?? docs/planImp/README.md                                         (new index)
 M CLAUDE.md                                                      (4 blocks re-pathed + docs-map entry)
 M app/Models/BatchItem.php, database/migrations/2026_09_13_120000_…, tests/Feature/*.php (9)   (comment paths only)
```

## What to verify in the browser

Nothing — no application code or view changed; the only source edits are comment text. `php artisan test` and a `grep -rn "docs/handoff\|(repo root)"` across the repo are the checks, and both are clean.

## Dev database state

Untouched.
