'use client';

import React, { useState } from 'react';
import { AdvisoryZone, Species, AdvisoryAuditLog, GeoJsonPolygon, api, User } from '../lib/api';
import {
  ShieldAlert,
  ShieldCheck,
  Plus,
  Edit2,
  Trash2,
  CheckCircle,
  Clock,
  History,
  AlertTriangle,
  Info,
  Calendar,
  Layers,
  Search,
  Filter,
  Check,
  X,
  FileText
} from 'lucide-react';

interface AdvisoryManagerProps {
  zones: AdvisoryZone[];
  speciesList: Species[];
  currentUser: User | null;
  onRefresh: () => void;
  onSelectZone: (zone: AdvisoryZone) => void;
  selectedZone: AdvisoryZone | null;
  drawnPolygon: GeoJsonPolygon | null;
  setIsDrawingMode: (active: boolean) => void;
}

export default function AdvisoryManager({
  zones,
  speciesList,
  currentUser,
  onRefresh,
  onSelectZone,
  selectedZone,
  drawnPolygon,
  setIsDrawingMode,
}: AdvisoryManagerProps) {
  // Filters
  const [filterStatus, setFilterStatus] = useState<string>('all');
  const [filterClassification, setFilterClassification] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');

  // Modals
  const [isFormOpen, setIsFormOpen] = useState<boolean>(false);
  const [editingZone, setEditingZone] = useState<AdvisoryZone | null>(null);
  const [isAuditModalOpen, setIsAuditModalOpen] = useState<boolean>(false);
  const [auditLogs, setAuditLogs] = useState<AdvisoryAuditLog[]>([]);
  const [auditLoading, setAuditLoading] = useState<boolean>(false);
  const [auditZoneTitle, setAuditZoneTitle] = useState<string>('');

  // Form State
  const [formData, setFormData] = useState<{
    title: string;
    concern: string;
    evidence: string;
    description: string;
    classification: 'suspected' | 'under_investigation' | 'authority-confirmed';
    status: 'draft' | 'under_investigation' | 'approved' | 'published';
    source: string;
    review_date: string;
    species_ids: number[];
    polygon_geojson_str: string;
  }>({
    title: '',
    concern: '',
    evidence: '',
    description: '',
    classification: 'under_investigation',
    status: 'draft',
    source: 'Ministry of Fisheries & Marine Resources Development (MFMRD)',
    review_date: '',
    species_ids: [],
    polygon_geojson_str: '',
  });

  const [formError, setFormError] = useState<string | null>(null);
  const [formSubmitting, setFormSubmitting] = useState<boolean>(false);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);

  // Open Form for Create
  const handleOpenCreate = () => {
    setEditingZone(null);
    setFormError(null);

    let defaultGeoJsonStr = '';
    if (drawnPolygon) {
      defaultGeoJsonStr = JSON.stringify(drawnPolygon, null, 2);
    } else {
      defaultGeoJsonStr = JSON.stringify(
        {
          type: 'Polygon',
          coordinates: [[
            [172.915, 1.35],
            [172.935, 1.35],
            [172.935, 1.37],
            [172.915, 1.37],
            [172.915, 1.35],
          ]],
        },
        null,
        2
      );
    }

    setFormData({
      title: '[DEMO - SYNTHETIC] ',
      concern: 'Ciguatera fish poisoning / benthic dinoflagellate risk',
      evidence: '',
      description: '',
      classification: 'under_investigation',
      status: 'draft',
      source: 'Ministry of Fisheries & Marine Resources Development (MFMRD)',
      review_date: new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
      species_ids: [],
      polygon_geojson_str: defaultGeoJsonStr,
    });

    setIsFormOpen(true);
  };

  // Open Form for Edit
  const handleOpenEdit = (zone: AdvisoryZone) => {
    setEditingZone(zone);
    setFormError(null);
    setFormData({
      title: zone.title,
      concern: zone.concern,
      evidence: zone.evidence || '',
      description: zone.description || '',
      classification: zone.classification,
      status: (zone.status === 'archived' ? 'draft' : zone.status) as any,
      source: zone.source,
      review_date: zone.review_date ? zone.review_date.split('T')[0] : '',
      species_ids: zone.species ? zone.species.map((s) => s.id) : [],
      polygon_geojson_str: JSON.stringify(zone.polygon_geojson, null, 2),
    });
    setIsFormOpen(true);
  };

  // Toggle Species Selection
  const toggleSpecies = (id: number) => {
    setFormData((prev) => {
      const exists = prev.species_ids.includes(id);
      return {
        ...prev,
        species_ids: exists
          ? prev.species_ids.filter((spId) => spId !== id)
          : [...prev.species_ids, id],
      };
    });
  };

  // Use currently drawn polygon from map
  const handleAdoptDrawnPolygon = () => {
    if (drawnPolygon) {
      setFormData((prev) => ({
        ...prev,
        polygon_geojson_str: JSON.stringify(drawnPolygon, null, 2),
      }));
    } else {
      setIsDrawingMode(true);
      setIsFormOpen(false);
    }
  };

  // Submit Form (Create / Update)
  const handleSubmitForm = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);
    setFormSubmitting(true);

    try {
      let parsedGeoJson: GeoJsonPolygon;
      try {
        parsedGeoJson = JSON.parse(formData.polygon_geojson_str);
      } catch (err: any) {
        throw new Error(`Invalid JSON in Polygon coordinates: ${err.message}`);
      }

      if (editingZone) {
        await api.updateAdvisoryZone(editingZone.id, {
          title: formData.title,
          concern: formData.concern,
          evidence: formData.evidence,
          description: formData.description,
          classification: formData.classification,
          status: formData.status,
          source: formData.source,
          review_date: formData.review_date || undefined,
          polygon_geojson: parsedGeoJson,
          species_ids: formData.species_ids,
        });
        setActionSuccess(`Advisory zone "${formData.title}" updated successfully.`);
      } else {
        await api.createAdvisoryZone({
          title: formData.title,
          concern: formData.concern,
          evidence: formData.evidence,
          description: formData.description,
          classification: formData.classification,
          status: formData.status,
          source: formData.source,
          review_date: formData.review_date || undefined,
          polygon_geojson: parsedGeoJson,
          species_ids: formData.species_ids,
        });
        setActionSuccess(`Advisory zone "${formData.title}" created successfully.`);
      }

      setIsFormOpen(false);
      onRefresh();
      setTimeout(() => setActionSuccess(null), 5000);
    } catch (err: any) {
      setFormError(err.message || 'Error saving advisory zone');
    } finally {
      setFormSubmitting(false);
    }
  };

  // Publish Action (Admin Only)
  const handlePublish = async (zone: AdvisoryZone) => {
    if (!currentUser?.is_admin) {
      alert('Only administrators have authority to publish advisory zones.');
      return;
    }

    if (!confirm(`Are you sure you want to approve and PUBLISH "${zone.title}" as an active public health warning?`)) {
      return;
    }

    try {
      await api.publishAdvisoryZone(zone.id);
      setActionSuccess(`Advisory "${zone.title}" published successfully as an active warning.`);
      onRefresh();
      setTimeout(() => setActionSuccess(null), 5000);
    } catch (err: any) {
      alert(`Publish failed: ${err.message}`);
    }
  };

  // Unpublish Action (Admin Only)
  const handleUnpublish = async (zone: AdvisoryZone) => {
    if (!currentUser?.is_admin) {
      alert('Only administrators have authority to unpublish advisory zones.');
      return;
    }

    if (!confirm(`Revert "${zone.title}" from published status back to draft?`)) {
      return;
    }

    try {
      await api.unpublishAdvisoryZone(zone.id);
      setActionSuccess(`Advisory "${zone.title}" reverted to draft.`);
      onRefresh();
      setTimeout(() => setActionSuccess(null), 5000);
    } catch (err: any) {
      alert(`Unpublish failed: ${err.message}`);
    }
  };

  // Open Audit Logs
  const handleViewAuditLogs = async (zone: AdvisoryZone) => {
    setAuditZoneTitle(zone.title);
    setIsAuditModalOpen(true);
    setAuditLoading(true);
    try {
      const res = await api.getAdvisoryAuditLogs(zone.id);
      setAuditLogs(res.audit_logs);
    } catch (err: any) {
      console.error('Audit logs fetch failed:', err);
    } finally {
      setAuditLoading(false);
    }
  };

  // Filtered Zones List
  const filteredZones = zones.filter((z) => {
    if (filterStatus !== 'all' && z.status !== filterStatus) return false;
    if (filterClassification !== 'all' && z.classification !== filterClassification) return false;
    if (searchQuery) {
      const q = searchQuery.toLowerCase();
      const matchTitle = z.title.toLowerCase().includes(q);
      const matchConcern = z.concern.toLowerCase().includes(q);
      const matchEvidence = (z.evidence || '').toLowerCase().includes(q);
      if (!matchTitle && !matchConcern && !matchEvidence) return false;
    }
    return true;
  });

  return (
    <div className="space-y-6">
      {/* Safety Notice Banner */}
      <div className="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start gap-3">
        <AlertTriangle className="w-5 h-5 text-amber-700 flex-shrink-0 mt-0.5" />
        <div className="text-xs text-amber-900 leading-relaxed">
          <span className="font-bold uppercase tracking-wider block mb-0.5 text-amber-800">
            Mandatory Demonstration Protocol & Safety Rules:
          </span>
          All advisory zones in this environment are clearly labeled synthetic demonstration models. Never invent real poisoning incidents.
          <b> AI photographs cannot determine whether fish contain ciguatoxins or other marine poisons</b>; active advisories require clinical incident reports, laboratory testing, and authorized administrative approval.
        </div>
      </div>

      {/* Success Notification */}
      {actionSuccess && (
        <div className="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl text-xs font-semibold flex items-center justify-between">
          <div className="flex items-center gap-2">
            <CheckCircle className="w-4 h-4 text-emerald-600" />
            <span>{actionSuccess}</span>
          </div>
          <button onClick={() => setActionSuccess(null)} className="text-emerald-600 hover:text-emerald-900">
            ✕
          </button>
        </div>
      )}

      {/* Controls & Filter Bar */}
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <div className="flex flex-col md:flex-row items-center justify-between gap-4">
          {/* Search Input */}
          <div className="relative w-full md:w-80">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              placeholder="Search advisory title, concern, evidence..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>

          {/* Status & Classification Selectors */}
          <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
            <div className="flex items-center gap-1.5 text-xs text-slate-600">
              <Filter className="w-3.5 h-3.5 text-slate-400" />
              <span>Status:</span>
              <select
                value={filterStatus}
                onChange={(e) => setFilterStatus(e.target.value)}
                className="text-xs border border-slate-300 rounded-md px-2 py-1.5 bg-white font-medium"
              >
                <option value="all">All Statuses</option>
                <option value="published">Published (Active)</option>
                <option value="under_investigation">Under Investigation</option>
                <option value="draft">Draft (Staff Only)</option>
                <option value="archived">Archived</option>
              </select>
            </div>

            <div className="flex items-center gap-1.5 text-xs text-slate-600">
              <span>Classification:</span>
              <select
                value={filterClassification}
                onChange={(e) => setFilterClassification(e.target.value)}
                className="text-xs border border-slate-300 rounded-md px-2 py-1.5 bg-white font-medium"
              >
                <option value="all">All Classifications</option>
                <option value="authority-confirmed">Authority Confirmed</option>
                <option value="under_investigation">Under Investigation</option>
                <option value="suspected">Suspected</option>
              </select>
            </div>

            {/* Create Advisory Button */}
            {(currentUser?.is_admin || currentUser?.is_reviewer) && (
              <button
                type="button"
                onClick={handleOpenCreate}
                className="px-3.5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg flex items-center gap-1.5 shadow-sm transition ml-auto"
              >
                <Plus className="w-4 h-4" />
                New Advisory Zone
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Advisory Zones Cards / Table */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {filteredZones.map((zone) => {
          const isPublished = zone.status === 'published';
          const isUnderInvestigation = zone.status === 'under_investigation';
          const isSelected = selectedZone?.id === zone.id;

          const statusBadge = isPublished
            ? 'bg-red-100 text-red-800 border-red-300'
            : isUnderInvestigation
            ? 'bg-amber-100 text-amber-800 border-amber-300'
            : 'bg-blue-100 text-blue-800 border-blue-300';

          const classificationBadge =
            zone.classification === 'authority-confirmed'
              ? 'bg-red-50 text-red-700 border-red-200'
              : zone.classification === 'under_investigation'
              ? 'bg-amber-50 text-amber-700 border-amber-200'
              : 'bg-slate-100 text-slate-700 border-slate-200';

          return (
            <div
              key={zone.id}
              onClick={() => onSelectZone(zone)}
              className={`bg-white rounded-xl shadow-sm border p-4 flex flex-col justify-between cursor-pointer transition-all ${
                isSelected
                  ? 'border-indigo-600 ring-2 ring-indigo-200 bg-indigo-50/20'
                  : 'border-slate-200 hover:border-slate-300 hover:shadow'
              }`}
            >
              <div>
                {/* Header Pills */}
                <div className="flex items-center justify-between gap-2 mb-2">
                  <span
                    className={`px-2 py-0.5 text-[10px] font-bold rounded-full border uppercase tracking-wider ${statusBadge}`}
                  >
                    {zone.status}
                  </span>
                  <span className={`px-2 py-0.5 text-[10px] font-medium rounded border ${classificationBadge}`}>
                    {zone.classification.replace('_', ' ')}
                  </span>
                </div>

                <h4 className="font-bold text-sm text-slate-900 leading-snug mb-1.5">
                  {zone.title}
                </h4>

                <div className="text-xs text-slate-700 mb-2 font-medium">
                  <span className="text-red-700 font-semibold">Concern:</span> {zone.concern}
                </div>

                {zone.evidence && (
                  <p className="text-xs text-slate-600 bg-slate-50 p-2 rounded-lg border border-slate-100 mb-3 line-clamp-2">
                    <span className="font-semibold text-slate-700">Evidence:</span> {zone.evidence}
                  </p>
                )}

                {/* Affected Species List */}
                <div className="mb-3">
                  <div className="text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">
                    Affected Species ({zone.species?.length || 0}):
                  </div>
                  <div className="flex flex-wrap gap-1">
                    {zone.species && zone.species.length > 0 ? (
                      zone.species.map((sp) => (
                        <span
                          key={sp.id}
                          className="px-2 py-0.5 text-[10px] rounded bg-slate-100 text-slate-800 border border-slate-200 font-medium"
                        >
                          {sp.kiribati_name} ({sp.english_name})
                        </span>
                      ))
                    ) : (
                      <span className="text-xs text-slate-400 italic">None specified</span>
                    )}
                  </div>
                </div>
              </div>

              {/* Card Footer: Metadata & Governance Actions */}
              <div className="pt-3 border-t border-slate-100 mt-2">
                <div className="flex items-center justify-between text-[11px] text-slate-500 mb-3">
                  <span>Source: {zone.source}</span>
                  {zone.review_date && (
                    <span className="flex items-center gap-1">
                      <Calendar className="w-3 h-3 text-slate-400" />
                      {zone.review_date}
                    </span>
                  )}
                </div>

                {/* Workflow Buttons */}
                <div className="flex items-center justify-between gap-1.5 pt-2">
                  <div className="flex items-center gap-1">
                    {/* Edit Button */}
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        handleOpenEdit(zone);
                      }}
                      className="p-1.5 text-slate-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg text-xs flex items-center gap-1 transition"
                      title="Edit Advisory"
                    >
                      <Edit2 className="w-3.5 h-3.5" />
                      Edit
                    </button>

                    {/* Audit Log Button */}
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        handleViewAuditLogs(zone);
                      }}
                      className="p-1.5 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-lg text-xs flex items-center gap-1 transition"
                      title="View Audit History"
                    >
                      <History className="w-3.5 h-3.5" />
                      Audit
                    </button>
                  </div>

                  {/* Governance Approval / Publication Controls */}
                  {currentUser?.is_admin ? (
                    isPublished ? (
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          handleUnpublish(zone);
                        }}
                        className="px-2.5 py-1 text-xs font-semibold text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200 rounded-lg transition"
                      >
                        Unpublish
                      </button>
                    ) : (
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          handlePublish(zone);
                        }}
                        className="px-2.5 py-1 text-xs font-bold text-white bg-red-600 hover:bg-red-700 rounded-lg shadow-sm transition"
                      >
                        Publish Warning
                      </button>
                    )
                  ) : (
                    <span
                      className="text-[10px] text-slate-400 font-medium italic"
                      title="Only administrators can approve and publish advisories"
                    >
                      {isPublished ? 'Active Warning' : 'Admin Approval Req.'}
                    </span>
                  )}
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {filteredZones.length === 0 && (
        <div className="text-center py-12 bg-white rounded-xl border border-slate-200 text-slate-500 text-sm">
          No advisory zones found matching your active filters.
        </div>
      )}

      {/* CREATE / EDIT MODAL */}
      {isFormOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-6 max-h-[90vh] overflow-y-auto border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-200 pb-3 mb-4">
              <h3 className="text-base font-bold text-slate-900 flex items-center gap-2">
                <ShieldAlert className="w-5 h-5 text-indigo-600" />
                {editingZone ? `Edit Advisory: ${editingZone.title}` : 'Create New Fish Poisoning Advisory'}
              </h3>
              <button
                type="button"
                onClick={() => setIsFormOpen(false)}
                className="text-slate-400 hover:text-slate-600 font-bold"
              >
                ✕
              </button>
            </div>

            {formError && (
              <div className="bg-red-50 border border-red-200 text-red-800 text-xs p-3 rounded-lg mb-4 flex items-center gap-2">
                <AlertTriangle className="w-4 h-4 flex-shrink-0 text-red-600" />
                <span>{formError}</span>
              </div>
            )}

            <form onSubmit={handleSubmitForm} className="space-y-4 text-xs">
              {/* Title */}
              <div>
                <label className="block font-bold text-slate-700 mb-1">
                  Advisory Title (Include [DEMO - SYNTHETIC] tag) *
                </label>
                <input
                  type="text"
                  required
                  value={formData.title}
                  onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                  placeholder="[DEMO - SYNTHETIC] Western Reef Ciguatera Warning"
                  className="w-full border border-slate-300 rounded-lg p-2 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                />
              </div>

              {/* Concern & Classification */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">
                    Advisory Concern / Toxin Type *
                  </label>
                  <input
                    type="text"
                    required
                    value={formData.concern}
                    onChange={(e) => setFormData({ ...formData, concern: e.target.value })}
                    placeholder="Ciguatera Fish Poisoning (CFP) / Dinoflagellate Bloom"
                    className="w-full border border-slate-300 rounded-lg p-2 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-700 mb-1">
                    Classification State *
                  </label>
                  <select
                    value={formData.classification}
                    onChange={(e) => setFormData({ ...formData, classification: e.target.value as any })}
                    className="w-full border border-slate-300 rounded-lg p-2 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none bg-white"
                  >
                    <option value="suspected">Suspected (Preliminary Report)</option>
                    <option value="under_investigation">Under Investigation (Testing in progress)</option>
                    <option value="authority-confirmed">Authority Confirmed (Bioassay & Clinic Verified)</option>
                  </select>
                </div>
              </div>

              {/* Status & Review Date */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">
                    Workflow Status
                  </label>
                  <select
                    value={formData.status}
                    onChange={(e) => setFormData({ ...formData, status: e.target.value as any })}
                    disabled={!currentUser?.is_admin && formData.status !== 'draft'}
                    className="w-full border border-slate-300 rounded-lg p-2 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none bg-white"
                  >
                    <option value="draft">Draft (Reviewer / Staff)</option>
                    <option value="under_investigation">Under Investigation</option>
                    {currentUser?.is_admin && (
                      <>
                        <option value="approved">Approved</option>
                        <option value="published">Published (Active Warning)</option>
                      </>
                    )}
                  </select>
                  {!currentUser?.is_admin && (
                    <span className="text-[10px] text-slate-400 mt-0.5 block">
                      Note: Only administrators can publish advisories.
                    </span>
                  )}
                </div>

                <div>
                  <label className="block font-bold text-slate-700 mb-1">
                    Next Review Date
                  </label>
                  <input
                    type="date"
                    value={formData.review_date}
                    onChange={(e) => setFormData({ ...formData, review_date: e.target.value })}
                    className="w-full border border-slate-300 rounded-lg p-2 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none bg-white"
                  />
                </div>
              </div>

              {/* Evidence & Clinic Observations */}
              <div>
                <label className="block font-bold text-slate-700 mb-1">
                  Evidence Notes & Laboratory / Clinical Records
                </label>
                <textarea
                  rows={2}
                  value={formData.evidence}
                  onChange={(e) => setFormData({ ...formData, evidence: e.target.value })}
                  placeholder="Betio Clinic incident reports, Gambierdiscus cell count results, environmental water samples..."
                  className="w-full border border-slate-300 rounded-lg p-2 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                />
              </div>

              {/* Affected Species Selection Checklist */}
              <div>
                <label className="block font-bold text-slate-700 mb-1">
                  Affected Species (Multiple selection)
                </label>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-36 overflow-y-auto border border-slate-200 p-2 rounded-lg bg-slate-50">
                  {speciesList.map((sp) => {
                    const isChecked = formData.species_ids.includes(sp.id);
                    return (
                      <label
                        key={sp.id}
                        className={`flex items-center gap-2 p-1.5 rounded cursor-pointer transition ${
                          isChecked ? 'bg-indigo-100 text-indigo-900 font-bold' : 'hover:bg-slate-200/60'
                        }`}
                      >
                        <input
                          type="checkbox"
                          checked={isChecked}
                          onChange={() => toggleSpecies(sp.id)}
                          className="rounded text-indigo-600 focus:ring-indigo-500"
                        />
                        <span className="text-[11px] truncate">
                          {sp.kiribati_name} ({sp.english_name})
                        </span>
                      </label>
                    );
                  })}
                </div>
              </div>

              {/* GeoJSON Polygon Coordinates */}
              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="font-bold text-slate-700">
                    GeoJSON Polygon Coordinates (RFC 7946) *
                  </label>
                  <button
                    type="button"
                    onClick={handleAdoptDrawnPolygon}
                    className="text-[11px] text-indigo-600 hover:text-indigo-800 font-semibold"
                  >
                    {drawnPolygon ? '✓ Adopt Drawn Map Polygon' : '✏️ Draw on Map First'}
                  </button>
                </div>
                <textarea
                  rows={5}
                  required
                  value={formData.polygon_geojson_str}
                  onChange={(e) => setFormData({ ...formData, polygon_geojson_str: e.target.value })}
                  placeholder='{"type": "Polygon", "coordinates": [[[172.915, 1.35], ...]]}'
                  className="w-full font-mono text-[11px] border border-slate-300 rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none bg-slate-900 text-emerald-400"
                />
                <span className="text-[10px] text-slate-500">
                  Must be a valid GeoJSON Polygon with closed rings (first and last vertex identical).
                </span>
              </div>

              {/* Modal Buttons */}
              <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-200">
                <button
                  type="button"
                  onClick={() => setIsFormOpen(false)}
                  className="px-4 py-2 border border-slate-300 rounded-lg text-slate-700 hover:bg-slate-100 font-medium"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={formSubmitting}
                  className="px-5 py-2 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                >
                  {formSubmitting ? 'Saving...' : editingZone ? 'Save Changes' : 'Create Advisory Zone'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* AUDIT LOGS MODAL */}
      {isAuditModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 max-h-[85vh] overflow-y-auto border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-200 pb-3 mb-4">
              <div>
                <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                  <History className="w-4 h-4 text-indigo-600" />
                  Audit Trail History
                </h3>
                <p className="text-xs text-slate-500 truncate max-w-md">{auditZoneTitle}</p>
              </div>
              <button
                type="button"
                onClick={() => setIsAuditModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 font-bold"
              >
                ✕
              </button>
            </div>

            {auditLoading ? (
              <div className="text-center py-8 text-xs text-slate-500">Loading audit history...</div>
            ) : auditLogs.length === 0 ? (
              <div className="text-center py-8 text-xs text-slate-500">No audit log records for this zone.</div>
            ) : (
              <div className="space-y-4">
                {auditLogs.map((log) => (
                  <div key={log.id} className="border-l-2 border-indigo-400 pl-3 py-1 text-xs">
                    <div className="flex items-center justify-between gap-2 mb-1">
                      <span className="font-bold text-slate-800 uppercase tracking-wider text-[10px] px-1.5 py-0.5 rounded bg-slate-100">
                        {log.action}
                      </span>
                      <span className="text-[10px] text-slate-400">
                        {new Date(log.created_at).toLocaleString()}
                      </span>
                    </div>

                    <div className="text-slate-700 font-medium">
                      User: {log.user?.name || `User #${log.user_id}`} ({log.user?.role || 'staff'})
                    </div>

                    {log.notes && <div className="text-slate-600 mt-1 italic text-[11px]">{log.notes}</div>}

                    {log.changes && (
                      <pre className="mt-1.5 p-2 bg-slate-50 border border-slate-200 rounded text-[10px] font-mono text-slate-700 overflow-x-auto">
                        {JSON.stringify(log.changes, null, 2)}
                      </pre>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
