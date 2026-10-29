<?php

namespace App\Http\Controllers;

use App\Models\CatchRecord;
use App\Models\Species;
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

        // Filter by verification status or non-catch classification
        if ($request->filled('status') && $request->query('status') !== 'all') {
            $status = $request->query('status');
            if ($status === 'verified') {
                $query->whereIn('verification_status', ['verified', 'corrected'])->where('is_catch', true);
            } elseif ($status === 'ai_identified') {
                $query->whereNotNull('ai_species_suggestion')->where('ai_confidence', '>', 0);
            } elseif ($status === 'not_a_catch') {
                $query->where(function ($q) {
                    $q->where('verification_status', 'not_a_catch')->orWhere('is_catch', false);
                });
            } elseif ($status === 'only_fishermen') {
                $query->where('is_catch', true)
                      ->where('verification_status', '!=', 'not_a_catch')
                      ->whereHas('user', function ($uq) {
                          $uq->where('role', 'fisher');
                      });
            } else {
                $query->where('verification_status', $status);
            }
        }

        // Dedicated only_fishermen flag
        if ($request->boolean('only_fishermen')) {
            $query->where('is_catch', true)
                  ->where('verification_status', '!=', 'not_a_catch')
                  ->whereHas('user', function ($uq) {
                      $uq->where('role', 'fisher');
                  });
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
            $item['is_catch'] = (bool) $catch->is_catch;
            $item['is_fisher_catch'] = ($catch->user && $catch->user->role === 'fisher') && (bool)$catch->is_catch && $catch->verification_status !== 'not_a_catch';
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

    /**
     * Submit new fish catch record / AI training specimen
     * POST /api/catches
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'client_uuid' => 'nullable|string|max:64',
            'species_id' => 'nullable|exists:species,id',
            'kiribati_name' => 'nullable|string|max:255',
            'english_name' => 'nullable|string|max:255',
            'scientific_name' => 'nullable|string|max:255',
            'species_name_raw' => 'nullable|string|max:255',
            'photo' => 'nullable|image|max:10240',
            'photo_url' => 'nullable|string',
            'ai_species_suggestion' => 'nullable|string|max:255',
            'ai_confidence' => 'nullable|numeric|min:0|max:1',
            // Actual provider payloads (e.g. SAM 3.1 raw output), verbatim evidence
            'ai_metadata' => 'nullable|array',
            'weight_kg' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:1',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'captured_at' => 'nullable|date',
            'caught_at' => 'nullable|date',
            'verification_notes' => 'nullable|string',
            'verification_status' => 'nullable|in:pending,verified,corrected,flagged',
        ]);

        // Idempotent replay (P0-10): a retried offline upload carries the
        // same client_uuid. Return the original row before touching the
        // uploaded file, so retries create neither rows nor orphan files.
        $clientUuid = $request->input('client_uuid');
        if ($clientUuid) {
            $existing = CatchRecord::where('client_uuid', $clientUuid)->first();
            if ($existing) {
                if ($existing->user_id !== $user->id) {
                    return response()->json([
                        'message' => 'client_uuid is already used by another fisher.',
                        'error' => 'CLIENT_UUID_CONFLICT',
                    ], 409);
                }
                $existing->load(['user', 'species', 'verifiedBy']);

                return response()->json([
                    'message' => 'Catch already recorded (idempotent replay).',
                    'catch' => $existing,
                    'idempotent_replay' => true,
                ], 200);
            }
        }

        $photoUrl = $request->input('photo_url');

        // Handle uploaded file if present
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'catch_' . time() . '_' . \Illuminate\Support\Str::random(8) . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/catches'), $filename);
            $photoUrl = url('uploads/catches/' . $filename);
        }

        // Determine species_id
        $speciesId = $request->input('species_id');
        if (!$speciesId && $request->filled('kiribati_name')) {
            $species = Species::firstOrCreate(
                ['kiribati_name' => $request->kiribati_name],
                [
                    'english_name' => $request->english_name ?? $request->kiribati_name,
                    'scientific_name' => $request->scientific_name ?? $request->english_name ?? $request->kiribati_name,
                    'photo_url' => $photoUrl,
                ]
            );
            $speciesId = $species->id;
        }

        // If submitted by admin/reviewer, auto-verify if requested
        $isAdminOrReviewer = in_array($user->role, ['admin', 'reviewer']);
        $verificationStatus = $request->input('verification_status', $isAdminOrReviewer ? 'verified' : 'pending');

        $suggestion = $request->input('ai_species_suggestion');
        if (!$suggestion && $request->filled('kiribati_name')) {
            $suggestion = $request->input('english_name')
                ? $request->input('kiribati_name') . ' (' . $request->input('english_name') . ')'
                : $request->input('kiribati_name');
        }
        $suggestion = $suggestion ?: $request->input('species_name_raw');

        try {
            $catch = CatchRecord::create([
                'user_id' => $user->id,
                'client_uuid' => $clientUuid,
                'species_id' => $speciesId,
                'ai_species_suggestion' => $suggestion,
                'ai_confidence' => $request->input('ai_confidence', 0.95),
                'ai_metadata' => $request->input('ai_metadata'),
                'quantity' => $request->input('quantity', 1),
                'weight_kg' => $request->input('weight_kg', 2.50),
                // Offline captures keep their on-device time; never
                // fabricate a GPS fix — missing coordinates stay null.
                'caught_at' => $request->input('caught_at') ?? $request->input('captured_at') ?? now(),
                'photo_url' => $photoUrl,
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'verification_status' => $verificationStatus,
                'verified_by_user_id' => ($verificationStatus === 'verified' && $isAdminOrReviewer) ? $user->id : null,
                'verified_at' => ($verificationStatus === 'verified') ? now() : null,
                'verification_notes' => $request->input('verification_notes', 'Identified & verified by ' . $user->name),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique client_uuid race: converge on the winning row.
            if ($clientUuid && ($winner = CatchRecord::where('client_uuid', $clientUuid)->first())) {
                if ($winner->user_id !== $user->id) {
                    return response()->json([
                        'message' => 'client_uuid is already used by another fisher.',
                        'error' => 'CLIENT_UUID_CONFLICT',
                    ], 409);
                }
                $winner->load(['user', 'species', 'verifiedBy']);

                return response()->json([
                    'message' => 'Catch already recorded (idempotent replay).',
                    'catch' => $winner,
                    'idempotent_replay' => true,
                ], 200);
            }
            throw $e;
        }

        $catch->load(['user', 'species', 'verifiedBy']);

        return response()->json([
            'message' => 'Fish catch specimen recorded and verified successfully.',
            'catch' => $catch,
        ], 201);
    }
}
