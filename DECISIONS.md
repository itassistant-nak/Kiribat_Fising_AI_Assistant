# Architecture Decision Log

## ADR-001 — Offline-first architecture (proposed)
- Status: Proposed, requires project inspection/approval.
- Decision: Flutter + SQLite for mobile; Laravel + MySQL backend; Next.js admin.
- Rationale: Fishing trips may lack connectivity.
- Consequences: Need idempotent sync, local durable storage, and clear stale-data handling.

## ADR-002 — AI identification is advisory (proposed)
- Status: Proposed.
- Decision: Use a documented vision-capable Meta model server-side when online; store suggestions separately from human verification.
- Rationale: Avoid false species certainty and protect API secrets.
- Consequences: Offline images queue until connectivity returns; previous AI responses are a reference catalog, not an offline image classifier.

## ADR-003 — Safety zones require evidence and approval (proposed)
- Status: Proposed.
- Decision: Authorized administrators associate species with geographic advisories, with evidence, approval, and expiry.
- Rationale: Images cannot establish ciguatoxin presence; no-advisory does not mean safe.

## ADR-004 — Next.js Admin Dashboard and Laravel API Architecture (accepted)
- Status: Accepted (Day 1).
- Context: Need an administrative dashboard to visualize catch statistics, review and verify AI species suggestions, and render GPS markers and trip routes with Leaflet.
- Decision:
  1. Backend in `backend/` using Laravel REST API with SQLite database (`database.sqlite`), token-based authentication, and role-based access control (`admin`, `fisher`, `reviewer`).
  2. GPS privacy protection: Precise coordinates are restricted to authorized administrators; unauthorized users receive redacted coordinates.
  3. AI suggestions preserved immutably in `ai_species_suggestion` and `ai_confidence` while human verification records `verified_species_id`, `verification_status`, `verified_by_user_id`, and `verification_notes`.
  4. Frontend in `frontend/` using Next.js with client-side Leaflet mapping for Kiribati (Tarawa Atoll) catch markers and trip route polylines.
  5. UI states explicitly handle loading skeletons, empty filters, and API errors with retry controls without fabricating data.
- Consequences: Modular structure, secure endpoints, auditable AI verification records, and automated testability.

## ADR template
- ID/date:
- Status: Proposed / Accepted / Superseded
- Context:
- Decision:
- Alternatives:
- Consequences:
- Approved by:

