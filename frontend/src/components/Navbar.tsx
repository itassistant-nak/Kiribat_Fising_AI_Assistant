'use client';

import React from 'react';
import { useAuth } from '../context/AuthContext';
import { ShieldCheck, UserCheck, LogOut, Waves, Fish, Anchor } from 'lucide-react';

interface NavbarProps {
  onRefresh?: () => void;
  isRefreshing?: boolean;
}

export default function Navbar({ onRefresh, isRefreshing }: NavbarProps) {
  const { user, logout, quickLogin, loading } = useAuth();

  return (
    <header className="bg-slate-900 border-b border-slate-800 text-white sticky top-0 z-50 shadow-md">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          {/* Logo & Portal Branding */}
          <div className="flex items-center space-x-3">
            <div className="w-10 h-10 rounded-lg bg-gradient-to-tr from-amber-500 to-blue-600 flex items-center justify-center shadow-md">
              <Waves className="w-6 h-6 text-white" />
            </div>
            <div>
              <div className="flex items-center space-x-2">
                <span className="font-bold text-lg tracking-tight text-white">
                  Kiribati AI Fisheries
                </span>
                <span className="bg-blue-600/30 border border-blue-500/40 text-blue-300 text-xs px-2 py-0.5 rounded-full font-mono uppercase">
                  Admin Portal
                </span>
              </div>
              <p className="text-xs text-slate-400 hidden sm:block">
                Ministry of Fisheries & Marine Resources Development (MFMRD)
              </p>
            </div>
          </div>

          {/* User & Role Controls */}
          <div className="flex items-center space-x-3">
            {user ? (
              <div className="flex items-center space-x-3">
                {/* Role Badge */}
                <div className="flex items-center space-x-2 bg-slate-800/80 border border-slate-700 px-3 py-1.5 rounded-lg text-sm">
                  {user.is_admin ? (
                    <ShieldCheck className="w-4 h-4 text-emerald-400" />
                  ) : (
                    <UserCheck className="w-4 h-4 text-amber-400" />
                  )}
                  <div className="text-left leading-tight hidden md:block">
                    <div className="font-medium text-slate-200 text-xs">{user.name}</div>
                    <div className="text-[10px] uppercase font-semibold tracking-wider text-slate-400">
                      {user.role}
                    </div>
                  </div>
                  <span
                    className={`inline-block px-1.5 py-0.5 text-[10px] font-bold rounded uppercase ${
                      user.is_admin
                        ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                        : 'bg-amber-500/20 text-amber-300 border border-amber-500/30'
                    }`}
                  >
                    {user.role}
                  </span>
                </div>

                {/* Quick Role Toggle (For Acceptance Testing) */}
                <div className="hidden lg:flex items-center space-x-1 bg-slate-800/60 p-1 rounded-lg text-xs">
                  <span className="text-slate-400 text-[10px] px-1 font-mono">Test Role:</span>
                  <button
                    onClick={() => quickLogin('admin')}
                    disabled={loading || user.is_admin}
                    className={`px-2 py-1 rounded text-xs font-medium transition ${
                      user.is_admin
                        ? 'bg-blue-600 text-white shadow-sm'
                        : 'text-slate-300 hover:text-white hover:bg-slate-700'
                    }`}
                  >
                    Admin
                  </button>
                  <button
                    onClick={() => quickLogin('fisher')}
                    disabled={loading || !user.is_admin}
                    className={`px-2 py-1 rounded text-xs font-medium transition ${
                      !user.is_admin
                        ? 'bg-amber-600 text-white shadow-sm'
                        : 'text-slate-300 hover:text-white hover:bg-slate-700'
                    }`}
                  >
                    Fisher
                  </button>
                </div>

                {/* Logout Button */}
                <button
                  onClick={logout}
                  className="flex items-center space-x-1.5 text-xs text-slate-300 hover:text-white bg-slate-800 hover:bg-red-900/40 hover:border-red-500/40 border border-slate-700 px-3 py-1.5 rounded-lg transition"
                  title="Sign out of administrative dashboard"
                >
                  <LogOut className="w-3.5 h-3.5" />
                  <span className="hidden sm:inline">Sign Out</span>
                </button>
              </div>
            ) : (
              <div className="flex items-center space-x-2">
                <button
                  onClick={() => quickLogin('admin')}
                  className="bg-blue-600 hover:bg-blue-500 text-white text-xs px-3 py-1.5 rounded-lg font-medium transition shadow-sm"
                >
                  Quick Login (Admin)
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    </header>
  );
}
