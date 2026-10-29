<?php

namespace App\Http\Controllers;

use App\Models\CatchRecord;
use App\Models\Species;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index(Request $request)
    {
        // Rule: Only the catch from fishermen counts towards official catch metrics
        $fisherCatches = CatchRecord::where('is_catch', true)
            ->where('verification_status', '!=', 'not_a_catch')
            ->whereHas('user', function ($q) {
                $q->where('role', 'fisher');
            });

        $totalCatches = (clone $fisherCatches)->count();
        $totalWeightKg = (float) (clone $fisherCatches)->sum('weight_kg');
        $totalQuantity = (int) (clone $fisherCatches)->sum('quantity');

        $pendingCount = (clone $fisherCatches)->where('verification_status', 'pending')->count();
        $verifiedCount = (clone $fisherCatches)->whereIn('verification_status', ['verified', 'corrected'])->count();
        $flaggedCount = CatchRecord::where('verification_status', 'flagged')->count();
        $notACatchCount = CatchRecord::where(function ($q) {
            $q->where('is_catch', false)->orWhere('verification_status', 'not_a_catch');
        })->count();

        $monitoredSpeciesCount = Species::count();
        $activeFishersCount = User::where('role', 'fisher')->count();

        // Species distribution — only genuine catches from fishermen
        $speciesBreakdown = (clone $fisherCatches)->with('species')
            ->select('species_id', DB::raw('count(*) as count'), DB::raw('sum(weight_kg) as total_weight'))
            ->whereNotNull('species_id')
            ->groupBy('species_id')
            ->orderByDesc('count')
            ->take(5)
            ->get()
            ->map(function ($item) {
                return [
                    'species_id' => $item->species_id,
                    'kiribati_name' => $item->species?->kiribati_name ?? 'Unknown',
                    'english_name' => $item->species?->english_name ?? 'Unknown',
                    'count' => $item->count,
                    'total_weight_kg' => round((float)$item->total_weight, 2),
                ];
            });

        return response()->json([
            'overview' => [
                'total_catches' => $totalCatches,
                'total_weight_kg' => round($totalWeightKg, 2),
                'total_quantity' => $totalQuantity,
                'pending_verification' => $pendingCount,
                'verified_catches' => $verifiedCount,
                'flagged_catches' => $flaggedCount,
                'not_a_catch_count' => $notACatchCount,
                'monitored_species' => $monitoredSpeciesCount,
                'active_fishers' => $activeFishersCount,
            ],
            'species_breakdown' => $speciesBreakdown,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
