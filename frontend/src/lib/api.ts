/**
 * Kiribati Fisheries Assistant - API Client
 */

export const API_BASE_URL =
  process.env.NEXT_PUBLIC_API_URL ||
  (typeof window !== 'undefined' ? '/api' : 'http://127.0.0.1:8000/api');

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'reviewer' | 'fisher';
  is_admin: boolean;
  is_reviewer: boolean;
}

export interface Species {
  id: number;
  kiribati_name: string;
  english_name: string;
  scientific_name: string;
  ciguatera_risk: 'low' | 'medium' | 'high' | 'none';
  notes?: string;
}

export interface CatchRecord {
  id: number;
  trip_id: number | null;
  user_id: number;
  species_id: number | null;
  ai_species_suggestion: string | null;
  ai_confidence: number | null;
  ai_metadata?: Record<string, unknown> | null;
  quantity: number;
  weight_kg: number;
  caught_at: string;
  photo_url: string | null;
  latitude: number | null;
  longitude: number | null;
  gps_protected?: boolean;
  verification_status: 'pending' | 'verified' | 'corrected' | 'flagged';
  verified_by_user_id: number | null;
  verified_at: string | null;
  verification_notes: string | null;
  user?: {
    id: number;
    name: string;
    email: string;
  };
  species?: Species;
  trip?: {
    id: number;
    vessel_name: string;
  };
}

export interface TripRoutePoint {
  id: number;
  latitude: number;
  longitude: number;
  sequence: number;
  recorded_at: string;
}

export interface TripDetail {
  trip: {
    id: number;
    vessel_name: string;
    fisherman: string;
    started_at: string;
    ended_at: string | null;
    status: string;
    notes: string | null;
  };
  route_points: TripRoutePoint[];
  catches: CatchRecord[];
}

export interface DashboardStats {
  overview: {
    total_catches: number;
    total_weight_kg: number;
    total_quantity: number;
    pending_verification: number;
    verified_catches: number;
    flagged_catches: number;
    monitored_species: number;
    active_fishers: number;
  };
  species_breakdown: Array<{
    species_id: number;
    kiribati_name: string;
    english_name: string;
    count: number;
    total_weight_kg: number;
  }>;
  timestamp: string;
}

export interface CatchFilters {
  fisherman?: string;
  species_id?: string;
  status?: string;
  date?: string;
  search?: string;
}

function getToken(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem('kiribati_auth_token');
}

export function setToken(token: string | null): void {
  if (typeof window === 'undefined') return;
  if (token) {
    localStorage.setItem('kiribati_auth_token', token);
  } else {
    localStorage.removeItem('kiribati_auth_token');
  }
}

async function request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const token = getToken();
  const headers: Record<string, string> = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    ...(options.headers as Record<string, string> || {}),
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const url = `${API_BASE_URL}${endpoint}`;
  let response: Response;
  try {
    response = await fetch(url, {
      ...options,
      headers,
    });
  } catch (err: unknown) {
    const errorMsg = err instanceof Error ? err.message : 'Network error';
    throw new Error(`Failed to connect to Fisheries API (${url}): ${errorMsg}`);
  }

  if (response.status === 401) {
    setToken(null);
    throw new Error('Session expired or unauthenticated. Please log in.');
  }

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const errorMsg = data?.message || data?.error || `API error (${response.status})`;
    const error = new Error(errorMsg);
    (error as unknown as { status: number }).status = response.status;
    (error as unknown as { data: unknown }).data = data;
    throw error;
  }

  return data as T;
}

export const api = {
  // Authentication
  async login(email: string, password: string): Promise<{ token: string; user: User }> {
    const res = await request<{ token: string; user: User }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    });
    setToken(res.token);
    return res;
  },

  async getMe(): Promise<{ user: User }> {
    return request<{ user: User }>('/auth/me');
  },

  async logout(): Promise<void> {
    try {
      await request('/auth/logout', { method: 'POST' });
    } finally {
      setToken(null);
    }
  },

  // Dashboard Stats
  async getStats(): Promise<DashboardStats> {
    return request<DashboardStats>('/admin/stats');
  },

  // Catches
  async getCatches(filters: CatchFilters = {}): Promise<{ data: CatchRecord[]; count: number; can_view_precise_gps: boolean }> {
    const params = new URLSearchParams();
    if (filters.fisherman) params.set('fisherman', filters.fisherman);
    if (filters.species_id && filters.species_id !== 'all') params.set('species_id', filters.species_id);
    if (filters.status && filters.status !== 'all') params.set('status', filters.status);
    if (filters.date) params.set('date', filters.date);
    if (filters.search) params.set('search', filters.search);

    const qs = params.toString() ? `?${params.toString()}` : '';
    return request<{ data: CatchRecord[]; count: number; can_view_precise_gps: boolean }>(`/catches${qs}`);
  },

  async getCatch(id: number): Promise<{ data: CatchRecord; can_view_precise_gps: boolean }> {
    return request<{ data: CatchRecord; can_view_precise_gps: boolean }>(`/catches/${id}`);
  },

  // Verification & Correction
  async verifyCatch(
    id: number,
    action: 'approve' | 'correct' | 'flag',
    speciesId?: number,
    notes?: string
  ): Promise<{ message: string; catch: CatchRecord }> {
    return request<{ message: string; catch: CatchRecord }>(`/admin/catches/${id}/verify`, {
      method: 'POST',
      body: JSON.stringify({
        action,
        species_id: speciesId,
        verification_notes: notes,
      }),
    });
  },

  // Species catalog
  async getSpecies(): Promise<{ data: Species[]; count: number }> {
    return request<{ data: Species[]; count: number }>('/species');
  },

  // Trips & GPS Routes
  async getTrips(): Promise<{ data: Array<{ id: number; vessel_name: string; user?: { name: string }; started_at: string; status: string; route_points_count: number; catches_count: number }> }> {
    return request('/trips');
  },

  async getTripRoute(id: number): Promise<TripDetail> {
    return request<TripDetail>(`/trips/${id}/route`);
  },
};
