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

## ADR template
- ID/date:
- Status: Proposed / Accepted / Superseded
- Context:
- Decision:
- Alternatives:
- Consequences:
- Approved by:
