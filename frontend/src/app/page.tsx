'use client';

import React, { useEffect, useState, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import dynamic from 'next/dynamic';
import { useAuth } from '../context/AuthContext';
import Navbar from '../components/Navbar';
import StatsCards from '../components/StatsCards';
import FilterBar from '../components/FilterBar';
import CatchTable from '../components/CatchTable';
import CorrectionModal from '../components/CorrectionModal';
import AdvisoryManager from '../components/AdvisoryManager';
import AiFishIdentifierModal from '../components/AiFishIdentifierModal';
import SpeciesCatalog from '../components/SpeciesCatalog';
import { api, CatchRecord, Species, DashboardStats, CatchFilters, AdvisoryZone, GeoJsonPolygon } from '../lib/api';
import { ShieldAlert, RefreshCw, AlertCircle, Lock, ArrowRight, UserCheck, Fish, AlertOctagon, Layers, Sparkles, BookOpen } from 'lucide-react';

const FisheriesMap = dynamic(() => import('../components/FisheriesMap'), {
  ssr: false,
  loading: () => (
    <div
      className="w-full bg-slate-100 rounded-xl flex items-center justify-center border border-slate-200"
      style={{ height: '520px', minHeight: '520px' }}
    >
      <div className="flex items-center gap-2 text-slate-500 text-xs">
        <div className="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
        <span>Loading Fisheries GPS Map...</span>
      </div>
    </div>
  ),
});

const AdvisoryPolygonMap = dynamic(() => import('../components/AdvisoryPolygonMap'), {
  ssr: false,
  loading: () => (
    <div
      className="w-full bg-slate-100 rounded-xl flex items-center justify-center border border-slate-200"
      style={{ height: '520px', minHeight: '520px' }}
    >
      <div className="flex items-center gap-2 text-slate-500 text-xs">
        <div className="w-4 h-4 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
        <span>Loading Advisory Zones GIS Map...</span>
      </div>
    </div>
  ),
});

const DEFAULT_BETIO_POLYGON: GeoJsonPolygon = {
  type: 'Polygon',
  coordinates: [
    [
      [172.915, 1.35],
      [172.935, 1.35],
      [172.935, 1.37],
      [172.915, 1.37],
      [172.915, 1.35],
    ],
  ],
};

export default function DashboardPage() {
  const router = useRouter();
  const { user, loading: authLoading, quickLogin } = useAuth();

  // State
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [catches, setCatches] = useState<CatchRecord[]>([]);
  const [speciesList, setSpeciesList] = useState<Species[]>([]);
  const [canViewPreciseGps, setCanViewPreciseGps] = useState(false);

  const [filters, setFilters] = useState<CatchFilters>({
    fisherman: '',
    species_id: 'all',
    status: 'all',
    date: '',
    search: '',
  });

  const [loadingData, setLoadingData] = useState(true);
  const [apiError, setApiError] = useState<string | null>(null);

  // Tab State: 'catches' vs 'advisories' vs 'species'
  const [activeTab, setActiveTab] = useState<'catches' | 'advisories' | 'species'>('catches');

  // Selected catch for map centering & review modal
  const [selectedCatch, setSelectedCatch] = useState<CatchRecord | null>(null);
  const [reviewCatch, setReviewCatch] = useState<CatchRecord | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isAiModalOpen, setIsAiModalOpen] = useState(false);
  const [aiModalSpecies, setAiModalSpecies] = useState<Species | null>(null);

  // Advisory Zones State
  const [advisoryZones, setAdvisoryZones] = useState<AdvisoryZone[]>([]);
  const [selectedZone, setSelectedZone] = useState<AdvisoryZone | null>(null);
  const [drawnPolygon, setDrawnPolygon] = useState<GeoJsonPolygon | null>(DEFAULT_BETIO_POLYGON);
  const [isDrawingMode, setIsDrawingMode] = useState<boolean>(false);

  // Load Dashboard Data
  const loadDashboardData = useCallback(async () => {
    if (!user) return;

    // If user is not admin or reviewer, non-admin guard kicks in below
    if (!user.is_admin && !user.is_reviewer) {
      setLoadingData(false);
      return;
    }

    setLoadingData(true);
    setApiError(null);

    try {
      // Fetch stats, species, filtered catches, and advisory zones concurrently
      const [statsRes, speciesRes, catchesRes, zonesRes] = await Promise.all([
        api.getStats(),
        api.getSpecies(),
        api.getCatches(filters),
        api.getAdvisoryZones(),
      ]);

      setStats(statsRes);
      setSpeciesList(speciesRes.data);
      setCatches(catchesRes.data);
      setCanViewPreciseGps(catchesRes.can_view_precise_gps);
      setAdvisoryZones(zonesRes.data);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Error loading fisheries data';
      setApiError(msg);
      // Ensure no invented/hallucinated data is displayed on error
      setCatches([]);
      setStats(null);
      setAdvisoryZones([]);
    } finally {
      setLoadingData(false);
    }
  }, [user, filters]);

  // Handle Auth Check & Data Fetch
  useEffect(() => {
    if (!authLoading) {
      if (!user) {
        router.push('/login');
      } else {
        loadDashboardData();
      }
    }
  }, [user, authLoading, router, loadDashboardData]);

  // Review & Correction Handlers
  const handleOpenReview = (catchRecord: CatchRecord) => {
    setReviewCatch(catchRecord);
    setIsModalOpen(true);
  };

  const handleCloseReview = () => {
    setIsModalOpen(false);
    setReviewCatch(null);
  };

  const handleSubmitVerification = async (
    action: 'approve' | 'correct' | 'flag' | 'not_a_catch',
    speciesId?: number,
    notes?: string,
    options?: {
      photoUrl?: string;
      photoFile?: File | null;
      aiSpeciesSuggestion?: string;
      aiConfidence?: number;
    }
  ) => {
    if (!reviewCatch) return;

    const res = await api.verifyCatch(reviewCatch.id, action, speciesId, notes, options);

    // Update local state with the returned catch
    setCatches((prev) =>
      prev.map((c) => (c.id === reviewCatch.id ? res.catch : c))
    );

    // Refresh stats to reflect new verified/corrected count
    try {
      const updatedStats = await api.getStats();
      setStats(updatedStats);
    } catch (err) {
      console.warn('Could not refresh stats after verification:', err);
    }
  };

  const handleSelectOnMap = (catchRecord: CatchRecord) => {
    setSelectedCatch(catchRecord);
    // Smooth scroll to map if on mobile
    const mapElement = document.getElementById('fisheries-map-section');
    if (mapElement) {
      mapElement.scrollIntoView({ behavior: 'smooth' });
    }
  };

  // Auth Loading Screen
  if (authLoading) {
    return (
      <div className="min-h-screen bg-slate-900 flex items-center justify-center">
        <div className="text-center space-y-3">
          <div className="w-10 h-10 border-4 border-blue-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
          <p className="text-slate-400 text-xs font-mono">Connecting to MFMRD secure portal...</p>
        </div>
      </div>
    );
  }

  // Not signed in (will be redirected by router)
  if (!user) {
    return null;
  }

  // Acceptance Test 2: Non-administrators cannot access restricted pages
  if (!user.is_admin && !user.is_reviewer) {
    return (
      <div className="min-h-screen bg-slate-50 flex flex-col">
        <Navbar />
        <main className="flex-1 max-w-4xl w-full mx-auto p-6 sm:p-12 flex flex-col items-center justify-center text-center">
          <div className="bg-white rounded-2xl border border-slate-200 p-8 sm:p-12 shadow-sm max-w-lg w-full">
            <div className="w-14 h-14 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center mx-auto mb-4">
              <Lock className="w-7 h-7" />
            </div>
            <h2 className="text-lg font-bold text-slate-900">Restricted Administrator Area</h2>
            <div className="mt-2 inline-flex items-center gap-1 bg-amber-50 border border-amber-200 text-amber-800 text-xs px-2.5 py-1 rounded-full font-semibold">
              <UserCheck className="w-3.5 h-3.5" />
              <span>Signed in as Fisher ({user.email})</span>
            </div>
            <p className="text-xs text-slate-600 mt-4 leading-relaxed">
              You do not have administrative privileges to access the fisheries dashboard, AI verification reviews, or confidential vessel GPS tracks. This portal is reserved for MFMRD compliance officers and marine biologists.
            </p>
            <div className="mt-6 pt-6 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-center gap-3">
              <button
                onClick={() => quickLogin('admin')}
                className="w-full sm:w-auto px-5 py-2.5 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow-sm transition flex items-center justify-center gap-1.5"
              >
                <span>Switch to Administrator</span>
                <ArrowRight className="w-3.5 h-3.5" />
              </button>
            </div>
          </div>
        </main>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <Navbar
        onRefresh={loadDashboardData}
        isRefreshing={loadingData}
        onOpenAiModal={() => {
          setAiModalSpecies(null);
          setIsAiModalOpen(true);
        }}
      />

      <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
        {/* Header Title & Sync Status */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-bold text-slate-900 tracking-tight sm:text-2xl">
              Artisanal Fisheries Monitoring & AI Species Review
            </h1>
            <p className="text-xs text-slate-500 mt-0.5">
              Tarawa Atoll & Outer Islands • Meta AI Vision Assistance • Ministry Compliance
            </p>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={() => {
                setAiModalSpecies(null);
                setIsAiModalOpen(true);
              }}
              className="flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-bold text-white bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 rounded-lg shadow-sm transition border border-indigo-400/30"
              title="Upload a fish specimen photo to assist Meta AI Vision and confirm local Kiribati name"
            >
              <Sparkles className="w-3.5 h-3.5 text-amber-300" />
              <span>Help AI Identify Fish (Upload Photo)</span>
            </button>

            <button
              onClick={loadDashboardData}
              disabled={loadingData}
              className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-lg shadow-sm transition disabled:opacity-50"
            >
              <RefreshCw className={`w-3.5 h-3.5 text-slate-500 ${loadingData ? 'animate-spin' : ''}`} />
              <span>Refresh Records</span>
            </button>
          </div>
        </div>

        {/* API Error State (Acceptance Test 7: API errors do not display invented data) */}
        {apiError && (
          <div className="bg-red-50 border border-red-200 rounded-xl p-4 shadow-sm">
            <div className="flex items-start gap-3">
              <AlertCircle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
              <div className="flex-1">
                <h4 className="text-xs font-bold text-red-900 uppercase tracking-wider">
                  API Connection Failure
                </h4>
                <p className="text-xs text-red-700 mt-0.5 font-mono">
                  {apiError}
                </p>
                <p className="text-[11px] text-red-600/90 mt-1">
                  Note: In compliance with data integrity policies, no simulated or fabricated records are displayed during network errors.
                </p>
                <button
                  onClick={loadDashboardData}
                  className="mt-3 px-3 py-1.5 text-xs font-semibold text-red-700 bg-white hover:bg-red-100 border border-red-300 rounded-lg transition shadow-2xs"
                >
                  Retry Connection
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Primary Navigation Tabs */}
        <div className="flex border-b border-slate-200 gap-6">
          <button
            type="button"
            onClick={() => setActiveTab('catches')}
            className={`pb-3 text-xs sm:text-sm font-bold flex items-center gap-2 border-b-2 transition ${
              activeTab === 'catches'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            <Fish className="w-4 h-4" />
            <span>Catches & AI Verification</span>
            <span className="ml-1 text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-semibold">
              {catches.length}
            </span>
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('advisories')}
            className={`pb-3 text-xs sm:text-sm font-bold flex items-center gap-2 border-b-2 transition ${
              activeTab === 'advisories'
                ? 'border-red-600 text-red-600'
                : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            <AlertOctagon className="w-4 h-4 text-red-600" />
            <span>Fish Poisoning Advisory Zones</span>
            <span
              className={`ml-1 text-[11px] px-2 py-0.5 rounded-full font-bold ${
                advisoryZones.filter((z) => z.status === 'published').length > 0
                  ? 'bg-red-100 text-red-800 border border-red-200'
                  : 'bg-slate-100 text-slate-600'
              }`}
            >
              {advisoryZones.filter((z) => z.status === 'published').length} Active Warnings
            </span>
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('species')}
            className={`pb-3 text-xs sm:text-sm font-bold flex items-center gap-2 border-b-2 transition ${
              activeTab === 'species'
                ? 'border-indigo-600 text-indigo-600'
                : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            <BookOpen className="w-4 h-4 text-indigo-600" />
            <span>Stored Fish & AI Species Catalog</span>
            <span className="ml-1 text-[11px] px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 font-bold">
              {speciesList.length} Species
            </span>
          </button>
        </div>

        {/* Tab 1: Recorded Catches & AI Verification */}
        {activeTab === 'catches' && (
          <div className="space-y-6">
            {/* 1. Dashboard Statistics Cards */}
            <StatsCards stats={stats} loading={loadingData} error={apiError} />

            {/* 2. Interactive Leaflet GPS Map & Trip Routes */}
            <div id="fisheries-map-section">
              <FisheriesMap
                catches={catches}
                selectedCatch={selectedCatch}
                canViewPreciseGps={canViewPreciseGps}
                advisoryZones={advisoryZones}
              />
            </div>

            {/* 3. Filter Toolbar */}
            <FilterBar
              filters={filters}
              onFilterChange={setFilters}
              speciesList={speciesList}
              totalMatches={catches.length}
              loading={loadingData}
            />

            {/* 4. Catch Lists & Human AI Verification Table */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-bold text-slate-800 uppercase tracking-wider">
                  Recorded Catches & AI Species Suggestions
                </h3>
                <span className="text-xs text-slate-500">
                  Showing {catches.length} {catches.length === 1 ? 'catch' : 'catches'}
                </span>
              </div>

              <CatchTable
                catches={catches}
                loading={loadingData}
                onVerify={handleOpenReview}
                onSelectOnMap={handleSelectOnMap}
                selectedCatchId={selectedCatch?.id}
              />
            </div>
          </div>
        )}

        {/* Tab 2: Fish Poisoning Advisory Zones Management & GIS Map */}
        {activeTab === 'advisories' && (
          <div className="space-y-6">
            {/* 1. Leaflet Advisory Polygon Map & Live Coordinate Warning Tester */}
            <AdvisoryPolygonMap
              zones={advisoryZones}
              selectedZone={selectedZone}
              onSelectZone={setSelectedZone}
              isDrawingMode={isDrawingMode}
              setIsDrawingMode={setIsDrawingMode}
              drawnPolygon={drawnPolygon}
              setDrawnPolygon={setDrawnPolygon}
            />

            {/* 2. Advisory Zones Management, Verification Workflow & Audit Trail */}
            <AdvisoryManager
              zones={advisoryZones}
              speciesList={speciesList}
              currentUser={user}
              onRefresh={loadDashboardData}
              onSelectZone={setSelectedZone}
              selectedZone={selectedZone}
              drawnPolygon={drawnPolygon}
              setIsDrawingMode={setIsDrawingMode}
            />
          </div>
        )}

        {/* Tab 3: Stored Fish & AI Species Catalog */}
        {activeTab === 'species' && (
          <SpeciesCatalog
            speciesList={speciesList}
            loading={loadingData}
            onRefresh={loadDashboardData}
            onIdentifySpecies={(spec) => {
              setAiModalSpecies(spec || null);
              setIsAiModalOpen(true);
            }}
            onViewCatches={(speciesId) => {
              setFilters((prev) => ({ ...prev, species_id: String(speciesId), status: 'all' }));
              setActiveTab('catches');
            }}
          />
        )}
      </main>

      {/* 5. Human Species Verification / Correction Modal */}
      <CorrectionModal
        catchRecord={reviewCatch}
        speciesList={speciesList}
        isOpen={isModalOpen}
        onClose={handleCloseReview}
        onSubmit={handleSubmitVerification}
      />

      {/* 6. Admin AI Fish Identification & Specimen Training Modal */}
      <AiFishIdentifierModal
        isOpen={isAiModalOpen}
        onClose={() => {
          setIsAiModalOpen(false);
          setAiModalSpecies(null);
        }}
        onSuccess={loadDashboardData}
        speciesList={speciesList}
        initialSpecies={aiModalSpecies}
      />
    </div>
  );
}
