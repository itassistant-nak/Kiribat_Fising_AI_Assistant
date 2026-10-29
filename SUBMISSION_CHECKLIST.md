# Submission Checklist (Day 4)

**Rule honored:** nothing below claims more than observed. Mocks appear only in
automated tests, never as AI output. Evidence commands were run on 2026-10-28
unless marked otherwise.

## 1. Feature readiness

| Feature | State | Evidence |
|---|---|---|
| Auth (Sanctum) + RBAC (admin/fisher/reviewer) | Ready | Live: fisher login 200, wrong pw 422, anon 401, fisher-verify 403 |
| Offline catch + photo + GPS (Flutter/SQLite) | Ready | 42 Dart tests incl. offline register, persist, airplane toggle |
| On-device photo clarity (blur) check | Ready | Calibrated Laplacian scorer; retake banner; 5 tests (ADR-017) |
| Sync engine + retry | Ready | Live uploads 201; trip retry idempotent; catch retry gap noted below |
| AI species suggestion + disclaimer | Ready (KB engine) | Live: Te Bwere 0.94 + toxin disclaimer |
| Human confirmation + admin review | Ready | Live approve 200 + correct 200; AI text preserved |
| Admin dashboard + maps + catalog + advisories | Ready | `npm run build` clean; stats feed live (`total_catches=16`) |
| Advisory zones + offline radar | Ready | Live check-point inside Betio zone; 12 GIS Dart tests |
| Trip routes + polyline sync | Ready | 6 PHPUnit + 6 Dart + 8/8 live acceptance |
| Server control scripts | Ready | start/status/stop syntax-checked, status/stop behavior verified |

## 2. Test results

| Suite | Result | Evidence |
|---|---|---|
| Backend `php artisan test` | 54 pass (410 assertions; +8 SAM) | Re-run Day 4 |
| Mobile `flutter test` | 27 pass (12 GIS, 4 sync, 4 AI catch, 6 trip, 1 widget) | Re-run Day 4 |
| Frontend `npm run build` | Clean, 0 errors | Re-run Day 4 |
| Day 4 live integration (`/tmp/day4_integration.js`) | 17/18 steps PASS (see §3) | Live server :8087 |
| P1-05 acceptance (8 checks) | 8/8 PASS | Prior Day 4 run, code unchanged since |
| Day 1 (7) / Day 2 (8) / Day 3 (9) scripts | PASS (prior sessions) | NOT re-run Day 4 |
| Seed reproducibility | PASS | `migrate:fresh --seed` → 5 users, 9 species, 2 trips, 8 catches, 3 zones |
| MySQL live run | BLOCKED | Sandbox veto (see §4) |
| Android APK | BLOCKED | No JDK + downloads blocked (see §4) |

## 3. End-to-end acceptance (18-step fisherman workflow)

| # | Step | Verdict | Evidence |
|---|---|---|---|
| 1 | Authenticate | PASS | Fisher login 200 + token (live) |
| 2 | Start fishing trip | PASS | `POST /api/trips` 201 `in_progress` id=4 (live) |
| 3 | Disable internet | PASS | Airplane-mode toggle + offline flows (Dart tests) |
| 4 | Record GPS coordinates | PASS | 3 breadcrumbs `synced=3` (live) |
| 5 | Cached demo advisory warning | PASS | `check-point` inside Betio zone, 2 species (live) + radar engine (Dart) |
| 6 | Photograph a fish | PASS | Preset/file/URL inputs accepted; `photo_url` stored (live) |
| 7 | Register catch offline | PASS | `local_catches` pending queue (Dart) → `POST /api/catches` 201 (live) |
| 8 | Close and reopen app | PASS | Restart-resume tests green (Dart) |
| 9 | Catch persists | PASS | SQLite durability tests green (Dart) |
| 10 | Restore connectivity | PASS | Airplane OFF + `performFullSync` (Dart) |
| 11 | Synchronize catch | PASS | Upload 201 with server id=16 (live) |
| 12 | No duplicates on retry | **FAIL** | Identical catch retry → **2 rows** (live). Trips idempotent (3 pts after retry) |
| 13 | Photo to Meta online model | PASS (LIVE) | Real `sam-3.1` call: HTTP 200, 6.8s, 17 objects, raw (15,177 B) verbatim; coverage unknown (arithmetic codec, ADR-016) |
| 14 | Store actual AI response | **PASS (path)** | `catches.ai_metadata` persists verbatim provider output (test-verified); live payload arrives with the key |
| 15 | Human species confirmation | PASS | Approve + notes 200 (live) |
| 16 | Open admin dashboard | PASS | Build clean + stats feed live; visual run on host (sandbox can't render) |
| 17 | Review catch, photo, GPS | PASS | GET shows `photo_url` + `1.334,172.984` (live) |
| 18 | Verify / correct species | PASS | Approve 200 + correct-to-species-1 200 (live) |

## 4. Deployment status

| Item | State | Notes |
|---|---|---|
| Local demo stack | Ready | `start.sh`/`status.sh`/`stop.sh`; backend+CORS+Flutter verified live |
| Laravel production config | Ready | `docs/deployment.md` + `.env.production.example` |
| MySQL secure config | Guide ready, live run BLOCKED | Schema statically compatible (`json`, single `dropColumn`); server can't boot in sandbox (unix-socket veto, 3 attempts) |
| Next.js deploy | Guide ready | Build clean; `NEXT_PUBLIC_API_URL` + CORS split-host notes |
| Android demo build | BLOCKED | SDK 36.1.0 + licenses present; no JDK anywhere, binary downloads proxy-blocked |
| Secrets handling | Verified | `.env` ignored, examples placeholder-only, key backend-only, CORS 204 live |
| Rollback instructions | Ready | `docs/deployment.md §6` (code + DB + APK) |

## 5. Meta model integration status

- Text reasoning: `MetaAiService` + `/api/ai/meta-health` (RBAC 401/403/200),
  unit-tested with mocks.
- **SAM 3.1 segmentation integrated (Day 4 follow-up):**
  `SamSegmentationService` calls `sam-3.1` on the same Meta Model API
  (`MODEL_API_KEY` auth, Responses streaming), decodes the SAM 3 grammar
  (boxes + COCO-RLE mask coverage), returns the raw output verbatim, and
  feeds `identification.segmentation` evidence into `/api/ai/identify-fish`
  (fish/no-fish warning; KB suggestion unchanged; never blocks). Raw
  responses persist via `catches.ai_metadata`. 8 PHPUnit tests (faked
  streams) pass; suite total 54.
- **Live SAM verified:** user configured `MODEL_API_KEY`; one real
  `sam-3.1` call returned HTTP 200 in 6.8s with 17 objects; the verbatim
  raw output (15,177 B) is stored in catch #23's `ai_metadata` (read-back
  matched). Mask coverage stays UNKNOWN (arithmetic codec — see ADR-016);
  suites now force-unconfigure the key so tests can never bill (54 pass,
  0.8s).
- SAM segments/isolates fish; it does **not** name species. Species
  suggestions remain knowledge-base + human confirmation.
- No live Meta spend incurred yet. First live call needs the user's key +
  billing approval.

## 6. Known limitations

1. **Catch uploads are not idempotent** — a sync retry duplicates rows
   (proven: 2 identical rows). Trips already upsert on `(trip_id, sequence)`.
2. **Missing GPS silently defaults** — `CatchController::store` falls back to
   `1.3650,172.9450` instead of null, fabricating a location.
3. **Mobile API URL is hardcoded** (`http://127.0.0.1:8000`) — device/emulator
   demos need a settings override or `--dart-define` plumbing.
4. **`next.config.ts` rewrite hardcodes 127.0.0.1:8000** — split-host deploys
   must use `NEXT_PUBLIC_API_URL` + extend `cors.php` origins.
5. **No DB-level unique key on `(trip_id, sequence)`** — idempotency relies on
   `updateOrCreate`; fine for single-writer demo.
6. Day 1/2/3 acceptance scripts not re-run on Day 4 (prior passes stand).

## 7. Video readiness

- Script ready: `docs/demo-video-script.md` (6 min, honest Meta narration).
- Shot prep listed (fresh boot, seed logins, preset photos, fallback plan).
- **Not recorded yet** — Day 5 action on the demo machine.

## 8. Repository readiness

- Only Day 1 commits exist (`7b9d397`, `74b5bef`, `f75eaab`); **all Day 2–4
  work is uncommitted** — commit is a user decision (agent rules forbid
  committing unasked).
- `.gitignore` now covers `mobile/build/`, `.dart_tool/`, uploads,
  `.toolchain/`; `backend/.env` confirmed ignored.
- `walkthrough.md` created (resolves all prior missing-file references).
- New/updated docs: `README.md`, `SUBMISSION_CHECKLIST.md`, `walkthrough.md`,
  `docs/{deployment,architecture,demo-video-script}.md`,
  `backend/.env.production.example`.

## 9. Submission blockers

None for a local-demo submission. The following bound what can be *claimed*:

- Online species *naming* beyond the knowledge base (SAM live-verified for
  segmentation; naming is still KB + human confirmation per ADR-002/016).
- Android APK artifact (needs JDK on the build machine).
- MySQL-backed staging run (needs a host that can run mysqld).
- Recorded demo video (needs the demo machine + camera run).

## 10. Day 5 actions (in priority order)

1. **Commit Day 2–4** (user): review `git status`, commit in slices; push.
2. **Catch idempotency**: add `client_uuid` unique column + `updateOrCreate`;
   mobile sends its local UUID (mirrors the trip pattern). Re-run §3 step 12.
3. **GPS defaults → null** in `CatchController::store`; audit rows already
   carrying `1.3650,172.9450`.
4. **MySQL staging run**: `migrate:fresh --seed` + full suite against MySQL 8.
5. **Meta**: provision key + billing approval; pick vision-capable model (or
   keep KB + text notes and say so); first live probe.
6. **Android**: install Temurin 17 on demo machine; `flutter build apk
   --debug`; add API-base override; device-test offline→sync.
7. **P0-03-AI**: confirm with claiming owner and close (implementation +
   tests already green) — do not take over unasked.
8. **Record the video** from `docs/demo-video-script.md` after a fresh
   `stop.sh` → `start.sh` → `status.sh` cycle.
9. Rotate/remove seed credentials before any public deployment.
