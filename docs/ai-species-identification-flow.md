# AI Fish Species Identification — Flow

How the Kiribati AI Fisheries Assistant identifies a fish species from a
photograph, from capture to human-verified ground truth.

> Honest scope note (per ADR-009): the current "vision" step is a
> **server-side morphological knowledge-base matcher**, not a live image-model
> inference call. `muse-wonder-1.0` is text-only and contributes advisory notes.
> Photographs **cannot** detect ciguatoxins — every response carries that
> disclaimer, and every result requires human confirmation (ADR-002).

## Flowchart

```mermaid
flowchart TD
    subgraph CAPTURE["1 — Photo capture (3 entry points)"]
        A1["Admin: AiFishIdentifierModal<br/>file upload / URL / 4 specimen presets"]
        A2["Officer: CorrectionModal<br/>replace catch photo (preset / file / URL)"]
        A3["Officer: EditSpeciesModal<br/>change catalog specimen photo"]
        A4["Fisher (Flutter, offline-first):<br/>photo + GPS saved to SQLite local_catches<br/>status = pending — NO local AI"]
    end

    subgraph API["2 — POST /api/ai/identify-fish (auth:sanctum)"]
        B["Normalize input:<br/>file → public/uploads/catches/<br/>image_url / base64 data URL passthrough"]
        B0{"Image provided?"}
    end

    subgraph MATCH["3 — Knowledge-base matching cascade<br/>(AiIdentificationController::identify)"]
        C1{"hint matches<br/>knowledge base?"}
        C2{"hint matches<br/>species DB?"}
        C3{"photo URL matches<br/>known specimen?"}
        C4{"photo URL matches<br/>species DB?"}
        C5["Deterministic fallback:<br/>crc32(image) % 9 species"]
    end

    subgraph SCORE["4 — Score & enrich"]
        D["confidence = range low + 75% of span<br/>candidates = 3 others @ 0.72x confidence<br/>species_id lookup in DB"]
        E{"MODEL_API_KEY<br/>configured?"}
        F["MetaAiService → muse-wonder-1.0<br/>text prompt: biology advisory notes<br/>failure → null, never blocks"]
        G["meta advisory_notes = null<br/>(offline / unconfigured fallback)"]
    end

    subgraph RESPOND["5 — Response"]
        H["JSON: kiribati/english/scientific names,<br/>confidence, visual_features,<br/>ciguatera_risk + warning, candidates,<br/>meta advisory_notes<br/>+ disclaimer: photos cannot detect toxins"]
    end

    subgraph HUMAN["6 — Human-in-the-loop (mandatory, ADR-002)"]
        I["UI pre-fills verification form<br/>with AI suggestion"]
        J{"Officer decision?"}
        J1["Approve AI"]
        J2["Correct species manually"]
        J3["Flag submission"]
        J4["Not a Catch<br/>(auto-set if admin changed photo)"]
    end

    subgraph STORE["7 — Dual-store persistence"]
        K["POST /api/catches:<br/>immutable ai_species_suggestion + ai_confidence<br/>+ verified_species_id / status / notes / reviewer"]
        L["POST / PUT /api/species:<br/>photo_url + visual_features → catalog<br/>knowledge-base sync"]
        M["VerificationController audit:<br/>ai_metadata keeps original_photo_url,<br/>corrected photo, prior AI suggestion"]
    end

    A1 --> B
    A2 --> B
    A3 --> B
    A4 -->|"SyncService uploads on reconnect"| B

    B --> B0
    B0 -- "no → 422 MISSING_IMAGE" --> Z(["Return error"])
    B0 -- "yes" --> C1
    C1 -- "yes" --> D
    C1 -- "no" --> C2
    C2 -- "yes" --> D
    C2 -- "no" --> C3
    C3 -- "yes" --> D
    C3 -- "no" --> C4
    C4 -- "yes" --> D
    C4 -- "no" --> C5 --> D

    D --> E
    E -- "yes" --> F --> H
    E -- "no" --> G --> H

    H --> I --> J
    J --> J1 --> K
    J --> J2 --> K
    J --> J3 --> K
    J --> J4 --> K
    K --> L
    K --> M
```

## Step notes (code references)

| Step | What happens | Code |
|---|---|---|
| 1 | Admin uploads photo / picks preset with hint; fisher app queues offline with `sync_status='pending'` | `frontend/src/components/AiFishIdentifierModal.tsx`, `mobile/lib/services/sync/sync_service.dart` |
| 2 | Multipart file saved to `public/uploads/catches/`; URL/base64 passed through; 422 if empty | `backend/app/Http/Controllers/AiIdentificationController.php` → `identify()` |
| 3 | 9-species Kiribati knowledge base (`Te Bwere`, `Te Ingimea`, …) matched by hint → DB hint → photo URL → DB photo → `crc32` hash | `AiIdentificationController::$speciesKnowledgeBase` |
| 4 | Confidence from species `confidence_range`; Meta text model adds notes only when key configured | `backend/app/Services/MetaAiService.php` → `queryText()` |
| 5 | Response always includes toxin disclaimer | `identify()` return payload |
| 6–7 | Officer must Approve / Correct / Flag / Not-a-Catch with notes; original AI suggestion preserved immutably; admin-changed photo ⇒ `is_catch=false` | `backend/app/Http/Controllers/VerificationController.php`, `CorrectionModal.tsx` |

## Related endpoints

- `POST /api/ai/identify-fish` — identification (any authenticated user)
- `GET/POST /api/ai/meta-health` — Meta API probe, admin/reviewer only, key never exposed
- `POST /api/catches`, `POST /api/species`, `PUT /api/species/{id}` — persistence
- `POST /api/admin/catches/{id}/verify` — verification (admin/reviewer only)
