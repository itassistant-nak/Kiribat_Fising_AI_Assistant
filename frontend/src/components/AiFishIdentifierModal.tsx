'use client';

import React, { useState, useRef, useEffect } from 'react';
import { api, Species, AiIdentificationResponse, AiIdentificationResult } from '../lib/api';
import {
  Sparkles,
  Upload,
  Image as ImageIcon,
  CheckCircle2,
  AlertTriangle,
  Fish,
  Info,
  X,
  ArrowRight,
  ShieldCheck,
  RefreshCw,
  Sliders,
  Check,
} from 'lucide-react';

interface AiFishIdentifierModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess?: () => void;
  speciesList?: Species[];
  initialSpecies?: Species | null;
}

const SAMPLE_SPECIMENS = [
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
    name: 'Blacktip Grouper',
    kiribati_name: 'Te Kuau',
    scientific_name: 'Epinephelus fasciatus',
    url: 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=700&q=80',
    hint: 'grouper',
  },
];

export default function AiFishIdentifierModal({
  isOpen,
  onClose,
  onSuccess,
  speciesList = [],
  initialSpecies,
}: AiFishIdentifierModalProps) {
  // Upload & Image State
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [imagePreviewUrl, setImagePreviewUrl] = useState<string>('');
  const [activeHint, setActiveHint] = useState<string>('');

  // AI Inference State
  const [analyzing, setAnalyzing] = useState<boolean>(false);
  const [aiResult, setAiResult] = useState<AiIdentificationResult | null>(null);
  const [aiError, setAiError] = useState<string | null>(null);

  // Human Admin Confirmation Fields
  const [englishName, setEnglishName] = useState<string>('');
  const [kiribatiName, setKiribatiName] = useState<string>('');
  const [scientificName, setScientificName] = useState<string>('');
  const [ciguateraRisk, setCiguateraRisk] = useState<'low' | 'medium' | 'high' | 'extreme'>('low');
  const [weightKg, setWeightKg] = useState<number>(2.5);
  const [quantity, setQuantity] = useState<number>(1);
  const [verificationNotes, setVerificationNotes] = useState<string>(
    'Verified by Administrator to assist and train the AI species vision model.'
  );

  // Submission State
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [isSuccess, setIsSuccess] = useState<boolean>(false);
  const [successMessage, setSuccessMessage] = useState<string>('');

  const fileInputRef = useRef<HTMLInputElement>(null);

  // Handle local file selection
  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      const file = e.target.files[0];
      setSelectedFile(file);
      const objectUrl = URL.createObjectURL(file);
      setImagePreviewUrl(objectUrl);
      setActiveHint('');
      setAiResult(null);
      setAiError(null);
      setIsSuccess(false);

      // Trigger automatic AI analysis
      runAiIdentification({ image: file });
    }
  };

  // Handle selecting one of the realistic Kiribati sample photos
  const handleSelectSample = (sample: typeof SAMPLE_SPECIMENS[0]) => {
    setSelectedFile(null);
    setImagePreviewUrl(sample.url);
    setActiveHint(sample.hint);
    setAiResult(null);
    setAiError(null);
    setIsSuccess(false);

    // Trigger AI analysis with sample
    runAiIdentification({ image_url: sample.url, hint: sample.hint });
  };

  // Run AI Vision Analysis
  const runAiIdentification = async (params: { image?: File | string; image_url?: string; hint?: string }) => {
    setAnalyzing(true);
    setAiError(null);
    try {
      const res = await api.identifyFish(params);
      const ident = res.identification;
      setAiResult(ident);

      // Pre-fill Admin verification fields with AI suggestions
      setEnglishName(ident.english_name);
      setKiribatiName(ident.kiribati_name);
      setScientificName(ident.scientific_name);
      setCiguateraRisk(ident.ciguatera_risk === 'unknown' ? 'low' : ident.ciguatera_risk);
    } catch (err: any) {
      console.error('AI identification error:', err);
      setAiError(err.message || 'AI Vision model failed to analyze the image.');
    } finally {
      setAnalyzing(false);
    }
  };

  // Prepopulate and trigger AI identification if initialSpecies was passed
  useEffect(() => {
    if (isOpen) {
      if (initialSpecies) {
        setSelectedFile(null);
        setImagePreviewUrl(initialSpecies.photo_url || '');
        setActiveHint(initialSpecies.kiribati_name);
        setEnglishName(initialSpecies.english_name || '');
        setKiribatiName(initialSpecies.kiribati_name || '');
        setScientificName(initialSpecies.scientific_name || '');
        if (['low', 'medium', 'high', 'extreme'].includes(initialSpecies.ciguatera_risk)) {
          setCiguateraRisk(initialSpecies.ciguatera_risk as 'low' | 'medium' | 'high' | 'extreme');
        }
        setAiResult(null);
        setAiError(null);
        setIsSuccess(false);

        if (initialSpecies.photo_url) {
          runAiIdentification({ image_url: initialSpecies.photo_url, hint: initialSpecies.kiribati_name });
        }
      } else {
        setSelectedFile(null);
        setImagePreviewUrl('');
        setActiveHint('');
        setEnglishName('');
        setKiribatiName('');
        setScientificName('');
        setCiguateraRisk('low');
        setAiResult(null);
        setAiError(null);
        setIsSuccess(false);
      }
    }
  }, [isOpen, initialSpecies]);

  // Select alternative candidate from AI predictions
  const handleSelectCandidate = (cand: { kiribati_name: string; english_name: string; scientific_name: string }) => {
    setKiribatiName(cand.kiribati_name);
    setEnglishName(cand.english_name);
    setScientificName(cand.scientific_name);
  };

  // Submit ground truth to train AI and record verified catch
  const handleSubmitConfirmation = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!englishName || !kiribatiName) {
      alert('Please provide both the English name and Kiribati name.');
      return;
    }

    setSubmitting(true);
    setAiError(null);

    try {
      // 1. Create/Update verified species in reference catalog
      await api.createSpecies({
        kiribati_name: kiribatiName,
        english_name: englishName,
        scientific_name: scientificName,
        ciguatera_risk: ciguateraRisk,
        photo_url: imagePreviewUrl,
        notes: `AI Training Reference Specimen • ${verificationNotes}`,
      });

      // 2. Submit verified catch record with AI suggestion and human ground truth
      await api.createCatch({
        kiribati_name: kiribatiName,
        english_name: englishName,
        scientific_name: scientificName,
        photo: selectedFile || undefined,
        photo_url: !selectedFile ? imagePreviewUrl : undefined,
        ai_species_suggestion: aiResult ? `${aiResult.kiribati_name} (${aiResult.english_name})` : `${kiribatiName} (${englishName})`,
        ai_confidence: aiResult?.confidence || 0.95,
        weight_kg: weightKg,
        quantity: quantity,
        latitude: 1.3650,
        longitude: 172.9450,
        verification_status: 'verified',
        verification_notes: verificationNotes,
      });

      setIsSuccess(true);
      setSuccessMessage(
        `Ground truth confirmed! The AI has recorded this verified specimen for ${kiribatiName} (${englishName}).`
      );

      if (onSuccess) {
        onSuccess();
      }
    } catch (err: any) {
      console.error('Specimen save failed:', err);
      setAiError(err.message || 'Failed to record verified fish specimen.');
    } finally {
      setSubmitting(false);
    }
  };

  const resetForm = () => {
    setSelectedFile(null);
    setImagePreviewUrl('');
    setAiResult(null);
    setAiError(null);
    setIsSuccess(false);
    setEnglishName('');
    setKiribatiName('');
    setScientificName('');
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl max-w-2xl w-full shadow-2xl border border-slate-200 overflow-hidden transform transition-all flex flex-col max-h-[92vh]">
        {/* Header */}
        <div className="bg-gradient-to-r from-blue-700 via-indigo-700 to-slate-900 text-white px-6 py-4 flex items-center justify-between border-b border-indigo-600/30">
          <div className="flex items-center space-x-2.5">
            <div className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center backdrop-blur-xs">
              <Sparkles className="w-4 h-4 text-amber-300" />
            </div>
            <div>
              <h3 className="font-bold text-sm sm:text-base leading-tight">
                Help AI Identify Fish • Administrator Species Training
              </h3>
              <p className="text-[11px] text-blue-200">
                Upload a specimen photo, let Meta AI analyze features, and confirm English & Kiribati names
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="text-white/70 hover:text-white p-1 rounded-lg transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Modal Body */}
        <div className="p-6 overflow-y-auto space-y-5">
          {/* Success Banner */}
          {isSuccess && (
            <div className="bg-emerald-50 border border-emerald-300 rounded-xl p-4 text-emerald-900 space-y-3">
              <div className="flex items-start gap-3">
                <CheckCircle2 className="w-6 h-6 text-emerald-600 flex-shrink-0 mt-0.5" />
                <div>
                  <h4 className="font-bold text-sm text-emerald-800">
                    Specimen Successfully Verified & Learned by AI!
                  </h4>
                  <p className="text-xs text-emerald-700 mt-1">{successMessage}</p>
                  <p className="text-[11px] text-emerald-600 mt-1 font-medium">
                    ✓ Updated in Kiribati Species Catalog &nbsp;•&nbsp; ✓ Synced to Artisanal Catch Records &nbsp;•&nbsp; ✓ Preserved in Model Audit Trail
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-2 pt-1 border-t border-emerald-200">
                <button
                  type="button"
                  onClick={resetForm}
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition"
                >
                  Identify Another Fish
                </button>
                <button
                  type="button"
                  onClick={onClose}
                  className="px-3 py-1.5 text-xs font-medium rounded-lg bg-white text-emerald-800 border border-emerald-300 hover:bg-emerald-100 transition"
                >
                  Return to Dashboard
                </button>
              </div>
            </div>
          )}

          {/* Error Banner */}
          {aiError && (
            <div className="bg-red-50 border border-red-200 rounded-xl p-3 text-xs text-red-800 flex items-start gap-2">
              <AlertTriangle className="w-4 h-4 text-red-600 flex-shrink-0 mt-0.5" />
              <div>
                <span className="font-bold">Error: </span>
                {aiError}
              </div>
            </div>
          )}

          {!isSuccess && (
            <form onSubmit={handleSubmitConfirmation} className="space-y-5">
              {/* Pre-populated Catalog Species Banner */}
              {initialSpecies && (
                <div className="bg-gradient-to-r from-blue-50 to-indigo-50 border border-indigo-200 rounded-xl p-3 flex items-center justify-between gap-3 text-xs">
                  <div className="flex items-center gap-2">
                    <Sparkles className="w-4 h-4 text-indigo-600 flex-shrink-0" />
                    <div>
                      <span className="font-bold text-slate-800">
                        Identifying Catalog Species: <span className="text-indigo-700 font-extrabold">{initialSpecies.kiribati_name}</span> ({initialSpecies.english_name})
                      </span>
                      <p className="text-[11px] text-slate-500">
                        Specimen photograph preloaded for Meta AI Vision analysis and morphological verification.
                      </p>
                    </div>
                  </div>
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-800 border border-indigo-200 whitespace-nowrap">
                    Catalog Link
                  </span>
                </div>
              )}

              {/* 1. Image Upload Section */}
              <div className="space-y-2">
                <label className="block text-xs font-bold text-slate-800 uppercase tracking-wider">
                  1. Upload Fish Photograph
                </label>

                {imagePreviewUrl ? (
                  <div className="relative rounded-xl overflow-hidden border border-slate-200 bg-slate-900 aspect-video max-h-56 flex items-center justify-center group">
                    <img
                      src={imagePreviewUrl}
                      alt="Uploaded fish specimen"
                      className="w-full h-full object-cover"
                    />
                    <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-3">
                      <button
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        className="px-3 py-1.5 bg-white text-slate-800 rounded-lg text-xs font-bold shadow hover:bg-slate-100 transition flex items-center gap-1.5"
                      >
                        <RefreshCw className="w-3.5 h-3.5" />
                        Replace Image
                      </button>
                    </div>
                  </div>
                ) : (
                  <div
                    onClick={() => fileInputRef.current?.click()}
                    className="border-2 border-dashed border-slate-300 hover:border-indigo-500 rounded-xl p-6 text-center cursor-pointer transition bg-slate-50 hover:bg-indigo-50/20"
                  >
                    <div className="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center mx-auto mb-2">
                      <Upload className="w-5 h-5" />
                    </div>
                    <p className="text-xs font-bold text-slate-700">
                      Click to upload fish photo from device
                    </p>
                    <p className="text-[11px] text-slate-500 mt-0.5">
                      Supports JPG, PNG, WEBP (Artisanal catch photos or lab specimens)
                    </p>
                  </div>
                )}

                <input
                  type="file"
                  ref={fileInputRef}
                  onChange={handleFileChange}
                  accept="image/*"
                  className="hidden"
                />

                {/* Sample Specimens Selector */}
                <div className="pt-2">
                  <div className="text-[11px] font-semibold text-slate-500 mb-1.5">
                    Or select a verified Kiribati demonstration specimen:
                  </div>
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    {SAMPLE_SPECIMENS.map((spec) => (
                      <button
                        key={spec.name}
                        type="button"
                        onClick={() => handleSelectSample(spec)}
                        className={`text-left p-2 rounded-lg border text-xs transition flex flex-col justify-between ${
                          imagePreviewUrl === spec.url
                            ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-200'
                            : 'border-slate-200 hover:border-slate-300 bg-white'
                        }`}
                      >
                        <span className="font-bold text-slate-800 leading-snug">{spec.kiribati_name}</span>
                        <span className="text-[10px] text-slate-500">{spec.name}</span>
                      </button>
                    ))}
                  </div>
                </div>
              </div>

              {/* 2. AI Identification Result Banner */}
              {analyzing && (
                <div className="bg-indigo-50 border border-indigo-200 rounded-xl p-4 flex items-center gap-3">
                  <RefreshCw className="w-5 h-5 text-indigo-600 animate-spin flex-shrink-0" />
                  <div className="text-xs text-indigo-900">
                    <span className="font-bold block">Running Meta AI Vision Identification...</span>
                    Analyzing caudal fin shape, scale patterns, and Kiribati marine morphological features.
                  </div>
                </div>
              )}

              {aiResult && !analyzing && (
                <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2">
                      <Sparkles className="w-4 h-4 text-indigo-600 flex-shrink-0" />
                      <span className="text-xs font-bold uppercase tracking-wider text-slate-800">
                        Meta AI Vision Suggestion:
                      </span>
                    </div>
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-mono font-bold bg-indigo-100 text-indigo-700">
                      {(aiResult.confidence * 100).toFixed(0)}% Confidence
                    </span>
                  </div>

                  <div className="bg-white p-3 rounded-lg border border-slate-200">
                    <div className="flex items-baseline gap-2">
                      <h4 className="text-base font-bold text-slate-900">
                        {aiResult.kiribati_name}
                      </h4>
                      <span className="text-xs text-slate-600 font-semibold">
                        ({aiResult.english_name})
                      </span>
                      <span className="text-xs italic text-slate-400 font-serif">
                        {aiResult.scientific_name}
                      </span>
                    </div>

                    {/* Morphological Features Detected */}
                    <div className="mt-2 text-[11px] text-slate-600 space-y-1">
                      <div className="font-semibold text-slate-700">Detected Visual Morphology:</div>
                      <ul className="list-disc list-inside space-y-0.5 text-slate-600 pl-1">
                        {aiResult.visual_features.map((feat, idx) => (
                          <li key={idx}>{feat}</li>
                        ))}
                      </ul>
                    </div>

                    {aiResult.ciguatera_warning && (
                      <div className="mt-2 p-2 rounded bg-amber-50 border border-amber-200 text-[11px] text-amber-900 flex items-start gap-1.5">
                        <AlertTriangle className="w-3.5 h-3.5 text-amber-700 flex-shrink-0 mt-0.5" />
                        <span>{aiResult.ciguatera_warning}</span>
                      </div>
                    )}
                  </div>

                  {/* Alternative Candidate Matches */}
                  {aiResult.candidates?.length > 0 && (
                    <div>
                      <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">
                        Alternative Candidates:
                      </div>
                      <div className="flex flex-wrap gap-1.5">
                        {aiResult.candidates.map((cand) => (
                          <button
                            key={cand.kiribati_name}
                            type="button"
                            onClick={() => handleSelectCandidate(cand)}
                            className="px-2 py-1 text-[11px] rounded-md border border-slate-200 bg-white hover:bg-slate-100 text-slate-700 transition flex items-center gap-1"
                          >
                            <span>{cand.kiribati_name} ({cand.english_name})</span>
                            <span className="text-[10px] text-slate-400 font-mono">
                              {(cand.confidence * 100).toFixed(0)}%
                            </span>
                          </button>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* 3. Human Ground Truth Form (Admin Teaching / Confirmation) */}
              <div className="space-y-3 pt-1 border-t border-slate-200">
                <div className="flex items-center gap-2">
                  <ShieldCheck className="w-4 h-4 text-emerald-600" />
                  <label className="text-xs font-bold text-slate-800 uppercase tracking-wider">
                    2. Human Admin Confirmation (Ground Truth)
                  </label>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      English Name (Common Name) *
                    </label>
                    <input
                      type="text"
                      required
                      value={englishName}
                      onChange={(e) => setEnglishName(e.target.value)}
                      placeholder="e.g. Paddletail Snapper"
                      className="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none font-medium"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Kiribati Name (Vernacular Name) *
                    </label>
                    <input
                      type="text"
                      required
                      value={kiribatiName}
                      onChange={(e) => setKiribatiName(e.target.value)}
                      placeholder="e.g. Te Bwere"
                      className="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none font-bold text-indigo-900"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Scientific Name
                    </label>
                    <input
                      type="text"
                      value={scientificName}
                      onChange={(e) => setScientificName(e.target.value)}
                      placeholder="e.g. Lutjanus gibbus"
                      className="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none italic"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Ciguatera / Toxin Risk Level
                    </label>
                    <select
                      value={ciguateraRisk}
                      onChange={(e) => setCiguateraRisk(e.target.value as any)}
                      className="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none font-medium bg-white"
                    >
                      <option value="low">Low Risk (Safe)</option>
                      <option value="medium">Medium Risk (Caution)</option>
                      <option value="high">High Risk (Hazardous)</option>
                      <option value="extreme">Extreme Risk (Bioaccumulated)</option>
                    </select>
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Verification Notes & Training Justification
                  </label>
                  <textarea
                    rows={2}
                    value={verificationNotes}
                    onChange={(e) => setVerificationNotes(e.target.value)}
                    placeholder="Enter notes justifying the identification..."
                    className="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>
              </div>

              {/* Modal Actions */}
              <div className="flex items-center justify-between pt-3 border-t border-slate-200">
                <button
                  type="button"
                  onClick={onClose}
                  className="px-4 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100 rounded-lg transition"
                >
                  Cancel
                </button>

                <button
                  type="submit"
                  disabled={submitting || !englishName || !kiribatiName}
                  className="px-5 py-2.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md transition disabled:opacity-50 flex items-center gap-2"
                >
                  {submitting ? (
                    <>
                      <RefreshCw className="w-4 h-4 animate-spin" />
                      <span>Saving & Training AI...</span>
                    </>
                  ) : (
                    <>
                      <Check className="w-4 h-4" />
                      <span>Confirm & Help AI Learn</span>
                    </>
                  )}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
