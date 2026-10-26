# Shared Task Board

Statuses: `TODO`, `CLAIMED`, `IN_PROGRESS`, `BLOCKED`, `IN_REVIEW`, `DONE`.
Only mark `DONE` when acceptance criteria are tested. Each agent claims one task at a time.

| ID | Priority | Task | Status | Owner/session | Branch | Acceptance criteria / evidence |
|---|---|---|---|---|---|---|
| P0-01 | P0 | Inspect repo and initialize architecture | TODO | — | — | App layout documented, boot commands verified |
| P0-02 | P0 | Laravel API + authentication + schema | TODO | — | — | Migrations and API tests pass |
| P0-03 | P0 | Flutter SQLite offline catch + photo | TODO | — | — | Catch persists after offline app restart |
| P0-04 | P0 | GPS capture per catch | TODO | — | — | Coordinates, accuracy, timestamp stored with consent |
| P0-05 | P0 | Durable sync engine | TODO | — | — | Retry/idempotency and server acknowledgment tested |
| P0-06 | P0 | Meta model fish image analysis | BLOCKED | — | — | Real image request tested; valid key/model/billing required |
| P0-07 | P0 | Human confirmation and admin review | TODO | — | — | Review workflow passes authorization tests |
| P1-01 | P1 | Admin polygon advisory editor | TODO | — | — | Approved demo zone can be created and displayed |
| P1-02 | P1 | Zone-specific species risks | TODO | — | — | Many-to-many association and evidence review work |
| P1-03 | P1 | Offline GPS zone alerts | TODO | — | — | Cached polygon entry triggers demo caution |
| P1-04 | P1 | Verified offline species catalog | TODO | — | — | Cached catalog searchable offline |
| P1-05 | P1 | GPS trip routes | TODO | — | — | Offline route survives restart and syncs |
| P0-08 | P0 | End-to-end demo + submission | TODO | — | — | Offline→sync→AI→review demo, docs and video ready |

## Claim and handoff template
- Task ID:
- Agent/model and session:
- Claimed at (timezone):
- Branch/worktree:
- Files expected to change:
- Implementation notes:
- Tests run and results:
- Remaining steps/blockers:
- Status:
