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
            'action' => 'required|in:approve,correct,flag,not_a_catch',
            'species_id' => 'nullable|exists:species,id',
            'verification_notes' => 'nullable|string|max:1000',
            'photo' => 'nullable|image|max:10240',
            'photo_url' => 'nullable|string',
            'ai_species_suggestion' => 'nullable|string|max:255',
            'ai_confidence' => 'nullable|numeric|min:0|max:1',
        ]);

        $action = $validated['action'];
        $notes = $validated['verification_notes'] ?? null;
        $reviewer = $request->user();

        // Ensure the original AI suggestion and photo are preserved in audit history
        $originalAiSuggestion = $catch->ai_species_suggestion;
        $originalAiConfidence = $catch->ai_confidence;
        $originalPhotoUrl = $catch->photo_url;

        $metadata = is_array($catch->ai_metadata) ? $catch->ai_metadata : [];

        // Handle fish image replacement / correction
        $newPhotoUrl = null;
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'catch_corrected_' . time() . '_' . \Illuminate\Support\Str::random(8) . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/catches'), $filename);
            $newPhotoUrl = url('uploads/catches/' . $filename);
        } elseif ($request->filled('photo_url')) {
            $newPhotoUrl = $request->input('photo_url');
        }

        $imageChangedByAdmin = ($newPhotoUrl && $newPhotoUrl !== $originalPhotoUrl);

        if ($imageChangedByAdmin) {
            $metadata['original_photo_url'] = $originalPhotoUrl;
            $metadata['corrected_photo_url'] = $newPhotoUrl;
            $metadata['photo_corrected_at'] = now()->toIso8601String();
            $metadata['photo_corrected_by'] = $reviewer->id;
            $metadata['image_changed_by_admin'] = true;
            $metadata['is_catch'] = false;
            $metadata['not_a_catch_reason'] = 'Fish image replaced by administrator — not a catch from fisherman.';
            $catch->photo_url = $newPhotoUrl;

            // If an updated AI suggestion was generated from the corrected image, record it
            if ($request->filled('ai_species_suggestion')) {
                $metadata['corrected_ai_suggestion'] = $request->input('ai_species_suggestion');
                $metadata['corrected_ai_confidence'] = $request->input('ai_confidence', 0.95);
            }
        }

        // Rule: If the images change by the admin this is not a catch. Only catch from fishermen.
        if ($imageChangedByAdmin || $action === 'not_a_catch') {
            $catch->is_catch = false;
            $catch->verification_status = 'not_a_catch';

            // Optional species assignment for catalog/reference identification
            if ($request->filled('species_id')) {
                $catch->species_id = $request->input('species_id');
            } elseif ($request->filled('ai_species_suggestion')) {
                $matched = Species::where('kiribati_name', 'like', "%{$request->input('ai_species_suggestion')}%")
                    ->orWhere('english_name', 'like', "%{$request->input('ai_species_suggestion')}%")
                    ->first();
                if ($matched) {
                    $catch->species_id = $matched->id;
                }
            }

            $defaultNote = $imageChangedByAdmin
                ? 'Image changed by administrator — this is not a catch from fisherman (reference specimen only).'
                : 'Marked as not a catch from fisherman by administrator.';
            $catch->verification_notes = $notes ?: $defaultNote;
            $metadata['not_a_catch'] = true;
            $metadata['is_catch'] = false;
        } elseif ($action === 'approve') {
            // If catch does not have species_id assigned yet, try finding matching species by AI suggestion or requested species_id
            if ($request->filled('species_id')) {
                $catch->species_id = $request->input('species_id');
            } elseif (!$catch->species_id && $originalAiSuggestion) {
                $matchedSpecies = Species::where('kiribati_name', 'like', "%{$originalAiSuggestion}%")
                    ->orWhere('english_name', 'like', "%{$originalAiSuggestion}%")
                    ->first();
                if ($matchedSpecies) {
                    $catch->species_id = $matchedSpecies->id;
                }
            }

            $catch->is_catch = true;
            $catch->verification_status = 'verified';
            $catch->verification_notes = $notes ?: ($catch->verification_notes ?: 'AI suggestion confirmed by fisheries officer.');
        } elseif ($action === 'correct') {
            if (empty($validated['species_id'])) {
                return response()->json([
                    'message' => 'A valid species ID must be provided when correcting an AI suggestion.',
                    'errors' => ['species_id' => ['Species selection is required for correction.']]
                ], 422);
            }

            $catch->is_catch = true;
            $catch->species_id = $validated['species_id'];
            $catch->verification_status = 'corrected';
            $catch->verification_notes = $notes ?: 'Species identification corrected during administrator review.';
        } elseif ($action === 'flag') {
            $catch->verification_status = 'flagged';
            $catch->verification_notes = $notes ?: 'Submission flagged as invalid or unidentifiable.';
        }

        if (!empty($metadata)) {
            $catch->ai_metadata = $metadata;
        }

        // Re-assert that original AI suggestion was preserved per ADR-002
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
            ],
            'photo_updated' => (bool)$newPhotoUrl,
        ]);
    }
}
