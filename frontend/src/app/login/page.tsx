'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '../../context/AuthContext';
import { Waves, Lock, Mail, ShieldAlert, ArrowRight, ShieldCheck, UserCheck } from 'lucide-react';

export default function LoginPage() {
  const router = useRouter();
  const { login, quickLogin, error, clearError, loading } = useAuth();

  const [email, setEmail] = useState('admin@fisheries.gov.ki');
  const [password, setPassword] = useState('KiribatiAdmin2026!');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    clearError();
    try {
      await login(email, password);
      router.push('/');
    } catch {
      // Error handled in AuthContext
    } finally {
      setSubmitting(false);
    }
  };

  const handleQuickLogin = async (role: 'admin' | 'fisher') => {
    setSubmitting(true);
    clearError();
    try {
      await quickLogin(role);
      router.push('/');
    } catch {
      // Error handled in AuthContext
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative overflow-hidden">
      {/* Background Decorative Ocean Gradients */}
      <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[800px] h-[500px] bg-gradient-to-b from-blue-600/20 via-sky-600/10 to-transparent blur-3xl pointer-events-none"></div>

      <div className="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center">
        {/* Emblem */}
        <div className="mx-auto w-14 h-14 rounded-2xl bg-gradient-to-tr from-amber-500 to-blue-600 flex items-center justify-center shadow-lg shadow-blue-900/30 mb-4">
          <Waves className="w-8 h-8 text-white" />
        </div>
        <h2 className="text-2xl font-extrabold tracking-tight text-white sm:text-3xl">
          Kiribati AI Fisheries
        </h2>
        <p className="mt-1 text-xs text-slate-400 font-medium">
          Ministry of Fisheries & Marine Resources Development (MFMRD)
        </p>
        <div className="mt-2 inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-blue-900/40 border border-blue-700/50 text-[11px] font-mono text-blue-300">
          <ShieldCheck className="w-3 h-3 text-blue-400" />
          <span>Authorized Officer & Reviewer Portal</span>
        </div>
      </div>

      <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md relative z-10">
        <div className="bg-slate-900 border border-slate-800 py-8 px-6 shadow-2xl rounded-2xl sm:px-10">
          {error && (
            <div className="mb-5 bg-red-950/60 border border-red-800/80 text-red-200 text-xs p-3.5 rounded-xl flex items-start gap-2.5">
              <ShieldAlert className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" />
              <div>
                <span className="font-semibold block">Authentication Failed</span>
                <span className="text-[11px] text-red-300">{error}</span>
              </div>
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Official Email Address
              </label>
              <div className="relative">
                <Mail className="w-4 h-4 text-slate-500 absolute left-3 top-3" />
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  placeholder="admin@fisheries.gov.ki"
                  className="w-full pl-9 pr-3 py-2.5 text-xs bg-slate-800 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Password
              </label>
              <div className="relative">
                <Lock className="w-4 h-4 text-slate-500 absolute left-3 top-3" />
                <input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  placeholder="••••••••••••"
                  className="w-full pl-9 pr-3 py-2.5 text-xs bg-slate-800 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={submitting || loading}
              className="w-full mt-2 py-2.5 px-4 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition flex items-center justify-center gap-2 disabled:opacity-50"
            >
              <span>{submitting ? 'Authenticating...' : 'Sign In to Dashboard'}</span>
              <ArrowRight className="w-3.5 h-3.5" />
            </button>
          </form>

          {/* Quick Demo Credentials Panel */}
          <div className="mt-6 pt-6 border-t border-slate-800">
            <p className="text-[11px] font-mono uppercase tracking-wider text-slate-400 text-center mb-3">
              One-Click Test Accounts
            </p>
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => handleQuickLogin('admin')}
                disabled={submitting}
                className="flex items-center justify-center gap-1.5 p-2 bg-slate-800/80 hover:bg-slate-700 border border-slate-700 hover:border-slate-600 text-slate-200 text-xs rounded-xl font-medium transition"
              >
                <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
                <span>Admin Login</span>
              </button>
              <button
                type="button"
                onClick={() => handleQuickLogin('fisher')}
                disabled={submitting}
                className="flex items-center justify-center gap-1.5 p-2 bg-slate-800/80 hover:bg-slate-700 border border-slate-700 hover:border-slate-600 text-slate-200 text-xs rounded-xl font-medium transition"
              >
                <UserCheck className="w-3.5 h-3.5 text-amber-400" />
                <span>Fisher Login</span>
              </button>
            </div>
            <p className="text-[10px] text-slate-400 text-center mt-2">
              Note: Non-administrator accounts have restricted access to protect fisher GPS locations.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
