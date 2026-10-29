<?php

namespace App\Http\Controllers;

use App\Models\FishingTrip;
use App\Models\TripRoutePoint;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canViewAllGps = $user && in_array($user->role, ['admin', 'reviewer']);

        $query = FishingTrip::with(['user'])->withCount(['routePoints', 'catches']);

        // Fishers can only see their own trips unless admin
        if (!$canViewAllGps) {
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated.'], 401);
            }
            $query->where('user_id', $user->id);
        }

        $trips = $query->orderBy('started_at', 'desc')->get();

        return response()->json([
            'data' => $trips,
            'count' => $trips->count(),
            'can_view_all_gps' => $canViewAllGps,
        ]);
    }

    /**
     * Start a new fishing trip (mobile sync target).
     * POST /api/trips
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'vessel_name' => 'nullable|string|max:255',
            'started_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        $trip = FishingTrip::create([
            'user_id' => $request->user()->id,
            'vessel_name' => $validated['vessel_name'] ?? null,
            'started_at' => $validated['started_at'] ?? now(),
            'status' => 'in_progress',
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Fishing trip started.',
            'data' => $trip->fresh(),
        ], 201);
    }

    /**
     * End a fishing trip.
     * PATCH /api/trips/{id}
     */
    public function update(Request $request, $id)
    {
        $trip = FishingTrip::findOrFail($id);
        $this->authorizeTripAccess($request, $trip);

        $validated = $request->validate([
            'vessel_name' => 'nullable|string|max:255',
            'ended_at' => 'nullable|date',
            'status' => 'nullable|in:in_progress,completed',
            'notes' => 'nullable|string|max:2000',
        ]);

        $trip->update(array_filter($validated, fn ($v) => $v !== null));

        return response()->json([
            'message' => 'Fishing trip updated.',
            'data' => $trip->fresh(),
        ]);
    }

    /**
     * Batch-upload offline breadcrumb route points.
     * Idempotent on (trip_id, sequence): safe to retry after timeouts.
     * POST /api/trips/{id}/route-points
     */
    public function appendRoutePoints(Request $request, $id)
    {
        $trip = FishingTrip::findOrFail($id);
        $this->authorizeTripAccess($request, $trip);

        $validated = $request->validate([
            'points' => 'required|array|min:1|max:2000',
            'points.*.latitude' => 'required|numeric|between:-90,90',
            'points.*.longitude' => 'required|numeric|between:-180,180',
            'points.*.sequence' => 'required|integer|min:0',
            'points.*.recorded_at' => 'required|date',
        ]);

        $synced = 0;
        foreach ($validated['points'] as $point) {
            TripRoutePoint::updateOrCreate(
                ['trip_id' => $trip->id, 'sequence' => $point['sequence']],
                [
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                    'recorded_at' => $point['recorded_at'],
                ]
            );
            $synced++;
        }

        return response()->json([
            'message' => 'Route points synced.',
            'trip_id' => $trip->id,
            'synced_points' => $synced,
            'total_points' => $trip->routePoints()->count(),
        ], 201);
    }

    /**
     * GPS privacy: only the owning fisher (or admin/reviewer) may write a trip.
     */
    protected function authorizeTripAccess(Request $request, FishingTrip $trip): void
    {
        $user = $request->user();
        $canManageAll = $user && in_array($user->role, ['admin', 'reviewer']);

        if (!$canManageAll && (!$user || $user->id !== $trip->user_id)) {
            abort(response()->json([
                'error' => 'Unauthorized. Fishing trip routes are confidential and protected.',
                'code' => 'GPS_PROTECTED',
            ], 403));
        }
    }

    public function route(Request $request, $id)
    {
        $user = $request->user();
        $canViewAllGps = $user && in_array($user->role, ['admin', 'reviewer']);

        $trip = FishingTrip::with(['user', 'catches.species'])->findOrFail($id);

        if (!$canViewAllGps && (!$user || $user->id !== $trip->user_id)) {
            return response()->json([
                'error' => 'Unauthorized. Fishing trip routes are confidential and protected.',
                'code' => 'GPS_PROTECTED'
            ], 403);
        }

        $routePoints = $trip->routePoints()->orderBy('sequence', 'asc')->get();

        // Also fetch any catches made on this trip
        $catches = $trip->catches->map(function ($catch) use ($canViewAllGps) {
            return $catch->toArrayProtected($canViewAllGps);
        });

        return response()->json([
            'trip' => [
                'id' => $trip->id,
                'vessel_name' => $trip->vessel_name,
                'fisherman' => $trip->user->name,
                'started_at' => $trip->started_at,
                'ended_at' => $trip->ended_at,
                'status' => $trip->status,
                'notes' => $trip->notes,
            ],
            'route_points' => $routePoints->map(function ($pt) {
                return [
                    'id' => $pt->id,
                    'latitude' => (float)$pt->latitude,
                    'longitude' => (float)$pt->longitude,
                    'sequence' => $pt->sequence,
                    'recorded_at' => $pt->recorded_at,
                ];
            }),
            'catches' => $catches,
        ]);
    }
}
