# planImp — how the app is being reviewed and improved

This folder is the project-management side of the repo: how the app is being
reviewed and improved, as distinct from how it works (`CLAUDE.md`, at the root)
or how it ships (`DEPLOYMENT.md`, also at the root).

| File | What it is | Reach for it when |
| --- | --- | --- |
| **`TESTING_AND_FIXES.md`** | The permanent record. One section per audit, newest first; every finding names the test that pins it. Also how to run the suites, the known-failing baseline, and how to reset the dev database between passes. | Before touching stock, allocation or weight arithmetic; after any test pass; when a figure on screen doesn't match the database. |
| **`OPEN_QUESTIONS.md`** | Design decisions not yet made — things the app has no opinion about, rather than things that are broken. Each written up so it can be decided later without re-deriving it. | Something needs Jon's judgement before anyone writes code. When settled, record the decision there and move the implementation into `handoff/NEXT.md`. |
| **`SMALL_FIXES.md`** | Deferred polish — copy, display, minor UX — batched on purpose so it lands in one pass rather than three. Includes a *Judgement needed* section that is Jon's, not anyone else's. | A substantive pass is done and there is room for a polish batch. |
| **`WORK_IN_PROGRESS.md`** | Current development state and what needs attention when resuming work; recently completed work by date. | Picking the app up after a gap. |
| **`FULFILMENT_PLAN.md`** | The phased roadmap for the picking → dispatch → delivery flow from the Claude Design handoff, with phases ticked as they land. | Resuming fulfilment-flow work from the next unchecked phase. |
| **`RESUME.md`** | The last stopping point: where the handoff loop is, what's uncommitted, the test baseline, the dev-DB state, how to bring the box up. | Coming back after a reboot or a gap — "Read `docs/planImp/RESUME.md`, then do the next handoff." |
| **`UX_REVIEW_2026-10-13.md`** | A Chrome walk-through as the office role — 34 flow, layout, copy and form findings ranked by cost to the person doing the job, each with the smallest fix. | Planning the next polish or order-flow cycle; before changing the order form, dashboard queue, or run-sheet strip. |
| **`UX_REVIEW_2026-10-28.md`** | Part 2 of the walk-through — production and stock as the office, picking as the factory on a phone, the driver, the admin screens, finance off the happy path — 25 findings ranked by cost, each with the smallest fix, plus the dev-box facts found on the way. | Planning cycle 14 (`plan.md`); before touching the backup, user management, the write-off or cut forms, or the mature undo. |
| **`handoff/`** | The working channel between the browser-testing session (writes `NEXT.md`) and Claude Code (writes `REPORT.md`). `handoff/README.md` has the protocol and the expected shape of a report. | "Do the next handoff." |
| **`planimp.md`** | The Plan/Implement protocol: a Fable session plans (`plan.md`), an Opus session implements (`implemented.md`), the planner reviews the diff. Kickoff prompts are in the file. Accepted cycles move to `archive/`. | A fix big enough to want a written plan and an independent implementer. |
| **`plan.md`** / **`implemented.md`** | The current Plan/Implement cycle — check the `Status:` line at the top of each. Empty between cycles. | "You are the Planner / Implementer." |

`SECURITY_NEXT_STEPS.md` stays at the root on purpose: it is the token-rotation
runbook that `DEPLOYMENT.md` and `README.md` point at, so it belongs with the
deploy documents rather than here.
