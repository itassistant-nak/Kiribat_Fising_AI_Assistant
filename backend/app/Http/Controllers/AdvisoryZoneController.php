<?php

namespace App\Http\Controllers;

use App\Models\AdvisoryZone;
use App\Models\AdvisoryAuditLog;
use App\Models\Species;
use App\Services\GeoJsonValidator;
use App\Services\PointInPolygonService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdvisoryZoneController extends Controller
{
    protected GeoJsonValidator $geoJsonValidator;
    protected PointInPolygonService $pointInPolygonService;

    public function __construct(
        GeoJsonValidator $geoJsonValidator,
        PointInPolygonService $pointInPolygonService
    ) {
        $this->geoJsonValidator = $geoJsonValidator;
        $this->pointInPolygonService = $pointInPolygonService;
    }

    /**
     * List advisory zones.
     * Fishers and unprivileged users only see published zones.
     * Administrators and reviewers can filter across all statuses.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isStaff = $user && $user->canEditAdvisory();

        $query = AdvisoryZone::with(['species', 'creator:id,name,role', 'approver:id,name,role']);

        if (!$isStaff) {
            // Fishers only receive published advisories
            $query->published();
        } else {
            // Staff filters
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            if ($request->has('classification') && $request->classification !== 'all') {
                $query->where('classification', $request->classification);
            }
        }

        // Filter by affected species if provided
        if ($request->filled('species_id') && $request->species_id !== 'all') {
            $speciesId = (int)$request->species_id;
            $query->whereHas('species', function ($q) use ($speciesId) {
                $q->where('species.id', $speciesId);
            });
        }

        // Search term in title, concern, description, or evidence
        if ($request->filled('search')) {
            $search = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhere('concern', 'like', $search)
                  ->orWhere('description', 'like', $search)
                  ->orWhere('evidence', 'like', $search);
            });
        }

        $zones = $query->orderBy('updated_at', 'desc')->get();

        return response()->json([
            'data' => $zones,
            'count' => $zones->count(),
            'is_staff' => $isStaff,
            'safety_disclaimer' => 'AI cannot detect fish toxins from photographs. Advisories require scientific and authority evidence.',
        ]);
    }

    /**
     * Retrieve a specific advisory zone.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isStaff = $user && $user->canEditAdvisory();

        $zone = AdvisoryZone::with(['species', 'creator:id,name,role', 'approver:id,name,role'])->find($id);

        if (!$zone) {
            return response()->json(['error' => 'Advisory zone not found.'], 404);
        }

        if (!$isStaff && $zone->status !== 'published') {
            return response()->json(['error' => 'Access denied to unpublished advisory zone.'], 403);
        }

        if ($isStaff) {
            $zone->load(['auditLogs.user:id,name,role']);
        }

        return response()->json([
            'data' => $zone,
        ]);
    }

    /**
     * Create a new advisory zone.
     * Authorized editors (reviewers and administrators).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->canEditAdvisory()) {
            return response()->json([
                'error' => 'Unauthorized. Administrator or Reviewer role required to create advisory zones.',
                'code' => 'FORBIDDEN_EDITOR'
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'concern' => 'required|string|max:255',
            'evidence' => 'nullable|string',
            'classification' => 'required|in:suspected,under_investigation,authority-confirmed',
            'status' => 'nullable|in:draft,under_investigation,approved,published',
            'source' => 'nullable|string|max:255',
            'review_date' => 'nullable|date',
            'polygon_geojson' => 'required',
            'species_ids' => 'nullable|array',
            'species_ids.*' => 'exists:species,id',
        ]);

        // Validate GeoJSON Polygon strictly
        $geoCheck = $this->geoJsonValidator->validatePolygon($validated['polygon_geojson']);
        if (!$geoCheck['valid']) {
            return response()->json([
                'error' => 'Invalid GeoJSON polygon.',
                'message' => $geoCheck['error'],
                'code' => 'INVALID_GEOJSON'
            ], 422);
        }

        // Enforce publication workflow permissions: only admin can create with 'published' status
        $status = $validated['status'] ?? 'draft';
        if ($status === 'published' && !$user->canPublishAdvisory()) {
            return response()->json([
                'error' => 'Unauthorized. Only administrators can publish advisory zones.',
                'code' => 'ADMIN_APPROVAL_REQUIRED'
            ], 403);
        }

        $zone = DB::transaction(function () use ($validated, $geoCheck, $user, $status, $request) {
            $advisory = AdvisoryZone::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'concern' => $validated['concern'],
                'evidence' => $validated['evidence'] ?? null,
                'classification' => $validated['classification'],
                'status' => $status,
                'polygon_geojson' => $geoCheck['normalized'],
                'source' => $validated['source'] ?? 'Ministry of Fisheries & Marine Resources Development (MFMRD)',
                'review_date' => $validated['review_date'] ?? null,
                'created_by_user_id' => $user->id,
                'approved_by_user_id' => $status === 'published' ? $user->id : null,
                'approved_at' => $status === 'published' ? now() : null,
                'published_at' => $status === 'published' ? now() : null,
            ]);

            // Attach multiple species
            if (!empty($validated['species_ids'])) {
                $speciesSyncData = [];
                foreach ($validated['species_ids'] as $spId) {
                    $speciesSyncData[$spId] = ['risk_level' => 'high'];
                }
                $advisory->species()->sync($speciesSyncData);
            }

            // Write initial audit log
            AdvisoryAuditLog::create([
                'advisory_zone_id' => $advisory->id,
                'user_id' => $user->id,
                'action' => 'created',
                'changes' => [
                    'title' => $advisory->title,
                    'status' => $advisory->status,
                    'classification' => $advisory->classification,
                    'concern' => $advisory->concern,
                    'species_count' => count($validated['species_ids'] ?? []),
                ],
                'notes' => "Initial advisory zone created by {$user->name} ({$user->role})",
                'ip_address' => $request->ip(),
            ]);

            return $advisory;
        });

        $zone->load(['species', 'creator:id,name,role', 'approver:id,name,role']);

        return response()->json([
            'message' => 'Advisory zone created successfully.',
            'data' => $zone,
        ], 201);
    }

    /**
     * Update an advisory zone.
     * Authorized editors (reviewers and administrators).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->canEditAdvisory()) {
            return response()->json([
                'error' => 'Unauthorized. Administrator or Reviewer role required to edit advisory zones.',
                'code' => 'FORBIDDEN_EDITOR'
            ], 403);
        }

        $zone = AdvisoryZone::find($id);
        if (!$zone) {
            return response()->json(['error' => 'Advisory zone not found.'], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'concern' => 'sometimes|required|string|max:255',
            'evidence' => 'nullable|string',
            'classification' => 'sometimes|required|in:suspected,under_investigation,authority-confirmed',
            'status' => 'sometimes|required|in:draft,under_investigation,approved,published,archived',
            'source' => 'nullable|string|max:255',
            'review_date' => 'nullable|date',
            'polygon_geojson' => 'sometimes|required',
            'species_ids' => 'nullable|array',
            'species_ids.*' => 'exists:species,id',
        ]);

        // Enforce publication workflow permissions on update
        if (isset($validated['status']) && $validated['status'] === 'published' && $zone->status !== 'published') {
            if (!$user->canPublishAdvisory()) {
                return response()->json([
                    'error' => 'Unauthorized. Only administrators can publish advisory zones.',
                    'code' => 'ADMIN_APPROVAL_REQUIRED'
                ], 403);
            }
        }

        $normalizedPolygon = null;
        if (isset($validated['polygon_geojson'])) {
            $geoCheck = $this->geoJsonValidator->validatePolygon($validated['polygon_geojson']);
            if (!$geoCheck['valid']) {
                return response()->json([
                    'error' => 'Invalid GeoJSON polygon.',
                    'message' => $geoCheck['error'],
                    'code' => 'INVALID_GEOJSON'
                ], 422);
            }
            $normalizedPolygon = $geoCheck['normalized'];
        }

        DB::transaction(function () use ($zone, $validated, $normalizedPolygon, $user, $request) {
            $original = $zone->toArray();

            $updateData = [];
            foreach (['title', 'description', 'concern', 'evidence', 'classification', 'source', 'review_date'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updateData[$field] = $validated[$field];
                }
            }

            if (isset($validated['status'])) {
                $updateData['status'] = $validated['status'];
                if ($validated['status'] === 'published' && $zone->status !== 'published') {
                    $updateData['approved_by_user_id'] = $user->id;
                    $updateData['approved_at'] = now();
                    $updateData['published_at'] = now();
                }
            }

            if ($normalizedPolygon) {
                $updateData['polygon_geojson'] = $normalizedPolygon;
            }

            $updateData['updated_by_user_id'] = $user->id;
            $zone->update($updateData);

            // Sync species if provided
            if (array_key_exists('species_ids', $validated)) {
                $speciesSyncData = [];
                if (!empty($validated['species_ids'])) {
                    foreach ($validated['species_ids'] as $spId) {
                        $speciesSyncData[$spId] = ['risk_level' => 'high'];
                    }
                }
                $zone->species()->sync($speciesSyncData);
            }

            // Record changes in audit log
            $changes = [];
            foreach ($updateData as $key => $val) {
                if ($key !== 'updated_by_user_id' && ($original[$key] ?? null) != $val) {
                    $changes[$key] = [
                        'old' => $original[$key] ?? null,
                        'new' => $val,
                    ];
                }
            }

            AdvisoryAuditLog::create([
                'advisory_zone_id' => $zone->id,
                'user_id' => $user->id,
                'action' => 'updated',
                'changes' => $changes,
                'notes' => "Updated by {$user->name} ({$user->role})",
                'ip_address' => $request->ip(),
            ]);
        });

        $zone->load(['species', 'creator:id,name,role', 'updater:id,name,role', 'approver:id,name,role']);

        return response()->json([
            'message' => 'Advisory zone updated successfully.',
            'data' => $zone,
        ]);
    }

    /**
     * Publish an advisory zone.
     * Authorized approver (Administrator ONLY).
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->canPublishAdvisory()) {
            return response()->json([
                'error' => 'Unauthorized. Only administrators can approve and publish advisory zones.',
                'code' => 'ADMIN_APPROVAL_REQUIRED'
            ], 403);
        }

        $zone = AdvisoryZone::find($id);
        if (!$zone) {
            return response()->json(['error' => 'Advisory zone not found.'], 404);
        }

        $zone->update([
            'status' => 'published',
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
            'published_at' => now(),
            'updated_by_user_id' => $user->id,
        ]);

        AdvisoryAuditLog::create([
            'advisory_zone_id' => $zone->id,
            'user_id' => $user->id,
            'action' => 'published',
            'changes' => [
                'status' => ['old' => 'draft', 'new' => 'published'],
                'approved_by' => $user->name,
            ],
            'notes' => "Advisory formally approved and published as an active warning by Administrator {$user->name}",
            'ip_address' => $request->ip(),
        ]);

        $zone->load(['species', 'creator:id,name,role', 'approver:id,name,role']);

        return response()->json([
            'message' => 'Advisory zone published successfully.',
            'data' => $zone,
        ]);
    }

    /**
     * Unpublish an advisory zone.
     * Reverts active warning to draft/archived status.
     * Authorized approver (Administrator ONLY).
     */
    public function unpublish(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->canPublishAdvisory()) {
            return response()->json([
                'error' => 'Unauthorized. Only administrators can unpublish advisory zones.',
                'code' => 'ADMIN_APPROVAL_REQUIRED'
            ], 403);
        }

        $zone = AdvisoryZone::find($id);
        if (!$zone) {
            return response()->json(['error' => 'Advisory zone not found.'], 404);
        }

        $previousStatus = $zone->status;
        $zone->update([
            'status' => 'draft',
            'updated_by_user_id' => $user->id,
        ]);

        AdvisoryAuditLog::create([
            'advisory_zone_id' => $zone->id,
            'user_id' => $user->id,
            'action' => 'unpublished',
            'changes' => [
                'status' => ['old' => $previousStatus, 'new' => 'draft'],
            ],
            'notes' => "Advisory withdrawn from active publication by Administrator {$user->name}",
            'ip_address' => $request->ip(),
        ]);

        $zone->load(['species', 'creator:id,name,role', 'approver:id,name,role']);

        return response()->json([
            'message' => 'Advisory zone unpublished and reverted to draft.',
            'data' => $zone,
        ]);
    }

    /**
     * Delete an advisory zone.
     * Administrator only.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return response()->json([
                'error' => 'Unauthorized. Administrator role required to delete advisory zones.',
                'code' => 'ADMIN_REQUIRED'
            ], 403);
        }

        $zone = AdvisoryZone::find($id);
        if (!$zone) {
            return response()->json(['error' => 'Advisory zone not found.'], 404);
        }

        $zoneTitle = $zone->title;
        $zone->delete();

        return response()->json([
            'message' => "Advisory zone '{$zoneTitle}' deleted successfully.",
        ]);
    }

    /**
     * Check if a GPS coordinate falls inside any active published advisory zones.
     * Returns matching zones and affected species.
     */
    public function checkPoint(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $lat = (float)$validated['latitude'];
        $lng = (float)$validated['longitude'];

        // Only published advisories trigger active warnings
        $publishedZones = AdvisoryZone::published()
            ->with(['species:id,kiribati_name,english_name,scientific_name,ciguatera_risk'])
            ->get();

        $matchingZones = [];
        $affectedSpeciesMap = [];

        foreach ($publishedZones as $zone) {
            $isInside = $this->pointInPolygonService->containsPoint($lat, $lng, $zone->polygon_geojson);
            if ($isInside) {
                $matchingZones[] = [
                    'id' => $zone->id,
                    'title' => $zone->title,
                    'concern' => $zone->concern,
                    'evidence' => $zone->evidence,
                    'classification' => $zone->classification,
                    'source' => $zone->source,
                    'review_date' => $zone->review_date,
                    'species' => $zone->species,
                ];

                foreach ($zone->species as $sp) {
                    $affectedSpeciesMap[$sp->id] = $sp;
                }
            }
        }

        $isInside = count($matchingZones) > 0;

        return response()->json([
            'inside_advisory_zone' => $isInside,
            'latitude' => $lat,
            'longitude' => $lng,
            'zones_count' => count($matchingZones),
            'matching_zones' => $matchingZones,
            'affected_species' => array_values($affectedSpeciesMap),
            'safety_notice' => 'AI photographs cannot detect ciguatoxins or marine poisons. Never rely on visual identification for toxicity.',
        ]);
    }

    /**
     * Retrieve audit logs for a specific advisory zone.
     * Staff only (admins and reviewers).
     */
    public function auditLogs(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->canEditAdvisory()) {
            return response()->json([
                'error' => 'Unauthorized. Staff role required to view audit logs.',
                'code' => 'ROLE_FORBIDDEN'
            ], 403);
        }

        $zone = AdvisoryZone::find($id);
        if (!$zone) {
            return response()->json(['error' => 'Advisory zone not found.'], 404);
        }

        $logs = AdvisoryAuditLog::with('user:id,name,role,email')
            ->where('advisory_zone_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'advisory_zone_id' => $id,
            'zone_title' => $zone->title,
            'audit_logs' => $logs,
            'count' => $logs->count(),
        ]);
    }
}
