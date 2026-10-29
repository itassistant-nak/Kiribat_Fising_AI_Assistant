'use client';

import React, { useEffect, useRef, useState, useCallback } from 'react';
import { AdvisoryZone, GeoJsonPolygon, CheckPointResult, api } from '../lib/api';
import {
  Layers,
  MapPin,
  ShieldAlert,
  ShieldCheck,
  PlusCircle,
  RotateCcw,
  CheckCircle2,
  AlertTriangle,
  Compass,
  Info,
  Maximize2,
  FileCode,
  Sparkles,
  X,
} from 'lucide-react';

interface AdvisoryPolygonMapProps {
  zones: AdvisoryZone[];
  selectedZone?: AdvisoryZone | null;
  onSelectZone?: (zone: AdvisoryZone) => void;
  isDrawingMode: boolean;
  setIsDrawingMode: (active: boolean) => void;
  drawnPolygon: GeoJsonPolygon | null;
  setDrawnPolygon: (poly: GeoJsonPolygon | null) => void;
  onOpenAuditLogs?: (zone: AdvisoryZone) => void;
}

const BETIO_DEMO_COORDS = [
  [172.915, 1.35],
  [172.935, 1.35],
  [172.935, 1.37],
  [172.915, 1.37],
  [172.915, 1.35],
];

export default function AdvisoryPolygonMap({
  zones,
  selectedZone,
  onSelectZone,
  isDrawingMode,
  setIsDrawingMode,
  drawnPolygon,
  setDrawnPolygon,
  onOpenAuditLogs,
}: AdvisoryPolygonMapProps) {
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const mapInstanceRef = useRef<any>(null);
  const zonesLayerRef = useRef<any>(null);
  const drawingLayerRef = useRef<any>(null);
  const testMarkerLayerRef = useRef<any>(null);

  // Drawing state
  const [currentVertices, setCurrentVertices] = useState<Array<[number, number]>>([]); // [lat, lng]
  const [testMode, setTestMode] = useState<boolean>(false);
  const [testResult, setTestResult] = useState<CheckPointResult | null>(null);
  const [testingCoord, setTestingCoord] = useState<{ lat: number; lng: number } | null>(null);
  const [testingLoading, setTestingLoading] = useState<boolean>(false);

  // Paste GeoJSON state
  const [isPasteModalOpen, setIsPasteModalOpen] = useState<boolean>(false);
  const [pasteInputText, setPasteInputText] = useState<string>(
    JSON.stringify(
      {
        type: 'Polygon',
        coordinates: [BETIO_DEMO_COORDS],
      },
      null,
      2
    )
  );

  const [mapReady, setMapReady] = useState<boolean>(false);

  // Stable references for map click listener
  const isDrawingModeRef = useRef<boolean>(isDrawingMode);
  isDrawingModeRef.current = isDrawingMode;

  const testModeRef = useRef<boolean>(testMode);
  testModeRef.current = testMode;

  // Zoom to a given polygon or coordinates
  const zoomToPolygon = useCallback((ring: number[][]) => {
    if (!mapInstanceRef.current) return;
    import('leaflet').then((L) => {
      if (!mapInstanceRef.current) return;
      // Convert GeoJSON [lng, lat] to Leaflet [lat, lng]
      const latLngs = ring.map((pt) => [pt[1], pt[0]] as [number, number]);
      const bounds = L.latLngBounds(latLngs);
      mapInstanceRef.current.fitBounds(bounds, { padding: [50, 50], maxZoom: 15 });
    });
  }, []);

  // Quick-load the Betio RFC 7946 Polygon directly
  const handleLoadBetioDemo = useCallback(() => {
    const polygon: GeoJsonPolygon = {
      type: 'Polygon',
      coordinates: [BETIO_DEMO_COORDS],
    };
    setDrawnPolygon(polygon);
    const latLngs = BETIO_DEMO_COORDS.slice(0, -1).map(
      (pt) => [Number(pt[1].toFixed(6)), Number(pt[0].toFixed(6))] as [number, number]
    );
    setCurrentVertices(latLngs);
    setIsDrawingMode(false);
    zoomToPolygon(BETIO_DEMO_COORDS);
  }, [setDrawnPolygon, setIsDrawingMode, zoomToPolygon]);

  // Load GeoJSON from paste modal
  const handleLoadGeoJson = (geoJsonText: string) => {
    try {
      const parsed = JSON.parse(geoJsonText);
      const geom = parsed.type === 'Feature' ? parsed.geometry : parsed;
      if (!geom || geom.type !== 'Polygon' || !Array.isArray(geom.coordinates) || !geom.coordinates[0]) {
        alert('GeoJSON must be a valid Polygon geometry with a coordinates array.');
        return;
      }
      const ring = geom.coordinates[0];
      if (ring.length < 4) {
        alert('A valid GeoJSON linear ring must contain at least 4 positions (closed ring).');
        return;
      }
      setDrawnPolygon({ type: 'Polygon', coordinates: geom.coordinates });
      const latLngs = ring
        .slice(0, -1)
        .map((pt: number[]) => [Number(pt[1].toFixed(6)), Number(pt[0].toFixed(6))] as [number, number]);
      setCurrentVertices(latLngs);
      setIsDrawingMode(false);
      setIsPasteModalOpen(false);

      zoomToPolygon(ring);
    } catch (e: any) {
      alert(`Invalid GeoJSON syntax: ${e.message}`);
    }
  };

  // Initialize Leaflet Map
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

        zonesLayerRef.current = L.layerGroup().addTo(map);
        drawingLayerRef.current = L.layerGroup().addTo(map);
        testMarkerLayerRef.current = L.layerGroup().addTo(map);

        // Bind single persistent map click listener using stable refs
        map.on('click', async (e: any) => {
          const { lat, lng } = e.latlng;
          const formattedLat = Number(lat.toFixed(6));
          const formattedLng = Number(lng.toFixed(6));

          if (isDrawingModeRef.current) {
            setCurrentVertices((prev) => [...prev, [formattedLat, formattedLng]]);
          } else if (testModeRef.current) {
            setTestingCoord({ lat: formattedLat, lng: formattedLng });
            setTestingLoading(true);
            try {
              const res = await api.checkPointAdvisory(formattedLat, formattedLng);
              setTestResult(res);
            } catch (err) {
              console.error('Point check error:', err);
            } finally {
              setTestingLoading(false);
            }
          }
        });

        mapInstanceRef.current = map;
        setMapReady(true);

        // Trigger size recalculation after render
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
        console.error('Leaflet initialization error:', err);
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
      zonesLayerRef.current = null;
      drawingLayerRef.current = null;
      testMarkerLayerRef.current = null;
      setMapReady(false);
    };
  }, []);

  // Render Drawing Layer (Vertices & Lines)
  useEffect(() => {
    if (typeof window === 'undefined' || !mapReady || !drawingLayerRef.current) return;
    import('leaflet').then((L) => {
      if (!drawingLayerRef.current) return;
      drawingLayerRef.current.clearLayers();

      if (currentVertices.length > 0) {
        // Draw polyline connecting vertices
        L.polyline(currentVertices, {
          color: '#3b82f6',
          weight: 3,
          dashArray: '4, 4',
        }).addTo(drawingLayerRef.current);

        // Draw circles for each vertex
        currentVertices.forEach((vertex, idx) => {
          const marker = L.circleMarker(vertex, {
            radius: 6,
            fillColor: idx === 0 ? '#10b981' : '#3b82f6',
            color: '#ffffff',
            weight: 2,
            fillOpacity: 0.9,
          }).addTo(drawingLayerRef.current);

          marker.bindTooltip(
            idx === 0
              ? `<b>Start Vertex</b><br/>${vertex[0]}, ${vertex[1]}`
              : `Vertex #${idx + 1}<br/>${vertex[0]}, ${vertex[1]}`,
            { permanent: false }
          );
        });
      }

      // If a polygon is finished & provided externally or saved
      if (drawnPolygon && drawnPolygon.coordinates && drawnPolygon.coordinates[0]) {
        const ring = drawnPolygon.coordinates[0];
        const latLngs = ring.map((pt) => [pt[1], pt[0]] as [number, number]);

        const poly = L.polygon(latLngs, {
          color: '#10b981',
          fillColor: '#10b981',
          fillOpacity: 0.3,
          weight: 3,
        }).addTo(drawingLayerRef.current);

        poly.bindPopup(`
          <div class="p-2">
            <div class="text-xs font-bold text-emerald-800 flex items-center gap-1 uppercase">
              ✓ Active Drawn Polygon
            </div>
            <div class="text-[11px] text-gray-600 mt-1">
              Linear ring with ${ring.length} coordinate pairs in South Tarawa.
            </div>
            <div class="text-[10px] text-gray-500 mt-1 font-mono">
              RFC 7946 GeoJSON format ready for advisory zone storage.
            </div>
          </div>
        `);
      }
    });
  }, [currentVertices, drawnPolygon, mapReady]);

  // Render Test Marker
  useEffect(() => {
    if (typeof window === 'undefined' || !mapReady || !testMarkerLayerRef.current) return;
    import('leaflet').then((L) => {
      if (!testMarkerLayerRef.current) return;
      testMarkerLayerRef.current.clearLayers();

      if (testingCoord && testResult) {
        const isInside = testResult.inside_advisory_zone;
        const color = isInside ? '#ef4444' : '#10b981';

        const marker = L.circleMarker([testingCoord.lat, testingCoord.lng], {
          radius: 9,
          fillColor: color,
          color: '#ffffff',
          weight: 3,
          fillOpacity: 0.95,
        }).addTo(testMarkerLayerRef.current);

        const content = isInside
          ? `<div class="p-2 min-w-[220px]">
               <div class="text-xs font-bold text-red-600 flex items-center gap-1 uppercase">
                 ⚠️ High Risk Advisory Warning
               </div>
               <div class="text-xs font-semibold mt-1">${testResult.matching_zones.map((z) => z.title).join(', ')}</div>
               <div class="text-xs text-gray-600 mt-1">Affected species: ${testResult.affected_species.map((s) => s.kiribati_name).join(', ') || 'General reef fish'}</div>
               <div class="text-[10px] text-gray-500 mt-1 italic">${testResult.safety_notice}</div>
             </div>`
          : `<div class="p-2">
               <div class="text-xs font-bold text-emerald-600 flex items-center gap-1">
                 ✓ Clear Location
               </div>
               <div class="text-xs text-gray-600 mt-1">Coordinates [${testingCoord.lat}, ${testingCoord.lng}] lie outside all published poisoning advisories.</div>
             </div>`;

        marker.bindPopup(content).openPopup();
      }
    });
  }, [testingCoord, testResult, mapReady]);

  // Render Advisory Polygons Layer
  useEffect(() => {
    if (typeof window === 'undefined' || !mapReady || !zonesLayerRef.current) return;
    import('leaflet').then((L) => {
      if (!zonesLayerRef.current) return;
      zonesLayerRef.current.clearLayers();

      zones.forEach((zone) => {
        if (!zone.polygon_geojson || !zone.polygon_geojson.coordinates) return;

        const rings = zone.polygon_geojson.coordinates;
        // Convert GeoJSON [lng, lat] to Leaflet [lat, lng]
        const latLngs = rings.map((ring) =>
          ring.map((pos) => [pos[1], pos[0]] as [number, number])
        );

        let color = '#2563eb'; // blue default for draft
        let fillOpacity = 0.2;
        let dashArray = undefined;

        if (zone.status === 'published') {
          color = zone.classification === 'authority-confirmed' ? '#dc2626' : '#ea580c';
          fillOpacity = 0.35;
        } else if (zone.status === 'under_investigation') {
          color = '#d97706';
          fillOpacity = 0.25;
          dashArray = '6, 6';
        } else if (zone.status === 'draft') {
          color = '#2563eb';
          fillOpacity = 0.15;
          dashArray = '3, 6';
        }

        const isSelected = selectedZone?.id === zone.id;
        if (isSelected) {
          color = '#7c3aed'; // Highlight selected
          fillOpacity = 0.45;
        }

        const polygon = L.polygon(latLngs, {
          color: color,
          fillColor: color,
          fillOpacity: fillOpacity,
          weight: isSelected ? 4 : 2,
          dashArray: dashArray,
        }).addTo(zonesLayerRef.current);

        // Status badge formatting
        const statusBadgeColor =
          zone.status === 'published'
            ? 'bg-red-100 text-red-800 border-red-300'
            : zone.status === 'under_investigation'
            ? 'bg-amber-100 text-amber-800 border-amber-300'
            : 'bg-blue-100 text-blue-800 border-blue-300';

        const classificationLabel =
          zone.classification === 'authority-confirmed'
            ? 'Authority Confirmed'
            : zone.classification === 'under_investigation'
            ? 'Under Investigation'
            : 'Suspected';

        const speciesListHtml =
          zone.species && zone.species.length > 0
            ? zone.species
                .map(
                  (s) =>
                    `<span class="inline-block px-1.5 py-0.5 text-[10px] bg-red-50 text-red-700 rounded border border-red-200 mr-1 mb-1">
                      ${s.kiribati_name} (${s.english_name})
                    </span>`
                )
                .join('')
            : '<span class="text-xs text-gray-500 italic">No specific species attached</span>';

        const popupContent = `
          <div class="p-2 max-w-[280px]">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <span class="px-2 py-0.5 text-[10px] font-bold rounded-full border ${statusBadgeColor} uppercase tracking-wider">
                ${zone.status}
              </span>
              <span class="text-[10px] font-medium text-gray-500">
                ${classificationLabel}
              </span>
            </div>
            <h4 class="text-sm font-bold text-gray-900 leading-snug mb-1">${zone.title}</h4>
            <div class="text-xs text-gray-700 font-semibold mb-1">
              <span class="text-red-700">Concern:</span> ${zone.concern}
            </div>
            ${
              zone.evidence
                ? `<div class="text-[11px] text-gray-600 mb-2 bg-gray-50 p-1.5 rounded border border-gray-100">
                     <b>Evidence:</b> ${zone.evidence}
                   </div>`
                : ''
            }
            <div class="mb-2">
              <div class="text-[10px] font-semibold text-gray-500 uppercase mb-1">Affected Species:</div>
              <div class="flex flex-wrap">${speciesListHtml}</div>
            </div>
            <div class="text-[10px] text-gray-500 border-t border-gray-200 pt-1.5 mt-1 flex justify-between">
              <span>Source: ${zone.source}</span>
              ${zone.review_date ? `<span>Review: ${zone.review_date}</span>` : ''}
            </div>
          </div>
        `;

        polygon.bindPopup(popupContent);

        polygon.on('click', () => {
          if (onSelectZone) {
            onSelectZone(zone);
          }
        });
      });
    });
  }, [zones, selectedZone, onSelectZone, mapReady]);

  // Complete Polygon Drawing Action
  const handleCompletePolygon = () => {
    if (currentVertices.length < 3) {
      alert('A polygon linear ring requires at least 3 vertices to close.');
      return;
    }

    // Convert Leaflet [lat, lng] to GeoJSON [lng, lat]
    // And close the ring by adding the first vertex at the end
    const closedRing = currentVertices.map((v) => [v[1], v[0]]);
    closedRing.push([currentVertices[0][1], currentVertices[0][0]]);

    const geoJson: GeoJsonPolygon = {
      type: 'Polygon',
      coordinates: [closedRing],
    };

    setDrawnPolygon(geoJson);
    setIsDrawingMode(false);
  };

  // Reset Drawing
  const handleResetDrawing = () => {
    setCurrentVertices([]);
    setDrawnPolygon(null);
  };

  return (
    <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col">
      {/* 1. Map Header Toolbar */}
      <div className="p-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Layers className="w-5 h-5 text-indigo-600" />
          <h3 className="font-bold text-slate-800 text-sm md:text-base">
            South Tarawa Advisory Zones & GIS Warning System
          </h3>
          <span className="text-xs px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-800 font-semibold">
            {zones.length} Zones
          </span>
        </div>

        {/* Action Toolbar */}
        <div className="flex items-center flex-wrap gap-2">
          {/* Quick Betio Polygon Button */}
          <button
            type="button"
            onClick={handleLoadBetioDemo}
            className="px-3 py-1.5 text-xs font-semibold rounded-lg flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-300 hover:bg-emerald-100 transition shadow-2xs"
            title="Load RFC 7946 Betio Passage demonstration polygon"
          >
            <Sparkles className="w-3.5 h-3.5 text-emerald-600" />
            Betio Polygon Demo
          </button>

          {/* Toggle Drawing Mode */}
          <button
            type="button"
            onClick={() => {
              if (!isDrawingMode) {
                setTestMode(false);
                setTestingCoord(null);
                setTestResult(null);
              }
              setIsDrawingMode(!isDrawingMode);
            }}
            className={`px-3 py-1.5 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all ${
              isDrawingMode
                ? 'bg-amber-600 text-white shadow-sm ring-2 ring-amber-300'
                : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-100'
            }`}
          >
            <PlusCircle className="w-4 h-4" />
            {isDrawingMode ? 'Drawing Active (Click map)' : 'Draw New Polygon'}
          </button>

          {/* Paste GeoJSON Button */}
          <button
            type="button"
            onClick={() => setIsPasteModalOpen(true)}
            className="px-3 py-1.5 text-xs font-semibold rounded-lg flex items-center gap-1.5 bg-white text-slate-700 border border-slate-300 hover:bg-slate-100 transition shadow-2xs"
          >
            <FileCode className="w-4 h-4 text-indigo-600" />
            Paste GeoJSON
          </button>

          {/* Toggle Point-in-Polygon Location Tester */}
          <button
            type="button"
            onClick={() => {
              if (!testMode) {
                setIsDrawingMode(false);
              }
              setTestMode(!testMode);
              setTestingCoord(null);
              setTestResult(null);
            }}
            className={`px-3 py-1.5 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all ${
              testMode
                ? 'bg-emerald-600 text-white shadow-sm ring-2 ring-emerald-300'
                : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-100'
            }`}
          >
            <Compass className="w-4 h-4" />
            {testMode ? 'Testing GPS (Click map)' : 'Test GPS Coordinate'}
          </button>

          {/* Reset Drawing */}
          {(currentVertices.length > 0 || drawnPolygon) && (
            <button
              type="button"
              onClick={handleResetDrawing}
              className="px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:text-red-600 hover:bg-red-50 rounded-lg flex items-center gap-1 transition"
              title="Reset drawn vertices"
            >
              <RotateCcw className="w-3.5 h-3.5" />
              Reset
            </button>
          )}

          {/* Finish Polygon Button */}
          {isDrawingMode && currentVertices.length >= 3 && (
            <button
              type="button"
              onClick={handleCompletePolygon}
              className="px-3 py-1.5 text-xs font-bold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 flex items-center gap-1 shadow-sm"
            >
              <CheckCircle2 className="w-4 h-4" />
              Close Polygon ({currentVertices.length} pts)
            </button>
          )}
        </div>
      </div>

      {/* 2. Interactive Mode Banners */}
      {isDrawingMode && (
        <div className="bg-amber-50 border-b border-amber-200 px-4 py-2.5 flex items-center justify-between text-xs text-amber-900">
          <div className="flex items-center gap-2">
            <Info className="w-4 h-4 text-amber-700 flex-shrink-0" />
            <span>
              <b>Drawing Mode Active:</b> Click anywhere on the reef or lagoon to add polygon vertices ({currentVertices.length} points placed). When finished, click <b>Close Polygon</b>.
            </span>
          </div>
          <span className="font-mono text-[11px] bg-amber-100 px-2 py-0.5 rounded font-semibold text-amber-800">
            Min 3 vertices required
          </span>
        </div>
      )}

      {testMode && (
        <div className="bg-emerald-50 border-b border-emerald-200 px-4 py-2.5 flex items-center justify-between text-xs text-emerald-900">
          <div className="flex items-center gap-2">
            <Compass className="w-4 h-4 text-emerald-700 flex-shrink-0" />
            <span>
              <b>GPS Test Mode Active:</b> Click on any reef, fishing spot, or lagoon point to test the Ray-Casting Point-in-Polygon warning engine live.
            </span>
          </div>
          {testingLoading && <span className="animate-pulse font-semibold">Testing coordinate...</span>}
        </div>
      )}

      {/* 3. Live GPS Coordinate Test Result Banner (Dedicated, Non-Overlapping) */}
      {testResult && testingCoord && (
        <div
          className={`border-b p-4 transition-all ${
            testResult.inside_advisory_zone
              ? 'bg-red-50 border-red-200 text-red-950'
              : 'bg-emerald-50 border-emerald-200 text-emerald-950'
          }`}
        >
          <div className="flex items-start justify-between gap-4">
            <div className="flex items-start gap-3">
              {testResult.inside_advisory_zone ? (
                <div className="w-8 h-8 rounded-full bg-red-100 border border-red-200 flex items-center justify-center text-red-600 flex-shrink-0 mt-0.5">
                  <ShieldAlert className="w-5 h-5" />
                </div>
              ) : (
                <div className="w-8 h-8 rounded-full bg-emerald-100 border border-emerald-200 flex items-center justify-center text-emerald-600 flex-shrink-0 mt-0.5">
                  <ShieldCheck className="w-5 h-5" />
                </div>
              )}

              <div>
                <div className="flex items-center gap-2">
                  <h4 className="font-bold text-xs uppercase tracking-wider">
                    {testResult.inside_advisory_zone
                      ? '⚠️ High Risk Poisoning Advisory Zone Warning'
                      : '✓ Tested Coordinates Safe'}
                  </h4>
                  <span className="font-mono text-[11px] bg-white/80 px-2 py-0.5 rounded border border-black/10">
                    GPS: {testingCoord.lat.toFixed(5)}, {testingCoord.lng.toFixed(5)}
                  </span>
                </div>

                {testResult.inside_advisory_zone ? (
                  <div className="mt-2 space-y-1.5 text-xs">
                    <div>
                      <span className="font-bold">Active Advisories: </span>
                      {testResult.matching_zones.map((z) => z.title).join(' • ')}
                    </div>
                    {testResult.matching_zones[0]?.concern && (
                      <div className="text-red-900/90">
                        <span className="font-bold">Clinical Concern: </span>
                        {testResult.matching_zones[0].concern}
                      </div>
                    )}
                    {testResult.affected_species.length > 0 && (
                      <div className="flex items-center gap-1.5 flex-wrap pt-1">
                        <span className="font-bold text-[11px] uppercase">High Risk Species:</span>
                        {testResult.affected_species.map((sp) => (
                          <span
                            key={sp.id}
                            className="px-2 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-800 border border-red-300"
                          >
                            {sp.kiribati_name} ({sp.english_name})
                          </span>
                        ))}
                      </div>
                    )}
                    <p className="text-[11px] text-red-800/80 italic pt-1">{testResult.safety_notice}</p>
                  </div>
                ) : (
                  <p className="text-xs text-emerald-800 mt-1">
                    The tested location lies outside all currently published ciguatera and marine toxin advisory polygons in South Tarawa.
                  </p>
                )}
              </div>
            </div>

            <button
              type="button"
              onClick={() => {
                setTestResult(null);
                setTestingCoord(null);
              }}
              className="text-slate-400 hover:text-slate-700 p-1 rounded-md"
              title="Dismiss warning banner"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        </div>
      )}

      {/* 4. Guaranteed Map Container (Always 520px height) */}
      <div
        className="w-full relative bg-slate-100"
        style={{ height: '520px', minHeight: '520px', width: '100%', position: 'relative' }}
      >
        <div
          ref={mapContainerRef}
          style={{ height: '520px', minHeight: '520px', width: '100%' }}
          className="w-full h-full z-0"
        />
      </div>

      {/* 5. Map Footer Bar with Integrated Status Legend (CANNOT overlap warning card!) */}
      <div className="bg-slate-50 border-t border-slate-200 px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-xs">
        <div className="flex items-center gap-1.5 font-bold text-slate-700">
          <Layers className="w-4 h-4 text-slate-500" />
          <span>Status Legend:</span>
        </div>

        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-1.5">
            <span className="w-3.5 h-3.5 rounded bg-red-600 border border-red-700 opacity-90 inline-block shadow-2xs" />
            <span className="text-slate-700 font-medium">Published (Confirmed CFP Risk)</span>
          </div>

          <div className="flex items-center gap-1.5">
            <span className="w-3.5 h-3.5 rounded bg-amber-500 border border-amber-600 border-dashed opacity-90 inline-block shadow-2xs" />
            <span className="text-slate-700 font-medium">Under Investigation</span>
          </div>

          <div className="flex items-center gap-1.5">
            <span className="w-3.5 h-3.5 rounded bg-blue-500 border border-blue-600 border-dotted opacity-90 inline-block shadow-2xs" />
            <span className="text-slate-700 font-medium">Draft (Staff Only)</span>
          </div>

          {drawnPolygon && (
            <div className="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-50 border border-emerald-300">
              <span className="w-3.5 h-3.5 rounded bg-emerald-500 border border-emerald-600 opacity-90 inline-block shadow-2xs" />
              <span className="text-emerald-800 font-bold">
                New Drawn Polygon ({drawnPolygon.coordinates[0]?.length || 0} pts) [Active]
              </span>
            </div>
          )}
        </div>

        <div className="text-[11px] text-slate-500 italic">
          Click polygon for clinical details • Betio to Teaoraereke
        </div>
      </div>

      {/* 6. PASTE GEOJSON MODAL */}
      {isPasteModalOpen && (
        <div className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-5 border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-200 pb-3 mb-3">
              <h4 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                <FileCode className="w-4 h-4 text-indigo-600" />
                Paste GeoJSON Polygon
              </h4>
              <button
                type="button"
                onClick={() => setIsPasteModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 font-bold"
              >
                ✕
              </button>
            </div>

            <p className="text-xs text-slate-600 mb-3">
              Paste a standard RFC 7946 GeoJSON Polygon. It will be drawn on the map with vertices and bounded automatically.
            </p>

            <textarea
              rows={8}
              value={pasteInputText}
              onChange={(e) => setPasteInputText(e.target.value)}
              className="w-full font-mono text-xs border border-slate-300 rounded-lg p-2.5 bg-slate-900 text-emerald-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 mb-3"
            />

            <div className="flex items-center justify-between gap-2 pt-2 border-t border-slate-100">
              <button
                type="button"
                onClick={() => {
                  setPasteInputText(
                    JSON.stringify(
                      {
                        type: 'Polygon',
                        coordinates: [BETIO_DEMO_COORDS],
                      },
                      null,
                      2
                    )
                  );
                }}
                className="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800"
              >
                Reset to Betio Demonstration Zone
              </button>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setIsPasteModalOpen(false)}
                  className="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg font-medium"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={() => handleLoadGeoJson(pasteInputText)}
                  className="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-sm"
                >
                  Render on Map
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
