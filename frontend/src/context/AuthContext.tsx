'use client';

import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { api, User, setToken } from '../lib/api';

interface AuthContextType {
  user: User | null;
  token: string | null;
  loading: boolean;
  error: string | null;
  login: (email: string, password: string) => Promise<void>;
  quickLogin: (role: 'admin' | 'fisher') => Promise<void>;
  logout: () => Promise<void>;
  clearError: () => void;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [token, setTokenState] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const initAuth = useCallback(async () => {
    // Add a race condition safety timer so initAuth never blocks longer than 4.5 seconds
    const timeoutPromise = new Promise<never>((_, reject) =>
      setTimeout(() => reject(new Error('Auth initialization timeout')), 4500)
    );

    try {
      await Promise.race([
        (async () => {
          const storedToken = typeof window !== 'undefined' ? localStorage.getItem('kiribati_auth_token') : null;
          if (storedToken) {
            setTokenState(storedToken);
            try {
              const res = await api.getMe();
              setUser(res.user);
              return;
            } catch {
              // Token expired or invalid, purge from storage
              setToken(null);
              setTokenState(null);
            }
          }
          // Default to Administrator
          const res = await api.login('admin@fisheries.gov.ki', 'KiribatiAdmin2026!');
          setUser(res.user);
          setTokenState(res.token);
        })(),
        timeoutPromise,
      ]);
    } catch (err) {
      console.warn('Auth initialization fallback:', err);
      setToken(null);
      setUser(null);
      setTokenState(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    initAuth();
  }, [initAuth]);

  const login = async (email: string, password: string) => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.login(email, password);
      setUser(res.user);
      setTokenState(res.token);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Login failed';
      setError(msg);
      throw err;
    } finally {
      setLoading(false);
    }
  };

  const quickLogin = async (role: 'admin' | 'fisher') => {
    if (role === 'admin') {
      await login('admin@fisheries.gov.ki', 'KiribatiAdmin2026!');
    } else {
      await login('teititeki@fisher.ki', 'FisherPass2026!');
    }
  };

  const logout = async () => {
    setLoading(true);
    try {
      await api.logout();
    } finally {
      setUser(null);
      setTokenState(null);
      setLoading(false);
    }
  };

  const clearError = () => setError(null);

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        loading,
        error,
        login,
        quickLogin,
        logout,
        clearError,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextType {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
