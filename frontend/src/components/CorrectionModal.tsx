'use client';

import React, { useState, useEffect, useRef } from 'react';
import { CatchRecord, Species, api, AiIdentificationResult } from '../lib/api';
import {
  Sparkles,
  AlertCircle,
  Check,
  X,
  Fish,
  Camera,
  Upload,
  Link as LinkIcon,
  RotateCcw,
  CheckCircle2,
  AlertTriangle,
  Loader2,
  Info,
  Ban,
} from 'lucide-react';

interface CorrectionModalProps {
  catchRecord: CatchRecord | null;
  speciesList: Species[];
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (
    action: 'approve' | 'correct' | 'flag' | 'not_a_catch',
    speciesId?: number,
    notes?: string,
    options?: {
      photoUrl?: string;
      photoFile?: File | null;
      aiSpeciesSuggestion?: string;
      aiConfidence?: number;
    }
  ) => Promise<void>;
}

const SPECIMEN_PRESETS = [
  {
    name: 'Paddletail Snapper',
    kiribati_name: 'Te Bwere',
    scientific_name: 'Lutjanus gibbus',
    url: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
    hint: 'paddletail',
  },
  {
    name: 'Yellowfin Tuna',
    kiribati_name: 'Te Ingimea',
    scientific_name: 'Thunnus albacares',
    url: 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?w=700&q=80',
    hint: 'tuna',
  },
  {
    name: 'Titan Triggerfish',
    kiribati_name: 'Te Okaoka',
    scientific_name: 'Balistoides viridescens',
    url: 'https://images.unsplash.com/photo-1576675466969-38eeae4b41f6?w=700&q=80',
    hint: 'triggerfish',
  },
  {
    name: 'Camouflage / Blacktip Grouper',
    kiribati_name: 'Te Kuau',
    scientific_name: 'Epinephelus polyphekadion',
    url: 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=700&q=80',
    hint: 'grouper',
  },
  {
    name: 'Skipjack Tuna',
    kiribati_name: 'Te Atun',
    scientific_name: 'Katsuwonus pelamis',
    url: 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?w=700&q=80',
    hint: 'skipjack',
  },
  {
    name: 'Bonefish',
    kiribati_name: 'Te Ikari',
    scientific_name: 'Albula glossodonta',
    url: 'https://images.unsplash.com/photo-1522069169874-c58ec4b76be5?auto=format&fit=crop&w=700&q=80',
    hint: 'bonefish',
  },
  {
    name: 'Peacock Grouper',
    kiribati_name: 'Te Baibo',
    scientific_name: 'Cephalopholis argus',
    url: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
    hint: 'peacock grouper',
  },
];

export default function CorrectionModal({
  catchRecord,
  speciesList,
  isOpen,
  onClose,
  onSubmit,
}: CorrectionModalProps) {
  // 1. Form and decision state
  const [action, setAction] = useState<'approve' | 'correct' | 'flag' | 'not_a_catch'>('correct');
  const [selectedSpeciesId, setSelectedSpeciesId] = useState<number | undefined>(undefined);
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // 2. Image correction state
  const [isChangingImage, setIsChangingImage] = useState(false);
  const [imageTab, setImageTab] = useState<'specimens' | 'upload' | 'url'>('specimens');
  const [currentPhotoUrl, setCurrentPhotoUrl] = useState<string | null>(null);
  const [photoChanged, setPhotoChanged] = useState(false);
  const [uploadedFile, setUploadedFile] = useState<File | null>(null);
  const [customUrlInput, setCustomUrlInput] = useState('');
  const fileInputRef = useRef<HTMLInputElement>(null);

  // 3. AI Re-analysis state for corrected image
  const [aiAnalyzing, setAiAnalyzing] = useState(false);
  const [aiResult, setAiResult] = useState<AiIdentificationResult | null>(null);
  const [aiError, setAiError] = useState<string | null>(null);

  // Synchronize modal state on catchRecord change or opening
  useEffect(() => {
    if (catchRecord) {
      setSelectedSpeciesId(catchRecord.species_id || undefined);
      setAction(
        catchRecord.verification_status === 'not_a_catch' || catchRecord.is_catch === false
          ? 'not_a_catch'
          : (catchRecord.verification_status === 'verified' ? 'approve' : 'correct')
      );
      setNotes(catchRecord.verification_notes || '');
      setCurrentPhotoUrl(catchRecord.photo_url || null);
      setPhotoChanged(false);
      setUploadedFile(null);
      setCustomUrlInput('');
      setIsChangingImage(false);
      setAiResult(null);
      setAiError(null);
      setError(null);
    }
  }, [catchRecord, isOpen]);

  // AI analysis runner for changed fish image
  const analyzeNewImage = async (imageInput: File | string, hint?: string) => {
    setAiAnalyzing(true);
    setAiError(null);

    try {
      const res = await api.identifyFish({
        image: imageInput,
        image_url: typeof imageInput === 'string' ? imageInput : undefined,
        hint: hint || undefined,
      });

      const topMatch = res.identification;
      setAiResult(topMatch);

      // Auto-match species in catalog by Kiribati or English name
      const matched = speciesList.find(
        (s) =>
          s.kiribati_name.toLowerCase() === topMatch.kiribati_name.toLowerCase() ||
          s.english_name.toLowerCase() === topMatch.english_name.toLowerCase() ||
          s.scientific_name.toLowerCase() === topMatch.scientific_name.toLowerCase()
      );

      if (matched) {
        setSelectedSpeciesId(matched.id);
      }

      // Rule: If the images change by the admin this is not a catch. Only catch from fishermen.
      setAction('not_a_catch');

      setNotes((prev) => {
        const updateNote = `Fish image changed by administrator — designated as Not a Catch (Only catches from fishermen are genuine catches). Specimen identified as ${topMatch.kiribati_name} (${(topMatch.confidence * 100).toFixed(0)}% confidence).`;
        return prev ? `${prev} • ${updateNote}` : updateNote;
      });
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to analyze corrected fish image with AI.';
      setAiError(msg);
    } finally {
      setAiAnalyzing(false);
    }
  };

  // Preset specimen selected
  const handleSelectPreset = (preset: typeof SPECIMEN_PRESETS[0]) => {
    setCurrentPhotoUrl(preset.url);
    setUploadedFile(null);
    setPhotoChanged(true);
    analyzeNewImage(preset.url, preset.hint);
  };

  // File upload handled
  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const objectUrl = URL.createObjectURL(file);
    setCurrentPhotoUrl(objectUrl);
    setUploadedFile(file);
    setPhotoChanged(true);
    analyzeNewImage(file);
  };

  // Custom URL applied
  const handleApplyCustomUrl = (e: React.FormEvent) => {
    e.preventDefault();
    if (!customUrlInput.trim()) return;

    setCurrentPhotoUrl(customUrlInput.trim());
    setUploadedFile(null);
    setPhotoChanged(true);
    analyzeNewImage(customUrlInput.trim());
  };

  // Revert back to original catch photo
  const handleRevertPhoto = () => {
    setCurrentPhotoUrl(catchRecord?.photo_url || null);
    setUploadedFile(null);
    setPhotoChanged(false);
    setAiResult(null);
    setAiError(null);
    setIsChangingImage(false);
    setSelectedSpeciesId(catchRecord?.species_id || undefined);
    setAction(
      catchRecord?.verification_status === 'not_a_catch' || catchRecord?.is_catch === false
        ? 'not_a_catch'
        : (catchRecord?.verification_status === 'verified' ? 'approve' : 'correct')
    );
    setNotes(catchRecord?.verification_notes || '');
  };

  // Submit decision & image correction
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (action === 'correct' && !selectedSpeciesId) {
      setError('Please select a corrected species from the catalog.');
      return;
    }

    setSubmitting(true);
    try {
      // If photo was changed by admin, enforce not_a_catch: only catches from fishermen are valid
      const effectiveAction = photoChanged ? 'not_a_catch' : action;

      const options = photoChanged
        ? {
            photoUrl: uploadedFile ? undefined : (currentPhotoUrl || undefined),
            photoFile: uploadedFile,
            aiSpeciesSuggestion: aiResult
              ? `${aiResult.kiribati_name} (${aiResult.english_name})`
              : undefined,
            aiConfidence: aiResult?.confidence,
          }
        : undefined;

      await onSubmit(effectiveAction, selectedSpeciesId, notes, options);
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to update verification status.');
    } finally {
      setSubmitting(false);
    }
  };

  // Rule of Hooks: Guard strictly after all hooks
  if (!isOpen || !catchRecord) return null;

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4">
      <div className="bg-white rounded-2xl max-w-xl w-full shadow-2xl border border-slate-200 overflow-hidden transform transition-all my-6">
        {/* Modal Header */}
        <div className="bg-slate-900 text-white px-6 py-4 flex items-center justify-between">
          <div className="flex items-center space-x-2">
            <Fish className="w-5 h-5 text-amber-400" />
            <div>
              <h3 className="font-bold text-base">Human Species & Image Verification</h3>
              <p className="text-[11px] text-slate-400">
                Catch #{catchRecord.id} • Fisher: {catchRecord.user?.name || `Fisher #${catchRecord.user_id}`}
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-white p-1 rounded-lg transition"
            title="Close modal"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-5 sm:p-6 space-y-4 max-h-[85vh] overflow-y-auto">
          {/* Section 1: Fish Photograph Preview & Change Action */}
          <div className="bg-slate-50 border border-slate-200 rounded-xl p-3.5 space-y-3">
            <div className="flex items-center justify-between text-xs">
              <span className="font-semibold text-slate-700 flex items-center gap-1.5">
                <Camera className="w-4 h-4 text-blue-600" />
                <span>Fish Specimen Photograph</span>
              </span>
              {photoChanged ? (
                <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full">
                  <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                  Image Changed (AI Re-Identified)
                </span>
              ) : (
                <span className="text-[11px] text-slate-500 font-medium">
                  Original Fisher Submission
                </span>
              )}
            </div>

            {/* Photo Container */}
            <div className="relative w-full h-48 bg-slate-900 rounded-xl overflow-hidden border border-slate-200 flex items-center justify-center">
              {currentPhotoUrl ? (
                <img
                  src={currentPhotoUrl}
                  alt="Fish specimen"
                  className="w-full h-full object-cover"
                />
              ) : (
                <div className="text-center text-slate-400 space-y-1">
                  <Fish className="w-8 h-8 mx-auto opacity-50" />
                  <p className="text-xs">No photograph attached</p>
                </div>
              )}

              {/* Photo Status Pill Overlay */}
              <div className="absolute top-2.5 left-2.5">
                {photoChanged ? (
                  <span className="bg-emerald-600/90 backdrop-blur-sm text-white text-[10px] font-bold px-2 py-1 rounded-md shadow-sm flex items-center gap-1">
                    <Sparkles className="w-3 h-3 text-amber-300" />
                    Corrected Specimen
                  </span>
                ) : (
                  <span className="bg-slate-900/80 backdrop-blur-sm text-slate-200 text-[10px] font-medium px-2 py-0.5 rounded-md">
                    Catch #{catchRecord.id} Photo
                  </span>
                )}
              </div>

              {/* Toggle Change Photo Button */}
              <div className="absolute bottom-2.5 right-2.5 flex items-center gap-1.5">
                {photoChanged && (
                  <button
                    type="button"
                    onClick={handleRevertPhoto}
                    className="bg-slate-900/80 hover:bg-slate-900 text-white text-[11px] font-medium px-2.5 py-1.5 rounded-lg shadow-sm backdrop-blur-sm flex items-center gap-1 transition"
                    title="Revert back to original photo"
                  >
                    <RotateCcw className="w-3.5 h-3.5" />
                    <span>Revert</span>
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => setIsChangingImage(!isChangingImage)}
                  className={`text-[11px] font-semibold px-3 py-1.5 rounded-lg shadow-md backdrop-blur-sm flex items-center gap-1.5 transition ${
                    isChangingImage
                      ? 'bg-blue-600 text-white hover:bg-blue-700'
                      : 'bg-white/95 text-slate-800 hover:bg-white border border-slate-200'
                  }`}
                >
                  <Camera className="w-3.5 h-3.5 text-blue-600" />
                  <span>{isChangingImage ? 'Hide Image Selector' : 'Change / Correct Image'}</span>
                </button>
              </div>
            </div>

            {/* Section 2: Change Image Drawer / Selection Panel */}
            {isChangingImage && (
              <div className="bg-white border border-blue-200 rounded-xl p-3.5 space-y-3 shadow-inner">
                <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                  <span className="text-xs font-bold text-slate-800">
                    Select Corrected Specimen Image:
                  </span>
                  <div className="flex items-center gap-1 text-[11px]">
                    <button
                      type="button"
                      onClick={() => setImageTab('specimens')}
                      className={`px-2.5 py-1 rounded-md font-medium transition ${
                        imageTab === 'specimens'
                          ? 'bg-blue-600 text-white'
                          : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                      }`}
                    >
                      Kiribati Presets
                    </button>
                    <button
                      type="button"
                      onClick={() => setImageTab('upload')}
                      className={`px-2.5 py-1 rounded-md font-medium transition ${
                        imageTab === 'upload'
                          ? 'bg-blue-600 text-white'
                          : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                      }`}
                    >
                      Upload File
                    </button>
                    <button
                      type="button"
                      onClick={() => setImageTab('url')}
                      className={`px-2.5 py-1 rounded-md font-medium transition ${
                        imageTab === 'url'
                          ? 'bg-blue-600 text-white'
                          : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                      }`}
                    >
                      Image URL
                    </button>
                  </div>
                </div>

                {/* Tab 1: Verified Kiribati Specimen Presets */}
                {imageTab === 'specimens' && (
                  <div className="space-y-2">
                    <p className="text-[11px] text-slate-500">
                      Choose a verified reference specimen. The AI will immediately analyze the new photograph and propose the classification:
                    </p>
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-48 overflow-y-auto pr-1">
                      {SPECIMEN_PRESETS.map((spec) => (
                        <button
                          key={spec.kiribati_name}
                          type="button"
                          onClick={() => handleSelectPreset(spec)}
                          className="group text-left border border-slate-200 hover:border-blue-500 rounded-lg p-2 bg-slate-50/50 hover:bg-blue-50/50 transition flex items-center gap-2"
                        >
                          <img
                            src={spec.url}
                            alt={spec.kiribati_name}
                            className="w-10 h-10 rounded-md object-cover flex-shrink-0 border border-slate-200 group-hover:scale-105 transition"
                          />
                          <div className="min-w-0 flex-1">
                            <span className="font-bold text-xs text-slate-800 block truncate group-hover:text-blue-700">
                              {spec.kiribati_name}
                            </span>
                            <span className="text-[10px] text-slate-500 block truncate">
                              {spec.name}
                            </span>
                          </div>
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                {/* Tab 2: Upload File */}
                {imageTab === 'upload' && (
                  <div className="space-y-2">
                    <p className="text-[11px] text-slate-500">
                      Upload a clearer photograph of this fish catch. Supported formats: JPEG, PNG, WEBP (up to 10MB):
                    </p>
                    <div
                      onClick={() => fileInputRef.current?.click()}
                      className="border-2 border-dashed border-blue-300 hover:border-blue-500 bg-blue-50/40 rounded-xl p-4 text-center cursor-pointer transition"
                    >
                      <Upload className="w-6 h-6 text-blue-600 mx-auto mb-1.5" />
                      <span className="text-xs font-semibold text-blue-800 block">
                        {uploadedFile ? uploadedFile.name : 'Click to browse or select fish photograph'}
                      </span>
                      <span className="text-[10px] text-slate-500 block mt-0.5">
                        AI will automatically classify the fish upon selection
                      </span>
                      <input
                        ref={fileInputRef}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="hidden"
                        onChange={handleFileChange}
                      />
                    </div>
                  </div>
                )}

                {/* Tab 3: Custom URL */}
                {imageTab === 'url' && (
                  <div className="space-y-2">
                    <div className="flex gap-2">
                      <input
                        type="url"
                        value={customUrlInput}
                        onChange={(e) => setCustomUrlInput(e.target.value)}
                        placeholder="https://example.com/fish-photo.jpg"
                        className="flex-1 text-xs border border-slate-300 rounded-lg px-2.5 py-1.5 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                      />
                      <button
                        type="button"
                        onClick={handleApplyCustomUrl}
                        className="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg transition"
                      >
                        Apply & Analyze
                      </button>
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* AI Re-Analyzing State */}
            {aiAnalyzing && (
              <div className="bg-blue-50/90 border border-blue-200 rounded-lg p-3 flex items-center gap-2.5 animate-pulse">
                <Loader2 className="w-4 h-4 text-blue-600 animate-spin flex-shrink-0" />
                <div className="text-xs text-blue-800 font-medium">
                  Meta AI Vision is analyzing the corrected specimen photograph...
                </div>
              </div>
            )}

            {/* AI Error Alert */}
            {aiError && (
              <div className="bg-amber-50 border border-amber-200 text-amber-800 text-xs p-2.5 rounded-lg flex items-center gap-2">
                <AlertCircle className="w-4 h-4 flex-shrink-0 text-amber-600" />
                <span>{aiError}</span>
              </div>
            )}

            {/* AI Re-Analysis Results Card */}
            {aiResult && !aiAnalyzing && (
              <div className="bg-gradient-to-r from-blue-50 to-indigo-50/70 border border-blue-200 rounded-xl p-3.5 space-y-2.5">
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-1.5">
                    <Sparkles className="w-4 h-4 text-blue-600 flex-shrink-0" />
                    <span className="text-xs font-bold text-blue-900">
                      Meta AI Vision Re-Identification:
                    </span>
                  </div>
                  <span className="bg-blue-600 text-white font-mono text-[10px] font-bold px-2 py-0.5 rounded-full">
                    {(aiResult.confidence * 100).toFixed(0)}% Confidence
                  </span>
                </div>

                <div className="text-sm font-bold text-slate-900 flex items-center gap-2">
                  <span className="text-blue-700">{aiResult.kiribati_name}</span>
                  <span className="text-xs text-slate-500 font-medium">
                    ({aiResult.english_name} — <em className="italic">{aiResult.scientific_name}</em>)
                  </span>
                </div>

                {/* Recognized Visual Features */}
                {aiResult.visual_features && aiResult.visual_features.length > 0 && (
                  <div className="space-y-1">
                    <span className="text-[10px] font-bold text-slate-500 uppercase tracking-wider block">
                      Recognized Morphological Traits:
                    </span>
                    <ul className="text-[11px] text-slate-700 space-y-0.5">
                      {aiResult.visual_features.map((feat, i) => (
                        <li key={i} className="flex items-start gap-1.5">
                          <Check className="w-3 h-3 text-emerald-600 flex-shrink-0 mt-0.5" />
                          <span>{feat}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}

                <div className="text-[11px] bg-white/80 border border-blue-100 rounded-lg p-2 text-blue-800 flex items-center gap-1.5 font-medium">
                  <Info className="w-3.5 h-3.5 text-blue-600 flex-shrink-0" />
                  <span>
                    Suggested species selected below. Click &quot;Approve AI&quot; or select custom species to verify.
                  </span>
                </div>
              </div>
            )}

            {/* Preserved Original AI Suggestion Alert */}
            {!photoChanged && (
              <div className="bg-slate-100/70 border border-slate-200 rounded-lg p-2.5 text-xs text-slate-600 flex items-start gap-2">
                <Sparkles className="w-3.5 h-3.5 text-slate-500 flex-shrink-0 mt-0.5" />
                <div className="text-[11px]">
                  <span className="font-semibold text-slate-700">Initial Catch AI Suggestion: </span>
                  <span className="font-medium">{catchRecord.ai_species_suggestion || 'No suggestion recorded'}</span>
                  {catchRecord.ai_confidence && (
                    <span className="ml-1 font-mono text-[10px] bg-slate-200 px-1.5 py-0.2 rounded font-semibold">
                      {(catchRecord.ai_confidence * 100).toFixed(0)}%
                    </span>
                  )}
                  <p className="text-[10px] text-slate-400 mt-0.5 italic">
                    Original submission suggestion is preserved immutably in database records per ADR-002.
                  </p>
                </div>
              </div>
            )}
          </div>

          {/* Admin Photo Changed Policy Banner */}
          {photoChanged && (
            <div className="bg-rose-50 border border-rose-200 rounded-xl p-3 flex items-start gap-2.5 text-xs text-rose-800">
              <Ban className="w-4 h-4 text-rose-600 flex-shrink-0 mt-0.5" />
              <div>
                <div className="font-bold text-rose-900 flex items-center gap-1.5">
                  <span>Image Changed by Admin — Designated as &quot;Not a Catch&quot;</span>
                </div>
                <p className="text-[11px] text-rose-700 mt-0.5">
                  Only original photos from fishermen count as genuine catches. Because this photo was replaced with an administrative specimen, this submission is designated as <strong>Not a Catch</strong> and excluded from official fishery catch tallies.
                </p>
              </div>
            </div>
          )}

          {/* Section 3: Verification Action Selector */}
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-2">
              Verification Decision (Human Confirmation Required):
            </label>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
              <button
                type="button"
                onClick={() => setAction('approve')}
                className={`py-2 px-2.5 rounded-lg text-xs font-medium border text-center transition flex items-center justify-center gap-1.5 ${
                  action === 'approve'
                    ? 'bg-emerald-600 text-white border-emerald-600 shadow-sm'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                }`}
              >
                <Check className="w-3.5 h-3.5" />
                <span>Approve AI</span>
              </button>
              <button
                type="button"
                onClick={() => setAction('correct')}
                className={`py-2 px-2.5 rounded-lg text-xs font-medium border text-center transition flex items-center justify-center gap-1.5 ${
                  action === 'correct'
                    ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                }`}
              >
                <span>Correct Species</span>
              </button>
              <button
                type="button"
                onClick={() => setAction('flag')}
                className={`py-2 px-2.5 rounded-lg text-xs font-medium border text-center transition flex items-center justify-center gap-1.5 ${
                  action === 'flag'
                    ? 'bg-amber-600 text-white border-amber-600 shadow-sm'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                }`}
              >
                <AlertTriangle className="w-3.5 h-3.5" />
                <span>Flag</span>
              </button>
              <button
                type="button"
                onClick={() => setAction('not_a_catch')}
                className={`py-2 px-2.5 rounded-lg text-xs font-medium border text-center transition flex items-center justify-center gap-1.5 ${
                  action === 'not_a_catch'
                    ? 'bg-rose-700 text-white border-rose-700 shadow-sm'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                }`}
              >
                <Ban className="w-3.5 h-3.5" />
                <span>Not a Catch</span>
              </button>
            </div>
          </div>

          {/* Section 4: Corrected / Specimen Species Selector */}
          {(action === 'correct' || action === 'approve' || action === 'not_a_catch') && (
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                {action === 'approve'
                  ? 'Approved Species:'
                  : action === 'not_a_catch'
                  ? 'Specimen Species Identification (Optional):'
                  : 'Select True Species:'}
              </label>
              <select
                value={selectedSpeciesId || ''}
                onChange={(e) => setSelectedSpeciesId(Number(e.target.value))}
                className="w-full text-xs border border-slate-300 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white text-slate-800 font-medium"
                required={action === 'correct'}
              >
                <option value="">-- Choose verified species --</option>
                {speciesList.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.kiribati_name} ({s.english_name} — {s.scientific_name})
                  </option>
                ))}
              </select>
            </div>
          )}

          {/* Section 5: Officer Verification Notes */}
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1.5">
              Reviewer Notes / Verification Rationale:
            </label>
            <textarea
              rows={2}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="e.g. Caudal peduncle spotting and opercular notch confirm Epinephelus polyphekadion."
              className="w-full text-xs border border-slate-300 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none"
            />
          </div>

          {error && (
            <div className="bg-red-50 text-red-700 text-xs p-3 rounded-lg border border-red-200 flex items-center gap-2">
              <AlertCircle className="w-4 h-4 flex-shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {/* Buttons */}
          <div className="flex items-center justify-end space-x-3 pt-3 border-t border-slate-100">
            <button
              type="button"
              onClick={onClose}
              disabled={submitting}
              className="px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={submitting || aiAnalyzing}
              className={`px-5 py-2 text-xs font-semibold text-white rounded-lg shadow-sm transition disabled:opacity-50 flex items-center gap-1.5 ${
                action === 'not_a_catch'
                  ? 'bg-rose-700 hover:bg-rose-800'
                  : 'bg-blue-600 hover:bg-blue-700'
              }`}
            >
              {submitting ? (
                <>
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  <span>Saving Verification...</span>
                </>
              ) : action === 'not_a_catch' ? (
                <>
                  <Ban className="w-3.5 h-3.5" />
                  <span>Confirm: Mark as Not a Catch</span>
                </>
              ) : (
                <>
                  <Check className="w-3.5 h-3.5" />
                  <span>Confirm Human Verification</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
