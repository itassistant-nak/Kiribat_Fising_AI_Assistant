# Shared Task Board

Statuses: `TODO`, `CLAIMED`, `IN_PROGRESS`, `BLOCKED`, `IN_REVIEW`, `DONE`.
Only mark `DONE` when acceptance criteria are tested. Each agent claims one task at a time.

| ID | Priority | Task | Status | Owner/session | Branch | Acceptance criteria / evidence |
|---|---|---|---|---|---|---|
| P0-01 | P0 | Inspect repo and initialize architecture | DONE | Day1-NextLaravel-Agent | main | Monorepo layout established: backend/ (Laravel 11) + frontend/ (Next.js 16) |
| P0-02 | P0 | Laravel API + authentication + schema | DONE | Day1-NextLaravel-Agent | main | SQLite schema, Sanctum auth, and 10 PHPUnit feature tests pass (70 assertions) |
| P0-03 | P0 | Flutter SQLite offline catch + photo | TODO | — | — | Catch persists after offline app restart |
| P0-04 | P0 | GPS capture per catch | TODO | — | — | Coordinates, accuracy, timestamp stored with consent |
| P0-05 | P0 | Durable sync engine | TODO | — | — | Retry/idempotency and server acknowledgment tested |
| P0-06 | P0 | Meta model fish image analysis | BLOCKED | — | — | Real image request tested; valid key/model/billing required |
| P0-07 | P0 | Human confirmation and admin review | DONE | Day1-NextLaravel-Agent | main | Next.js dashboard, Leaflet GPS & routes, AI preservation verified (7/7 acceptance tests passed) |
| P1-01 | P1 | Admin polygon advisory editor | TODO | — | — | Approved demo zone can be created and displayed |
| P1-02 | P1 | Zone-specific species risks | TODO | — | — | Many-to-many association and evidence review work |
| P1-03 | P1 | Offline GPS zone alerts | TODO | — | — | Cached polygon entry triggers demo caution |
| P1-04 | P1 | Verified offline species catalog | TODO | — | — | Cached catalog searchable offline |
| P1-05 | P1 | GPS trip routes | TODO | — | — | Offline route survives restart and syncs |
| P0-08 | P0 | End-to-end demo + submission | TODO | — | — | Offline→sync→AI→review demo, docs and video ready |

## Claim and handoff template
- Task ID: P0-01, P0-02, P0-07
- Agent/model and session: Day 1 Senior Next.js/Laravel Integration Engineer (Antigravity)
- Claimed at (timezone): 2026-10-27 09:50 (Pacific/Tarawa +12:00)
- Branch/worktree: main
- Files expected to change: backend/*, frontend/*, scripts/*, TASKS.md, PROJECT_STATE.md, DECISIONS.md
- Implementation notes: Built Laravel API backend (auth, schema, catches, trips, species, verification) and Next.js admin dashboard (Leaflet GPS, trip routes, stats, review, filtering, error states). Preserves original Meta AI vision suggestion immutably upon human verification. Protected fisher GPS privacy with authorization checks.
- Tests run and results:
  1. `cd backend && php artisan test` — 10 passed (70 assertions)
  2. `node scripts/verify_day1_acceptance.js` — All 7 acceptance criteria passed
  3. `cd frontend && npm run build` — Compiled successfully with zero errors
- Remaining steps/blockers: Day 1 objectives fully delivered and verified. Ready for Day 2 sync engine and mobile integration.
- Status: DONE

