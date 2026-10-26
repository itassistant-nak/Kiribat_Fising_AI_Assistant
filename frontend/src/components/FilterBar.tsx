'use client';

import React from 'react';
import { CatchFilters, Species } from '../lib/api';
import { Search, Filter, RotateCcw, Calendar, CheckCircle2, Clock, AlertTriangle, Layers } from 'lucide-react';

interface FilterBarProps {
  filters: CatchFilters;
  onFilterChange: (newFilters: CatchFilters) => void;
  speciesList: Species[];
  totalMatches: number;
  loading: boolean;
}

export default function FilterBar({
  filters,
  onFilterChange,
  speciesList,
  totalMatches,
  loading,
}: FilterBarProps) {
  const handleInputChange = (field: keyof CatchFilters, value: string) => {
    onFilterChange({
      ...filters,
      [field]: value,
    });
  };

  const handleReset = () => {
    onFilterChange({
      fisherman: '',
      species_id: 'all',
      status: 'all',
      date: '',
      search: '',
    });
  };

  const hasActiveFilters =
    Boolean(filters.fisherman) ||
    Boolean(filters.search) ||
    Boolean(filters.date) ||
    (filters.species_id && filters.species_id !== 'all') ||
    (filters.status && filters.status !== 'all');

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm space-y-3">
      {/* Top row: Status Tabs & Result Count */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="text-xs font-semibold text-slate-500 mr-1 flex items-center gap-1">
            <Filter className="w-3.5 h-3.5" /> Status:
          </span>
          {[
            { id: 'all', label: 'All Catches', icon: Layers },
            { id: 'pending', label: 'Pending Review', icon: Clock, badge: 'Needs AI Check' },
            { id: 'verified', label: 'Verified', icon: CheckCircle2 },
            { id: 'flagged', label: 'Flagged', icon: AlertTriangle },
          ].map((tab) => {
            const Icon = tab.icon;
            const isActive = (filters.status || 'all') === tab.id;
            return (
              <button
                key={tab.id}
                onClick={() => handleInputChange('status', tab.id)}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition ${
                  isActive
                    ? 'bg-blue-600 text-white shadow-sm'
                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                }`}
              >
                <Icon className="w-3.5 h-3.5" />
                <span>{tab.label}</span>
              </button>
            );
          })}
        </div>

        <div className="flex items-center gap-2 text-xs text-slate-600">
          <span className="font-semibold text-slate-900 bg-slate-100 px-2 py-1 rounded">
            {totalMatches} {totalMatches === 1 ? 'record' : 'records'}
          </span>
          {hasActiveFilters && (
            <button
              onClick={handleReset}
              className="flex items-center gap-1 text-blue-600 hover:text-blue-800 font-medium px-2 py-1 hover:bg-blue-50 rounded transition"
            >
              <RotateCcw className="w-3 h-3" />
              Reset Filters
            </button>
          )}
        </div>
      </div>

      {/* Second row: Inputs for Fisherman, Species, Date, and Search */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        {/* Keyword Search */}
        <div className="relative">
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
          <input
            type="text"
            placeholder="Search AI suggestion, notes..."
            value={filters.search || ''}
            onChange={(e) => handleInputChange('search', e.target.value)}
            className="w-full pl-9 pr-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-slate-50/50"
          />
        </div>

        {/* Fisherman Filter */}
        <div>
          <input
            type="text"
            placeholder="Filter by fisherman name..."
            value={filters.fisherman || ''}
            onChange={(e) => handleInputChange('fisherman', e.target.value)}
            className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-slate-50/50"
          />
        </div>

        {/* Species Select */}
        <div>
          <select
            value={filters.species_id || 'all'}
            onChange={(e) => handleInputChange('species_id', e.target.value)}
            className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-slate-50/50 text-slate-700"
          >
            <option value="all">All Species</option>
            {speciesList.map((s) => (
              <option key={s.id} value={s.id}>
                {s.kiribati_name} ({s.english_name})
              </option>
            ))}
          </select>
        </div>

        {/* Date Filter */}
        <div className="relative">
          <input
            type="date"
            value={filters.date || ''}
            onChange={(e) => handleInputChange('date', e.target.value)}
            className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-slate-50/50 text-slate-700"
          />
        </div>
      </div>
    </div>
  );
}
