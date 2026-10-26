<?php

namespace App\Http\Controllers;

use App\Models\FishingTrip;
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
