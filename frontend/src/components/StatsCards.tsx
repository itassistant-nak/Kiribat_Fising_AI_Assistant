'use client';

import React from 'react';
import { DashboardStats } from '../lib/api';
import { Fish, Scale, Clock, CheckCircle2, ShieldAlert, Users, Layers } from 'lucide-react';

interface StatsCardsProps {
  stats: DashboardStats | null;
  loading: boolean;
  error?: string | null;
}

export default function StatsCards({ stats, loading, error }: StatsCardsProps) {
  if (loading) {
    return (
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        {[...Array(6)].map((_, i) => (
          <div
            key={i}
            className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm animate-pulse"
          >
            <div className="h-4 bg-slate-200 rounded w-16 mb-3"></div>
            <div className="h-7 bg-slate-200 rounded w-24 mb-2"></div>
            <div className="h-3 bg-slate-100 rounded w-20"></div>
          </div>
        ))}
      </div>
    );
  }

  if (error || !stats) {
    return null;
  }

  const { overview } = stats;

  const cards = [
    {
      title: 'Fishermen Catches',
      value: overview.total_catches.toLocaleString(),
      subtext: `${overview.total_quantity} fish (genuine catches)`,
      icon: Fish,
      color: 'text-blue-600',
      bgColor: 'bg-blue-50',
      borderColor: 'border-blue-100',
    },
    {
      title: 'Landed Weight',
      value: `${overview.total_weight_kg.toFixed(1)} kg`,
      subtext: 'Cumulative biomass',
      icon: Scale,
      color: 'text-indigo-600',
      bgColor: 'bg-indigo-50',
      borderColor: 'border-indigo-100',
    },
    {
      title: 'Pending AI Review',
      value: overview.pending_verification.toLocaleString(),
      subtext: 'Awaiting human officer',
      icon: Clock,
      color: 'text-amber-600',
      bgColor: 'bg-amber-50',
      borderColor: 'border-amber-200',
      highlight: overview.pending_verification > 0,
    },
    {
      title: 'Verified Catches',
      value: overview.verified_catches.toLocaleString(),
      subtext: 'Approved species record',
      icon: CheckCircle2,
      color: 'text-emerald-600',
      bgColor: 'bg-emerald-50',
      borderColor: 'border-emerald-100',
    },
    {
      title: 'Flagged / Blurry',
      value: overview.flagged_catches.toLocaleString(),
      subtext: overview.not_a_catch_count !== undefined && overview.not_a_catch_count > 0
        ? `${overview.not_a_catch_count} non-catches excluded`
        : 'Requires resubmission',
      icon: ShieldAlert,
      color: 'text-rose-600',
      bgColor: 'bg-rose-50',
      borderColor: 'border-rose-100',
    },
    {
      title: 'Active Fishers',
      value: overview.active_fishers.toLocaleString(),
      subtext: `${overview.monitored_species} species tracked`,
      icon: Users,
      color: 'text-cyan-600',
      bgColor: 'bg-cyan-50',
      borderColor: 'border-cyan-100',
    },
  ];

  return (
    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
      {cards.map((card, i) => {
        const Icon = card.icon;
        return (
          <div
            key={i}
            className={`bg-white rounded-xl border p-4 shadow-sm transition hover:shadow-md relative overflow-hidden ${
              card.highlight ? 'border-amber-400 ring-2 ring-amber-100' : 'border-slate-200'
            }`}
          >
            <div className="flex items-center justify-between mb-2">
              <span className="text-xs font-semibold uppercase tracking-wider text-slate-500 truncate">
                {card.title}
              </span>
              <div className={`p-1.5 rounded-lg ${card.bgColor} ${card.color}`}>
                <Icon className="w-4 h-4" />
              </div>
            </div>
            <div className="text-2xl font-bold text-slate-900 tracking-tight">
              {card.value}
            </div>
            <p className="text-[11px] text-slate-500 mt-1 truncate">
              {card.subtext}
            </p>
          </div>
        );
      })}
    </div>
  );
}
