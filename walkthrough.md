# Demo Walkthrough

End-to-end tour of the Kiribati AI Fisheries Assistant prototype. All data is
synthetic (`[DEMO - SYNTHETIC]`); seed logins are admin
`admin@fisheries.gov.ki` / `KiribatiAdmin2026!` and fisher
`teititeki@fisher.ki` / `FisherPass2026!`.

## 1. Start the stack

```bash
bash scripts/stop.sh     # clean slate (kills leftovers)
bash scripts/start.sh    # detached backend + Flutter + frontend
bash scripts/status.sh   # expect three ✔ UP lines
```

- API: http://127.0.0.1:8000/api/health
- Dashboard: http://127.0.0.1:3000
- Mobile app: http://127.0.0.1:8080 (ready after first Flutter build)

## 2. Fisher at sea (offline)

1. Open the mobile app → Safety Radar → airplane mode ON.
2. Tap simulated positions toward Betio reef: GREEN → YELLOW (≤500 m) → RED
   (inside, evidence shown).
3. Trips → Start Trip → record breadcrumbs → note the counter.
4. Log Catch → Te Bwere preset photo, 3.5 kg → save (`pending`,
   `waiting_for_online_ai`).
5. Reload the app → catch and trip persist (restart resume).

## 3. Back on shore (sync)

1. Airplane mode OFF → Sync → Synchronize Now → server IDs return.
2. Trip route re-pushes are idempotent (same batch twice = one polyline).

## 4. AI identification (honest scope)

1. Dashboard → Stored Fish catalog → click Te Bwere → Identify with AI.
2. Suggestion + confidence + traits + candidates appear with the toxin
   disclaimer ("photographs cannot detect marine toxins").
3. Meta text reasoning is wired but **unconfigured** in this build (no key):
   `/api/ai/meta-health` reports `unconfigured` instead of faking output.

## 5. Officer review

1. Catches tab → synced catch with photo, weight, GPS pin on the map.
2. Click photo → Approve with note (or Correct species / Flag).
3. Original AI suggestion stays preserved next to the verdict; stats update.

## 6. Verify from the terminal

```bash
cd backend && php artisan test                                  # 46 pass
cd ../mobile && flutter test                                    # 27 pass
cd ../frontend && npm run build                                 # clean
PORT=8087 node scripts/verify_trip_routes_acceptance.js        # 8/8 (needs server)
```

Full evidence table: `SUBMISSION_CHECKLIST.md`. Video beats:
`docs/demo-video-script.md`. Something down? See `docs/deployment.md §7`.
