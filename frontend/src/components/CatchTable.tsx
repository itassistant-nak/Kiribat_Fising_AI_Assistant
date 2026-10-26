'use client';

import React from 'react';
import { CatchRecord } from '../lib/api';
import { Sparkles, CheckCircle2, Clock, AlertTriangle, MapPin, Edit3, Image as ImageIcon } from 'lucide-react';

interface CatchTableProps {
  catches: CatchRecord[];
  loading: boolean;
  onVerify: (catchRecord: CatchRecord) => void;
  onSelectOnMap: (catchRecord: CatchRecord) => void;
  selectedCatchId?: number;
}

export default function CatchTable({
  catches,
  loading,
  onVerify,
  onSelectOnMap,
  selectedCatchId,
}: CatchTableProps) {
  if (loading) {
    return (
      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
        <div className="p-4 border-b border-slate-100 flex items-center justify-between animate-pulse">
          <div className="h-4 bg-slate-200 rounded w-32"></div>
          <div className="h-4 bg-slate-200 rounded w-20"></div>
        </div>
        <div className="divide-y divide-slate-100">
          {[...Array(5)].map((_, i) => (
            <div key={i} className="p-4 flex items-center gap-4 animate-pulse">
              <div className="w-14 h-14 bg-slate-200 rounded-lg flex-shrink-0"></div>
              <div className="space-y-2 flex-1">
                <div className="h-4 bg-slate-200 rounded w-1/3"></div>
                <div className="h-3 bg-slate-100 rounded w-1/4"></div>
              </div>
              <div className="h-6 bg-slate-200 rounded w-20"></div>
              <div className="h-8 bg-slate-200 rounded w-24"></div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (catches.length === 0) {
    return (
      <div className="bg-white rounded-xl border border-dashed border-slate-300 p-12 text-center shadow-sm">
        <div className="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
          <ImageIcon className="w-6 h-6" />
        </div>
        <h4 className="text-sm font-semibold text-slate-800">No catch records found</h4>
        <p className="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
          No records match your active search or filter criteria. Try adjusting your filters or date range.
        </p>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-left border-collapse text-xs">
          <thead>
            <tr className="bg-slate-50/80 border-b border-slate-200 text-slate-500 font-semibold uppercase tracking-wider">
              <th className="py-3 px-4">Catch & Fisher</th>
              <th className="py-3 px-4">Species Confirmed</th>
              <th className="py-3 px-4">Meta AI Suggestion</th>
              <th className="py-3 px-4">Metrics</th>
              <th className="py-3 px-4">Date & Time</th>
              <th className="py-3 px-4">Status</th>
              <th className="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {catches.map((c) => {
              const isSelected = selectedCatchId === c.id;
              const hasCoordinates = c.latitude !== null && c.longitude !== null;

              return (
                <tr
                  key={c.id}
                  className={`transition hover:bg-slate-50/60 ${
                    isSelected ? 'bg-blue-50/70 border-l-4 border-l-blue-600' : ''
                  }`}
                >
                  {/* Photo & Fisher */}
                  <td className="py-3 px-4">
                    <div className="flex items-center space-x-3">
                      <div className="relative w-12 h-12 rounded-lg bg-slate-100 overflow-hidden flex-shrink-0 border border-slate-200">
                        {c.photo_url ? (
                          <img
                            src={c.photo_url}
                            alt={c.ai_species_suggestion || 'Fish catch'}
                            className="w-full h-full object-cover"
                          />
                        ) : (
                          <div className="w-full h-full flex items-center justify-center text-slate-400">
                            <ImageIcon className="w-5 h-5" />
                          </div>
                        )}
                      </div>
                      <div>
                        <div className="font-semibold text-slate-900">
                          {c.user?.name || `Fisher #${c.user_id}`}
                        </div>
                        <div className="text-[11px] text-slate-500">
                          {c.trip?.vessel_name ? `Vessel: ${c.trip.vessel_name}` : 'Artisanal catch'}
                        </div>
                      </div>
                    </div>
                  </td>

                  {/* Confirmed Species */}
                  <td className="py-3 px-4">
                    {c.species ? (
                      <div>
                        <span className="font-semibold text-slate-800">
                          {c.species.kiribati_name}
                        </span>
                        <div className="text-[11px] text-slate-500 italic">
                          {c.species.scientific_name} ({c.species.english_name})
                        </div>
                      </div>
                    ) : (
                      <span className="text-slate-400 italic">Unassigned</span>
                    )}
                  </td>

                  {/* Meta AI Vision Suggestion */}
                  <td className="py-3 px-4">
                    <div className="space-y-1">
                      <div className="flex items-center gap-1.5 font-medium text-slate-800">
                        <Sparkles className="w-3.5 h-3.5 text-blue-600 flex-shrink-0" />
                        <span>{c.ai_species_suggestion || 'None'}</span>
                      </div>
                      {c.ai_confidence !== null && (
                        <div className="inline-flex items-center gap-1 bg-blue-50 border border-blue-200/60 text-blue-700 px-1.5 py-0.5 rounded text-[10px] font-mono font-semibold">
                          Confidence: {(c.ai_confidence * 100).toFixed(0)}%
                        </div>
                      )}
                    </div>
                  </td>

                  {/* Quantity & Weight */}
                  <td className="py-3 px-4">
                    <div className="font-semibold text-slate-800">
                      {c.weight_kg.toFixed(2)} kg
                    </div>
                    <div className="text-[11px] text-slate-500">
                      {c.quantity} {c.quantity === 1 ? 'fish' : 'fishes'}
                    </div>
                  </td>

                  {/* Date & Time */}
                  <td className="py-3 px-4 text-slate-600">
                    <div>{new Date(c.caught_at).toLocaleDateString()}</div>
                    <div className="text-[10px] text-slate-400">
                      {new Date(c.caught_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                    </div>
                  </td>

                  {/* Verification Status Badge */}
                  <td className="py-3 px-4">
                    {c.verification_status === 'verified' && (
                      <span className="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold text-[11px]">
                        <CheckCircle2 className="w-3 h-3" /> Verified
                      </span>
                    )}
                    {c.verification_status === 'corrected' && (
                      <span className="inline-flex items-center gap-1 bg-blue-50 text-blue-700 border border-blue-200 px-2 py-0.5 rounded-full font-semibold text-[11px]">
                        <CheckCircle2 className="w-3 h-3" /> Corrected
                      </span>
                    )}
                    {c.verification_status === 'pending' && (
                      <span className="inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 rounded-full font-semibold text-[11px]">
                        <Clock className="w-3 h-3" /> Pending Review
                      </span>
                    )}
                    {c.verification_status === 'flagged' && (
                      <span className="inline-flex items-center gap-1 bg-rose-50 text-rose-700 border border-rose-200 px-2 py-0.5 rounded-full font-semibold text-[11px]">
                        <AlertTriangle className="w-3 h-3" /> Flagged
                      </span>
                    )}
                  </td>

                  {/* Action Controls */}
                  <td className="py-3 px-4 text-right">
                    <div className="flex items-center justify-end space-x-1.5">
                      {hasCoordinates && (
                        <button
                          onClick={() => onSelectOnMap(c)}
                          className="p-1.5 text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition"
                          title="Locate catch on Leaflet Map"
                        >
                          <MapPin className="w-4 h-4" />
                        </button>
                      )}
                      <button
                        onClick={() => onVerify(c)}
                        className="flex items-center gap-1 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:text-blue-700 bg-slate-100 hover:bg-blue-50 border border-slate-200 hover:border-blue-200 rounded-lg transition"
                      >
                        <Edit3 className="w-3 h-3" />
                        <span>Review</span>
                      </button>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
