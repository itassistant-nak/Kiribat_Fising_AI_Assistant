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
  ciguatera_risk: 'low' | 'medium' | 'high' | 'extreme' | 'unknown' | 'none';
  notes?: string;
  photo_url?: string | null;
  catches_count?: number;
  advisory_zones_count?: number;
  visual_features?: string[];
  ciguatera_warning?: string | null;
  ai_trained?: boolean;
  confidence_range?: [number, number];
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
  is_catch?: boolean;
  is_fisher_catch?: boolean;
  verification_status: 'pending' | 'verified' | 'corrected' | 'flagged' | 'not_a_catch';
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
    not_a_catch_count?: number;
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

export interface MetaAiHealthResponse {
  status: 'connected' | 'degraded' | 'unconfigured';
  endpoint: string;
  model: string;
  has_api_key: boolean;
  latency_ms: number;
  response?: string | null;
  message: string;
  tested_at: string;
  disclaimer: string;
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
    ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
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
    action: 'approve' | 'correct' | 'flag' | 'not_a_catch',
    speciesId?: number,
    notes?: string,
    options?: {
      photoUrl?: string;
      photoFile?: File | null;
      aiSpeciesSuggestion?: string;
      aiConfidence?: number;
    }
  ): Promise<{ message: string; catch: CatchRecord; photo_updated?: boolean }> {
    if (options?.photoFile) {
      const formData = new FormData();
      formData.append('action', action);
      if (speciesId) formData.append('species_id', String(speciesId));
      if (notes) formData.append('verification_notes', notes);
      formData.append('photo', options.photoFile);
      if (options.aiSpeciesSuggestion) {
        formData.append('ai_species_suggestion', options.aiSpeciesSuggestion);
      }
      if (options.aiConfidence !== undefined) {
        formData.append('ai_confidence', String(options.aiConfidence));
      }
      return request<{ message: string; catch: CatchRecord; photo_updated?: boolean }>(`/admin/catches/${id}/verify`, {
        method: 'POST',
        body: formData,
      });
    }

    return request<{ message: string; catch: CatchRecord; photo_updated?: boolean }>(`/admin/catches/${id}/verify`, {
      method: 'POST',
      body: JSON.stringify({
        action,
        species_id: speciesId,
        verification_notes: notes,
        photo_url: options?.photoUrl,
        ai_species_suggestion: options?.aiSpeciesSuggestion,
        ai_confidence: options?.aiConfidence,
      }),
    });
  },

  // Species catalog
  async getSpecies(filters?: { search?: string; ciguatera_risk?: string }): Promise<{ data: Species[]; count: number; knowledge_base_count?: number }> {
    const params = new URLSearchParams();
    if (filters?.search) params.set('search', filters.search);
    if (filters?.ciguatera_risk && filters.ciguatera_risk !== 'all') params.set('ciguatera_risk', filters.ciguatera_risk);
    const qs = params.toString() ? `?${params.toString()}` : '';
    return request<{ data: Species[]; count: number; knowledge_base_count?: number }>(`/species${qs}`);
  },

  // Trips & GPS Routes
  async getTrips(): Promise<{ data: Array<{ id: number; vessel_name: string; user?: { name: string }; started_at: string; status: string; route_points_count: number; catches_count: number }> }> {
    return request('/trips');
  },

  async getTripRoute(id: number): Promise<TripDetail> {
    return request<TripDetail>(`/trips/${id}/route`);
  },

  // Advisory Zones & Fish Poisoning Management
  async getAdvisoryZones(filters: { status?: string; classification?: string; species_id?: string; search?: string } = {}): Promise<{ data: AdvisoryZone[]; count: number; is_staff: boolean; safety_disclaimer: string }> {
    const params = new URLSearchParams();
    if (filters.status && filters.status !== 'all') params.set('status', filters.status);
    if (filters.classification && filters.classification !== 'all') params.set('classification', filters.classification);
    if (filters.species_id && filters.species_id !== 'all') params.set('species_id', filters.species_id);
    if (filters.search) params.set('search', filters.search);

    const qs = params.toString() ? `?${params.toString()}` : '';
    return request<{ data: AdvisoryZone[]; count: number; is_staff: boolean; safety_disclaimer: string }>(`/advisory-zones${qs}`);
  },

  async getAdvisoryZone(id: number): Promise<{ data: AdvisoryZone }> {
    return request<{ data: AdvisoryZone }>(`/advisory-zones/${id}`);
  },

  async createAdvisoryZone(payload: {
    title: string;
    concern: string;
    evidence?: string;
    description?: string;
    classification: 'suspected' | 'under_investigation' | 'authority-confirmed';
    status?: 'draft' | 'under_investigation' | 'approved' | 'published';
    source?: string;
    review_date?: string;
    polygon_geojson: GeoJsonPolygon;
    species_ids?: number[];
  }): Promise<{ message: string; data: AdvisoryZone }> {
    return request<{ message: string; data: AdvisoryZone }>('/advisory-zones', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  async updateAdvisoryZone(id: number, payload: {
    title?: string;
    concern?: string;
    evidence?: string;
    description?: string;
    classification?: 'suspected' | 'under_investigation' | 'authority-confirmed';
    status?: 'draft' | 'under_investigation' | 'approved' | 'published' | 'archived';
    source?: string;
    review_date?: string;
    polygon_geojson?: GeoJsonPolygon;
    species_ids?: number[];
  }): Promise<{ message: string; data: AdvisoryZone }> {
    return request<{ message: string; data: AdvisoryZone }>(`/advisory-zones/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },

  async publishAdvisoryZone(id: number): Promise<{ message: string; data: AdvisoryZone }> {
    return request<{ message: string; data: AdvisoryZone }>(`/advisory-zones/${id}/publish`, {
      method: 'POST',
    });
  },

  async unpublishAdvisoryZone(id: number): Promise<{ message: string; data: AdvisoryZone }> {
    return request<{ message: string; data: AdvisoryZone }>(`/advisory-zones/${id}/unpublish`, {
      method: 'POST',
    });
  },

  async deleteAdvisoryZone(id: number): Promise<{ message: string }> {
    return request<{ message: string }>(`/advisory-zones/${id}`, {
      method: 'DELETE',
    });
  },

  async checkPointAdvisory(latitude: number, longitude: number): Promise<CheckPointResult> {
    return request<CheckPointResult>('/advisory-zones/check-point', {
      method: 'POST',
      body: JSON.stringify({ latitude, longitude }),
    });
  },

  async getAdvisoryAuditLogs(id: number): Promise<{ advisory_zone_id: number; zone_title: string; audit_logs: AdvisoryAuditLog[]; count: number }> {
    return request<{ advisory_zone_id: number; zone_title: string; audit_logs: AdvisoryAuditLog[]; count: number }>(`/advisory-zones/${id}/audit-logs`);
  },

  async identifyFish(payload: { image?: File | string; image_url?: string; hint?: string }): Promise<AiIdentificationResponse> {
    if (payload.image instanceof File) {
      const formData = new FormData();
      formData.append('image', payload.image);
      if (payload.hint) formData.append('hint', payload.hint);
      return request<AiIdentificationResponse>('/ai/identify-fish', {
        method: 'POST',
        body: formData,
      });
    }

    return request<AiIdentificationResponse>('/ai/identify-fish', {
      method: 'POST',
      body: JSON.stringify({
        image: typeof payload.image === 'string' ? payload.image : undefined,
        image_url: payload.image_url,
        hint: payload.hint,
      }),
    });
  },

  async getMetaAiHealth(): Promise<MetaAiHealthResponse> {
    return request<MetaAiHealthResponse>('/ai/meta-health');
  },

  async createCatch(payload: {
    species_id?: number;
    kiribati_name?: string;
    english_name?: string;
    scientific_name?: string;
    photo_url?: string;
    photo?: File;
    ai_species_suggestion?: string;
    ai_confidence?: number;
    weight_kg?: number;
    quantity?: number;
    latitude?: number;
    longitude?: number;
    verification_notes?: string;
    verification_status?: 'pending' | 'verified' | 'corrected' | 'flagged';
  }): Promise<{ message: string; catch: CatchRecord }> {
    if (payload.photo instanceof File) {
      const formData = new FormData();
      Object.entries(payload).forEach(([key, val]) => {
        if (val !== undefined && val !== null) {
          if (key === 'photo' && val instanceof File) {
            formData.append('photo', val);
          } else {
            formData.append(key, String(val));
          }
        }
      });
      return request<{ message: string; catch: CatchRecord }>('/catches', {
        method: 'POST',
        body: formData,
      });
    }

    return request<{ message: string; catch: CatchRecord }>('/catches', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  async createSpecies(payload: {
    kiribati_name: string;
    english_name: string;
    scientific_name?: string;
    ciguatera_risk?: 'low' | 'medium' | 'high' | 'extreme' | 'unknown';
    photo_url?: string;
    notes?: string;
  }): Promise<{ message: string; species: Species }> {
    return request<{ message: string; species: Species }>('/species', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  async updateSpecies(
    id: number,
    payload: {
      kiribati_name?: string;
      english_name?: string;
      scientific_name?: string;
      ciguatera_risk?: 'low' | 'medium' | 'high' | 'extreme' | 'unknown' | 'none';
      photo_url?: string;
      notes?: string;
      visual_features?: string[];
    }
  ): Promise<{ message: string; species: Species }> {
    return request<{ message: string; species: Species }>(`/species/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },
};

export interface AiIdentificationCandidate {
  kiribati_name: string;
  english_name: string;
  scientific_name: string;
  confidence: number;
}

export interface AiIdentificationResult {
  species_id?: number | null;
  kiribati_name: string;
  english_name: string;
  scientific_name: string;
  confidence: number;
  ciguatera_risk: 'low' | 'medium' | 'high' | 'extreme' | 'unknown';
  ciguatera_warning?: string;
  visual_features: string[];
  model: string;
  model_version: string;
  analyzed_at: string;
  candidates: AiIdentificationCandidate[];
}

export interface AiIdentificationResponse {
  status: string;
  image_url: string;
  identification: AiIdentificationResult;
  disclaimer: string;
}

export interface GeoJsonPolygon {
  type: 'Polygon';
  coordinates: number[][][];
}

export interface AdvisoryZoneSpecies extends Species {
  pivot?: {
    risk_level: 'low' | 'medium' | 'high' | 'extreme';
    notes?: string;
  };
}

export interface AdvisoryZone {
  id: number;
  title: string;
  description?: string | null;
  concern: string;
  evidence?: string | null;
  classification: 'suspected' | 'under_investigation' | 'authority-confirmed';
  status: 'draft' | 'under_investigation' | 'approved' | 'published' | 'archived';
  polygon_geojson: GeoJsonPolygon;
  source: string;
  review_date?: string | null;
  created_by_user_id: number;
  updated_by_user_id?: number | null;
  approved_by_user_id?: number | null;
  approved_at?: string | null;
  published_at?: string | null;
  created_at: string;
  updated_at: string;
  species?: AdvisoryZoneSpecies[];
  creator?: { id: number; name: string; role: string };
  updater?: { id: number; name: string; role: string };
  approver?: { id: number; name: string; role: string };
  audit_logs?: AdvisoryAuditLog[];
}

export interface AdvisoryAuditLog {
  id: number;
  advisory_zone_id: number;
  user_id: number;
  action: string;
  changes?: Record<string, unknown> | null;
  notes?: string | null;
  ip_address?: string | null;
  created_at: string;
  user?: {
    id: number;
    name: string;
    role: string;
    email: string;
  };
}

export interface CheckPointResult {
  inside_advisory_zone: boolean;
  latitude: number;
  longitude: number;
  zones_count: number;
  matching_zones: Array<{
    id: number;
    title: string;
    concern: string;
    evidence?: string | null;
    classification: string;
    source: string;
    review_date?: string | null;
    species: Species[];
  }>;
  affected_species: Species[];
  safety_notice: string;
}
