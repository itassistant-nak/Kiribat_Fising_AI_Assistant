'use client';

import React, { useState, useMemo, useEffect } from 'react';
import { Species, api } from '../lib/api';
import {
  Fish,
  Sparkles,
  Search,
  Filter,
  RotateCcw,
  AlertTriangle,
  ShieldAlert,
  ShieldCheck,
  CheckCircle2,
  ExternalLink,
  Layers,
  LayoutGrid,
  List,
  Eye,
  Camera,
  BookOpen,
  Edit3,
  X,
  Check,
  Plus,
  Trash2,
  Image as ImageIcon,
  Loader2,
} from 'lucide-react';

interface SpeciesCatalogProps {
  speciesList: Species[];
  loading: boolean;
  onRefresh: () => void;
  onIdentifySpecies?: (species?: Species) => void;
  onViewCatches?: (speciesId: number) => void;
}

export default function SpeciesCatalog({
  speciesList,
  loading,
  onRefresh,
  onIdentifySpecies,
  onViewCatches,
}: SpeciesCatalogProps) {
  const [searchTerm, setSearchTerm] = useState('');
  const [riskFilter, setRiskFilter] = useState<'all' | 'low' | 'medium' | 'high'>('all');
  const [aiFilter, setAiFilter] = useState<'all' | 'ai_trained'>('all');
  const [viewMode, setViewMode] = useState<'grid' | 'table'>('grid');

  // Species currently being edited in modal
  const [editingSpecies, setEditingSpecies] = useState<Species | null>(null);

  // Card AI analysis in progress
  const [cardAnalyzingId, setCardAnalyzingId] = useState<number | null>(null);

  const handleGenerateTraitsForCard = async (species: Species) => {
    if (!species.photo_url) return;
    setCardAnalyzingId(species.id);
    try {
      const res = await api.identifyFish({
        image_url: species.photo_url,
        hint: species.kiribati_name,
      });
      const traits = res.identification.visual_features;
      if (traits && traits.length > 0) {
        await api.updateSpecies(species.id, {
          visual_features: traits,
        });
        onRefresh();
      }
    } catch (err) {
      console.warn('Failed to auto-generate traits for card:', err);
    } finally {
      setCardAnalyzingId(null);
    }
  };

  // Filtered Species
  const filteredSpecies = useMemo(() => {
    return speciesList.filter((s) => {
      // Search matches
      const query = searchTerm.toLowerCase().trim();
      const matchesSearch =
        !query ||
        s.kiribati_name.toLowerCase().includes(query) ||
        s.english_name.toLowerCase().includes(query) ||
        s.scientific_name.toLowerCase().includes(query) ||
        (s.notes && s.notes.toLowerCase().includes(query)) ||
        (s.visual_features && s.visual_features.some((f) => f.toLowerCase().includes(query)));

      // Risk filter
      const matchesRisk = riskFilter === 'all' || s.ciguatera_risk === riskFilter;

      // AI filter
      const matchesAi = aiFilter === 'all' || s.ai_trained === true;

      return matchesSearch && matchesRisk && matchesAi;
    });
  }, [speciesList, searchTerm, riskFilter, aiFilter]);

  // Statistics
  const totalSpecies = speciesList.length;
  const totalCatches = speciesList.reduce((acc, s) => acc + (s.catches_count || 0), 0);
  const aiTrainedCount = speciesList.filter((s) => s.ai_trained).length;
  const highRiskCount = speciesList.filter((s) => s.ciguatera_risk === 'high' || s.ciguatera_risk === 'extreme').length;

  const handleResetFilters = () => {
    setSearchTerm('');
    setRiskFilter('all');
    setAiFilter('all');
  };

  const hasActiveFilters = Boolean(searchTerm) || riskFilter !== 'all' || aiFilter !== 'all';

  if (loading && speciesList.length === 0) {
    return (
      <div className="space-y-6">
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 animate-pulse">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="bg-white p-4 rounded-xl border border-slate-200 h-24"></div>
          ))}
        </div>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 animate-pulse">
          {[...Array(6)].map((_, i) => (
            <div key={i} className="bg-white rounded-xl border border-slate-200 h-80"></div>
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* 1. Header Overview & Summary Statistics */}
      <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-4 border-b border-slate-100">
          <div>
            <div className="flex items-center gap-2">
              <span className="p-2 rounded-xl bg-blue-100 text-blue-700">
                <BookOpen className="w-5 h-5" />
              </span>
              <div>
                <h2 className="text-base font-bold text-slate-900 tracking-tight">
                  Kiribati Marine Species Catalog & AI Vision Knowledge Base
                </h2>
                <p className="text-xs text-slate-500">
                  Stored taxonomy, authentic Kiribati vernacular names, Meta AI vision morphological traits, and ciguatera risks
                </p>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2">
            {onIdentifySpecies && (
              <button
                onClick={() => onIdentifySpecies()}
                className="flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold text-white bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 rounded-xl shadow-sm transition"
              >
                <Sparkles className="w-3.5 h-3.5 text-amber-300" />
                <span>Help AI Identify Fish (Upload Photo)</span>
              </button>
            )}
          </div>
        </div>

        {/* 4 Summary Metric Badges */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4">
          <div className="bg-slate-50 rounded-xl p-3 border border-slate-200/80">
            <span className="text-[11px] font-semibold uppercase text-slate-500 tracking-wider flex items-center gap-1">
              <Fish className="w-3.5 h-3.5 text-blue-600" />
              <span>Stored Species</span>
            </span>
            <div className="mt-1 flex items-baseline gap-2">
              <span className="text-2xl font-black text-slate-900">{totalSpecies}</span>
              <span className="text-xs text-slate-500">cataloged</span>
            </div>
          </div>

          <div className="bg-slate-50 rounded-xl p-3 border border-slate-200/80">
            <span className="text-[11px] font-semibold uppercase text-slate-500 tracking-wider flex items-center gap-1">
              <Sparkles className="w-3.5 h-3.5 text-indigo-600" />
              <span>AI Vision Models</span>
            </span>
            <div className="mt-1 flex items-baseline gap-2">
              <span className="text-2xl font-black text-indigo-600">{aiTrainedCount}</span>
              <span className="text-xs text-slate-500">active traits</span>
            </div>
          </div>

          <div className="bg-slate-50 rounded-xl p-3 border border-slate-200/80">
            <span className="text-[11px] font-semibold uppercase text-slate-500 tracking-wider flex items-center gap-1">
              <Layers className="w-3.5 h-3.5 text-emerald-600" />
              <span>Catch Records</span>
            </span>
            <div className="mt-1 flex items-baseline gap-2">
              <span className="text-2xl font-black text-slate-900">{totalCatches}</span>
              <span className="text-xs text-slate-500">submissions</span>
            </div>
          </div>

          <div className="bg-slate-50 rounded-xl p-3 border border-slate-200/80">
            <span className="text-[11px] font-semibold uppercase text-slate-500 tracking-wider flex items-center gap-1">
              <ShieldAlert className="w-3.5 h-3.5 text-red-600" />
              <span>High CFP Risk</span>
            </span>
            <div className="mt-1 flex items-baseline gap-2">
              <span className="text-2xl font-black text-red-600">{highRiskCount}</span>
              <span className="text-xs text-slate-500">monitored</span>
            </div>
          </div>
        </div>
      </div>

      {/* 2. Search & Filter Toolbar */}
      <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm space-y-3">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
          {/* Keyword Search */}
          <div className="relative flex-1">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
            <input
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Search by Kiribati name (e.g. Te Bwere), English name, scientific name, or traits..."
              className="w-full pl-9 pr-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 placeholder:text-slate-400"
            />
          </div>

          {/* View Mode Toggle */}
          <div className="flex items-center gap-1 bg-slate-100 p-1 rounded-lg">
            <button
              onClick={() => setViewMode('grid')}
              className={`p-1.5 rounded text-xs font-medium transition ${
                viewMode === 'grid'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-500 hover:text-slate-900'
              }`}
              title="Grid Card View"
            >
              <LayoutGrid className="w-4 h-4" />
            </button>
            <button
              onClick={() => setViewMode('table')}
              className={`p-1.5 rounded text-xs font-medium transition ${
                viewMode === 'table'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-500 hover:text-slate-900'
              }`}
              title="Compact Table View"
            >
              <List className="w-4 h-4" />
            </button>
          </div>
        </div>

        {/* Filter Pills */}
        <div className="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-slate-100 text-xs">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-slate-400 text-[11px] font-semibold uppercase tracking-wider flex items-center gap-1">
              <Filter className="w-3 h-3" /> Ciguatera Risk:
            </span>
            {(['all', 'low', 'medium', 'high'] as const).map((r) => (
              <button
                key={r}
                onClick={() => setRiskFilter(r)}
                className={`px-2.5 py-1 rounded-md text-xs font-semibold capitalize transition ${
                  riskFilter === r
                    ? r === 'high'
                      ? 'bg-red-600 text-white'
                      : r === 'medium'
                      ? 'bg-amber-600 text-white'
                      : r === 'low'
                      ? 'bg-emerald-600 text-white'
                      : 'bg-blue-600 text-white'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                {r === 'all' ? 'All Risks' : `${r} Risk`}
              </button>
            ))}

            <div className="h-4 w-px bg-slate-200 mx-1"></div>

            <button
              onClick={() => setAiFilter(aiFilter === 'all' ? 'ai_trained' : 'all')}
              className={`flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold transition ${
                aiFilter === 'ai_trained'
                  ? 'bg-indigo-600 text-white shadow-xs'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              <Sparkles className="w-3 h-3 text-amber-300" />
              <span>AI Vision Trained ({aiTrainedCount})</span>
            </button>
          </div>

          <div className="flex items-center gap-2 text-slate-500">
            <span>
              Showing <strong className="text-slate-900">{filteredSpecies.length}</strong> of{' '}
              {totalSpecies} fish
            </span>
            {hasActiveFilters && (
              <button
                onClick={handleResetFilters}
                className="flex items-center gap-1 text-blue-600 hover:text-blue-800 font-medium px-2 py-0.5 rounded hover:bg-blue-50 transition"
              >
                <RotateCcw className="w-3 h-3" />
                Reset
              </button>
            )}
          </div>
        </div>
      </div>

      {/* 3. Empty State */}
      {filteredSpecies.length === 0 && (
        <div className="bg-white rounded-2xl border border-dashed border-slate-300 p-12 text-center">
          <div className="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
            <Fish className="w-6 h-6" />
          </div>
          <h3 className="text-sm font-bold text-slate-900">No fish species match criteria</h3>
          <p className="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
            Try adjusting your search terms or clearing the ciguatera risk filter.
          </p>
          <button
            onClick={handleResetFilters}
            className="mt-4 px-3.5 py-1.5 text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition"
          >
            Clear All Filters
          </button>
        </div>
      )}

      {/* 4. Grid Card View */}
      {viewMode === 'grid' && filteredSpecies.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {filteredSpecies.map((species) => {
            const hasCatches = (species.catches_count || 0) > 0;
            const hasAdvisories = (species.advisory_zones_count || 0) > 0;

            return (
              <div
                key={species.id}
                className="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm hover:shadow-md transition flex flex-col group relative"
              >
                {/* Photo & Badge Overlays */}
                <div className="relative h-48 bg-slate-100 overflow-hidden">
                  {species.photo_url ? (
                    <img
                      src={species.photo_url}
                      alt={species.english_name}
                      className="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                    />
                  ) : (
                    <div className="w-full h-full flex flex-col items-center justify-center text-slate-400">
                      <Fish className="w-12 h-12 stroke-[1.2]" />
                      <span className="text-[11px] mt-1 font-mono">No specimen photo</span>
                    </div>
                  )}

                  {/* Gradient Overlay */}
                  <div className="absolute inset-0 bg-gradient-to-t from-slate-900/80 via-slate-900/20 to-transparent"></div>

                  {/* Top Badges: Ciguatera Risk, AI Status, and Edit Button */}
                  <div className="absolute top-3 left-3 right-3 flex items-center justify-between gap-2">
                    <span
                      className={`inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full uppercase tracking-wider shadow-sm backdrop-blur-md ${
                        species.ciguatera_risk === 'high' || species.ciguatera_risk === 'extreme'
                          ? 'bg-red-500/90 text-white'
                          : species.ciguatera_risk === 'medium'
                          ? 'bg-amber-500/90 text-white'
                          : 'bg-emerald-500/90 text-white'
                      }`}
                    >
                      {species.ciguatera_risk === 'high' || species.ciguatera_risk === 'extreme' ? (
                        <ShieldAlert className="w-3.5 h-3.5" />
                      ) : (
                        <ShieldCheck className="w-3.5 h-3.5" />
                      )}
                      <span>CFP: {species.ciguatera_risk}</span>
                    </span>

                    <div className="flex items-center gap-1.5">
                      {species.ai_trained && (
                        <span className="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full bg-slate-900/80 text-amber-300 border border-amber-400/30 backdrop-blur-md shadow-sm">
                          <Sparkles className="w-3.5 h-3.5 text-amber-400" />
                          <span>AI Trained</span>
                        </span>
                      )}

                      {/* CHANGE PHOTO & EXTRACT TRAITS BUTTON (Top Overlay) */}
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          setEditingSpecies(species);
                        }}
                        className="inline-flex items-center gap-1 text-[11px] font-semibold px-2.5 py-1 rounded-full bg-slate-900/80 hover:bg-slate-900 text-white border border-white/20 backdrop-blur-md transition shadow-sm hover:scale-105"
                        title="Change specimen photo and auto-generate AI morphological traits"
                      >
                        <Camera className="w-3.5 h-3.5 text-amber-300" />
                        <span>Change Photo</span>
                      </button>

                      {/* EDIT SPECIES BUTTON (Top Overlay) */}
                      <button
                        type="button"
                        onClick={() => setEditingSpecies(species)}
                        className="p-1.5 rounded-full bg-slate-900/80 hover:bg-slate-900 text-white border border-white/20 backdrop-blur-md transition shadow-sm hover:scale-105"
                        title="Edit species details & AI morphological traits"
                      >
                        <Edit3 className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>

                  {/* Bottom Text Over Photo: CLICKABLE SPECIES TITLE LINK TO IDENTIFY WITH AI */}
                  <div className="absolute bottom-3 left-3 right-3 text-white">
                    <button
                      type="button"
                      onClick={() => onIdentifySpecies && onIdentifySpecies(species)}
                      className="group/title flex items-center justify-between gap-2 text-left hover:text-amber-300 transition focus:outline-none w-full"
                      title="Click on species to identify / verify with Meta AI Vision"
                    >
                      <span className="text-lg font-black tracking-tight drop-shadow-sm group-hover/title:underline">
                        {species.kiribati_name}
                      </span>
                      <span className="text-[11px] font-semibold text-amber-300 bg-amber-500/20 border border-amber-400/30 px-2 py-0.5 rounded-full backdrop-blur-xs flex items-center gap-1 group-hover/title:bg-amber-500/40">
                        <Sparkles className="w-3 h-3 text-amber-300" />
                        <span>Identify with AI</span>
                      </span>
                    </button>

                    <div className="text-xs text-slate-200 font-medium drop-shadow-xs flex items-center justify-between gap-1 mt-0.5">
                      <button
                        type="button"
                        onClick={() => onIdentifySpecies && onIdentifySpecies(species)}
                        className="hover:text-amber-300 transition text-left flex items-center gap-1 truncate"
                        title="Click to identify this species with Meta AI Vision"
                      >
                        <span>{species.english_name}</span>
                        <span>•</span>
                        <span className="italic text-slate-300">{species.scientific_name}</span>
                      </button>
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          setEditingSpecies(species);
                        }}
                        className="text-[11px] text-white/90 hover:text-white flex items-center gap-1 shrink-0 ml-1 bg-black/40 hover:bg-black/60 px-2 py-0.5 rounded transition"
                        title="Edit this species"
                      >
                        <Edit3 className="w-3 h-3" />
                        <span>Edit</span>
                      </button>
                    </div>
                  </div>
                </div>

                {/* Content Body */}
                <div className="p-4 space-y-3 flex-1 flex flex-col justify-between">
                  <div className="space-y-2.5">
                    {/* Visual Traits Breakdown */}
                    <div>
                      <div className="flex items-center justify-between mb-1.5">
                        <div className="text-[11px] font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1">
                          <Eye className="w-3 h-3 text-indigo-500" />
                          <span>AI Morphological Traits</span>
                          {species.visual_features && species.visual_features.length > 0 && (
                            <span className="ml-1 text-[10px] font-bold px-1.5 py-0.2 bg-indigo-50 text-indigo-700 border border-indigo-200/80 rounded-full">
                              {species.visual_features.length}
                            </span>
                          )}
                        </div>

                        {cardAnalyzingId === species.id ? (
                          <span className="flex items-center gap-1 text-[10px] text-indigo-600 font-semibold animate-pulse">
                            <Loader2 className="w-3 h-3 animate-spin" />
                            <span>Analyzing...</span>
                          </span>
                        ) : species.photo_url ? (
                          <button
                            type="button"
                            onClick={() => handleGenerateTraitsForCard(species)}
                            className="text-[10px] text-indigo-600 hover:text-indigo-800 font-semibold flex items-center gap-0.5 hover:underline"
                            title="Re-analyze current specimen photo to refresh AI morphological traits"
                          >
                            <Sparkles className="w-2.5 h-2.5 text-amber-500" />
                            <span>Re-Analyze</span>
                          </button>
                        ) : null}
                      </div>

                      {species.visual_features && species.visual_features.length > 0 ? (
                        <ul className="space-y-1">
                          {species.visual_features.slice(0, 3).map((feat, idx) => (
                            <li
                              key={idx}
                              className="text-xs text-slate-600 flex items-start gap-1.5 leading-relaxed bg-slate-50 p-1.5 rounded-lg border border-slate-100"
                            >
                              <span className="w-1.5 h-1.5 rounded-full bg-indigo-500 mt-1.5 flex-shrink-0"></span>
                              <span>{feat}</span>
                            </li>
                          ))}
                          {species.visual_features.length > 3 && (
                            <p className="text-[10px] text-slate-400 italic pl-1">
                              +{species.visual_features.length - 3} more traits analyzed
                            </p>
                          )}
                        </ul>
                      ) : (
                        <div className="bg-indigo-50/50 border border-indigo-100 rounded-xl p-2.5 text-center space-y-1.5">
                          <p className="text-[11px] text-slate-600 leading-tight">
                            No traits saved. Analyze photo with Meta AI Vision:
                          </p>
                          <button
                            type="button"
                            onClick={() => handleGenerateTraitsForCard(species)}
                            disabled={cardAnalyzingId === species.id || !species.photo_url}
                            className="w-full py-1.5 px-2 bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-700 hover:to-blue-700 text-white rounded-lg font-bold text-xs shadow-xs flex items-center justify-center gap-1.5 transition disabled:opacity-50"
                          >
                            {cardAnalyzingId === species.id ? (
                              <>
                                <Loader2 className="w-3 h-3 animate-spin" />
                                <span>Analyzing Picture...</span>
                              </>
                            ) : (
                              <>
                                <Sparkles className="w-3 h-3 text-amber-300" />
                                <span>Generate AI Traits from Picture</span>
                              </>
                            )}
                          </button>
                        </div>
                      )}
                    </div>

                    {/* Ciguatera Warning */}
                    {species.ciguatera_warning && (
                      <div className="text-[11px] text-amber-800 bg-amber-50/80 border border-amber-200/70 rounded-lg p-2 flex items-start gap-1.5 leading-relaxed">
                        <AlertTriangle className="w-3.5 h-3.5 text-amber-600 flex-shrink-0 mt-0.5" />
                        <span>{species.ciguatera_warning}</span>
                      </div>
                    )}
                  </div>

                  {/* Footer Metrics & Actions */}
                  <div className="pt-3 border-t border-slate-100 space-y-2">
                    <div className="flex items-center justify-between text-xs text-slate-500">
                      <span className="flex items-center gap-1">
                        <Layers className="w-3.5 h-3.5 text-slate-400" />
                        <strong>{species.catches_count || 0}</strong> catches recorded
                      </span>

                      {hasAdvisories && (
                        <span className="text-[11px] font-bold text-red-600 bg-red-50 border border-red-200 px-2 py-0.5 rounded-full">
                          {species.advisory_zones_count} Advisory Zone
                        </span>
                      )}
                    </div>

                    <div className="grid grid-cols-3 gap-1.5 pt-1">
                      {/* 1. Identify with AI Button */}
                      {onIdentifySpecies && (
                        <button
                          onClick={() => onIdentifySpecies(species)}
                          className="flex items-center justify-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-lg transition"
                          title="Run Meta AI vision identification for this species"
                        >
                          <Sparkles className="w-3 h-3 text-indigo-500" />
                          <span>Identify AI</span>
                        </button>
                      )}

                      {/* 2. Edit Species Button */}
                      <button
                        onClick={() => setEditingSpecies(species)}
                        className="flex items-center justify-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-lg transition shadow-2xs"
                        title="Edit Kiribati name, English name, CFP risk, and photo"
                      >
                        <Edit3 className="w-3 h-3 text-slate-500" />
                        <span>Edit</span>
                      </button>

                      {/* 3. View Catches Button */}
                      {onViewCatches && (
                        <button
                          onClick={() => onViewCatches(species.id)}
                          className="flex items-center justify-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 rounded-lg transition"
                          title="Filter catches dashboard by this species"
                        >
                          <Fish className="w-3 h-3 text-slate-500" />
                          <span>Catches</span>
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* 5. Compact Table View */}
      {viewMode === 'table' && filteredSpecies.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50/80 border-b border-slate-200 text-slate-500 font-semibold uppercase tracking-wider">
                  <th className="py-3 px-4">Specimen Photo</th>
                  <th className="py-3 px-4">Kiribati Name (Click to Identify)</th>
                  <th className="py-3 px-4">English & Scientific Name</th>
                  <th className="py-3 px-4">CFP Toxicity Risk</th>
                  <th className="py-3 px-4">AI Vision Status</th>
                  <th className="py-3 px-4">Catches</th>
                  <th className="py-3 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredSpecies.map((species) => (
                  <tr key={species.id} className="hover:bg-slate-50/60 transition">
                    <td className="py-3 px-4">
                      <button
                        type="button"
                        onClick={() => setEditingSpecies(species)}
                        className="w-12 h-12 rounded-lg bg-slate-100 overflow-hidden border border-slate-200 relative group/thumb block text-left"
                        title="Click to change photo and update AI morphological traits"
                      >
                        {species.photo_url ? (
                          <img
                            src={species.photo_url}
                            alt={species.english_name}
                            className="w-full h-full object-cover group-hover/thumb:scale-110 transition duration-200"
                          />
                        ) : (
                          <div className="w-full h-full flex items-center justify-center text-slate-400">
                            <Fish className="w-5 h-5" />
                          </div>
                        )}
                        <div className="absolute inset-0 bg-slate-900/50 opacity-0 group-hover/thumb:opacity-100 transition flex items-center justify-center">
                          <Camera className="w-4 h-4 text-white" />
                        </div>
                      </button>
                    </td>

                    <td className="py-3 px-4">
                      {/* Clickable Kiribati Name link to identify with AI */}
                      <button
                        type="button"
                        onClick={() => onIdentifySpecies && onIdentifySpecies(species)}
                        className="text-left font-bold text-slate-900 text-sm hover:text-indigo-600 transition flex items-center gap-1.5"
                        title="Click to identify with Meta AI Vision"
                      >
                        <span>{species.kiribati_name}</span>
                        <Sparkles className="w-3 h-3 text-indigo-500" />
                      </button>
                      <span className="text-[10px] text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded font-medium">
                        Vernacular
                      </span>
                    </td>

                    <td className="py-3 px-4">
                      <div className="font-semibold text-slate-800">{species.english_name}</div>
                      <div className="text-[11px] text-slate-500 italic">{species.scientific_name}</div>
                    </td>

                    <td className="py-3 px-4">
                      <span
                        className={`inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-0.5 rounded-full uppercase ${
                          species.ciguatera_risk === 'high' || species.ciguatera_risk === 'extreme'
                            ? 'bg-red-100 text-red-800 border border-red-200'
                            : species.ciguatera_risk === 'medium'
                            ? 'bg-amber-100 text-amber-800 border border-amber-200'
                            : 'bg-emerald-100 text-emerald-800 border border-emerald-200'
                        }`}
                      >
                        {species.ciguatera_risk}
                      </span>
                    </td>

                    <td className="py-3 px-4">
                      {species.ai_trained ? (
                        <div className="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-full">
                          <Sparkles className="w-3 h-3 text-amber-500" />
                          <span>Trained Model</span>
                        </div>
                      ) : (
                        <span className="text-slate-400 text-[11px]">Standard Catalog</span>
                      )}
                    </td>

                    <td className="py-3 px-4">
                      <span className="font-bold text-slate-900">{species.catches_count || 0}</span>
                      <span className="text-slate-500 ml-1">catches</span>
                    </td>

                    <td className="py-3 px-4 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        {onIdentifySpecies && (
                          <button
                            onClick={() => onIdentifySpecies(species)}
                            className="px-2.5 py-1 text-xs font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-lg transition"
                            title="Run AI vision identification"
                          >
                            Identify AI
                          </button>
                        )}
                        <button
                          onClick={() => setEditingSpecies(species)}
                          className="px-2.5 py-1 text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-lg transition"
                          title="Edit species details"
                        >
                          Edit
                        </button>
                        {onViewCatches && (
                          <button
                            onClick={() => onViewCatches(species.id)}
                            className="px-2.5 py-1 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 rounded-lg transition"
                          >
                            Catches
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* 6. EDIT SPECIES MODAL */}
      {editingSpecies && (
        <EditSpeciesModal
          species={editingSpecies}
          isOpen={Boolean(editingSpecies)}
          onClose={() => setEditingSpecies(null)}
          onSaved={() => {
            setEditingSpecies(null);
            onRefresh();
          }}
        />
      )}
    </div>
  );
}

// =========================================================================
// Specimen Presets for Kiribati Marine Species
// =========================================================================
const SPECIMEN_PRESETS = [
  {
    name: 'Paddletail Snapper',
    kiribati_name: 'Te Bwere',
    scientific_name: 'Lutjanus gibbus',
    url: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
    hint: 'Te Bwere',
  },
  {
    name: 'Yellowfin Tuna',
    kiribati_name: 'Te Ingimea',
    scientific_name: 'Thunnus albacares',
    url: 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?w=700&q=80',
    hint: 'Te Ingimea',
  },
  {
    name: 'Titan Triggerfish',
    kiribati_name: 'Te Okaoka',
    scientific_name: 'Balistoides viridescens',
    url: 'https://images.unsplash.com/photo-1576675466969-38eeae4b41f6?w=700&q=80',
    hint: 'Te Okaoka',
  },
  {
    name: 'Blacktip Grouper',
    kiribati_name: 'Te Kuau',
    scientific_name: 'Epinephelus fasciatus',
    url: 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=700&q=80',
    hint: 'Te Kuau',
  },
  {
    name: 'Skipjack Tuna',
    kiribati_name: 'Te Atun',
    scientific_name: 'Katsuwonus pelamis',
    url: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=700&q=80',
    hint: 'Te Atun',
  },
  {
    name: 'Bonefish',
    kiribati_name: 'Te Ikari',
    scientific_name: 'Albula glossodonta',
    url: 'https://images.unsplash.com/photo-1522069169874-c58ec4b76be5?auto=format&fit=crop&w=700&q=80',
    hint: 'Te Ikari',
  },
  {
    name: 'Yellowfin Goatfish',
    kiribati_name: 'Te Ouna',
    scientific_name: 'Mulloidichthys vanicolensis',
    url: 'https://images.unsplash.com/photo-1534043464124-3be32fe00099?auto=format&fit=crop&w=700&q=80',
    hint: 'Te Ouna',
  },
  {
    name: 'Blacktip Reef Shark',
    kiribati_name: 'Te Baibo',
    scientific_name: 'Carcharhinus melanopterus',
    url: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=700&q=80',
    hint: 'Te Baibo',
  },
  {
    name: 'Giant Trevally',
    kiribati_name: 'Te Rereba',
    scientific_name: 'Caranx ignobilis',
    url: 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?w=700&q=80',
    hint: 'Te Rereba',
  },
];

// =========================================================================
// Edit Species Modal Component
// =========================================================================
interface EditSpeciesModalProps {
  species: Species;
  isOpen: boolean;
  onClose: () => void;
  onSaved: () => void;
}

function EditSpeciesModal({ species, isOpen, onClose, onSaved }: EditSpeciesModalProps) {
  const [kiribatiName, setKiribatiName] = useState(species.kiribati_name);
  const [englishName, setEnglishName] = useState(species.english_name);
  const [scientificName, setScientificName] = useState(species.scientific_name);
  const [ciguateraRisk, setCiguateraRisk] = useState<'low' | 'medium' | 'high' | 'extreme'>(
    (species.ciguatera_risk as 'low' | 'medium' | 'high' | 'extreme') || 'low'
  );
  const [photoUrl, setPhotoUrl] = useState(species.photo_url || '');
  const [notes, setNotes] = useState(species.notes || '');
  const [visualFeatures, setVisualFeatures] = useState<string[]>(species.visual_features || []);
  const [newFeatureText, setNewFeatureText] = useState('');

  const [imageSourceMode, setImageSourceMode] = useState<'presets' | 'upload' | 'url'>('presets');
  const [analyzingPhoto, setAnalyzingPhoto] = useState(false);
  const [aiNotice, setAiNotice] = useState<string | null>(null);

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  useEffect(() => {
    setKiribatiName(species.kiribati_name);
    setEnglishName(species.english_name);
    setScientificName(species.scientific_name);
    setCiguateraRisk((species.ciguatera_risk as 'low' | 'medium' | 'high' | 'extreme') || 'low');
    setPhotoUrl(species.photo_url || '');
    setNotes(species.notes || '');
    setVisualFeatures(species.visual_features || []);
    setAiNotice(null);
    setError(null);
    setSuccess(null);
    setAnalyzingPhoto(false);
  }, [species]);

  // AI analysis runner for changed specimen photograph
  const analyzePhotoForTraits = async (photoInput: File | string, hintName?: string) => {
    setAnalyzingPhoto(true);
    setAiNotice(null);
    setError(null);

    try {
      const res = await api.identifyFish({
        image: photoInput,
        image_url: typeof photoInput === 'string' ? photoInput : undefined,
        hint: hintName || kiribatiName || species.kiribati_name,
      });

      const traits = res.identification.visual_features;
      if (traits && traits.length > 0) {
        setVisualFeatures(traits);
        setAiNotice(
          `✨ Meta AI Vision analyzed the picture: ${traits.length} morphological traits automatically extracted and updated!`
        );
      } else {
        setAiNotice('✨ Picture updated and analyzed by AI.');
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'AI Vision analysis error';
      console.warn('AI analysis on picture change:', msg);
      setAiNotice('Specimen picture updated.');
    } finally {
      setAnalyzingPhoto(false);
    }
  };

  const handleSelectPreset = async (preset: typeof SPECIMEN_PRESETS[0]) => {
    setPhotoUrl(preset.url);
    await analyzePhotoForTraits(preset.url, preset.kiribati_name);
  };

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = async () => {
      const dataUrl = reader.result as string;
      setPhotoUrl(dataUrl);
      await analyzePhotoForTraits(file, kiribatiName || species.kiribati_name);
    };
    reader.readAsDataURL(file);
  };

  const handleApplyCustomUrl = async () => {
    if (!photoUrl.trim()) return;
    await analyzePhotoForTraits(photoUrl.trim(), kiribatiName || species.kiribati_name);
  };

  const handleReanalyzeCurrent = async () => {
    if (!photoUrl) return;
    await analyzePhotoForTraits(photoUrl, kiribatiName || species.kiribati_name);
  };

  const handleAddFeature = () => {
    if (!newFeatureText.trim()) return;
    setVisualFeatures([...visualFeatures, newFeatureText.trim()]);
    setNewFeatureText('');
  };

  const handleRemoveFeature = (index: number) => {
    setVisualFeatures(visualFeatures.filter((_, idx) => idx !== index));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!kiribatiName.trim() || !englishName.trim()) {
      setError('Please provide both an authentic Kiribati Name and English Common Name.');
      return;
    }

    setSaving(true);
    setError(null);

    try {
      await api.updateSpecies(species.id, {
        kiribati_name: kiribatiName.trim(),
        english_name: englishName.trim(),
        scientific_name: scientificName.trim(),
        ciguatera_risk: ciguateraRisk,
        photo_url: photoUrl.trim() || undefined,
        notes: notes.trim(),
        visual_features: visualFeatures,
      });

      setSuccess('Species updated successfully!');
      setTimeout(() => {
        onSaved();
      }, 700);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Error updating species.';
      setError(msg);
    } finally {
      setSaving(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div className="relative bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl border border-slate-200">
        {/* Header */}
        <div className="flex items-center justify-between pb-4 border-b border-slate-100">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-indigo-100 text-indigo-700 rounded-xl">
              <Edit3 className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-base font-bold text-slate-900">
                Edit Species: {species.kiribati_name}
              </h3>
              <p className="text-xs text-slate-500">
                Update photo, auto-extract AI morphological traits, taxonomy, and CFP risk
              </p>
            </div>
          </div>

          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Feedback Messages */}
        {error && (
          <div className="mt-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs flex items-center gap-2">
            <AlertTriangle className="w-4 h-4 text-red-600 flex-shrink-0" />
            <span>{error}</span>
          </div>
        )}
        {success && (
          <div className="mt-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4 text-emerald-600 flex-shrink-0" />
            <span>{success}</span>
          </div>
        )}

        {/* Form */}
        <form onSubmit={handleSubmit} className="mt-4 space-y-4 text-xs">
          {/* Kiribati & English Names */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block font-bold text-slate-700 mb-1">
                Kiribati Name (Local Vernacular) *
              </label>
              <input
                type="text"
                required
                value={kiribatiName}
                onChange={(e) => setKiribatiName(e.target.value)}
                placeholder="e.g. Te Bwere"
                className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 font-semibold"
              />
            </div>

            <div>
              <label className="block font-bold text-slate-700 mb-1">
                English Common Name *
              </label>
              <input
                type="text"
                required
                value={englishName}
                onChange={(e) => setEnglishName(e.target.value)}
                placeholder="e.g. Paddletail Snapper"
                className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
              />
            </div>
          </div>

          {/* Scientific Name & Ciguatera Risk */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block font-bold text-slate-700 mb-1">
                Scientific Classification
              </label>
              <input
                type="text"
                value={scientificName}
                onChange={(e) => setScientificName(e.target.value)}
                placeholder="e.g. Lutjanus gibbus"
                className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 italic"
              />
            </div>

            <div>
              <label className="block font-bold text-slate-700 mb-1">
                Ciguatera Toxicity Risk Level
              </label>
              <select
                value={ciguateraRisk}
                onChange={(e) => setCiguateraRisk(e.target.value as any)}
                className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 font-medium bg-white"
              >
                <option value="low">Low Risk (Generally safe)</option>
                <option value="medium">Medium Risk (Caution on large specimens)</option>
                <option value="high">High Risk (Reef bioaccumulator)</option>
                <option value="extreme">Extreme Risk (Biohazard / Do not consume)</option>
              </select>
            </div>
          </div>

          {/* Specimen Photograph Selection & Automated AI Trait Extraction */}
          <div className="bg-slate-50 rounded-xl p-3 border border-slate-200/80 space-y-3">
            <div className="flex items-center justify-between">
              <label className="block font-bold text-slate-800 text-xs flex items-center gap-1.5">
                <Camera className="w-4 h-4 text-indigo-600" />
                <span>Specimen Photograph & AI Analysis</span>
              </label>
              <div className="flex items-center gap-1 bg-white p-0.5 rounded-lg border border-slate-200 text-[11px]">
                <button
                  type="button"
                  onClick={() => setImageSourceMode('presets')}
                  className={`px-2 py-0.5 rounded font-medium transition ${
                    imageSourceMode === 'presets'
                      ? 'bg-indigo-600 text-white font-semibold'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  Kiribati Presets
                </button>
                <button
                  type="button"
                  onClick={() => setImageSourceMode('upload')}
                  className={`px-2 py-0.5 rounded font-medium transition ${
                    imageSourceMode === 'upload'
                      ? 'bg-indigo-600 text-white font-semibold'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  Upload File
                </button>
                <button
                  type="button"
                  onClick={() => setImageSourceMode('url')}
                  className={`px-2 py-0.5 rounded font-medium transition ${
                    imageSourceMode === 'url'
                      ? 'bg-indigo-600 text-white font-semibold'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  Photo URL
                </button>
              </div>
            </div>

            {/* Active Source Mode Input */}
            {imageSourceMode === 'presets' && (
              <div>
                <div className="text-[10px] text-slate-500 font-medium mb-1.5 flex items-center gap-1">
                  <Sparkles className="w-3 h-3 text-amber-500" />
                  <span>Click any specimen below to change picture and auto-extract AI morphological traits:</span>
                </div>
                <div className="grid grid-cols-3 sm:grid-cols-4 gap-2 max-h-40 overflow-y-auto p-1 bg-white rounded-lg border border-slate-200">
                  {SPECIMEN_PRESETS.map((preset, idx) => {
                    const isSelected = photoUrl === preset.url;
                    return (
                      <button
                        key={idx}
                        type="button"
                        onClick={() => handleSelectPreset(preset)}
                        disabled={analyzingPhoto}
                        className={`p-1.5 rounded-lg border text-left transition flex flex-col items-center group/p ${
                          isSelected
                            ? 'border-indigo-600 bg-indigo-50 ring-2 ring-indigo-500/20'
                            : 'border-slate-200 hover:border-indigo-300 hover:bg-slate-50'
                        }`}
                      >
                        <div className="w-12 h-12 rounded bg-slate-100 overflow-hidden mb-1 relative">
                          <img src={preset.url} alt={preset.name} className="w-full h-full object-cover" />
                          {isSelected && (
                            <div className="absolute inset-0 bg-indigo-600/30 flex items-center justify-center">
                              <Check className="w-4 h-4 text-white stroke-[3]" />
                            </div>
                          )}
                        </div>
                        <span className="text-[11px] font-bold text-slate-900 leading-tight text-center">
                          {preset.kiribati_name}
                        </span>
                        <span className="text-[9px] text-slate-500 text-center truncate max-w-full">
                          {preset.name}
                        </span>
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            {imageSourceMode === 'upload' && (
              <div className="space-y-2">
                <div className="border-2 border-dashed border-slate-300 rounded-xl p-4 text-center bg-white hover:bg-slate-50/50 transition cursor-pointer relative">
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    onChange={handleFileUpload}
                    disabled={analyzingPhoto}
                    className="absolute inset-0 opacity-0 cursor-pointer w-full h-full"
                  />
                  <div className="flex flex-col items-center justify-center pointer-events-none">
                    <Camera className="w-6 h-6 text-indigo-500 mb-1" />
                    <span className="text-xs font-bold text-slate-800">
                      Click to upload fish photograph (JPEG, PNG, WEBP)
                    </span>
                    <span className="text-[10px] text-slate-400 mt-0.5">
                      Meta AI Vision will immediately analyze this picture for morphological traits
                    </span>
                  </div>
                </div>
              </div>
            )}

            {imageSourceMode === 'url' && (
              <div className="space-y-2">
                <div className="flex gap-2">
                  <input
                    type="url"
                    value={photoUrl}
                    onChange={(e) => setPhotoUrl(e.target.value)}
                    placeholder="https://images.unsplash.com/photo-..."
                    className="flex-1 px-3 py-1.5 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 font-mono text-[11px] bg-white"
                  />
                  <button
                    type="button"
                    onClick={handleApplyCustomUrl}
                    disabled={!photoUrl.trim() || analyzingPhoto}
                    className="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg flex items-center gap-1 transition text-xs shadow-xs disabled:opacity-50"
                  >
                    <Sparkles className="w-3.5 h-3.5 text-amber-300" />
                    <span>Extract Traits</span>
                  </button>
                </div>
              </div>
            )}

            {/* Live Photo Preview & AI Analyzing Status Overlay */}
            {photoUrl && (
              <div className="flex items-center gap-3 bg-white p-2.5 rounded-lg border border-slate-200">
                <div className="w-16 h-16 rounded-lg bg-slate-100 overflow-hidden border border-slate-200 flex-shrink-0 relative">
                  <img src={photoUrl} alt="Preview" className="w-full h-full object-cover" />
                  {analyzingPhoto && (
                    <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-2xs flex items-center justify-center">
                      <Loader2 className="w-5 h-5 text-amber-300 animate-spin" />
                    </div>
                  )}
                </div>

                <div className="flex-1 min-w-0">
                  <div className="flex items-center justify-between">
                    <span className="text-[11px] font-bold text-slate-800">Active Specimen Photo</span>
                    {analyzingPhoto ? (
                      <span className="text-[10px] text-indigo-600 font-semibold flex items-center gap-1 animate-pulse">
                        <Loader2 className="w-3 h-3 animate-spin" />
                        <span>Analyzing with Meta AI Vision...</span>
                      </span>
                    ) : (
                      <button
                        type="button"
                        onClick={handleReanalyzeCurrent}
                        className="text-[10px] text-indigo-600 hover:text-indigo-800 font-semibold flex items-center gap-1 hover:underline"
                        title="Re-run Meta AI Vision on this picture"
                      >
                        <Sparkles className="w-2.5 h-2.5 text-amber-500" />
                        <span>Re-Analyze Picture</span>
                      </button>
                    )}
                  </div>
                  <p className="text-[10px] text-slate-500 truncate mt-0.5 font-mono">
                    {photoUrl.length > 50 ? `${photoUrl.slice(0, 50)}...` : photoUrl}
                  </p>

                  {aiNotice && (
                    <div className="mt-1 text-[11px] font-medium text-emerald-800 bg-emerald-50 border border-emerald-200/80 rounded px-2 py-0.5 flex items-center gap-1">
                      <Sparkles className="w-3 h-3 text-emerald-600 flex-shrink-0" />
                      <span className="truncate">{aiNotice}</span>
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>

          {/* AI Morphological Traits Editor */}
          <div>
            <div className="flex items-center justify-between mb-1">
              <label className="font-bold text-slate-700 flex items-center gap-1.5">
                <Eye className="w-3.5 h-3.5 text-indigo-600" />
                <span>AI Vision Morphological Traits ({visualFeatures.length})</span>
              </label>
              <span className="text-[10px] font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-full flex items-center gap-1">
                <Sparkles className="w-2.5 h-2.5 text-amber-500" />
                <span>Synced with Picture</span>
              </span>
            </div>

            <div className="space-y-1.5 mb-2 max-h-48 overflow-y-auto">
              {visualFeatures.length === 0 ? (
                <div className="text-[11px] text-slate-400 italic bg-slate-50 p-2.5 rounded-lg border border-slate-200 text-center">
                  No morphological traits recorded yet. Select or upload a picture above to auto-extract traits.
                </div>
              ) : (
                visualFeatures.map((feat, idx) => (
                  <div key={idx} className="flex items-center gap-1.5 bg-slate-50 p-1.5 rounded-lg border border-slate-100">
                    <span className="w-1.5 h-1.5 rounded-full bg-indigo-500 ml-1"></span>
                    <span className="flex-1 text-[11px] text-slate-700">{feat}</span>
                    <button
                      type="button"
                      onClick={() => handleRemoveFeature(idx)}
                      className="p-1 text-slate-400 hover:text-red-600 transition"
                      title="Remove trait"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                ))
              )}
            </div>

            <div className="flex gap-2">
              <input
                type="text"
                value={newFeatureText}
                onChange={(e) => setNewFeatureText(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    handleAddFeature();
                  }
                }}
                placeholder="Add custom trait (e.g. Paddle-shaped caudal fin with white margin)..."
                className="flex-1 px-3 py-1.5 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
              />
              <button
                type="button"
                onClick={handleAddFeature}
                className="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg flex items-center gap-1 transition"
              >
                <Plus className="w-3.5 h-3.5" />
                <span>Add Trait</span>
              </button>
            </div>
          </div>

          {/* Notes */}
          <div>
            <label className="block font-bold text-slate-700 mb-1">
              Biological Notes & Local Advisory Context
            </label>
            <textarea
              rows={2}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Habitat, aggregation seasons, or ciguatera incident reports..."
              className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
            />
          </div>

          {/* Modal Actions */}
          <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-2.5">
            <button
              type="button"
              onClick={onClose}
              disabled={saving || analyzingPhoto}
              className="px-4 py-2 font-semibold text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving || analyzingPhoto}
              className="px-5 py-2 font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow-sm transition flex items-center gap-1.5 disabled:opacity-50"
            >
              {saving ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>Saving Changes...</span>
                </>
              ) : (
                <>
                  <Check className="w-4 h-4" />
                  <span>Save Species</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
