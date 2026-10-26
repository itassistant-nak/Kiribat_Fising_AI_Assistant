<?php

namespace App\Http\Controllers;

use App\Models\CatchRecord;
use Illuminate\Http\Request;

class CatchController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canViewPreciseGps = $user && in_array($user->role, ['admin', 'reviewer']);

        $query = CatchRecord::with(['user', 'species', 'trip', 'verifiedBy']);

        // Filter by fisherman (id or name match)
        if ($request->filled('fisherman')) {
            $val = $request->query('fisherman');
            if (is_numeric($val)) {
                $query->where('user_id', $val);
            } else {
                $query->whereHas('user', function ($q) use ($val) {
                    $q->where('name', 'like', "%{$val}%")
                      ->orWhere('email', 'like', "%{$val}%");
                });
            }
        }

        // Filter by species ID
        if ($request->filled('species_id') && $request->query('species_id') !== 'all') {
            $query->where('species_id', $request->query('species_id'));
        }

        // Filter by verification status
        if ($request->filled('status') && $request->query('status') !== 'all') {
            $status = $request->query('status');
            if ($status === 'verified') {
                $query->whereIn('verification_status', ['verified', 'corrected']);
            } else {
                $query->where('verification_status', $status);
            }
        }

        // Filter by date
        if ($request->filled('date')) {
            $query->whereDate('caught_at', $request->query('date'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('caught_at', '>=', $request->query('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('caught_at', '<=', $request->query('end_date'));
        }

        // Search query
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('ai_species_suggestion', 'like', "%{$search}%")
                  ->orWhere('verification_notes', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('species', function ($sq) use ($search) {
                      $sq->where('kiribati_name', 'like', "%{$search}%")
                         ->orWhere('english_name', 'like', "%{$search}%")
                         ->orWhere('scientific_name', 'like', "%{$search}%");
                  });
            });
        }

        $catches = $query->orderBy('caught_at', 'desc')->get();

        // Map through and apply GPS privacy protection
        $data = $catches->map(function ($catch) use ($canViewPreciseGps) {
            $item = $catch->toArray();
            if (!$canViewPreciseGps) {
                $item['latitude'] = null;
                $item['longitude'] = null;
                $item['gps_protected'] = true;
            } else {
                $item['gps_protected'] = false;
            }
            return $item;
        });

        return response()->json([
            'data' => $data,
            'count' => $data->count(),
            'can_view_precise_gps' => $canViewPreciseGps,
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $canViewPreciseGps = $user && in_array($user->role, ['admin', 'reviewer']);

        $catch = CatchRecord::with(['user', 'species', 'trip.routePoints', 'verifiedBy'])->findOrFail($id);

        $item = $catch->toArray();
        if (!$canViewPreciseGps) {
            $item['latitude'] = null;
            $item['longitude'] = null;
            $item['gps_protected'] = true;
        } else {
            $item['gps_protected'] = false;
        }

        return response()->json([
            'data' => $item,
            'can_view_precise_gps' => $canViewPreciseGps,
        ]);
    }
}
