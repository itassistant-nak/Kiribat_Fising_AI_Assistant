# Demonstration Video Script (draft, ~6 minutes)

Audience: hackathon judges. Rule: show only what runs — narrate the Meta
integration exactly as configured (knowledge-base identification + honest
`unconfigured` health state). All data is synthetic (`[DEMO - SYNTHETIC]`).

## 0:00–0:30 — Setup (voiceover + terminal)

Show `bash scripts/status.sh` → three `✔ UP` lines. Say: "API on 8000,
dashboard on 3000, mobile app on 8080 — one command starts the stack."

## 0:30–1:30 — Scene 1: At sea, offline (Flutter app)

1. Open the app → Safety Radar tab. Toggle airplane mode ON.
2. Tap simulated positions drifting toward the Betio reef zone: GREEN safe →
   YELLOW caution (≤500 m, species listed) → RED inside (evidence shown).
3. Open Trips → Start Trip → record 3 breadcrumbs → show the counter.
4. Open Log Catch → attach the Te Bwere preset photo, 3.5 kg, GPS shown →
   save. Point out `waiting_for_online_ai` + `pending` badges.
5. Reload the app (restart) → catch and trip are still there.

Say: "No internet, no problem — everything persists locally."

## 1:30–2:30 — Scene 2: Back on shore, sync (Flutter + API)

1. Airplane mode OFF → Sync tab → Synchronize Now.
2. Show result: zones/species cached, catch + trip uploaded with server IDs.
3. Say: "Retries are idempotent for routes — the same batch twice still
   yields one polyline." (Do NOT claim catch dedupe — it is a known gap.)

## 2:30–4:00 — Scene 3: AI identification, honestly (dashboard)

1. Open `http://127.0.0.1:3000` → Stored Fish catalog → click Te Bwere →
   "Identify with AI" → show suggestion, confidence, traits, candidates.
2. Read the disclaimer aloud: "Photographs cannot detect marine toxins."
3. Open `/api/ai/meta-health` (or state it): "Live Meta text reasoning is
   wired but unconfigured in this build — no key, no billing — so the
   knowledge base answers and the endpoint reports `unconfigured` instead of
   faking AI output."

## 4:00–5:30 — Scene 4: Human review (dashboard)

1. Catches tab → the synced catch appears with photo, weight, GPS, map pin.
2. Click the photo → CorrectionModal → Approve with a note → badge flips to
   Verified; stats cards update.
3. Show a second catch → Correct species → note the original AI suggestion is
   preserved next to the correction (auditability).
4. Briefly show the advisory tab: published Betio polygon + point tester.

## 5:30–6:00 — Close (voiceover + architecture diagram)

"Offline-first capture, durable sync, advisory AI with mandatory human
confirmation, and evidence-gated safety zones." Show `docs/architecture.md`
rendered. Credits + repo link.

## Shot list / prep

- `bash scripts/stop.sh && bash scripts/start.sh` fresh before recording.
- Seed logins: admin `admin@fisheries.gov.ki` / `KiribatiAdmin2026!`.
- Preset photo URLs are in the app (no file hunting on camera).
- If live demo fails: fall back to the recorded video, then show
  `SUBMISSION_CHECKLIST.md` test table as evidence.
