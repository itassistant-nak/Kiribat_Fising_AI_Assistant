'use client';

import React, { useState, useRef, useEffect } from 'react';
import { api, Species, AiIdentificationResponse, AiIdentificationResult, resolvePhotoUrl } from '../lib/api';
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
  Database,
  Globe,
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

  // AI Inference & Provider State
  const [selectedAiProvider, setSelectedAiProvider] = useState<'compare' | 'muse_glimmer' | 'openai'>('compare');
  const [analyzing, setAnalyzing] = useState<boolean>(false);
  const [aiResult, setAiResult] = useState<AiIdentificationResult | null>(null);
  const [comparisonData, setComparisonData] = useState<{
    status?: string;
    providers?: any;
    comparison?: any;
  } | null>(null);
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
      setComparisonData(null);
      setAiError(null);
      setIsSuccess(false);

      // Trigger automatic AI analysis with currently selected provider
      runAiIdentification({ image: file });
    }
  };

  // Handle selecting one of the realistic Kiribati sample photos
  const handleSelectSample = (sample: typeof SAMPLE_SPECIMENS[0]) => {
    setSelectedFile(null);
    setImagePreviewUrl(sample.url);
    setActiveHint(sample.hint);
    setAiResult(null);
    setComparisonData(null);
    setAiError(null);
    setIsSuccess(false);

    // Trigger AI analysis with sample using selected provider
    runAiIdentification({ image_url: sample.url, hint: sample.hint });
  };

  // Run AI Vision Analysis
  const runAiIdentification = async (
    params: { image?: File | string; image_url?: string; hint?: string },
    providerOverride?: 'compare' | 'muse_glimmer' | 'openai'
  ) => {
    setAnalyzing(true);
    setAiError(null);
    const providerToUse = providerOverride || selectedAiProvider;
    try {
      const res = await api.identifyFish({
        ...params,
        provider: providerToUse,
      });
      const ident = res.identification;
      setAiResult(ident);

      if (res.comparison_status || res.providers) {
        setComparisonData({
          status: res.comparison_status,
          providers: res.providers,
          comparison: res.comparison,
        });
      } else {
        setComparisonData(null);
      }

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

  // Handle user changing the active AI vision provider
  const handleSelectProvider = (prov: 'compare' | 'muse_glimmer' | 'openai') => {
    setSelectedAiProvider(prov);
    if (selectedFile) {
      runAiIdentification({ image: selectedFile, hint: activeHint }, prov);
    } else if (imagePreviewUrl) {
      runAiIdentification({ image_url: imagePreviewUrl, hint: activeHint }, prov);
    }
  };

  // Apply suggestion from a specific provider card
  const handleApplyProviderSuggestion = (providerData: any) => {
    if (!providerData) return;
    const suggested = providerData.suggested_species || {};
    const kName = suggested.kiribati_name || '';
    const eName = suggested.common_name || '';
    const sName = providerData.scientific_name || '';
    const risk = suggested.ciguatera_risk || 'low';

    if (kName) setKiribatiName(kName);
    if (eName) setEnglishName(eName);
    if (sName) setScientificName(sName);
    if (['low', 'medium', 'high', 'extreme'].includes(risk)) {
      setCiguateraRisk(risk);
    }
  };

  // Prepopulate and trigger AI identification if initialSpecies was passed
  useEffect(() => {
    if (isOpen) {
      if (initialSpecies) {
        setSelectedFile(null);
        setImagePreviewUrl(resolvePhotoUrl(initialSpecies.photo_url) || '');
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

              {/* AI Vision Provider Selector */}
              <div className="bg-slate-50 border border-slate-200 rounded-xl p-3.5 space-y-2.5">
                <div className="flex items-center justify-between">
                  <label className="text-xs font-bold text-slate-800 flex items-center gap-1.5 uppercase tracking-wider">
                    <Sliders className="w-3.5 h-3.5 text-indigo-600" />
                    Select AI Vision Engine
                  </label>
                  <span className="text-[11px] font-medium text-slate-500">
                    {selectedAiProvider === 'compare' && '⚖️ Parallel Comparison (SAM 3.1 + OpenAI)'}
                    {selectedAiProvider === 'muse_glimmer' && '🔬 Meta SAM 3.1 (Segmentation Evidence)'}
                    {selectedAiProvider === 'openai' && '⚡ OpenAI Vision (GPT-4o)'}
                  </span>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                  <button
                    type="button"
                    onClick={() => handleSelectProvider('compare')}
                    className={`px-3 py-2 rounded-lg text-xs font-bold flex items-center justify-center gap-1.5 border transition ${
                      selectedAiProvider === 'compare'
                        ? 'bg-indigo-600 text-white border-indigo-600 shadow-xs ring-2 ring-indigo-200'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-100'
                    }`}
                  >
                    <span>⚖️</span>
                    <span>Compare Both</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => handleSelectProvider('muse_glimmer')}
                    className={`px-3 py-2 rounded-lg text-xs font-bold flex items-center justify-center gap-1.5 border transition ${
                      selectedAiProvider === 'muse_glimmer'
                        ? 'bg-purple-600 text-white border-purple-600 shadow-xs ring-2 ring-purple-200'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-100'
                    }`}
                  >
                    <span>🔬</span>
                    <span>Meta SAM 3.1</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => handleSelectProvider('openai')}
                    className={`px-3 py-2 rounded-lg text-xs font-bold flex items-center justify-center gap-1.5 border transition ${
                      selectedAiProvider === 'openai'
                        ? 'bg-teal-600 text-white border-teal-600 shadow-xs ring-2 ring-teal-200'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-100'
                    }`}
                  >
                    <span>⚡</span>
                    <span>OpenAI (GPT-4o)</span>
                  </button>
                </div>
              </div>

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
                <div className="bg-indigo-50 border border-indigo-200 rounded-xl p-4 space-y-2">
                  <div className="flex items-center gap-3">
                    <RefreshCw className="w-5 h-5 text-indigo-600 animate-spin flex-shrink-0" />
                    <div className="text-xs text-indigo-900">
                      <span className="font-bold block">Running 4-Stage AI Vision Pipeline...</span>
                      DB photos → morphological traits → global web vision → OpenAI Vision fallback.
                    </div>
                  </div>
                  <div className="grid grid-cols-4 gap-1.5 pt-2 border-t border-indigo-100 text-[10px]">
                    <div className="flex items-center gap-1.5 text-indigo-700 bg-white/70 px-2 py-1 rounded">
                      <span className="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span>
                      <span>1. DB Photos</span>
                    </div>
                    <div className="flex items-center gap-1.5 text-indigo-700 bg-white/70 px-2 py-1 rounded">
                      <span className="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span>
                      <span>2. Traits</span>
                    </div>
                    <div className="flex items-center gap-1.5 text-indigo-700 bg-white/70 px-2 py-1 rounded">
                      <span className="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span>
                      <span>3. Web Vision</span>
                    </div>
                    <div className="flex items-center gap-1.5 text-teal-700 bg-white/70 px-2 py-1 rounded">
                      <span className="w-1.5 h-1.5 rounded-full bg-teal-500 animate-pulse"></span>
                      <span>4. OpenAI</span>
                    </div>
                  </div>
                </div>
              )}


              {aiResult && !analyzing && (
                <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
                  {/* Provider Comparison View (when compare mode was selected or comparisonData returned) */}
                  {comparisonData?.providers && (
                    <div className="space-y-3 pb-3 border-b border-slate-200">
                      {/* Comparison Status Banner */}
                      <div className={`p-3 rounded-xl border text-xs flex items-start gap-2.5 ${
                        comparisonData.status === 'agreement'
                          ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
                          : comparisonData.status === 'disagreement'
                          ? 'bg-amber-50 border-amber-300 text-amber-900'
                          : comparisonData.status === 'single_provider_result'
                          ? 'bg-blue-50 border-blue-200 text-blue-900'
                          : 'bg-slate-100 border-slate-300 text-slate-800'
                      }`}>
                        {comparisonData.status === 'agreement' ? (
                          <ShieldCheck className="w-4 h-4 text-emerald-600 flex-shrink-0 mt-0.5" />
                        ) : comparisonData.status === 'disagreement' ? (
                          <AlertTriangle className="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" />
                        ) : (
                          <Sparkles className="w-4 h-4 text-indigo-600 flex-shrink-0 mt-0.5" />
                        )}
                        <div className="flex-1">
                          <div className="font-bold flex items-center justify-between">
                            <span>
                              {comparisonData.status === 'agreement' && '✓ Models Agree on Species Identification'}
                              {comparisonData.status === 'disagreement' && '⚠️ Model Conflict — Human Verification Required'}
                              {comparisonData.status === 'single_provider_result' && 'ℹ️ Single Provider Succeeded'}
                              {comparisonData.status === 'both_failed' && '❌ Both Providers Failed'}
                              {comparisonData.status === 'insufficient_evidence' && '⚠️ Insufficient Evidence from Providers'}
                            </span>
                            <span className="text-[10px] font-mono uppercase px-2 py-0.5 rounded bg-white/70 border border-black/10">
                              {comparisonData.status}
                            </span>
                          </div>
                          <p className="text-[11px] mt-0.5 opacity-90">
                            {comparisonData.status === 'agreement' && 'Both Meta Muse Glimmer and OpenAI Vision identified the same species. Review suggestions below.'}
                            {comparisonData.status === 'disagreement' && 'The AI models suggest different species. Review both analyses below and choose the correct suggestion, or enter ground truth manually.'}
                            {comparisonData.status === 'single_provider_result' && 'One model succeeded while the other encountered an issue or timeout.'}
                            {comparisonData.comparison?.warning_message && ` ${comparisonData.comparison.warning_message}`}
                          </p>

                          {/* Shared Features between models */}
                          {comparisonData.comparison?.shared_features && comparisonData.comparison.shared_features.length > 0 && (
                            <div className="mt-2 pt-2 border-t border-black/10 flex items-center gap-1.5 flex-wrap">
                              <span className="text-[10px] font-bold">Observed by both models:</span>
                              {comparisonData.comparison.shared_features.map((feat: string, idx: number) => (
                                <span key={idx} className="text-[10px] px-1.5 py-0.5 bg-white rounded border border-black/10 font-medium">
                                  {feat}
                                </span>
                              ))}
                            </div>
                          )}
                        </div>
                      </div>

                      {/* Dual Provider Cards Grid */}
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        {/* Meta SAM 3.1 Card */}
                        {comparisonData.providers.muse_glimmer && (
                          <div className={`p-3 rounded-xl border flex flex-col justify-between transition ${
                            comparisonData.providers.muse_glimmer.status === 'success'
                              ? 'bg-purple-50/50 border-purple-200'
                              : 'bg-slate-50 border-slate-200 opacity-60'
                          }`}>
                            <div className="space-y-2">
                              <div className="flex items-center justify-between">
                                <div className="flex items-center gap-1.5">
                                  <span className="text-sm">🔬</span>
                                  <span className="text-xs font-bold text-purple-900">
                                    {comparisonData.providers.muse_glimmer.model_identifier === 'sam-3.1' || comparisonData.providers.muse_glimmer.model?.includes('SAM')
                                      ? 'Meta SAM 3.1 (Segmentation)'
                                      : 'Meta AI'}
                                  </span>
                                </div>
                                <div className="flex items-center gap-1.5">
                                  {comparisonData.providers.muse_glimmer.duration_ms > 0 && (
                                    <span className="text-[10px] font-mono text-purple-700 bg-purple-100 px-1.5 py-0.5 rounded">
                                      {comparisonData.providers.muse_glimmer.duration_ms}ms
                                    </span>
                                  )}
                                  <span className="text-[11px] font-bold font-mono text-purple-800 bg-purple-100 px-2 py-0.5 rounded-full">
                                    {comparisonData.providers.muse_glimmer.status === 'success'
                                      ? 'Segmented'
                                      : 'Unavailable'}
                                  </span>
                                </div>
                              </div>

                              {comparisonData.providers.muse_glimmer.status === 'success' ? (
                                <>
                                  <div>
                                    <div className="text-sm font-bold text-slate-900 flex items-center gap-1.5">
                                      <span className="w-2 h-2 rounded-full bg-emerald-500"></span>
                                      {comparisonData.providers.muse_glimmer.suggested_species?.kiribati_name || 'Fish Specimen Isolated'}
                                    </div>
                                    <div className="text-xs text-slate-600 font-medium">
                                      {comparisonData.providers.muse_glimmer.suggested_species?.common_name || 'Visual Concept: fish'}
                                    </div>
                                    <div className="text-[11px] italic text-slate-400 font-serif">
                                      {comparisonData.providers.muse_glimmer.scientific_name || 'Actinopterygii'}
                                    </div>
                                  </div>

                                  {comparisonData.providers.muse_glimmer.reasoning && (
                                    <p className="text-[11px] text-purple-950 bg-white/70 p-2 rounded-lg border border-purple-100">
                                      <span className="font-semibold text-purple-900">SAM Evidence: </span>
                                      {comparisonData.providers.muse_glimmer.reasoning}
                                    </p>
                                  )}

                                  {comparisonData.providers.muse_glimmer.visible_features?.length > 0 && (
                                    <div className="text-[10px] text-slate-600">
                                      <span className="font-semibold text-purple-900">Segmentation traits: </span>
                                      {comparisonData.providers.muse_glimmer.visible_features.slice(0, 3).join(', ')}
                                    </div>
                                  )}
                                </>
                              ) : (
                                <div className="text-xs text-slate-500 italic py-2">
                                  SAM unconfigured or unavailable: {comparisonData.providers.muse_glimmer.error || 'Failed'}
                                </div>
                              )}
                            </div>

                            {comparisonData.providers.muse_glimmer.status === 'success' && (
                              <button
                                type="button"
                                onClick={() => handleApplyProviderSuggestion(comparisonData.providers.muse_glimmer)}
                                className="mt-3 w-full py-1.5 px-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs font-bold transition flex items-center justify-center gap-1 shadow-xs"
                              >
                                <span>Use SAM Evidence</span>
                              </button>
                            )}
                          </div>
                        )}

                        {/* OpenAI Vision Card */}
                        {comparisonData.providers.openai && (
                          <div className={`p-3 rounded-xl border flex flex-col justify-between transition ${
                            comparisonData.providers.openai.status === 'success'
                              ? 'bg-teal-50/50 border-teal-200'
                              : 'bg-slate-50 border-slate-200 opacity-60'
                          }`}>
                            <div className="space-y-2">
                              <div className="flex items-center justify-between">
                                <div className="flex items-center gap-1.5">
                                  <span className="text-sm">⚡</span>
                                  <span className="text-xs font-bold text-teal-900">OpenAI Vision (GPT-4o)</span>
                                </div>
                                <div className="flex items-center gap-1.5">
                                  {comparisonData.providers.openai.duration_ms > 0 && (
                                    <span className="text-[10px] font-mono text-teal-700 bg-teal-100 px-1.5 py-0.5 rounded">
                                      {comparisonData.providers.openai.duration_ms}ms
                                    </span>
                                  )}
                                  <span className="text-[11px] font-bold font-mono text-teal-800 bg-teal-100 px-2 py-0.5 rounded-full">
                                    {((comparisonData.providers.openai.confidence || 0) * 100).toFixed(0)}%
                                  </span>
                                </div>
                              </div>

                              {comparisonData.providers.openai.status === 'success' ? (
                                <>
                                  <div>
                                    <div className="text-sm font-bold text-slate-900">
                                      {comparisonData.providers.openai.suggested_species?.kiribati_name || 'Unknown'}
                                    </div>
                                    <div className="text-xs text-slate-600 font-medium">
                                      {comparisonData.providers.openai.suggested_species?.common_name || ''}
                                    </div>
                                    <div className="text-[11px] italic text-slate-400 font-serif">
                                      {comparisonData.providers.openai.scientific_name}
                                    </div>
                                  </div>

                                  {comparisonData.providers.openai.reasoning && (
                                    <p className="text-[11px] text-teal-950 bg-white/70 p-2 rounded-lg border border-teal-100">
                                      <span className="font-semibold text-teal-900">Reasoning: </span>
                                      {comparisonData.providers.openai.reasoning}
                                    </p>
                                  )}

                                  {comparisonData.providers.openai.visible_features?.length > 0 && (
                                    <div className="text-[10px] text-slate-600">
                                      <span className="font-semibold text-teal-900">Visible traits: </span>
                                      {comparisonData.providers.openai.visible_features.slice(0, 3).join(', ')}
                                    </div>
                                  )}
                                </>
                              ) : (
                                <div className="text-xs text-slate-500 italic py-2">
                                  Provider unconfigured or unavailable: {comparisonData.providers.openai.error || 'Failed'}
                                </div>
                              )}
                            </div>

                            {comparisonData.providers.openai.status === 'success' && (
                              <button
                                type="button"
                                onClick={() => handleApplyProviderSuggestion(comparisonData.providers.openai)}
                                className="mt-3 w-full py-1.5 px-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-xs font-bold transition flex items-center justify-center gap-1 shadow-xs"
                              >
                                <span>Use OpenAI Suggestion</span>
                              </button>
                            )}
                          </div>
                        )}
                      </div>
                    </div>
                  )}

                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2 flex-wrap">
                      <Sparkles className="w-4 h-4 text-indigo-600 flex-shrink-0" />
                      <span className="text-xs font-bold uppercase tracking-wider text-slate-800">
                        {comparisonData?.providers ? 'Primary / Active Suggestion:' : 'AI Vision Suggestion:'}
                      </span>
                      {selectedAiProvider === 'muse_glimmer' ? (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-700 border border-purple-200">
                          🔬 Meta SAM 3.1 (Segmentation)
                        </span>
                      ) : selectedAiProvider === 'openai' ? (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-teal-100 text-teal-800 border border-teal-200">
                          ⚡ OpenAI Vision (GPT-4o)
                        </span>
                      ) : (aiResult.pipeline_stage === 'db_image_match' || aiResult.source === 'db_image_match') ? (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 flex items-center gap-1">
                          <Database className="w-3 h-3 text-emerald-600" />
                          Stage 1: DB Photo Match
                        </span>
                      ) : (aiResult.pipeline_stage === 'morphological_match' || aiResult.source === 'morphological_match') ? (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800 border border-blue-200 flex items-center gap-1">
                          <Sparkles className="w-3 h-3 text-blue-600" />
                          Stage 2: Morphological Traits Match
                        </span>
                      ) : (aiResult.pipeline_stage === 'web_vision' || aiResult.source === 'web_vision') ? (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800 border border-purple-200 flex items-center gap-1">
                          <Globe className="w-3 h-3 text-purple-600" />
                          Stage 3: Web & Global Vision
                        </span>
                      ) : (aiResult.pipeline_stage === 'openai_vision' || aiResult.source === 'openai_vision') ? (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-teal-100 text-teal-800 border border-teal-200 flex items-center gap-1">
                          <Sparkles className="w-3 h-3 text-teal-600" />
                          Stage 4: OpenAI Vision ({aiResult.openai_model?.model ?? 'gpt-4o'})
                        </span>
                      ) : (aiResult.model?.includes('SAM') || aiResult.model?.includes('sam-3.1')) ? (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-700 border border-purple-200">
                          🔬 Meta SAM 3.1
                        </span>
                      ) : aiResult.model?.includes('Muse Glimmer') ? (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-700 border border-purple-200">
                          ✨ Meta Muse Glimmer Vision
                        </span>
                      ) : (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-medium bg-slate-100 text-slate-600 border border-slate-200">
                          Morphological Knowledge Base
                        </span>
                      )}
                    </div>
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-mono font-bold bg-indigo-100 text-indigo-700">
                      {(aiResult.confidence * 100).toFixed(0)}% Confidence
                    </span>
                  </div>

                  {/* 4-Stage Pipeline Progression Audit Bar */}
                  {aiResult.pipeline?.stages && (
                    <div className="flex items-center gap-1.5 text-[10px] bg-slate-100 p-2 rounded-lg border border-slate-200 overflow-x-auto">
                      <span className="font-semibold text-slate-600 flex-shrink-0">Pipeline:</span>
                      <span className={`px-2 py-0.5 rounded flex-shrink-0 ${
                        aiResult.pipeline.stages.db_image_match?.status === 'completed'
                          ? 'bg-emerald-100 text-emerald-800 font-bold border border-emerald-300'
                          : (aiResult.pipeline.stages.db_image_match?.attempted ? 'bg-slate-200 text-slate-500' : 'text-slate-400')
                      }`}>
                        1. DB Photos {aiResult.pipeline.stages.db_image_match?.status === 'completed' ? '✓' : ''}
                      </span>
                      <span className="text-slate-400">→</span>
                      <span className={`px-2 py-0.5 rounded flex-shrink-0 ${
                        aiResult.pipeline.stages.morphological_match?.status === 'completed'
                          ? 'bg-blue-100 text-blue-800 font-bold border border-blue-300'
                          : (aiResult.pipeline.stages.morphological_match?.attempted ? 'bg-slate-200 text-slate-500' : 'text-slate-400')
                      }`}>
                        2. Traits {aiResult.pipeline.stages.morphological_match?.status === 'completed' ? '✓' : ''}
                      </span>
                      <span className="text-slate-400">→</span>
                      <span className={`px-2 py-0.5 rounded flex-shrink-0 ${
                        aiResult.pipeline.stages.web_vision?.status === 'completed'
                          ? 'bg-purple-100 text-purple-800 font-bold border border-purple-300'
                          : (aiResult.pipeline.stages.web_vision?.attempted ? 'bg-slate-200 text-slate-500' : 'text-slate-400')
                      }`}>
                        3. Web Vision {aiResult.pipeline.stages.web_vision?.status === 'completed' ? '✓' : ''}
                      </span>
                      <span className="text-slate-400">→</span>
                      <span className={`px-2 py-0.5 rounded flex-shrink-0 ${
                        aiResult.pipeline.stages.openai_vision?.status === 'completed'
                          ? 'bg-teal-100 text-teal-800 font-bold border border-teal-300'
                          : (aiResult.pipeline.stages.openai_vision?.attempted ? 'bg-slate-200 text-slate-500' : 'text-slate-400')
                      }`}>
                        4. OpenAI {aiResult.pipeline.stages.openai_vision?.status === 'completed' ? '✓' : ''}
                      </span>
                    </div>
                  )}

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

                    {aiResult.meta_model?.glimmer_vision?.reasoning && (
                      <div className="mt-2 text-[11px] text-purple-800 bg-purple-50 p-2 rounded border border-purple-100">
                        <span className="font-semibold text-purple-900">Muse Glimmer Reasoning: </span>
                        {aiResult.meta_model.glimmer_vision.reasoning}
                      </div>
                    )}

                    {aiResult.openai_model?.reasoning && (
                      <div className="mt-2 text-[11px] text-teal-800 bg-teal-50 p-2 rounded border border-teal-100">
                        <span className="font-semibold text-teal-900">OpenAI Vision Reasoning: </span>
                        {aiResult.openai_model.reasoning}
                      </div>
                    )}

                    {/* Meta SAM 3.1 Segmentation Evidence Card */}
                    {aiResult.segmentation && (
                      <div className="mt-3 p-2.5 rounded-lg border bg-gradient-to-r from-purple-50/70 to-indigo-50/70 border-purple-200 space-y-2">
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-1.5">
                            <span className="text-sm">🔬</span>
                            <span className="text-xs font-bold text-purple-900">
                              Meta SAM 3.1 Visual Segmentation Evidence
                            </span>
                          </div>
                          {aiResult.segmentation.status === 'ok' ? (
                            <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 flex items-center gap-1">
                              ✓ {aiResult.segmentation.fish_detected ? 'Fish Confirmed' : 'No Fish Detected'}
                            </span>
                          ) : (
                            <span className="text-[10px] font-medium px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                              {aiResult.segmentation.status}
                            </span>
                          )}
                        </div>

                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 text-[11px]">
                          <div className="bg-white/80 p-2 rounded border border-purple-100">
                            <span className="text-[10px] text-slate-500 block">Objects Segmented:</span>
                            <span className="font-bold text-slate-800">
                              {aiResult.segmentation.object_count ?? 0} fish object(s)
                            </span>
                          </div>
                          <div className="bg-white/80 p-2 rounded border border-purple-100">
                            <span className="text-[10px] text-slate-500 block">Bounding Box:</span>
                            <span className="font-mono text-[10px] font-semibold text-purple-900">
                              {aiResult.segmentation.best_box
                                ? `[${aiResult.segmentation.best_box.x1}, ${aiResult.segmentation.best_box.y1}] → [${aiResult.segmentation.best_box.x2}, ${aiResult.segmentation.best_box.y2}]`
                                : 'None detected'}
                            </span>
                          </div>
                          <div className="bg-white/80 p-2 rounded border border-purple-100 col-span-2 sm:col-span-1">
                            <span className="text-[10px] text-slate-500 block">Contour Mask:</span>
                            <span className="font-semibold text-slate-800">
                              {aiResult.segmentation.best_box?.has_mask ? '✓ Mask present' : 'Bounding box only'}
                            </span>
                          </div>
                        </div>

                        {aiResult.segmentation_warning && (
                          <div className="text-[11px] text-amber-800 bg-amber-50 p-2 rounded border border-amber-200 flex items-center gap-1.5">
                            <AlertTriangle className="w-3.5 h-3.5 text-amber-700 flex-shrink-0" />
                            <span>{aiResult.segmentation_warning}</span>
                          </div>
                        )}
                      </div>
                    )}

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
