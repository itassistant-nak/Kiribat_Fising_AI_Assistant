import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import '../../models/advisory_zone.dart';
import '../../models/alert_state.dart';
import '../../models/catch_record.dart';
import '../../models/trip_record.dart';
import '../../services/gis/zone_alert_engine.dart';
import '../../data/database_helper.dart';
import '../../services/sync/sync_service.dart';

/// Full interactive GPS Map for artisanal fishermen matching the admin dashboard.
/// Renders South Tarawa Lagoon, live vessel location with accuracy radius,
/// published advisory zone polygons, catch markers, trip route polylines,
/// and live safety alerts.
class LiveMapScreen extends StatefulWidget {
  final SyncService syncService;
  final double? initialLat;
  final double? initialLon;
  final List<AdvisoryZone>? initialZones;
  final List<LocalCatch>? initialCatches;

  const LiveMapScreen({
    super.key,
    required this.syncService,
    this.initialLat,
    this.initialLon,
    this.initialZones,
    this.initialCatches,
  });

  @override
  State<LiveMapScreen> createState() => _LiveMapScreenState();
}

class _LiveMapScreenState extends State<LiveMapScreen> {
  final MapController _mapController = MapController();
  final ZoneAlertEngine _alertEngine = ZoneAlertEngine();
  final DatabaseHelper _db = DatabaseHelper.instance;

  // Live Vessel GPS Coordinates (defaults to South Tarawa / Betio Lagoon)
  double _currentLat = 1.3300;
  double _currentLon = 172.9800;
  double _currentAccuracy = 12.0;

  List<AdvisoryZone> _cachedZones = [];
  List<LocalCatch> _cachedCatches = [];
  List<TripPoint> _activeTripPoints = [];
  LocalTrip? _activeTrip;
  ZoneAlertResult _currentAlert = ZoneAlertResult.safe;

  bool _isLoading = true;
  bool _isStale = false;

  // Layer Visibility Toggles (matching admin dashboard)
  bool _showAdvisories = true;
  bool _showCatches = true;
  bool _showRoutes = true;

  @override
  void initState() {
    super.initState();
    if (widget.initialLat != null) _currentLat = widget.initialLat!;
    if (widget.initialLon != null) _currentLon = widget.initialLon!;

    if (widget.initialZones != null) {
      _cachedZones = widget.initialZones!;
      _cachedCatches = widget.initialCatches ?? [];
      _isLoading = false;
      _evaluateGps();
    } else {
      _loadAllMapData();
    }
    widget.syncService.addListener(_onSyncUpdate);
  }

  @override
  void dispose() {
    widget.syncService.removeListener(_onSyncUpdate);
    _mapController.dispose();
    super.dispose();
  }

  void _onSyncUpdate() {
    _loadAllMapData();
  }

  Future<void> _loadAllMapData() async {
    final zones = await _db.getCachedAdvisoryZones();
    final catches = await _db.getLocalCatches();
    final stale = await _db.isCacheStale('advisory_zones');

    // Load active trip route points
    final trips = await _db.getLocalTrips();
    LocalTrip? activeTrip;
    List<TripPoint> routePoints = [];
    for (final t in trips) {
      if (t.status == 'in_progress') {
        activeTrip = t;
        routePoints = await _db.getTripPoints(t.id);
        break;
      }
    }
    if (activeTrip == null && trips.isNotEmpty) {
      activeTrip = trips.first;
      routePoints = await _db.getTripPoints(activeTrip.id);
    }

    if (mounted) {
      setState(() {
        _cachedZones = zones;
        _cachedCatches = catches;
        _activeTrip = activeTrip;
        _activeTripPoints = routePoints;
        _isStale = stale;
        _isLoading = false;
        _evaluateGps();
      });
    }
  }

  void _evaluateGps() {
    final result = _alertEngine.evaluateLocation(
      latitude: _currentLat,
      longitude: _currentLon,
      gpsAccuracy: _currentAccuracy,
      publishedZones: _cachedZones,
      isCacheStale: _isStale,
    );

    setState(() {
      _currentAlert = result;
    });
  }

  void _recenterOnVessel() {
    _mapController.move(LatLng(_currentLat, _currentLon), 13.0);
  }

  void _setSimulatedLocation({
    required double lat,
    required double lon,
    required double accuracy,
    required String label,
  }) {
    setState(() {
      _currentLat = lat;
      _currentLon = lon;
      _currentAccuracy = accuracy;
    });
    _evaluateGps();
    _recenterOnVessel();

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('Vessel Moved: $label (${lat.toStringAsFixed(4)}, ${lon.toStringAsFixed(4)})'),
        duration: const Duration(seconds: 2),
        backgroundColor: _getAlertBannerColor(),
      ),
    );
  }

  Color _getAlertBannerColor() {
    switch (_currentAlert.level) {
      case AlertLevel.red:
        return const Color(0xFFDC2626); // Red 600
      case AlertLevel.yellow:
        return const Color(0xFFEA580C); // Orange 600
      case AlertLevel.green:
        return const Color(0xFF0D9488); // Teal 600
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Scaffold(
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircularProgressIndicator(color: Color(0xFF007A87)),
              SizedBox(height: 12),
              Text('Loading South Tarawa GPS Map...'),
            ],
          ),
        ),
      );
    }

    final publishedZones = _cachedZones.where((z) => z.status == 'published').toList();

    return Scaffold(
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Fisheries GPS Map',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 17),
            ),
            Text(
              'South Tarawa Lagoon • Live Fisher Location',
              style: TextStyle(fontSize: 11, color: Colors.white70),
            ),
          ],
        ),
        backgroundColor: const Color(0xFF007A87),
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.layers_rounded),
            tooltip: 'Layer Toggles',
            onPressed: _showLayerTogglesDialog,
          ),
          IconButton(
            icon: const Icon(Icons.tune_rounded),
            tooltip: 'GPS Simulation Controls',
            onPressed: _showSimulationDialog,
          ),
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            tooltip: 'Refresh Map Data',
            onPressed: _loadAllMapData,
          ),
        ],
      ),
      body: Stack(
        children: [
          // 1. The Interactive Flutter Map Canvas
          FlutterMap(
            mapController: _mapController,
            options: MapOptions(
              initialCenter: LatLng(_currentLat, _currentLon),
              initialZoom: 12.0,
              minZoom: 9.0,
              maxZoom: 18.0,
              onTap: (tapPosition, point) {
                // Allow tapping map to inspect coordinates
              },
            ),
            children: [
              // OpenStreetMap Tile Layer (with Marine ocean background color)
              TileLayer(
                urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                userAgentPackageName: 'ki.gov.fisheries.assistant',
                tileBuilder: (context, tileWidget, tile) {
                  return Container(
                    color: const Color(0xFFE2F1F8), // Subtle sea/lagoon blue background
                    child: tileWidget,
                  );
                },
              ),

              // Published Advisory Zone Polygons
              if (_showAdvisories)
                PolygonLayer(
                  polygons: _buildAdvisoryPolygons(publishedZones),
                ),

              // Trip Route Polylines
              if (_showRoutes && _activeTripPoints.isNotEmpty)
                PolylineLayer(
                  polylines: [
                    Polyline(
                      points: _activeTripPoints
                          .map((pt) => LatLng(pt.latitude, pt.longitude))
                          .toList(),
                      color: const Color(0xFF2563EB),
                      strokeWidth: 4.0,
                    ),
                  ],
                ),

              // GPS Accuracy Circle for Vessel
              CircleLayer(
                circles: [
                  CircleMarker(
                    point: LatLng(_currentLat, _currentLon),
                    radius: _currentAccuracy,
                    useRadiusInMeter: true,
                    color: _getAlertBannerColor().withValues(alpha: 0.18),
                    borderColor: _getAlertBannerColor(),
                    borderStrokeWidth: 1.5,
                  ),
                ],
              ),

              // Markers Layer: Catch Markers, Advisory Labels, Trip Start/End, Live Vessel
              MarkerLayer(
                markers: [
                  // Catch Markers
                  if (_showCatches) ..._buildCatchMarkers(),

                  // Advisory Zone Inspection Pins
                  if (_showAdvisories) ..._buildAdvisoryMarkers(publishedZones),

                  // Trip Route Start & End Pins
                  if (_showRoutes && _activeTripPoints.isNotEmpty)
                    ..._buildTripMarkers(),

                  // Live Fisherman Vessel Marker
                  Marker(
                    point: LatLng(_currentLat, _currentLon),
                    width: 52,
                    height: 52,
                    child: GestureDetector(
                      onTap: () {
                        _showVesselStatusBottomSheet();
                      },
                      child: _buildVesselMarkerWidget(),
                    ),
                  ),
                ],
              ),
            ],
          ),

          // 2. Floating Top Live Safety Status Banner
          Positioned(
            top: 12,
            left: 12,
            right: 12,
            child: _buildFloatingStatusBanner(),
          ),

          // 3. Floating Bottom-Right Quick Action Controls
          Positioned(
            bottom: 20,
            right: 16,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Recenter to vessel button
                FloatingActionButton.small(
                  heroTag: 'recenter_vessel_btn',
                  backgroundColor: Colors.white,
                  foregroundColor: const Color(0xFF007A87),
                  tooltip: 'Recenter on My Vessel',
                  onPressed: _recenterOnVessel,
                  child: const Icon(Icons.my_location_rounded),
                ),
                const SizedBox(height: 10),
                // Layers button
                FloatingActionButton.small(
                  heroTag: 'layers_btn',
                  backgroundColor: Colors.white,
                  foregroundColor: const Color(0xFF007A87),
                  tooltip: 'Map Layers',
                  onPressed: _showLayerTogglesDialog,
                  child: const Icon(Icons.layers_outlined),
                ),
                const SizedBox(height: 10),
                // Simulation selector button
                FloatingActionButton.extended(
                  heroTag: 'sim_btn',
                  backgroundColor: const Color(0xFF007A87),
                  foregroundColor: Colors.white,
                  icon: const Icon(Icons.alt_route_rounded, size: 18),
                  label: const Text('Move Vessel', style: TextStyle(fontSize: 12)),
                  onPressed: _showSimulationDialog,
                ),
              ],
            ),
          ),

          // 4. Stale cache notice bar (if cache > 7 days)
          if (_isStale)
            Positioned(
              bottom: 0,
              left: 0,
              right: 0,
              child: Container(
                color: Colors.amber.shade800,
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                child: const Row(
                  children: [
                    Icon(Icons.warning_amber_rounded, size: 16, color: Colors.white),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Advisory cache >7 days old. Connect to sync latest boundaries.',
                        style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600),
                      ),
                    ),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }

  // --- Map Layer Builders ---

  List<Polygon> _buildAdvisoryPolygons(List<AdvisoryZone> zones) {
    final polygons = <Polygon>[];

    for (final zone in zones) {
      final rings = zone.polygonGeoJson['coordinates'] as List<dynamic>?;
      if (rings == null || rings.isEmpty) continue;

      final outerRing = rings[0] as List<dynamic>;
      final points = <LatLng>[];

      for (final coord in outerRing) {
        if (coord is List && coord.length >= 2) {
          final lon = (coord[0] as num).toDouble();
          final lat = (coord[1] as num).toDouble();
          points.add(LatLng(lat, lon));
        }
      }

      if (points.length < 3) continue;

      final isAuthority = zone.classification == 'authority-confirmed';
      final baseColor = isAuthority ? const Color(0xFFDC2626) : const Color(0xFFEA580C);

      polygons.add(
        Polygon(
          points: points,
          color: baseColor.withValues(alpha: 0.25),
          borderColor: baseColor,
          borderStrokeWidth: 2.5,
          pattern: StrokePattern.dashed(segments: const [6.0, 4.0]),
        ),
      );
    }

    return polygons;
  }

  List<Marker> _buildAdvisoryMarkers(List<AdvisoryZone> zones) {
    final markers = <Marker>[];

    for (final zone in zones) {
      final rings = zone.polygonGeoJson['coordinates'] as List<dynamic>?;
      if (rings == null || rings.isEmpty) continue;

      final outerRing = rings[0] as List<dynamic>;
      if (outerRing.isEmpty) continue;

      // Calculate approximate centroid
      double sumLat = 0.0;
      double sumLon = 0.0;
      int count = 0;
      for (final coord in outerRing) {
        if (coord is List && coord.length >= 2) {
          sumLon += (coord[0] as num).toDouble();
          sumLat += (coord[1] as num).toDouble();
          count++;
        }
      }

      final center = LatLng(sumLat / count, sumLon / count);

      markers.add(
        Marker(
          point: center,
          width: 140,
          height: 38,
          child: GestureDetector(
            onTap: () => _showZoneDetailsBottomSheet(zone),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: const Color(0xFFDC2626).withValues(alpha: 0.92),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: Colors.white, width: 1.5),
                boxShadow: const [
                  BoxShadow(color: Colors.black26, blurRadius: 4, offset: Offset(0, 2)),
                ],
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.shield_outlined, color: Colors.white, size: 14),
                  const SizedBox(width: 4),
                  Flexible(
                    child: Text(
                      zone.title,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 10,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    return markers;
  }

  List<Marker> _buildCatchMarkers() {
    final markers = <Marker>[];

    for (final c in _cachedCatches) {
      if (c.latitude == null || c.longitude == null) continue;

      final isSynced = c.syncStatus == 'synced';
      final markerColor = isSynced ? const Color(0xFF10B981) : const Color(0xFFF59E0B);

      markers.add(
        Marker(
          point: LatLng(c.latitude!, c.longitude!),
          width: 32,
          height: 32,
          child: GestureDetector(
            onTap: () => _showCatchDetailsBottomSheet(c),
            child: Container(
              decoration: BoxDecoration(
                color: markerColor,
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white, width: 2.5),
                boxShadow: const [
                  BoxShadow(color: Colors.black38, blurRadius: 4, offset: Offset(0, 2)),
                ],
              ),
              child: const Icon(
                Icons.phishing_rounded,
                color: Colors.white,
                size: 16,
              ),
            ),
          ),
        ),
      );
    }

    return markers;
  }

  List<Marker> _buildTripMarkers() {
    final markers = <Marker>[];
    if (_activeTripPoints.isEmpty) return markers;

    // Start point
    final startPt = _activeTripPoints.first;
    markers.add(
      Marker(
        point: LatLng(startPt.latitude, startPt.longitude),
        width: 64,
        height: 26,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
          decoration: BoxDecoration(
            color: const Color(0xFF10B981),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: Colors.white, width: 1.5),
            boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 3)],
          ),
          child: Center(
            child: Text(
              _activeTrip?.vesselName != null && _activeTrip!.vesselName!.isNotEmpty
                  ? 'START (${_activeTrip!.vesselName})'
                  : 'START',
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 10),
            ),
          ),
        ),
      ),
    );

    // End / Last point
    if (_activeTripPoints.length > 1) {
      final endPt = _activeTripPoints.last;
      markers.add(
        Marker(
          point: LatLng(endPt.latitude, endPt.longitude),
          width: 72,
          height: 26,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: const Color(0xFF6366F1),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: Colors.white, width: 1.5),
              boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 3)],
            ),
            child: const Center(
              child: Text(
                'RETURN',
                style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 10),
              ),
            ),
          ),
        ),
      );
    }

    return markers;
  }

  Widget _buildVesselMarkerWidget() {
    final alertColor = _getAlertBannerColor();

    return Stack(
      alignment: Alignment.center,
      children: [
        // Pulsating outer halo
        Container(
          width: 48,
          height: 48,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: alertColor.withValues(alpha: 0.24),
          ),
        ),
        // Solid center pin
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: alertColor,
            border: Border.all(color: Colors.white, width: 2.5),
            boxShadow: const [
              BoxShadow(color: Colors.black45, blurRadius: 6, offset: Offset(0, 2)),
            ],
          ),
          child: const Icon(
            Icons.directions_boat_rounded,
            color: Colors.white,
            size: 18,
          ),
        ),
      ],
    );
  }

  // --- Top Status Floating Banner ---

  Widget _buildFloatingStatusBanner() {
    final isRed = _currentAlert.level == AlertLevel.red;
    final isYellow = _currentAlert.level == AlertLevel.yellow;

    final bannerColor = _getAlertBannerColor();
    final title = isRed
        ? '🚨 ACTIVE POISONING ADVISORY'
        : (isYellow ? '⚠️ CAUTION: APPROACHING ADVISORY' : '✅ SAFE FISHING WATERS');

    final subtitle = isRed
        ? 'Inside ${_currentAlert.zoneTitle ?? "Active Zone"} • Severe Ciguatera Risk'
        : (isYellow
            ? '${_currentAlert.distanceMeters.toStringAsFixed(0)}m to ${_currentAlert.zoneTitle ?? "advisory boundary"}'
            : 'No active fish poisoning advisories within 500m');

    return Card(
      elevation: 4,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: Colors.white,
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(12),
          border: Border(
            left: BorderSide(color: bannerColor, width: 6),
          ),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        child: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(
                color: bannerColor.withValues(alpha: 0.12),
                shape: BoxShape.circle,
              ),
              child: Icon(
                isRed
                    ? Icons.gpp_bad_rounded
                    : (isYellow ? Icons.warning_rounded : Icons.verified_rounded),
                color: bannerColor,
                size: 22,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    title,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 12,
                      color: bannerColor,
                    ),
                  ),
                  Text(
                    subtitle,
                    style: const TextStyle(fontSize: 11, color: Colors.black87),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'GPS: ${_currentLat.toStringAsFixed(4)}°, ${_currentLon.toStringAsFixed(4)}° (±${_currentAccuracy.toStringAsFixed(0)}m)',
                    style: TextStyle(fontSize: 10, color: Colors.grey.shade600),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // --- Bottom Sheets & Dialogs ---

  void _showVesselStatusBottomSheet() {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SingleChildScrollView(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.directions_boat_rounded, color: _getAlertBannerColor(), size: 28),
                const SizedBox(width: 12),
                const Text(
                  'My Vessel Live Position',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
              ],
            ),
            const Divider(height: 24),
            Text('Latitude: ${_currentLat.toStringAsFixed(6)}°'),
            Text('Longitude: ${_currentLon.toStringAsFixed(6)}°'),
            Text('Accuracy: ±${_currentAccuracy.toStringAsFixed(1)} meters'),
            const SizedBox(height: 8),
            Text(
              'Safety Status: ${_currentAlert.level.name.toUpperCase()}',
              style: TextStyle(
                fontWeight: FontWeight.bold,
                color: _getAlertBannerColor(),
              ),
            ),
            if (_currentAlert.zoneTitle != null)
              Text('Advisory Zone: ${_currentAlert.zoneTitle}'),
            if (_currentAlert.level != AlertLevel.red)
              Text('Distance to Nearest Boundary: ${_currentAlert.distanceMeters.toStringAsFixed(0)} meters'),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF007A87),
                  foregroundColor: Colors.white,
                ),
                icon: const Icon(Icons.my_location_rounded),
                label: const Text('Center Map on Vessel'),
                onPressed: () {
                  Navigator.pop(ctx);
                  _recenterOnVessel();
                },
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _showZoneDetailsBottomSheet(AdvisoryZone zone) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => DraggableScrollableSheet(
        initialChildSize: 0.55,
        minChildSize: 0.35,
        maxChildSize: 0.85,
        expand: false,
        builder: (_, scrollController) => ListView(
          controller: scrollController,
          padding: const EdgeInsets.all(20.0),
          children: [
            Row(
              children: [
                const Icon(Icons.shield_outlined, color: Color(0xFFDC2626), size: 26),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    zone.title,
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: Colors.red.shade100,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    zone.classification.toUpperCase(),
                    style: TextStyle(
                      color: Colors.red.shade900,
                      fontSize: 10,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ],
            ),
            const Divider(height: 24),
            const Text(
              'HEALTH CONCERN & BIOTOXIN RISK',
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey),
            ),
            const SizedBox(height: 4),
            Text(
              zone.concern,
              style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: Color(0xFFB91C1C)),
            ),
            if (zone.evidence != null) ...[
              const SizedBox(height: 12),
              const Text(
                'CLINICAL EVIDENCE / HOSPITAL RECORDS',
                style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey),
              ),
              const SizedBox(height: 4),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(zone.evidence!, style: const TextStyle(fontSize: 13)),
              ),
            ],
            const SizedBox(height: 16),
            const Text(
              'HIGH RISK SPECIES IN THIS ZONE',
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey),
            ),
            const SizedBox(height: 8),
            if (zone.species.isEmpty)
              const Text('General reef species', style: TextStyle(fontStyle: FontStyle.italic))
            else
              Wrap(
                spacing: 8,
                runSpacing: 6,
                children: zone.species.map((s) {
                  return Chip(
                    label: Text('${s.kiribatiName} (${s.englishName})'),
                    backgroundColor: Colors.red.shade50,
                    side: BorderSide(color: Colors.red.shade200),
                    labelStyle: TextStyle(
                      color: Colors.red.shade900,
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                    ),
                  );
                }).toList(),
              ),
            if (zone.source != null) ...[
              const SizedBox(height: 16),
              Text(
                'Source: ${zone.source}',
                style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
              ),
            ],
          ],
        ),
      ),
    );
  }

  void _showCatchDetailsBottomSheet(LocalCatch c) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.phishing_rounded, color: Color(0xFF007A87), size: 26),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    c.speciesNameRaw.isNotEmpty ? c.speciesNameRaw : 'Catch Submission',
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: c.syncStatus == 'synced' ? Colors.green.shade100 : Colors.amber.shade100,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    c.syncStatus.toUpperCase(),
                    style: TextStyle(
                      color: c.syncStatus == 'synced' ? Colors.green.shade900 : Colors.amber.shade900,
                      fontSize: 10,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ],
            ),
            const Divider(height: 20),
            Text('Weight: ${c.weightKg.toStringAsFixed(2)} kg  •  Count: ${c.quantity} fish'),
            Text('GPS: ${c.latitude?.toStringAsFixed(4)}°, ${c.longitude?.toStringAsFixed(4)}°'),
            Text('Captured At: ${c.capturedAt}'),
            if (c.photoLocalPath != null) ...[
              const SizedBox(height: 8),
              Text('Photo stored locally: ${c.photoLocalPath}'),
            ],
          ],
        ),
      ),
    );
  }

  void _showLayerTogglesDialog() {
    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: const Row(
            children: [
              Icon(Icons.layers_rounded, color: Color(0xFF007A87)),
              SizedBox(width: 8),
              Text('Map Layer Toggles'),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SwitchListTile(
                title: const Text('🛡️ Published Advisories'),
                subtitle: const Text('Poisoning zones & danger polygons'),
                value: _showAdvisories,
                onChanged: (val) {
                  setDialogState(() => _showAdvisories = val);
                  setState(() => _showAdvisories = val);
                },
              ),
              SwitchListTile(
                title: const Text('🐟 Catch Markers'),
                subtitle: const Text('Recorded artisanal catches'),
                value: _showCatches,
                onChanged: (val) {
                  setDialogState(() => _showCatches = val);
                  setState(() => _showCatches = val);
                },
              ),
              SwitchListTile(
                title: const Text('🧭 Trip Routes'),
                subtitle: const Text('Recorded vessel breadcrumb polylines'),
                value: _showRoutes,
                onChanged: (val) {
                  setDialogState(() => _showRoutes = val);
                  setState(() => _showRoutes = val);
                },
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Done'),
            ),
          ],
        ),
      ),
    );
  }

  void _showSimulationDialog() {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SingleChildScrollView(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.alt_route_rounded, color: Color(0xFF007A87), size: 26),
                SizedBox(width: 10),
                Text(
                  'Simulate Vessel Movement',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
              ],
            ),
            const SizedBox(height: 6),
            const Text(
              'Test how live GPS alerts react across South Tarawa Lagoon:',
              style: TextStyle(fontSize: 12, color: Colors.grey),
            ),
            const Divider(height: 20),
            ListTile(
              leading: const Icon(Icons.verified_rounded, color: Color(0xFF0D9488)),
              title: const Text('🟢 Safe Waters (Bairiki Lagoon)'),
              subtitle: const Text('Lat: 1.3300, Lon: 172.9800 (>500m from warning)'),
              onTap: () {
                Navigator.pop(ctx);
                _setSimulatedLocation(
                  lat: 1.3300,
                  lon: 172.9800,
                  accuracy: 12.0,
                  label: 'Bairiki Safe Waters',
                );
              },
            ),
            ListTile(
              leading: const Icon(Icons.warning_rounded, color: Color(0xFFEA580C)),
              title: const Text('🟡 Caution Boundary (500m to Betio Zone)'),
              subtitle: const Text('Lat: 1.3480, Lon: 172.9350 (Approaching boundary)'),
              onTap: () {
                Navigator.pop(ctx);
                _setSimulatedLocation(
                  lat: 1.3480,
                  lon: 172.9350,
                  accuracy: 14.0,
                  label: 'Caution Boundary (Betio)',
                );
              },
            ),
            ListTile(
              leading: const Icon(Icons.gpp_bad_rounded, color: Color(0xFFDC2626)),
              title: const Text('🔴 Inside Betio Lagoon Red Zone'),
              subtitle: const Text('Lat: 1.3650, Lon: 172.9450 (Inside active advisory)'),
              onTap: () {
                Navigator.pop(ctx);
                _setSimulatedLocation(
                  lat: 1.3650,
                  lon: 172.9450,
                  accuracy: 8.0,
                  label: 'Inside Betio Red Zone',
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}
