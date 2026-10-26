'use client';

import React, { useState } from 'react';
import { CatchRecord, Species } from '../lib/api';
import { Sparkles, AlertCircle, Check, X, ShieldAlert, Fish } from 'lucide-react';

interface CorrectionModalProps {
  catchRecord: CatchRecord | null;
  speciesList: Species[];
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (action: 'approve' | 'correct' | 'flag', speciesId?: number, notes?: string) => Promise<void>;
}

export default function CorrectionModal({
  catchRecord,
  speciesList,
  isOpen,
  onClose,
  onSubmit,
}: CorrectionModalProps) {
  const [action, setAction] = useState<'approve' | 'correct' | 'flag'>('correct');
  const [selectedSpeciesId, setSelectedSpeciesId] = useState<number | undefined>(
    catchRecord?.species_id || undefined
  );
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!isOpen || !catchRecord) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (action === 'correct' && !selectedSpeciesId) {
      setError('Please select a corrected species from the catalog.');
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit(action, selectedSpeciesId, notes);
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to update verification status.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-200 overflow-hidden transform transition-all">
        {/* Modal Header */}
        <div className="bg-slate-900 text-white px-6 py-4 flex items-center justify-between">
          <div className="flex items-center space-x-2">
            <Fish className="w-5 h-5 text-amber-400" />
            <h3 className="font-bold text-base">Human Species Verification</h3>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-white p-1 rounded-lg transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-5">
          {/* Catch Snapshot & Preserved AI Suggestion Box */}
          <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
            <div className="flex items-center justify-between text-xs text-slate-500">
              <span>Catch #{catchRecord.id} • {catchRecord.user?.name}</span>
              <span>{new Date(catchRecord.caught_at).toLocaleDateString()}</span>
            </div>

            {/* Preserved AI Suggestion Alert */}
            <div className="bg-blue-50/80 border border-blue-200/80 rounded-lg p-3">
              <div className="flex items-start gap-2.5">
                <Sparkles className="w-4 h-4 text-blue-600 mt-0.5 flex-shrink-0" />
                <div className="text-xs">
                  <span className="font-semibold text-blue-900 block">
                    Meta AI Vision Identification:
                  </span>
                  <div className="text-blue-800 font-medium text-sm mt-0.5">
                    {catchRecord.ai_species_suggestion || 'No suggestion recorded'}
                  </div>
                  {catchRecord.ai_confidence && (
                    <span className="inline-block mt-1 bg-blue-100 text-blue-700 font-mono text-[11px] px-2 py-0.5 rounded font-semibold">
                      Confidence: {(catchRecord.ai_confidence * 100).toFixed(0)}%
                    </span>
                  )}
                  <p className="text-[11px] text-blue-700/80 mt-1 italic">
                    Note: This original AI suggestion will remain preserved permanently in database records for audit and model evaluation.
                  </p>
                </div>
              </div>
            </div>
          </div>

          {/* Verification Action Selector */}
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-2">
              Verification Decision:
            </label>
            <div className="grid grid-cols-3 gap-2">
              <button
                type="button"
                onClick={() => setAction('approve')}
                className={`py-2 px-3 rounded-lg text-xs font-medium border text-center transition ${
                  action === 'approve'
                    ? 'bg-emerald-600 text-white border-emerald-600 shadow-sm'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                }`}
              >
                Approve AI
              </button>
              <button
                type="button"
                onClick={() => setAction('correct')}
                className={`py-2 px-3 rounded-lg text-xs font-medium border text-center transition ${
                  action === 'correct'
                    ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                }`}
              >
                Correct Species
              </button>
              <button
                type="button"
                onClick={() => setAction('flag')}
                className={`py-2 px-3 rounded-lg text-xs font-medium border text-center transition ${
                  action === 'flag'
                    ? 'bg-rose-600 text-white border-rose-600 shadow-sm'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                }`}
              >
                Flag / Reject
              </button>
            </div>
          </div>

          {/* Corrected Species Selector (Shown if action is 'correct') */}
          {action === 'correct' && (
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                Select True Species:
              </label>
              <select
                value={selectedSpeciesId || ''}
                onChange={(e) => setSelectedSpeciesId(Number(e.target.value))}
                className="w-full text-xs border border-slate-300 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white text-slate-800"
                required
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

          {/* Officer Verification Notes */}
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1.5">
              Reviewer Notes / Rationale:
            </label>
            <textarea
              rows={3}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="e.g. Distinct spotting on caudal peduncle confirms Epinephelus polyphekadion."
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
              disabled={submitting}
              className="px-5 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm transition disabled:opacity-50 flex items-center gap-1.5"
            >
              {submitting ? 'Saving...' : 'Confirm Verification'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
