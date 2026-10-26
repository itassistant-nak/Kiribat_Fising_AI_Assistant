<?php

namespace App\Http\Controllers;

use App\Models\CatchRecord;
use App\Models\Species;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function verify(Request $request, $id)
    {
        $catch = CatchRecord::with(['species', 'user'])->findOrFail($id);

        $validated = $request->validate([
            'action' => 'required|in:approve,correct,flag',
            'species_id' => 'nullable|exists:species,id',
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        $action = $validated['action'];
        $notes = $validated['verification_notes'] ?? null;
        $reviewer = $request->user();

        // Ensure the original AI suggestion is never overwritten
        $originalAiSuggestion = $catch->ai_species_suggestion;
        $originalAiConfidence = $catch->ai_confidence;

        if ($action === 'approve') {
            // If catch does not have species_id assigned yet, try finding matching species by AI suggestion name
            if (!$catch->species_id && $originalAiSuggestion) {
                $matchedSpecies = Species::where('kiribati_name', 'like', "%{$originalAiSuggestion}%")
                    ->orWhere('english_name', 'like', "%{$originalAiSuggestion}%")
                    ->first();
                if ($matchedSpecies) {
                    $catch->species_id = $matchedSpecies->id;
                }
            }

            $catch->verification_status = 'verified';
            $catch->verification_notes = $notes ?: ($catch->verification_notes ?: 'AI suggestion confirmed by fisheries officer.');
        } elseif ($action === 'correct') {
            if (empty($validated['species_id'])) {
                return response()->json([
                    'message' => 'A valid species ID must be provided when correcting an AI suggestion.',
                    'errors' => ['species_id' => ['Species selection is required for correction.']]
                ], 422);
            }

            $catch->species_id = $validated['species_id'];
            $catch->verification_status = 'corrected';
            $catch->verification_notes = $notes ?: 'Species identification corrected during administrator review.';
        } elseif ($action === 'flag') {
            $catch->verification_status = 'flagged';
            $catch->verification_notes = $notes ?: 'Submission flagged as invalid or unidentifiable.';
        }

        // Re-assert that original AI suggestion was preserved
        $catch->ai_species_suggestion = $originalAiSuggestion;
        $catch->ai_confidence = $originalAiConfidence;
        $catch->verified_by_user_id = $reviewer->id;
        $catch->verified_at = now();
        $catch->save();

        $catch->load(['user', 'species', 'verifiedBy', 'trip']);

        return response()->json([
            'message' => "Catch identification successfully updated with action: {$action}.",
            'catch' => $catch->toArrayProtected(true),
            'preserved_ai_suggestion' => [
                'original_suggestion' => $catch->ai_species_suggestion,
                'confidence' => $catch->ai_confidence,
            ]
        ]);
    }
}
