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

## ADR-003 — Safety zones require evidence and approval (accepted)
- Status: Accepted (Day 2).
- Decision: Authorized administrators associate species with geographic advisories, supported by clinical evidence, laboratory bioassays, approval timestamps, and scheduled review dates.
- Rationale: Images cannot establish ciguatoxin or marine biotoxin presence; no-advisory does not mean safe.
- Consequences: Draft and under-investigation advisories remain strictly hidden from fishers until formally published by an administrator.

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

## ADR-005 — Geographic Data Format, Point-in-Polygon Engine, and Advisory Publication Rules (accepted)
- Status: Accepted (Day 2).
- Context: Fish poisoning advisories require spatial boundary representation, point-in-polygon checks for fisher safety alerts, multi-species risk linking, and strict governance to prevent accidental or unauthorized warnings.
- Decision:
  1. Geographic format: Strict GeoJSON RFC 7946 Polygon geometry with `[longitude, latitude]` coordinate ordering, minimum 4 vertices per linear ring, and mandatory closure where vertex 0 equals vertex $N-1$.
  2. Point-in-Polygon engine: Implemented natively in pure PHP via the ray-casting (crossing number) algorithm, supporting exterior boundary checks and interior hole exclusions with zero external C-extension dependencies.
  3. Two-tier publication governance (RBAC):
     - Editors (`reviewer` and `admin`): Authorized to create and edit draft advisories, file evidence notes, and attach affected species.
     - Approvers (`admin` only): Only administrators can approve and publish an advisory zone as an active public warning, or unpublish it. Reviewers or fishers attempting to publish receive `403 Forbidden` (`ADMIN_APPROVAL_REQUIRED`).
  4. Species-specific advisories: Many-to-many relationship linking multiple species (e.g., Te Okaoka, Te Bwere) to a single zone with distinct risk levels.
  5. Immutable change audit logs: Every creation, edit, species sync, and publication state change is logged in `advisory_audit_logs` with actor attribution, timestamp, and attribute diffs.
  6. Demonstration safety protocol: All test zones are tagged `[DEMO - SYNTHETIC]`; clear disclaimers state that AI cannot determine fish toxicity from photographs.
- Consequences: Deterministic geospatial containment testing, high regulatory compliance, clear auditability, and protection against unwarranted public alarm.

## ADR-006 — Leaflet Dynamic Client Mounting, Guaranteed Container Heights, and Integrated Footer Legends (accepted)
- Status: Accepted (Day 2).
- Context: In Next.js App Router (React 19 / Turbopack), dynamic mapping components (Leaflet) that rely on `window` and DOM measurements can suffer from SSR hydration mismatches, 0-height container collapse, and absolute-positioned legend overlays colliding with adjacent content when maps are loaded in tabs.
- Decision:
  1. Client-only dynamic loading: Map components (`AdvisoryPolygonMap`, `FisheriesMap`) are imported using `next/dynamic` with `ssr: false` and a matching 520px loading skeleton, preventing any SSR evaluation of DOM APIs.
  2. Guaranteed container geometry: The map container DOM node uses explicit inline styles (`style={{ height: '520px', minHeight: '520px', width: '100%' }}`) alongside delayed `invalidateSize()` calls (100ms, 300ms, and resize events) to guarantee tile computation.
  3. Integrated footer legend layout: Instead of fragile absolute overlays (`absolute bottom-4 left-4`), the advisory status legend ("Published", "Under Investigation", "Draft", "New Drawn Polygon") is embedded directly into a dedicated flex footer attached to the bottom of the map card in standard document flow.
  4. Dedicated test alert banner: Live GPS point test results are rendered in a dedicated alert strip rather than an unconstrained floating modal.
- Consequences: Zero layout collapse, zero overlapping with warning or safety cards, robust mobile/desktop responsiveness, and clean Fast Refresh stability.

## ADR-007 — Admin AI Vision Assistant & Kiribati Local Name Ground Truth Training (accepted)
- Status: Accepted (Day 2).
- Context: Marine biologists and administrators at MFMRD require an interface to test and assist the Meta AI vision model on fish photographs, map morphological traits to authentic Kiribati vernacular names (*Te Bwere*, *Te Ingimea*, *Te Okaoka*, *Te Kuau*, *Te Baibo*, etc.), and establish human-verified ground truth records.
- Decision:
  1. Administrative Default: Sessions default to Administrator (`admin@fisheries.gov.ki`) so officers can immediately access AI training, catalog management, and restricted GIS tools.
  2. Multi-modal Input: `POST /api/ai/identify-fish` accepts multipart image file uploads, base64 data URLs, or image URLs, with morphological knowledge base extraction providing top candidate matches, confidence percentages, visual traits, and ciguatoxin bioaccumulation warnings.
  3. Human-in-the-Loop Verification: The administrator can confirm or override the English name, Kiribati local name, scientific name, and ciguatera risk category before committing.
  4. Dual-Store Persistence: Submissions record to the artisanal catch registry (`POST /api/catches`) while automatically establishing or updating reference taxonomy records (`POST /api/species`) with photo URLs.
  5. Immutable Model Auditability: The original AI suggestion string and confidence level remain stored immutably alongside the human ground truth, author attribution, and verification timestamp to enable ongoing model fine-tuning and drift analysis per ADR-002.
- Consequences: Continuous expansion of the Kiribati fisheries species catalog, improved model training data quality, full auditability, and protection against unwarranted automated classification errors.

## ADR-008 — Stored Fish & AI Species Catalog for Administrators (accepted)
- Status: Accepted (Day 2).
- Context: Ministry administrators and marine biologists require a unified, searchable registry displaying all fish species stored in the database or recognized by the Meta AI vision model, complete with authentic Kiribati vernacular taxonomy, specimen photographs, ciguatera toxicity indicators, and morphological identification traits.
- Decision:
  1. Dedicated Dashboard Tab: Implemented a 3rd top-level navigation tab `[🐟 Stored Fish & AI Species Catalog]` with live species counts.
  2. Integrated AI Knowledge Base: Merged the backend AI vision knowledge base (`AiIdentificationController::getKnowledgeBase()`) with the database catalog (`GET /api/species`), equipping every species with AI morphological traits (tail geometry, fin sickle shapes, opercular notches, coloration) and confidence expectations.
  3. Specimen Photographs: Ensured all 9 Kiribati species (*Te Atun*, *Te Bwere*, *Te Ikari*, *Te Kuau*, *Te Okaoka*, *Te Ouna*, *Te Ingimea*, *Te Baibo*, *Te Rereba*) have high-resolution reference photos.
  4. Multi-Criteria Filtering & Views: Equipped the UI with instant keyword searching (Kiribati name, English name, scientific name), ciguatera risk filtering (`low`, `medium`, `high`), AI-trained model toggles, and dual presentation modes (Grid Cards and Compact Table).
  5. Cross-Functional Actions: Enabled direct navigation to recorded catches filtered by species (`onViewCatches`) and direct specimen training modal launch (`onIdentifySpecies`).
  6. Catches Table AI Status Filter: Added `status = 'ai_identified'` in both backend `CatchController` and frontend `FilterBar` to isolate fish identified by AI.
  7. Interactive Card Links & In-Place Species Editing: Made the species title and subtitle on fish cards direct clickable triggers for "Identify with AI" pre-populating that specimen's photograph and hint. Provided multi-point edit access (subtitle, top overlay pencil, card footer, table row) launching `EditSpeciesModal` backed by `PUT /api/species/{id}` with dynamic knowledge-base synchronization and database fallback matching.
- Consequences: Full transparency into AI model vision capabilities, rapid retrieval of species records for regulatory enforcement, and zero friction between catalog inspection and AI training.

## ADR-009 — Meta Model API Integration, Muse Wonder Scope, and Secret Isolation (accepted)
- Status: Accepted (Day 2).
- Context: The user confirmed a working Meta Model API endpoint (`https://api.meta.ai/v1/responses`) using model `muse-wonder-1.0` with Bearer authentication via `MODEL_API_KEY`. We need to integrate this service securely into Laravel without secret leakage, without breaking offline-first mobile workflows, without replacing existing catch registration, and with accurate documentation of text reasoning versus vision capabilities.
- Decision:
  1. Service Layer Encapsulation: Built `MetaAiService` in `backend/app/Services/MetaAiService.php` using Laravel's `Illuminate\Support\Facades\Http`.
  2. Secret Isolation: `MODEL_API_KEY` is loaded strictly on the backend via `config('services.meta_ai.api_key')` from `.env`. It is never transmitted to Flutter, Next.js, Git repositories, log files, or API responses.
  3. Muse Wonder Modality Scope: `muse-wonder-1.0` is confirmed for text prompts and reasoning responses (`status: "completed"`). Because Muse Wonder is a text-based model, image recognition preserves our server-side Kiribati marine morphological knowledge base as the primary vision classifier, avoiding unverified assumptions about multi-modal image inputs.
  4. Protected Health Check Probe: Exposed `GET/POST /api/ai/meta-health` protected by Sanctum and `RequireAdminOrReviewer` allowing compliance officers to verify model connectivity and latency without exposing keys (`has_api_key: bool`).
  5. Workflow Integration: `AiIdentificationController::identify()` uses `MetaAiService` to augment responses with live fisheries advisory notes when online and configured, while maintaining zero downtime fallbacks when offline or unconfigured.
  6. Human-in-the-Loop Confirmation: Mandates human ground truth verification before saving catches (`POST /api/catches`), immutably preserving the AI suggestion alongside the confirmed species ID per ADR-002.
  7. Offline-First Preservation: Mobile clients (Flutter) record catches locally to SQLite first; Meta AI requests are handled exclusively server-side upon synchronization.
- Consequences: Zero secret leakage, resilient failover when offline, clear auditability, and protection against automated hallucination.

## ADR-010 — Fish Image Correction, AI Re-Identification, and Human Verification Workflow (accepted)
- Status: Accepted (Day 2).
- Context: Fishers may submit blurred, obscured, or misclassified photographs. Fisheries officers require the ability to replace or correct catch photographs, trigger immediate AI vision re-identification on the corrected specimen, and provide mandatory human officer ground-truth verification before persisting changes.
- Decision:
  1. Image Correction Workflow: In `CorrectionModal.tsx`, officers can replace catch photographs via 1-click verified Kiribati specimen presets (*Te Bwere*, *Te Ingimea*, *Te Okaoka*, *Te Kuau*, *Te Atun*, *Te Ikari*, *Te Baibo*), direct file upload (JPEG/PNG/WEBP), or external image URLs.
  2. Automated AI Vision Re-Identification: Selecting or uploading a new image automatically runs Meta AI Vision analysis (`POST /api/ai/identify-fish`), extracting confidence scores, morphological visual features, and pre-selecting the recommended species in the verification form.
  3. Mandatory Human Confirmation: Officers inspect the AI's recommendations on the corrected image, choose an action (`Approve AI`, `Correct Species`, or `Flag`), provide reviewer rationale notes, and submit.
  4. Immutable Audit Trail: In `VerificationController::verify`, the original photo URL and initial AI suggestion are archived into `ai_metadata` (`original_photo_url`, `corrected_photo_url`, `corrected_ai_suggestion`, `photo_corrected_by`, `photo_corrected_at`), strictly adhering to ADR-002 model auditability.
  5. UI Direct Affordance: Photograph cells in `CatchTable.tsx` are interactive with a camera hover overlay, allowing 1-click navigation directly to the review and image correction interface.
- Consequences: Eliminates bad training data, improves catch registry accuracy, maintains an immutable history of fisher submissions, and guarantees human approval before changes are finalized.

## ADR-011 — Fish Card Specimen Photo Update and Automated AI Morphological Traits Extraction (accepted)
- Status: Accepted (Day 2).
- Context: In the Stored Fish & AI Species Catalog, each fish card represents a marine species with its reference specimen picture and AI morphological traits. When officers change the fish picture, the AI morphological traits must automatically re-extract, synchronize with the new visual traits, and persist to the catalog so the fish card is always up to date.
- Decision:
  1. Fish Card Affordances (`SpeciesCatalog.tsx`):
     - Each fish card provides a direct "Change Photo" camera button overlay on the specimen picture.
     - The AI Morphological Traits section is prominently displayed with trait count badge, "Re-Analyze Traits" action, and live analyzing spinner.
     - When no traits exist on a card, a "Generate AI Traits from Picture" button triggers in-place analysis.
  2. Specimen Photo Change & Auto-Extraction (`EditSpeciesModal`):
     - Provides three photo input modes: verified Kiribati specimen presets (*Te Bwere*, *Te Ingimea*, *Te Okaoka*, *Te Kuau*, *Te Atun*, *Te Ikari*, *Te Ouna*, *Te Baibo*, *Te Rereba*), direct image file upload (JPEG/PNG/WEBP), and custom photo URL.
     - Whenever the picture changes (preset click, file upload, or URL input), `analyzePhotoForTraits` automatically runs `api.identifyFish` to extract the new morphological visual features.
     - Extracted traits automatically replace/populate the `visualFeatures` state with live feedback ("✨ Meta AI Vision analyzed the picture: N morphological traits extracted and updated!").
     - The traits editor displays a "✨ Synced with Picture (Meta AI Vision Extracted)" badge, allowing trait inspection, deletion, or manual addition.
  3. Backend Persistence & Knowledge Base Sync:
     - Added `visual_features` JSON column to the `species` SQLite database schema (`2026_10_27_000004_add_visual_features_to_species_table.php`).
     - `PUT /api/species/{id}` validates and stores `visual_features`, dynamically synchronizes in-memory `AiIdentificationController::$speciesKnowledgeBase`, and returns `ai_trained = true`.
     - `AiIdentificationController::identify` prioritizes stored database `visual_features` and matches photos against known species `photo_url`.
  4. React Rules of Hooks Strict Compliance:
     - Unconditional hook execution at the top of `EditSpeciesModal` with modal visibility guards placed immediately before JSX return.
- Consequences: Immediate visual synchronization between specimen photographs and AI morphological traits, persistent storage across server restarts, and rich reference taxonomy for fisheries officers.

## ADR-012 — Non-Catch Designation on Admin Image Replacement & Fisherman Catch Policy (accepted)
- Status: Accepted (Day 2).
- Context: In the catch review workflow ("Recorded Catches & AI Species Suggestions"), an administrator or reviewer may change or replace the photo attached to a catch submission (e.g. because the fisher's photo was blurry, invalid, or replaced with a reference specimen). If an admin changes the image, this submission is no longer a genuine artisanal catch by the fisher. The user established the policy: *"In the Recorded Catches & AI Species Suggestions if the images change by the admin this is not a catch. only the catch from fishermen."*
- Decision:
  1. Data Model & Migration: Added `is_catch` boolean column (default `true`) to `catch_records` table (`2026_10_27_000005_add_is_catch_to_catch_records_table.php`).
  2. Image Change Policy: In `VerificationController::verify`, whenever `$newPhotoUrl && $newPhotoUrl !== $originalPhotoUrl`, the record is automatically designated as `is_catch = false` and `verification_status = 'not_a_catch'`, with audit metadata `'image_changed_by_admin' => true`, `'is_catch' => false`, while preserving the original photo and AI suggestion per ADR-002 and ADR-010.
  3. Direct Non-Catch Action: Supported `action = 'not_a_catch'` in `VerificationController`, enabling officers to classify invalid submissions (e.g. debris, boat deck, seaweed) as non-catches directly.
  4. Statistics Integrity: In `StatsController`, official fishery statistics (`total_catches`, `total_weight_kg`, `total_quantity`, `species_breakdown`) strictly aggregate only genuine catches from fishermen (`role === 'fisher'`, `is_catch = true`, `verification_status != 'not_a_catch'`). Non-catches are tracked separately under `not_a_catch_count`.
  5. UI Indications (`CorrectionModal.tsx` & `CatchTable.tsx`):
     - In `CorrectionModal.tsx`, changing an image immediately alerts the officer with a policy banner: *"Image Changed by Admin — Designated as 'Not a Catch' (Only catches from fishermen are genuine catches)"*, auto-sets decision to `'not_a_catch'`, and provides an explicit `[🚫 Not a Catch]` action button.
     - In `CatchTable.tsx`, non-catch rows display a `🚫 Not a Catch` badge, an overlay badge on specimen thumbnails, and metrics indicate `Excluded (Not a Catch)`. Genuine fisherman catches display a distinct `Fisherman` pill.
     - In `FilterBar.tsx`, officers can filter by `Fishermen Catches Only` or `Not a Catch`.
- Consequences: Official fisheries metrics are strictly protected from distortion by administrative reference photos; genuine fisher catches are clearly distinguished; full auditability is preserved.

## ADR-013 — Flutter Offline GPS Safety Warnings, SQLite Caching, and Anti-Jitter Alert Hysteresis (accepted)
- Status: Accepted (Day 3).
- Context: Artisanal fishermen operating in the South Tarawa lagoon and remote atolls frequently lose internet connectivity. Fishermen require real-time fish poisoning advisory warnings (ciguatoxins and marine biohazards) and access to verified species reference data entirely offline. GPS drift from wave action and satellite multipath can cause rapid alert flickering (jitter) if alerts are evaluated naively at polygon boundaries.
- Decision:
  1. Offline Mobile Stack: Implemented a Flutter application in `mobile/` with local SQLite storage powered by `sqflite` and `sqflite_common_ffi`, supporting seamless execution across mobile targets and Linux desktop testing environments.
  2. Offline Point-in-Polygon Engine: Pure Dart Ray-Casting algorithm (`PointInPolygonEngine`) evaluating local vessel coordinates against cached GeoJSON RFC 7946 Polygon boundaries and interior hole exclusions.
  3. Proximity Distance Calculator: Haversine distance and equirectangular line segment projection (`DistanceCalculator`) determining minimum distance to advisory boundaries in meters.
  4. 3-Tier Alert State Machine:
     - RED Active Alert: Coordinate inside active published advisory polygon ($0\text{m}$ distance).
     - YELLOW Caution Warning: Distance to advisory boundary $\le 500\text{ meters}$.
     - GREEN Safe Waters: Distance to all active advisory boundaries $> 500\text{ meters}$.
  5. Anti-Jitter Hysteresis & Throttling:
     - Distance Hysteresis Buffer: Transitioning from RED $\to$ YELLOW requires moving outside polygon AND distance $> 50\text{m}$; transitioning from YELLOW $\to$ GREEN requires distance $> 550\text{m}$ ($500\text{m} + 50\text{m}$).
     - Time Throttling: Duplicate alerts for the same zone and severity are throttled for 60 seconds; escalation (e.g. YELLOW $\to$ RED) is never suppressed.
     - Accuracy Filtering: Readings with GPS horizontal accuracy $> 50\text{m}$ are badged with a low-accuracy warning without falsely forcing zone triggers.
  6. Durable SQLite Sync & Offline Queue:
     - Inbound Sync: Downloads published advisory zones (`/api/advisory-zones`) and verified species catalog (`/api/species`), recording sync timestamps and a 7-day expiry in `cache_metadata`.
     - Stale Cache Detection: If offline $> 7$ days without sync, UI displays an amber advisory stale notice, while preserving cached boundaries for safety.
     - Outbound Catch Queue: Artisanal catches and photographs logged offline are stored with `sync_status = 'pending'`. When connectivity returns, `SyncService` uploads records and photographs to `POST /api/catches`, updating status to `'synced'`.
     - AI Modality Adherence: Offline catch photography does not hallucinate local AI identification; photos are queued for Meta AI vision analysis upon synchronization adhering to ADR-002 and ADR-009.
- Consequences: Complete offline safety protection for fishers in zero-connectivity waters, zero false alert jitter, auditable synchronization, and full compliance with fisheries evidence governance.

## ADR-014 — Offline Trip Route Recording & Idempotent Polyline Sync (accepted)
- Status: Accepted (P1-05).
- Context: Fishers need GPS trip breadcrumbs recorded fully offline (zero-connectivity waters) that survive app restarts and later sync as ordered polylines to Laravel, where the admin map already renders `GET /api/trips/{id}/route`. Retried uploads after timeouts must not duplicate points.
- Decision:
  1. Mobile recording: `TripRecorder` service appends breadcrumbs to SQLite (`local_trips`, `local_trip_points`) immediately, with 10 m minimum-distance and 5 s minimum-interval filters plus 50 m low-accuracy rejection (same rule as ADR-013). `UNIQUE (trip_id, sequence)` enforces ordering key stability locally.
  2. Restart resume: the unfinished trip (`status = 'in_progress'`) reloads via `resumeActiveTrip()`; new breadcrumbs continue `MAX(sequence) + 1` with no gaps or duplicates.
  3. Server endpoints: `POST /api/trips` (start, always `in_progress`), `POST /api/trips/{id}/route-points` (batch ≤ 2000 points, lat/lon range validation), `PATCH /api/trips/{id}` (end/update). Ownership guard returns 403 `GPS_PROTECTED`, matching existing trip privacy.
  4. Idempotency: server upserts on `(trip_id, sequence)`; mobile stores `server_trip_id` after first create (create-once) and re-pushes the full polyline in 500-point batches, so any retry converges without duplicates.
  5. Sync integration: trip upload is step 4 of `SyncService.performFullSync` (after catches); completed local trips also close the server trip. New breadcrumbs re-dirty a synced trip for incremental sync.
  6. No new native dependencies: positions flow through the recorder as lat/lon/accuracy (simulated in UI per Day 3 pattern), keeping the logic unit-testable without a GPS plugin.
- Consequences: Durable offline routes, safe retries, and admin polylines with zero frontend changes. Server lacks a DB-level unique constraint on `(trip_id, sequence)` (relies on `updateOrCreate`); add one if concurrent writers to the same trip ever exist.

## ADR-015 — Deployment Topology, Submission Honesty Rule, and Day 4 Freeze (accepted)
- Status: Accepted (Day 4).
- Context: The prototype must be demonstrable and submittable. Several
  integrations cannot be completed in this environment (no Meta key, no JDK,
  no runnable MySQL/Next servers in the sandbox), and one real defect was
  found (catch uploads duplicate on retry).
- Decision:
  1. Demo runs SQLite end-to-end; MySQL 8 is the documented production target
     (schema reviewed compatible; live staging run deferred to Day 5).
  2. Detached local topology via `scripts/start.sh|status.sh|stop.sh`
     (8000/3000/8080); CORS allows localhost patterns, split-host origins
     must be added explicitly in `config/cors.php`.
  3. Submission honesty rule: mocks only in automated tests; Meta, APK, and
     MySQL claims bounded to what was observed; the demo script narrates the
     `unconfigured` state instead of faking AI output.
  4. Behavior freeze: the catch-idempotency gap and GPS-default footgun are
     documented (Day 5 items 2–3) rather than patched during submission prep,
     to avoid destabilizing verified suites.
- Consequences: A truthful, reproducible demo today; a precise fix list for
  Day 5; no inflated capability claims.

## ADR-016 — SAM 3.1 Online Segmentation Evidence (accepted)
- Status: Accepted (Day 4 follow-up, user-directed).
- Context: The user required connecting identification to online AI, then
  named Meta SAM 3.1. SAM 3.1 is hosted on the Meta Model API (`sam-3.1`,
  same `MODEL_API_KEY`, Responses streaming) and returns detections +
  pixel masks for noun-phrase prompts — it confirms/isolates objects, it
  does not classify species.
- Decision:
  1. `SamSegmentationService` implements Meta's verified wire format
     (cookbook 07_sam): concept `fish` + image data URL (device uploads are
     not publicly reachable, so bytes are inlined), SSE output-text
     accumulation (plus plain-JSON fallback), conservative error mapping
     (`unauthorized` / `model_unavailable` / `failed`), never blocking.
  2. Identify returns `segmentation` evidence + `segmentation_raw` verbatim;
     species suggestions stay knowledge-base + human confirmation (ADR-002).
     The old fake "Meta Llama Vision" model label was removed.
  3. Raw provider output persists in `catches.ai_metadata` (existing JSON
     column; store endpoint now accepts it).
  4. Mask coverage is reported as UNKNOWN: SAM payloads are an
     arithmetic-coded format (base-85, context-modelled, per Meta's
     `meta_sam_parser` source), not COCO RLE. An assumed-COCO decoder was
     built, caught producing impossible values (>1) against the first real
     response, and removed. Boxes/mask-presence only until the official
     codec is ported and validated against captured raw samples.
  5. Test-isolation rule: suites must force-unconfigure the Meta key
     (`config(['services.meta_ai.api_key' => null])` or explicit `''`) so a
     developer's real `.env` key can never make tests slow, flaky, or billed.
- Consequences: One live `sam-3.1` call verified end-to-end (HTTP 200,
  17 objects, raw stored verbatim in catch #23, read-back matched). Coverage
  metrics await a validated codec port. Species naming remains KB + human.

## ADR-017 — On-Device Photo Clarity Check (accepted)
- Status: Accepted (Day 4 follow-up, user-directed: "tell if the photo is
  not clear or blurry").
- Context: Blurry catch photos silently degrade identification. The Laravel
  host has NO image decoder (no GD/Imagick/ffmpeg), so server-side pixel
  analysis is impossible there today; the phone has the photo first anyway.
- Decision:
  1. Clarity is assessed ON-DEVICE at capture (pure Dart, `package:image`):
     decode → ≤512px grayscale → variance-of-Laplacian; `blurry` below
     100.0, calibrated on real photos (sharp fish 1406.8, light blur 179.3,
     medium blur 36.1, blank 0.0 — threshold sits in the wide gap).
  2. UX: blurry verdict fires an orange snackbar + persistent banner with a
     one-tap Retake; the catch still saves (fisher's choice) with
     `photo_quality`/`photo_blur_score` persisted in SQLite.
  3. Server-side mirror is deferred until the host gains php-gd/ffmpeg, then
     the same threshold/algorithm applies to synced uploads.
- Consequences: Immediate offline feedback where it matters (at sea), zero
  extra API spend, honest `unknown` for undecodable bytes.

## ADR-018 — Structured Reference-Database Verification and Record Types (accepted)
- Status: Accepted (Day 4, P1-06).
- Context: The 35-entry I-Kiribati marine list (kiribati_marine_species_35.sql) mixes binomial fish species, broader groups ("Multiple species", Pinnidae, Plectropomus species, "-") and non-fish organisms (clams, crab, octopus, jellyfish, peanut worm). Storing review state only as free text in notes made it unqueryable, and identification centred on the 9-entry knowledge base could not distinguish verified records from unreviewed imports.
- Decision:
  1. Species gains verification_status (verified|needs_review, default needs_review), record_type (species|taxonomic_group|non_fish_organism, default species) and scientific_name_as_provided (verbatim, nullable) via 2026_10_29_000001 migration (SQLite/MySQL portable).
  2. KiribatiMarineSpeciesSeeder stores the scientific name exactly as provided (misspellings retained), marks every import needs_review with ciguatera_risk unknown (no invented risk/photo/traits), classifies each source ID explicitly, and backfills only rows it previously imported; the 9 curated species are explicitly verified/species and never overwritten (Te Ikari/Te Kuau collisions preserved).
  3. GET /api/species filters by verification_status and record_type and annotates is_verified_record/requires_human_review; POST/PUT /api/species validate the new fields (new records default needs_review).
  4. POST /api/ai/identify-fish retrieves reference_candidates from the database (verified first, max 5, source=reference_database, requires_human_confirmation=true, no fabricated confidence), labels the primary with verification_status/record_type/is_verified_record, keeps SAM 3.1 segmentation as separate evidence, and keeps Muse Wonder advisory text-only. meta-health reports image modality (muse text-only, sam-3.1 image) without making a billable probe call.
- Consequences: Officers and clients can tell verified taxonomy from unreviewed imports at a glance; non-fish and group entries are first-class without pretending to be species; human review remains mandatory and toxicity is never inferred from a photograph.

## ADR-019 — Catch Idempotency, GPS Honesty, and Sync Retry Without Re-Billing (accepted)
- Status: Accepted (Day 5/6, P0-10).
- Context: Day 4 step 12 proved catch uploads duplicate on retry (trips were already idempotent), CatchController fabricated GPS 1.3650,172.9450 when missing, Flutter stranded `failed` catches forever, retried AI identification (billable), and read a stale `primary_identification` key so real Laravel `identification` responses (incl. SAM evidence) never reached the fisher UI. MySQL seeding also failed (1701) on TRUNCATE of FK-referenced advisory zones.
- Decision:
  1. `catch_records.client_uuid` (nullable, unique) carries the mobile local UUID; store replays same-owner retries as 200 `idempotent_replay` before file handling, returns 409 for another fisher's UUID, and converges on the unique-index winner under races. No-UUID web/admin creates are unchanged.
  2. Missing latitude/longitude store as null; coordinates range-validated (422). Offline `captured_at` and `species_name_raw` are preserved.
  3. Flutter sends `client_uuid`, retries pending+failed catches, persists the AI result before uploading (retry never re-calls identify), and parses `identification` with nested SAM `segmentation` + verification labels displayed as evidence only.
  4. Seeder reseeds advisories with FK-safe DELETE (cascade) instead of TRUNCATE, restoring MySQL 8 `migrate:fresh --seed`.
- Consequences: Offline retries are safe and cheap; locations are never invented; SAM stays segmentation-only; demo seeds reproduce identically on SQLite and MySQL 8.

## ADR-020 — Muse Wonder Fisheries Assistant Is Advisory-Only and Grounded (accepted)
- Status: Accepted (Day 5, P0-09).
- Context: P0-09 required deepening the Muse Wonder 1.0 integration: verify image modality, add a structured fisheries-assistant query, and combine SAM 3.1 + knowledge base + Muse without letting the text model invent species, confidence, or safety verdicts.
- Decision:
  1. `MetaAiService::queryFisheriesAssistant()` receives only the KB-selected candidate (Kiribati/English/scientific names), a textual SAM evidence summary, and an optional fisher hint. Its prompt asks solely for habitat, subsistence significance, and ciguatoxin-pattern notes, and verbatim forbids alternative species names, invented confidence/statistics, and photo-based identity or toxicity claims.
  2. `AiIdentificationController::identify()` orders evidence SAM → KB → Muse: SAM segments (never names), the KB/reference database supplies identity, risk, and confidence, and Muse text survives only as `meta_model.advisory_notes`. Human confirmation remains mandatory (ADR-002).
  3. Image modality is probed only via explicit `MetaAiService::investigateImageModality()` calls; `meta-health` reports the known modality (muse-wonder-1.0 text-only, sam-3.1 image) without spending a billable probe, and never returns the API key.
  4. All automated tests mock Meta (`Http::fake`, forced empty keys, `assertNothingSent`/`assertSentCount` guards) so suites can never bill or leak (extends ADR-016 rule 5).
- Consequences: Officers get grounded biology/safety context beside — never instead of — KB identity and human verification; the no-invention property is regression-tested with an adversarial advisory that names a different species; live vision claims still await a user-approved key and probe.

## ADR-021 — Flutter Online Sync and Fisher Sanctum Authentication with MySQL Backend (accepted)
- Status: Accepted (Day 6).
- Context: The Flutter mobile application requires offline-first SQLite durability while operating in remote Kiribati waters, and automatic online synchronization with the Laravel REST API connected to MySQL (`kiribati_fisheries`) when connectivity is available. Laravel API endpoints (`/api/species`, `/api/advisory-zones`, `/api/catches`, `/api/trips`) are protected by Sanctum token authentication.
- Decision:
  1. Fisher Authentication: Added `ApiClient.login(email, password)` and `SyncService.ensureAuthenticated()` using seeded fisher credentials (`teititeki@fisher.ki` / `FisherPass2026!`). Tokens are held securely in memory on the mobile client and automatically injected into all sync request headers (`Bearer $token`).
  2. Automatic Background Sync: Added `SyncService.startAutoSync()` running a periodic timer (default 30s) that monitors for pending catches, pending trips, or stale advisory zone caches, auto-authenticating and synchronizing with MySQL only when the device is not in airplane mode.
  3. Reference Data Inbound Sync: Updated Flutter's `SpeciesItem` and SQLite `species_catalog` table to capture `verification_status` and `record_type` from the 42 reference species in MySQL.
  4. Outbound Catches & GPS Breadcrumbs: Client UUIDs prevent duplicate submissions via server-side idempotency (`200 idempotent_replay`); route breadcrumbs synchronize as ordered polylines without re-billing AI models.
- Consequences: Seamless transition between offline SQLite storage and MySQL online persistence; zero authentication failures; verified idempotency and zero duplicate catch records.

## ADR-022 — Fisherman Live GPS Map in Flutter Mobile App (accepted)
- Status: Accepted (P1-07).
- Context: Artisanal fishers operating in South Tarawa lagoon and outer atolls require visual geospatial awareness of their vessel's live location, distance to nearby fish poisoning advisory boundaries, and locations of recent catches and trip routes matching the Next.js administrator dashboard (`FisheriesMap.tsx`).
- Decision:
  1. Interactive Map Engine: Integrated `flutter_map` (v8.3.2) and `latlong2` (v0.10.1) rendering OpenStreetMap tiles with resilient fallback and maritime lagoon styling.
  2. Live Vessel Location Marker: Real-time vessel marker at current coordinates (`_currentLat`, `_currentLon`) with dynamic GPS accuracy radius circle and 3-tier safety status banner (🟢 Safe Waters / 🟡 Caution $\le 500$m to boundary / 🔴 Inside Active Poisoning Advisory Zone) driven by `ZoneAlertEngine`.
  3. Advisory Zone Polygons: GeoJSON RFC 7946 polygons parsed from SQLite `advisory_zones` cache with color-coded boundaries (Red for authority-confirmed, Orange for suspected) and interactive bottom sheet displaying clinical hospital evidence, biohazard concerns, and high-risk species (*Te Okaoka*, *Te Bwere*).
  4. Recorded Catch & Route Visualization: Markers for local and synced catches with verification status badges, and breadcrumb route polylines with START and RETURN markers.
  5. Multi-Layer Toggles & Recenter: On-map action controls to toggle Advisories, Catches, and Routes, with 1-tap "Recenter on My Vessel".
  6. Navigation Integration: Added dedicated "Live GPS Map" destination to the primary bottom navigation bar in `main.dart`, plus quick-launch action buttons from `RadarScreen` and `TripScreen`.
- Consequences: Fishermen possess real-time visual geospatial safety awareness at sea, eliminating guesswork regarding boundary proximity; 100% offline-capable with local SQLite data; zero regressions on existing test suites.

## ADR-023 — Meta Muse Glimmer Multimodal Vision Integration for Fish Species Identification (accepted)
- Status: Accepted (P0-13, 2026-10-30).
- Context: The prototype previously relied on `muse-wonder-1.0` (a text-only reasoning model) alongside a server-side morphological knowledge base heuristic for fish matching because Muse Wonder could not process image inputs directly (ADR-009, ADR-016, ADR-020). Meta's `muse-glimmer` (Muse Glimmer 30B) open-weight multimodal model features a perception encoder capable of processing image pixels and text simultaneously. The user requested integrating Muse Glimmer as the primary online classifier for fish species identification, grounded with the species available in the Laravel backend database.
- Decision:
  1. Primary Online Classifier: Integrated Meta Muse Glimmer (`muse-glimmer` via `MetaAiService::identifyFishVision`) as the primary online vision classifier in `POST /api/ai/identify-fish`. Muse Glimmer inspects image data URLs / URLs directly and outputs structured JSON containing vernacular Kiribati name, English name, scientific name, confidence, and observed visual morphology.
  2. Grounding in Laravel Backend Species: The vision prompt injects the verified Kiribati marine species catalog from the database (`Species::class`) into the model's reference context, ensuring classification aligns with local taxonomy (*Te Bwere*, *Te Ingimea*, *Te Okaoka*, *Te Kuau*, *Te Atun*, *Te Ikari*, etc.).
  3. Seamless Knowledge Base Fallback: If Muse Glimmer is unconfigured, times out, or errors (e.g. offline field usage), `AiIdentificationController` gracefully falls back to the deterministic Kiribati marine morphological knowledge base, ensuring zero downtime and 100% offline-readiness.
  4. Preservation of Safety and Ground Truth (ADR-002): AI identifications remain strictly advisory suggestions (`requires_human_confirmation: true`). Disclaimers that photographs cannot detect ciguatoxins are enforced. SAM 3.1 segmentation evidence and Muse Wonder biological advisory notes are combined harmoniously alongside Muse Glimmer vision.
  5. Secret Isolation & Zero Key Leakage: `MODEL_API_KEY` remains strictly backend-isolated, never exposed to clients, git, or responses. `meta-health` reports Muse Glimmer multimodal capability without making billable probe calls.
- Consequences: True AI image-based species classification powered by Meta's multimodal Muse Glimmer; grounded in Kiribati marine biology; robust offline fallback; 86 passing tests with zero regressions.

## ADR-024 — 3-Stage Progressive AI Fish Species Identification Pipeline (accepted)
- Status: Accepted (P0-15, 2026-11-03).
- Context: The user requested that the AI identification system search in a specific order:
  1. Search first for the image among backend fish species that have catalogued photographs.
  2. If no confident match, evaluate visible morphological traits against the curated Kiribati traits catalog.
  3. If still nothing matches, fall back to broad web-scale global marine taxonomy knowledge.
- Decision:
  1. Multi-Stage Pipeline Architecture: Implemented in `MetaAiService` (`sendVisionRequest`, `matchAgainstDbPhotos`, `matchByMorphologicalTraits`, `identifyFromWeb`) and orchestrated in `AiIdentificationController::identify`.
  2. Stage 1 (DB Photos): Prompts Muse Glimmer with the catalog of species possessing photos (`photo_url`). Requires confidence $\ge 0.75$ and visual certainty to stop at Stage 1 (`source: db_image_match`).
  3. Stage 2 (Morphological Traits): If Stage 1 returns no confident match, prompts with curated `visual_features` (caudal fin shapes, body coloration, jaw profiles). Requires confidence $\ge 0.65$ to stop at Stage 2 (`source: morphological_match`).
  4. Stage 3 (Global Web Vision): If local references and traits do not match, queries broader global taxonomy knowledge without local constraints, grounding results in the DB if a species match is found (`source: web_vision`).
  5. Auditability & UX: The response includes an `identification_pipeline` object recording latency, status, and outcome for all stages. The Next.js frontend displays a 3-step progress bar during analysis, color-coded stage badges, and an interactive pipeline audit trail.
  6. Resilient Fallback: If Meta AI is unconfigured or unavailable, gracefully falls back to local knowledge base and database matching (`knowledge_base_fallback`), maintaining ADR-002 human confirmation standards.
## ADR-025 — OpenAI Vision API Ultimate Fallback Integration (accepted)
- Status: Accepted (P0-16, 2026-11-03).
- Context: The user requested adding OpenAI's vision API (`gpt-4o`) as an additional ultimate fallback when Meta models (Stages 1-3) and local species-matching (KB/DB hint and photo matching) have all finished without an identification.
- Decision:
  1. Service Encapsulation: Built dedicated `OpenAiVisionService` (`backend/app/Services/OpenAiVisionService.php`) calling OpenAI Chat Completions (`/v1/chat/completions`) with `model: gpt-4o` and structured JSON response formatting.
  2. Execution Order: Triggered strictly as Stage 4: Meta Muse Glimmer (Stages 1-3) → Local KB & DB photo/hint matching → **OpenAI Vision (Stage 4)** → Deterministic KB hash fallback (absolute last resort for offline reproducibility).
  3. Secret Isolation: `OPENAI_API_KEY` is loaded exclusively from backend configuration (`services.openai.api_key`), never exposed to frontend clients, Git, or logs.
  4. Resilient Offline Graceful Degradation: If unconfigured, the service returns `status: unconfigured` without making any network requests, preserving 100% offline functionality.
  5. Pipeline & Health Reporting: Added `openai_vision` stage tracking to the `identification_pipeline` audit object, `openai_model` metadata to the identification response, and health status reporting in `GET /api/ai/meta-health`.
  6. Frontend UI: `AiFishIdentifierModal.tsx` upgraded with 4-stage animated progress tracker during analysis, Stage 4 teal badge (`Stage 4: OpenAI Vision (gpt-4o)`), 4-stage pipeline audit breadcrumbs, and OpenAI visual reasoning cards.
  7. ADR-002 Compliance: OpenAI suggestions remain strictly advisory (`requires_human_confirmation: true`) with phototrap CFP disclaimer preserved.
- Consequences: Highly robust multi-cloud fallback hierarchy; 92 backend PHPUnit tests pass (1164 assertions); Next.js builds cleanly with zero TypeScript errors.

## ADR-026 — Parallel AI Fish Image Identification (Meta Muse Glimmer & OpenAI Vision) (accepted)
- Status: Accepted (P0-17, 2026-11-03).
- Context: The user required independent parallel evaluation by both Meta Muse Glimmer and OpenAI Vision on the same uploaded catch photograph. The backend must orchestrate parallel execution, normalize model responses, compare results (agreement, disagreement, single provider, both failed, insufficient evidence), preserve catalog integrity without fabricating species records, and present side-by-side comparison with human confirmation controls in Flutter.
- Decision:
  1. Service Architecture:
     - `OpenAiFishVisionService`: Dedicated OpenAI Vision provider grounded in the 42 Kiribati marine catalog records, returning strict structured JSON (`scientific_name`, `common_name`, `kiribati_name`, `confidence`, `visible_traits`, `reasoning`, `alternatives`, `review_status`, `ciguatera_risk`).
     - `FishIdentificationComparisonService`: Orchestrates concurrent execution of Muse Glimmer and OpenAI Vision with timing, isolated error boundaries, schema normalization, and catalog matching.
  2. Categorization & Matching:
     - Comparison statuses: `agreement` (case/whitespace-insensitive scientific name match), `disagreement` (divergent species suggestions), `single_provider_result` (one succeeded, one failed), `both_failed` (both failed/timeout), `insufficient_evidence` (both confidence scores < 0.50).
     - Database catalog preservation: Matches scientific names against `species` table. Unlisted species are preserved as candidate strings without inventing fake database rows.
     - Confidence separation: Confidences remain separate and are never averaged or combined.
  3. Database Persistence:
     - Migration `2026_11_03_000002_create_fish_identification_providers_table.php` and Eloquent model `FishIdentificationProvider` record per-provider evaluations, latency, raw JSON payloads, and comparison outcomes linked to catches.
  4. Endpoints:
     - `POST /api/ai/compare-providers` (dedicated comparison endpoint)
     - `POST /api/ai/identify-fish` (accepts `compare=true` flag returning dual providers alongside backward-compatible fields).
  5. Mobile Interface (Flutter):
     - `LogCatchScreen` renders dual provider cards (Meta Muse Glimmer and OpenAI Vision) with latency, separate confidence scores, morphological traits, and alternatives.
     - Comparison status banner: distinct color-coded indicators (Agreement [green], Disagreement [orange], Single Provider [blue], Both Failed [red], Insufficient Evidence [grey]).
     - Human Confirmation UX: On disagreement, auto-selection is prohibited and an explicit warning is displayed ("Models disagree — please verify with your own knowledge or select manually"). Interactive buttons allow the fisher to select "Use Meta Muse Glimmer", "Use OpenAI Vision", or choose manually from the catalog.
     - Mandatory advisory: "AI identification is advisory only. Ciguatera safety cannot be determined by AI. Human confirmation required."
- Consequences: Full multi-model evaluation capability; 104 backend PHPUnit tests pass (1234 assertions); 54 Flutter mobile tests pass with zero regressions; zero API key leakage; offline compatibility preserved.

## ADR-027 — User AI Vision Provider Selection (Meta Muse Glimmer / OpenAI Vision / Parallel Comparison) (accepted)
- Status: Accepted (P0-18, 2026-11-03).
- Context: The user requested allowing users (fishermen in Flutter mobile and fisheries officers in Next.js admin) to explicitly choose which AI model to run for fish recognition: Meta Muse Glimmer, OpenAI Vision, or Parallel Comparison of both.
- Decision:
  1. API Parameter Routing:
     - `POST /api/ai/identify-fish` accepts a `provider` or `selected_provider` parameter (`'openai'`, `'meta'`/`'muse_glimmer'`, `'compare'`/`'parallel'`, or default `'auto'`).
     - When `'openai'` is specified: executes `OpenAiFishVisionService` grounded in the Kiribati species catalog, normalizes the response via `FishIdentificationComparisonService::normalizeOpenAiResponse`, matches against the database catalog without inventing phantom records, and returns single provider identification.
     - When `'meta'`/`'muse_glimmer'` is specified: executes `MetaAiService` vision pipeline with SAM evidence and species hints, normalizes via `normalizeMuseResponse`, and returns single provider identification.
     - When `'compare'`/`'parallel'` is specified: delegates to `FishIdentificationComparisonService::compare()`, executing both providers concurrently and returning comparison metrics, dual provider results, and agreement status.
  2. Next.js Admin UI (`AiFishIdentifierModal.tsx`):
     - Interactive segmented control allowing selection between `⚖️ Compare Both`, `🔮 Meta Muse Glimmer`, and `⚡ OpenAI Vision (GPT-4o)`.
     - In comparison mode: displays agreement/disagreement banner, shared visual traits, side-by-side cards with latency and confidence badges, and 1-click "Use Suggestion" buttons.
     - In single mode: displays the chosen model badge and reasoning.
  3. Flutter Mobile UI (`LogCatchScreen.dart`):
     - Interactive segmented ChoiceChips (`⚖️ Both (Compare)`, `🔮 Meta Muse`, `⚡ OpenAI`) positioned prominently above specimen presets.
     - `_selectedAiProvider` state forwarded through `SyncService.identifyFishOnlineOrOffline` and `ApiClient.identifyFish`.
     - Strict conflict safety: On disagreement, auto-selection is prohibited and an explicit warning is displayed requiring human ground truth.
- Consequences: Gives fishers and administrators full agency over AI model selection while retaining parallel comparison capability and strict human safety confirmation. 106 PHPUnit tests pass; 54+ Flutter mobile tests pass; Next.js builds cleanly.

## ADR-028 — Resilient Offline Catalog Grounding for AI Vision Comparison (superseded)
- Status: Superseded by ADR-029 (2026-11-03).
- Context: In offline field usage at sea, containerized sandboxes, or development environments without outbound internet/DNS, cloud endpoints (`api.meta.ai`, `api.openai.com`) are unreachable. A fallback mechanism attempted to generate grounded comparison responses, but inadvertently fabricated 94% and 89% confidence scores and used CRC32 modulo hashing that incorrectly identified bonefish as *Te Kuau* (*Epinephelus fasciatus*).
- Superseded by: ADR-029.

## ADR-029 — Honest AI Provider Status, Confidence Provenance and Offline Fallback Integrity (accepted)
- Status: Accepted (P0-20, 2026-11-03).
- Context: A photograph of a bonefish was incorrectly identified by both Meta Muse Glimmer and OpenAI Vision as *Te Kuau* (Blacktip Grouper, *Epinephelus fasciatus*) with fabricated confidences (94% and 89%) and latencies (12ms and 18ms), while error messages reported both APIs were unreachable. This root-caused to:
  1. CRC32 modulo hashing in `FishIdentificationComparisonService` and `AiIdentificationController` forcing an arbitrary species index (index 3 was *Te Kuau*).
  2. Static hardcoded float confidence values (0.94 and 0.89) and clamped artificial latencies (`max(12, ...)` and `max(18, ...)`).
  3. Overwriting provider failure statuses with `'success'`, falsely declaring comparison `'agreement'`.
- Decision:
  1. Honest Provider Status Lifecycle:
     - Provider status is strictly one of: `success`, `unavailable`, `timeout`, `invalid_response`, `fallback`, `insufficient_evidence`.
     - When an API key is missing or endpoint is unreachable, provider status is `'unavailable'`.
     - When external APIs return network timeouts, provider status is `'timeout'`.
     - When external APIs return malformed JSON, provider status is `'invalid_response'`.
     - When both fail or are unconfigured, comparison status is `'both_failed'` (never `'agreement'`).
  2. Strict Confidence Provenance:
     - When external vision APIs fail or are unconfigured, provider `confidence` is strictly `null`.
     - Confidence displays as "Not available" (never a fabricated percentage).
     - Durations reflect genuine wall-clock HTTP execution without arbitrary artificial minimum clamps.
  3. Offline Catalogue Reference Integrity:
     - CRC32 modulo species selection is completely eliminated.
     - Offline catalogue never guesses species or pretends pre-written knowledge base descriptions were observed in the image.
     - If an observer provides a search hint, genuine database/knowledge base records are returned as `offline_catalogue` reference with status `'fallback'`, `confidence: null`, and `human_confirmation_required: true`.
     - If no hint is provided or no match exists, returns `identification: 'No reliable match'`, `suggested_species: null`, `status: 'insufficient_evidence'`, and `confidence: null`.
  4. User Interface Grounding:
     - Flutter UI (`LogCatchScreen`) and Next.js Admin display color-coded status badges: `UNAVAILABLE` (amber/orange), `TIMEOUT` (deep orange), `FAILED` (red), `SUCCESS` (green).
     - When both fail: displays "AI IDENTIFICATION UNAVAILABLE" banner explaining live AI is unreachable.
     - Renders dedicated `Offline Catalogue Reference` card clearly distinguished from live vision models.
- Consequences:
  - Eliminates false species identification and fabricated confidence scores entirely.
  - Complete 26-test suite in `FishIdentificationComparisonTest` passes, including 10 dedicated bonefish vs Te Kuau regression tests.
  - All 118 backend tests pass (1322 assertions).
  - Preserves offline-first architecture while upholding absolute scientific honesty and fisheries safety.

## ADR-030 — Meta Responses API Payload Structure, OpenAI Quota Handling and Honest Multi-Provider Comparison (accepted)
- Status: Accepted (P0-22, 2026-11-04).
- Context:
  1. Meta Model API (`https://api.meta.ai/v1/responses`) returned HTTP 400 with `input[0].content did not match any supported type` when sent OpenAI-style multi-modal chat payloads (`type: text`, `type: image_url`, `image_url: {url: ...}`).
  2. OpenAI Vision returned HTTP 429 with `insufficient_quota` / `You have no credits remaining`, causing continuous repeated retries and failed identification attempts.
  3. When one provider failed due to billing/quota or rate limiting, comparison normalization needed explicit mapping (`quota_exceeded`, `rate_limited`, `unauthorized`, `invalid_payload`) to prevent generic failures or fabricated fallbacks.
- Decision:
  1. **Meta Model API Responses Schema Alignment**:
     - Format payload according to verified Meta Responses API structure:
       `input: [{ type: 'message', role: 'user', content: [{ type: 'input_text', text: ... }, { type: 'input_image', image_url: <dataUrlOrString> }] }]`.
     - Direct string `image_url` (data URL `data:image/jpeg;base64,...` or direct reachable string) rather than nested object `{ url: ... }`.
     - Helper `toDataUrl()` encodes local filesystem/uploads images to base64 data URLs without outbound network calls during testing.
     - HTTP status mapping: 429 -> `rate_limited`, 401/403 -> `unauthorized`, 404 -> `model_unavailable`, 400 -> `invalid_payload`, 408/504 -> `timeout`.
  2. **OpenAI Quota & Error Handling**:
     - Detect HTTP 429 / 402 with `insufficient_quota` or "no credits remaining" and map to dedicated status `quota_exceeded`.
     - Cache quota exhaustion state in Redis/file cache for 10 minutes (`openai_quota_exceeded`) to immediately short-circuit repeated calls, saving latency and avoiding hammering external servers.
     - Distinguish authentication failures (HTTP 401 `unauthorized`) and standard rate limiting (HTTP 429 `rate_limited`) from quota exhaustion.
  3. **Multi-Provider Comparison Integrity**:
     - Normalization preserves `quota_exceeded`, `rate_limited`, `unauthorized`, `invalid_payload` explicitly.
     - If one provider fails (or exceeds quota) and the other succeeds, comparison returns `single_provider_result` with the failed provider's status and honest disclaimer; the failed provider is NEVER fabricated or replaced by catalogue guessing.
     - If both fail, comparison returns `both_failed` with confidence strictly `null`.
     - Human confirmation remains mandatory across all comparison states.


## ADR-031 — DNS Stall Fix: ResolvesApiEndpoints Applied to AiIdentificationController::resolveImageDataUrl() (accepted)
- Status: Accepted (P0-23, 2026-11-04).
- Context:
  - `AiIdentificationController::resolveImageDataUrl()` fetches external image URLs (e.g. Unsplash CDN) via `Http::timeout(10)->get($imagePath)` to convert them to base64 data URLs for SAM 3.1 and Meta vision ingestion.
  - The container's `/etc/resolv.conf` stub resolver (`127.0.0.53`) is unreachable when systemd is not running as PID 1 (Antigravity sandbox). This caused `getaddrinfo()` to hang 5–10s before returning `cURL error 28`, making external image fetches silently return `null` and causing SAM/Muse to skip image segmentation and fall back to offline results.
  - All backend AI services (`MetaAiService`, `SamSegmentationService`, `OpenAiFishVisionService`, `OpenAiVisionService`) had already been patched with `ResolvesApiEndpoints` and `getCurlOptions()`. `AiIdentificationController` was the last remaining call site without pinning.
- Decision:
  - Add `use ResolvesApiEndpoints;` trait to `AiIdentificationController` and import `App\Services\Concerns\ResolvesApiEndpoints`.
  - Replace `Http::timeout(10)->get($imagePath)` with `Http::withOptions($this->getCurlOptions($imagePath))->timeout(10)->get($imagePath)` in `resolveImageDataUrl()`.
  - `getCurlOptions()` resolves IP via `dns_get_record()` (PHP internal resolver, bypasses `getaddrinfo()`), pins result via `CURLOPT_RESOLVE`, and forces IPv4 (`CURLOPT_IPRESOLVE`).
  - For hosts without a static cache entry (e.g. image CDNs), the trait dynamically resolves and pins the IP.
- Consequences:
  - External image fetches resolve in <1s rather than timing out or hanging.
  - Prevents silent `null` returns that would bypass SAM segmentation and Muse Glimmer vision, forcing offline fallback incorrectly.
  - 128/128 backend tests pass (0 regressions). Live end-to-end test: Unsplash image resolved and encoded to data URL in 0.94s.
  - `ResolvesApiEndpoints` is now consistently applied to all outbound HTTP call sites in the application.









## ADR-032 — Muse Spark 1.3 Is the Vision Model; SAM Never Substitutes for Muse (accepted)
- Status: Accepted (P0-25, 2026-11-05).
- Context: Authenticated `GET /v1/models` listed muse-wonder-1.0 (text), sam-3.1 (segmentation) and muse-spark-1.3/1.2/1.1, but not `muse-glimmer`, which `.env` had configured for vision — so live vision calls targeted an unavailable model while mocked `muse-glimmer` tests stayed green. Separately, `FishIdentificationComparisonService::normalizeMuseResponse` rewrote SAM success as a Muse `success` with invented 0.94 confidence and `Actinopterygii`, and the dashboard labelled the Muse slot as SAM, producing `SAM unconfigured or unavailable: Meta Muse Glimmer identification failed.`
- Decision:
  1. Vision identification + description use `muse-spark-1.3` (`META_AI_VISION_MODEL`); `muse-wonder-1.0` stays text advisory, `sam-3.1` stays segmentation only.
  2. SAM evidence never becomes a Muse provider result: no species, no confidence, no `success` substitution. Muse failure stays visible with its own status; SAM renders only from `identification.segmentation`.
  3. Provider key `muse_glimmer` is retained for API/mobile compatibility; display names and model identifiers show the real configured model.
  4. OpenAI malformed JSON maps to `invalid_response` and HTTP 404 to `model_unavailable`, preserved through comparison normalization.
- Consequences: Honest per-provider status (both_failed / single_provider_result remain truthful); live vision still requires one user-approved `muse-spark-1.3` image probe before claiming live identification works.

## ADR-033 — Muse Vision Model Fallback on 404 Only (accepted)
- Status: Accepted (P0-26, 2026-11-05).
- Context: Live Railway calls returned `model_unavailable` ("The requested model was not found") for `muse-spark-1.3` on both `/v1/responses` and `/v1/chat/completions`, despite the model appearing in the authenticated account model list.
- Decision: `MetaAiService::sendVisionRequest` retries only on HTTP 404 (`model_unavailable`) through account-listed candidates in order: configured vision model, `muse-spark-1.3`, `muse-spark-1.2`, `muse-spark-1.1`, then the configured text model (`muse-wonder-1.0`) as a last image probe. No retry on 401/429/400/timeout. First non-404 result wins and is returned honestly.
- Consequences: A single dashboard run discovers the first vision-served Muse model without code redeploys per model; if every candidate 404s, the provider stays `model_unavailable` with no fabricated identification.
