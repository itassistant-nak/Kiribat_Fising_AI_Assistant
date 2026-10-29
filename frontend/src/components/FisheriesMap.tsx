'use client';

import React, { useEffect, useRef, useState, useMemo } from 'react';
import { CatchRecord, TripDetail, AdvisoryZone, api } from '../lib/api';
import {
  MapPin,
  Navigation,
  Eye,
  EyeOff,
  ShieldCheck,
  ShieldAlert,
  Layers,
  RefreshCw,
  AlertOctagon,
  AlertTriangle,
} from 'lucide-react';

interface FisheriesMapProps {
  catches: CatchRecord[];
  selectedCatch?: CatchRecord | null;
  canViewPreciseGps: boolean;
  advisoryZones?: AdvisoryZone[];
}

/**
 * Fast ray-casting algorithm to test whether a coordinate lies inside a GeoJSON Polygon
 */
function isCoordinateInsidePolygon(lat: number, lng: number, coordinates: number[][][]): boolean {
  if (!coordinates || !coordinates.length) return false;
  // Test outer ring
  const outerRing = coordinates[0];
  let inside = false;
  for (let i = 0, j = outerRing.length - 1; i < outerRing.length; j = i++) {
    const xi = outerRing[i][0]; // Lng
    const yi = outerRing[i][1]; // Lat
    const xj = outerRing[j][0]; // Lng
    const yj = outerRing[j][1]; // Lat

    const intersect = yi > lat !== yj > lat && lng < ((xj - xi) * (lat - yi)) / (yj - yi) + xi;
    if (intersect) inside = !inside;
  }
  return inside;
}

export default function FisheriesMap({
  catches,
  selectedCatch,
  canViewPreciseGps,
  advisoryZones = [],
}: FisheriesMapProps) {
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const mapInstanceRef = useRef<any>(null);
  const markersLayerRef = useRef<any>(null);
  const routesLayerRef = useRef<any>(null);
  const advisoriesLayerRef = useRef<any>(null);

  const [mapReady, setMapReady] = useState(false);
  const [showCatches, setShowCatches] = useState(true);
  const [showRoutes, setShowRoutes] = useState(true);
  const [showAdvisories, setShowAdvisories] = useState(true);

  const [activeTripDetail, setActiveTripDetail] = useState<TripDetail | null>(null);
  const [loadingTrip, setLoadingTrip] = useState(false);
  const [tripsList, setTripsList] = useState<Array<{ id: number; vessel_name: string; user?: { name: string } }>>([]);
  const [selectedTripId, setSelectedTripId] = useState<number | null>(null);

  // Published zones for display
  const publishedZones = useMemo(() => {
    return (advisoryZones || []).filter((z) => z.status === 'published');
  }, [advisoryZones]);

  // Load trips list on mount
  useEffect(() => {
    async function loadTrips() {
      try {
        const res = await api.getTrips();
        setTripsList(res.data);
        if (res.data.length > 0) {
          setSelectedTripId(res.data[0].id);
        }
      } catch (err) {
        console.warn('Could not load trips for map:', err);
      }
    }
    loadTrips();
  }, []);

  // Fetch route points when selectedTripId changes
  useEffect(() => {
    if (!selectedTripId) return;
    let isCurrent = true;
    setLoadingTrip(true);
    api
      .getTripRoute(selectedTripId)
      .then((detail) => {
        if (isCurrent) {
          setActiveTripDetail(detail);
        }
      })
      .catch((err) => {
        console.warn('Could not load trip route:', err);
      })
      .finally(() => {
        if (isCurrent) setLoadingTrip(false);
      });

    return () => {
      isCurrent = false;
    };
  }, [selectedTripId]);

  // Initialize Leaflet map
  useEffect(() => {
    if (typeof window === 'undefined' || !mapContainerRef.current) return;
    let isMounted = true;

    import('leaflet').then((L) => {
      if (!isMounted || !mapContainerRef.current) return;

      const container = mapContainerRef.current;

      // Clean up previous instance on this DOM node if any
      if (mapInstanceRef.current) {
        try {
          mapInstanceRef.current.remove();
        } catch (_) {}
        mapInstanceRef.current = null;
      }

      if ((container as any)._leaflet_id) {
        delete (container as any)._leaflet_id;
      }
      container.innerHTML = '';

      try {
        // Center on South Tarawa Atoll (Betio - Bairiki lagoon)
        const map = L.map(container, {
          center: [1.365, 172.980],
          zoom: 12,
          scrollWheelZoom: true,
        });

        // OpenStreetMap tile layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
          maxZoom: 18,
        }).addTo(map);

        advisoriesLayerRef.current = L.layerGroup().addTo(map);
        routesLayerRef.current = L.layerGroup().addTo(map);
        markersLayerRef.current = L.layerGroup().addTo(map);

        mapInstanceRef.current = map;
        setMapReady(true);

        setTimeout(() => {
          if (isMounted && mapInstanceRef.current) {
            mapInstanceRef.current.invalidateSize();
          }
        }, 100);

        setTimeout(() => {
          if (isMounted && mapInstanceRef.current) {
            mapInstanceRef.current.invalidateSize();
          }
        }, 300);

        const handleResize = () => {
          if (mapInstanceRef.current) {
            mapInstanceRef.current.invalidateSize();
          }
        };
        window.addEventListener('resize', handleResize);

        return () => {
          window.removeEventListener('resize', handleResize);
        };
      } catch (err) {
        console.error('Leaflet map initialization error in FisheriesMap:', err);
      }
    });

    return () => {
      isMounted = false;
      if (mapInstanceRef.current) {
        try {
          mapInstanceRef.current.remove();
        } catch (_) {}
        mapInstanceRef.current = null;
      }
      markersLayerRef.current = null;
      routesLayerRef.current = null;
      advisoriesLayerRef.current = null;
      setMapReady(false);
    };
  }, []);

  // Update Published Advisory Zones Layer
  useEffect(() => {
    if (typeof window === 'undefined' || !mapReady || !advisoriesLayerRef.current) return;

    import('leaflet').then((L) => {
      if (!advisoriesLayerRef.current) return;
      advisoriesLayerRef.current.clearLayers();

      if (!showAdvisories) return;

      publishedZones.forEach((zone) => {
        if (!zone.polygon_geojson || !zone.polygon_geojson.coordinates) return;

        const rings = zone.polygon_geojson.coordinates;
        // Convert GeoJSON [lng, lat] to Leaflet [lat, lng]
        const latLngs = rings.map((ring) =>
          ring.map((pos) => [pos[1], pos[0]] as [number, number])
        );

        const color = zone.classification === 'authority-confirmed' ? '#dc2626' : '#ea580c';

        const polygon = L.polygon(latLngs, {
          color: color,
          fillColor: color,
          fillOpacity: 0.22,
          weight: 2,
          dashArray: '5, 5',
        }).addTo(advisoriesLayerRef.current);

        const speciesListHtml =
          zone.species && zone.species.length > 0
            ? zone.species
                .map(
                  (s) =>
                    `<span style="display: inline-block; padding: 1px 5px; font-size: 10px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 4px; margin-right: 4px; margin-bottom: 2px;">
                      ${s.kiribati_name} (${s.english_name})
                    </span>`
                )
                .join('')
            : '<span style="font-size: 11px; color: #94a3b8; font-style: italic;">General reef species</span>';

        const popupContent = `
          <div style="font-family: sans-serif; font-size: 12px; line-height: 1.4; min-width: 200px; max-width: 280px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
              <span style="font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 2px 6px; border-radius: 9999px; background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;">
                Active Poisoning Advisory
              </span>
              <span style="font-size: 10px; color: #64748b; font-weight: 600;">
                ${zone.classification.replace('_', ' ')}
              </span>
            </div>
            <div style="font-weight: 700; color: #0f172a; font-size: 13px; margin-bottom: 4px;">
              ${zone.title}
            </div>
            <div style="font-size: 11px; color: #b91c1c; font-weight: 600; margin-bottom: 4px;">
              ${zone.concern}
            </div>
            ${
              zone.evidence
                ? `<div style="font-size: 10px; color: #475569; background: #f8fafc; padding: 4px 6px; border-radius: 4px; margin-bottom: 6px; border: 1px solid #e2e8f0;">
                    <b>Evidence:</b> ${zone.evidence}
                   </div>`
                : ''
            }
            <div style="margin-bottom: 4px;">
              <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 2px;">
                High Risk Species:
              </div>
              <div>${speciesListHtml}</div>
            </div>
            <div style="font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 4px; margin-top: 4px;">
              Source: ${zone.source}
            </div>
          </div>
        `;

        polygon.bindPopup(popupContent);
      });
    });
  }, [publishedZones, showAdvisories, mapReady]);

  // Update Catch Markers Layer
  useEffect(() => {
    if (typeof window === 'undefined' || !mapReady || !markersLayerRef.current) return;

    import('leaflet').then((L) => {
      if (!markersLayerRef.current) return;
      markersLayerRef.current.clearLayers();

      if (!showCatches) return;

      const validCatches = catches.filter((c) => c.latitude !== null && c.longitude !== null);

      validCatches.forEach((c) => {
        const isVerified = c.verification_status === 'verified' || c.verification_status === 'corrected';
        const isFlagged = c.verification_status === 'flagged';
        const markerColor = isVerified ? '#10b981' : isFlagged ? '#ef4444' : '#f59e0b';

        // Check if this catch coordinate lies inside any published advisory zone
        const insideZone = publishedZones.find((z) =>
          isCoordinateInsidePolygon(c.latitude!, c.longitude!, z.polygon_geojson.coordinates)
        );

        // Custom circular marker icon
        const customIcon = L.divIcon({
          className: 'custom-catch-marker',
          html: `<div style="background-color: ${markerColor}; width: 18px; height: 18px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.35); position: relative;">
            ${
              insideZone
                ? `<span style="position: absolute; top: -6px; right: -6px; width: 10px; height: 10px; background: #dc2626; border: 1.5px solid white; border-radius: 50%;" title="Inside Fish Poisoning Advisory Zone"></span>`
                : ''
            }
          </div>`,
          iconSize: [18, 18],
          iconAnchor: [9, 9],
        });

        const popupContent = `
          <div style="font-family: sans-serif; font-size: 12px; line-height: 1.4; min-width: 190px;">
            <div style="font-weight: 700; color: #0f172a; margin-bottom: 2px;">
              ${c.species?.kiribati_name || c.ai_species_suggestion || 'Catch #' + c.id}
            </div>
            <div style="color: #64748b; font-size: 11px; margin-bottom: 6px;">
              Fisher: <b>${c.user?.name || 'Artisanal Fisher'}</b>
            </div>
            ${
              c.photo_url
                ? `<img src="${c.photo_url}" style="width: 100%; height: 90px; object-fit: cover; border-radius: 6px; margin-bottom: 6px;" />`
                : ''
            }
            <div style="display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 4px;">
              <span>Weight: <b>${c.weight_kg.toFixed(2)} kg</b></span>
              <span>Count: <b>${c.quantity}</b></span>
            </div>
            <div style="font-size: 10px; color: #475569; margin-top: 4px; padding-top: 4px; border-top: 1px solid #e2e8f0;">
              GPS: ${c.latitude?.toFixed(4)}°, ${c.longitude?.toFixed(4)}°
            </div>
            <div style="margin-top: 4px;">
              <span style="display: inline-block; padding: 2px 6px; font-size: 10px; font-weight: 600; border-radius: 4px; background: ${
                isVerified ? '#dcfce7; color: #15803d;' : isFlagged ? '#fee2e2; color: #b91c1c;' : '#fef3c7; color: #b45309;'
              }">
                ${c.verification_status.toUpperCase()}
              </span>
            </div>
            ${
              insideZone
                ? `<div style="margin-top: 6px; padding: 4px 6px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; color: #991c1c; font-size: 10px; font-weight: 700;">
                    ⚠️ CAUTION: Caught inside active advisory: ${insideZone.title}
                   </div>`
                : ''
            }
          </div>
        `;

        const marker = L.marker([c.latitude!, c.longitude!], { icon: customIcon }).bindPopup(popupContent);

        markersLayerRef.current.addLayer(marker);

        // If this marker is the currently selected catch, open its popup
        if (selectedCatch && selectedCatch.id === c.id) {
          marker.openPopup();
        }
      });
    });
  }, [catches, showCatches, selectedCatch, publishedZones, mapReady]);

  // Update Trip Routes Layer (Polylines)
  useEffect(() => {
    if (typeof window === 'undefined' || !mapReady || !routesLayerRef.current) return;

    import('leaflet').then((L) => {
      if (!routesLayerRef.current) return;
      routesLayerRef.current.clearLayers();

      if (!showRoutes || !activeTripDetail || !activeTripDetail.route_points?.length) return;

      const latLngs = activeTripDetail.route_points.map((pt) => [pt.latitude, pt.longitude] as [number, number]);

      // Route Polyline
      L.polyline(latLngs, {
        color: '#2563eb', // Blue navigation line
        weight: 4,
        opacity: 0.85,
        dashArray: '6, 6',
      }).addTo(routesLayerRef.current);

      // Start Marker (green pin)
      const startPt = latLngs[0];
      const startIcon = L.divIcon({
        className: 'route-start-icon',
        html: `<div style="background-color: #10b981; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px; font-weight: 700; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">START</div>`,
        iconAnchor: [20, 10],
      });
      L.marker(startPt, { icon: startIcon })
        .bindPopup(`<b>Trip Start Point</b><br/>Vessel: ${activeTripDetail.trip.vessel_name}`)
        .addTo(routesLayerRef.current);

      // End Marker (purple pin)
      if (latLngs.length > 1) {
        const endPt = latLngs[latLngs.length - 1];
        const endIcon = L.divIcon({
          className: 'route-end-icon',
          html: `<div style="background-color: #6366f1; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px; font-weight: 700; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">RETURN</div>`,
          iconAnchor: [24, 10],
        });
        L.marker(endPt, { icon: endIcon })
          .bindPopup(`<b>Trip Dock Point</b><br/>Status: ${activeTripDetail.trip.status}`)
          .addTo(routesLayerRef.current);
      }
    });
  }, [activeTripDetail, showRoutes, mapReady]);

  // Center on selected catch if changed
  useEffect(() => {
    if (!mapInstanceRef.current || !selectedCatch) return;
    if (selectedCatch.latitude !== null && selectedCatch.longitude !== null) {
      mapInstanceRef.current.flyTo([selectedCatch.latitude, selectedCatch.longitude], 14, {
        duration: 1.2,
      });
    }
  }, [selectedCatch]);

  return (
    <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col h-[540px]">
      {/* Map Control Toolbar */}
      <div className="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2 text-xs">
        <div className="flex items-center space-x-2">
          <div className="p-1.5 rounded-lg bg-blue-100 text-blue-700">
            <Navigation className="w-4 h-4" />
          </div>
          <div>
            <h4 className="font-bold text-slate-800">
              Tarawa Atoll Fisheries GPS Map
            </h4>
            <span className="text-[10px] text-slate-500">
              South Tarawa Lagoon & Ocean Routes • Catches & Published Advisory Zones
            </span>
          </div>
        </div>

        {/* Layer Toggles & Trip Selector */}
        <div className="flex flex-wrap items-center gap-2">
          {/* Trip Selector */}
          {tripsList.length > 0 && (
            <div className="flex items-center gap-1.5 bg-white border border-slate-200 px-2 py-1 rounded-lg">
              <span className="text-[11px] text-slate-500 font-medium">Trip:</span>
              <select
                value={selectedTripId || ''}
                onChange={(e) => setSelectedTripId(Number(e.target.value))}
                className="text-xs bg-transparent focus:outline-none text-slate-800 font-semibold"
              >
                {tripsList.map((t) => (
                  <option key={t.id} value={t.id}>
                    Trip #{t.id} ({t.vessel_name})
                  </option>
                ))}
              </select>
            </div>
          )}

          {/* Toggle Published Advisory Zones */}
          <button
            type="button"
            onClick={() => setShowAdvisories(!showAdvisories)}
            className={`flex items-center gap-1.5 px-2.5 py-1 rounded-lg font-semibold transition border ${
              showAdvisories
                ? 'bg-red-600 text-white border-red-600 shadow-2xs'
                : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100'
            }`}
            title="Toggle published poisoning advisory zones on this map"
          >
            <AlertOctagon className="w-3.5 h-3.5" />
            <span>Advisory Zones ({publishedZones.length})</span>
          </button>

          {/* Toggle Catch Markers */}
          <button
            type="button"
            onClick={() => setShowCatches(!showCatches)}
            className={`flex items-center gap-1 px-2.5 py-1 rounded-lg font-medium transition border ${
              showCatches
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100'
            }`}
          >
            <MapPin className="w-3.5 h-3.5" />
            <span>Catch Markers</span>
          </button>

          {/* Toggle Route Polylines */}
          <button
            type="button"
            onClick={() => setShowRoutes(!showRoutes)}
            className={`flex items-center gap-1 px-2.5 py-1 rounded-lg font-medium transition border ${
              showRoutes
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100'
            }`}
          >
            <Navigation className="w-3.5 h-3.5" />
            <span>Trip Routes</span>
          </button>
        </div>
      </div>

      {/* Map Container */}
      <div className="relative flex-1 w-full bg-slate-100" style={{ minHeight: '440px' }}>
        <div
          ref={mapContainerRef}
          style={{ height: '100%', minHeight: '440px', width: '100%' }}
          className="w-full h-full"
        />

        {/* GPS Privacy Status Indicator Overlay */}
        <div className="absolute bottom-3 left-3 z-[1000] bg-slate-900/85 backdrop-blur-sm text-white px-3 py-1.5 rounded-lg text-[11px] flex items-center gap-2 shadow-lg border border-slate-700">
          {canViewPreciseGps ? (
            <>
              <ShieldCheck className="w-4 h-4 text-emerald-400" />
              <span>
                <b>Authorized GPS Access:</b> Precise coordinates displayed under MFMRD authorization.
              </span>
            </>
          ) : (
            <>
              <EyeOff className="w-4 h-4 text-amber-400" />
              <span>
                <b>Protected GPS:</b> Coordinates redacted to safeguard artisanal fisher fishing zones.
              </span>
            </>
          )}
        </div>

        {/* Legend Overlay */}
        <div className="absolute top-3 right-3 z-[1000] bg-white/95 backdrop-blur-sm border border-slate-200 p-2.5 rounded-lg text-[10px] space-y-1 shadow-md max-w-[210px]">
          <div className="font-bold text-slate-700 mb-1 flex items-center gap-1">
            <Layers className="w-3 h-3 text-slate-500" />
            <span>Map Layers Legend</span>
          </div>

          <div className="flex items-center gap-1.5">
            <span className="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>
            <span>Verified Catch</span>
          </div>

          <div className="flex items-center gap-1.5">
            <span className="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>
            <span>Pending AI Verification</span>
          </div>

          <div className="flex items-center gap-1.5">
            <span className="w-2.5 h-2.5 rounded-full bg-rose-500 inline-block"></span>
            <span>Flagged Catch</span>
          </div>

          <div className="flex items-center gap-1.5 pt-0.5">
            <span className="w-3.5 h-0.5 bg-blue-600 border-dashed inline-block"></span>
            <span>Recorded Trip Route</span>
          </div>

          {/* Published Advisory Zone Legend Entry */}
          <div className="flex items-center gap-1.5 pt-1 border-t border-slate-200 mt-1">
            <span className="w-3 h-2 rounded bg-red-600/30 border border-red-600 border-dashed inline-block flex-shrink-0"></span>
            <span className="text-red-700 font-bold">Published Advisory Zone</span>
          </div>
        </div>
      </div>
    </div>
  );
}
