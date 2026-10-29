# Kiribati AI Fisheries Assistant

Offline-first hackathon prototype for Kiribati artisanal fisheries: fishers
log catches with photos and GPS without connectivity, synchronize on shore,
receive advisory AI species suggestions, and officers verify every record on
an admin dashboard with maps and poisoning-advisory zones.

> Safety: AI suggestions are advisory only and **cannot detect fish toxins**
> from photographs. Advisories are evidence-based and human-published.

## Quickstart (local demo)

```bash
bash scripts/stop.sh     # clear leftovers
bash scripts/start.sh    # backend :8000 + Flutter :8080 + dashboard :3000 (detached)
bash scripts/status.sh   # three ✔ UP lines
```

Open http://127.0.0.1:3000 (dashboard) and http://127.0.0.1:8080 (mobile app,
ready after first Flutter build). Seed logins: admin
`admin@fisheries.gov.ki` / `KiribatiAdmin2026!`, fisher `teititeki@fisher.ki` /
`FisherPass2026!`. Full tour: [walkthrough.md](walkthrough.md).

## Layout

- `backend/` — Laravel 11 REST API (Sanctum auth, RBAC, catches, trips,
  species, advisory zones, AI identification, verification). SQLite for demo,
  MySQL for production (`docs/deployment.md`).
- `frontend/` — Next.js admin dashboard (review table, Leaflet maps, species
  catalog, advisory editor, verification workflow).
- `mobile/` — Flutter app (offline SQLite, safety radar, catch logging, trip
  recorder, sync engine).
- `scripts/` — `start.sh` / `status.sh` / `stop.sh` plus acceptance suites.
- `docs/` — deployment, architecture, AI flow, video script.

## Testing

```bash
cd backend && php artisan test     # 46 PHPUnit, 373 assertions
cd ../mobile && flutter test       # 27 Dart tests
cd ../frontend && npm run build    # production build, 0 errors
```

Day 4 integration evidence (18-step workflow + auth/authz/validation) and the
PASS/FAIL/BLOCKED table live in [SUBMISSION_CHECKLIST.md](SUBMISSION_CHECKLIST.md).

## Documentation index

- [walkthrough.md](walkthrough.md) — demo tour
- [SUBMISSION_CHECKLIST.md](SUBMISSION_CHECKLIST.md) — readiness + test evidence
- [docs/architecture.md](docs/architecture.md) — system diagram + trust boundaries
- [docs/ai-species-identification-flow.md](docs/ai-species-identification-flow.md) — how AI identification works
- [docs/deployment.md](docs/deployment.md) — production deploy + MySQL + rollback
- [docs/demo-video-script.md](docs/demo-video-script.md) — 6-minute video beats
- `AGENTS.md`, `PROJECT_STATE.md`, `TASKS.md`, `DECISIONS.md` — agent coordination

## Status

Day 4 integration complete; see `SUBMISSION_CHECKLIST.md` for readiness,
known limitations (catch-upload idempotency, live Meta vision, Android APK),
and Day 5 actions.
