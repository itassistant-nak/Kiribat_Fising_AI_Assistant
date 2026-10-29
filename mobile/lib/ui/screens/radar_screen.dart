import 'package:flutter/material.dart';
import '../../models/advisory_zone.dart';
import '../../models/alert_state.dart';
import '../../services/gis/zone_alert_engine.dart';
import '../../data/database_helper.dart';
import '../../services/sync/sync_service.dart';

class RadarScreen extends StatefulWidget {
  final SyncService syncService;

  const RadarScreen({super.key, required this.syncService});

  @override
  State<RadarScreen> createState() => _RadarScreenState();
}

class _RadarScreenState extends State<RadarScreen> {
  final ZoneAlertEngine _alertEngine = ZoneAlertEngine();
  final DatabaseHelper _db = DatabaseHelper.instance;

  // Simulated GPS Coordinates (South Tarawa / Betio Lagoon)
  double _currentLat = 1.3300;
  double _currentLon = 172.9800;
  double _currentAccuracy = 12.0; // meters

  List<AdvisoryZone> _cachedZones = [];
  ZoneAlertResult _currentAlert = ZoneAlertResult.safe;
  bool _isLoading = true;
  bool _isStale = false;

  @override
  void initState() {
    super.initState();
    _loadZonesAndEvaluate();
    widget.syncService.addListener(_onSyncUpdate);
  }

  @override
  void dispose() {
    widget.syncService.removeListener(_onSyncUpdate);
    super.dispose();
  }

  void _onSyncUpdate() {
    _loadZonesAndEvaluate();
  }

  Future<void> _loadZonesAndEvaluate() async {
    final zones = await _db.getCachedAdvisoryZones();
    final stale = await _db.isCacheStale('advisory_zones');

    if (mounted) {
      setState(() {
        _cachedZones = zones;
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

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('Simulated movement: $label (${lat.toStringAsFixed(4)}, ${lon.toStringAsFixed(4)})'),
        duration: const Duration(milliseconds: 1400),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Center(child: CircularProgressIndicator());
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Fisheries Safety Radar'),
        backgroundColor: _getAppBarColor(),
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Re-evaluate GPS',
            onPressed: _evaluateGps,
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // 1. Stale Cache Warning Banner (if cache > 7 days)
            if (_isStale)
              Card(
                color: Colors.amber.shade100,
                child: Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Row(
                    children: [
                      Icon(Icons.warning_amber_rounded, color: Colors.amber.shade900),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Advisory cache is stale (>7 days old). Connect to network to refresh public safety zones.',
                          style: TextStyle(color: Colors.amber.shade900, fontWeight: FontWeight.w600),
                        ),
                      ),
                    ],
                  ),
                ),
              ),

            // 2. Low Accuracy GPS Warning
            if (_currentAlert.accuracyWarning)
              Card(
                color: Colors.orange.shade100,
                child: Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Row(
                    children: [
                      Icon(Icons.gps_off_rounded, color: Colors.orange.shade900),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Low GPS Accuracy (±${_currentAccuracy.toStringAsFixed(0)}m). Warning thresholds may be imprecise.',
                          style: TextStyle(color: Colors.orange.shade900, fontWeight: FontWeight.w600),
                        ),
                      ),
                    ],
                  ),
                ),
              ),

            const SizedBox(height: 8),

            // 3. Primary Alert Status Banner
            _buildAlertBanner(),

            const SizedBox(height: 16),

            // 4. Affected Species & Evidence Section (if inside or caution)
            if (_currentAlert.level != AlertLevel.green && _currentAlert.affectedSpecies.isNotEmpty)
              _buildRiskDetailsCard(),

            const SizedBox(height: 16),

            // 5. Scientific Evidence Disclaimer
            Card(
              elevation: 1,
              color: Colors.blueGrey.shade50,
              child: const Padding(
                padding: EdgeInsets.all(12.0),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.verified_user_outlined, size: 20, color: Colors.blueGrey),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'AI cannot determine fish toxicity from photographs. Marine poisoning warnings are authorized by MFMRD from clinical hospital records and bioassays.',
                        style: TextStyle(fontSize: 12, color: Colors.blueGrey, fontStyle: FontStyle.italic),
                      ),
                    ),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 20),

            // 6. Interactive Movement Simulation Controls
            _buildSimulationControls(),
          ],
        ),
      ),
    );
  }

  Color _getAppBarColor() {
    switch (_currentAlert.level) {
      case AlertLevel.red:
        return Colors.red.shade800;
      case AlertLevel.yellow:
        return Colors.orange.shade800;
      case AlertLevel.green:
        return Colors.teal.shade800;
    }
  }

  Widget _buildAlertBanner() {
    final isRed = _currentAlert.level == AlertLevel.red;
    final isYellow = _currentAlert.level == AlertLevel.yellow;

    final bgColor = isRed
        ? Colors.red.shade700
        : (isYellow ? Colors.orange.shade600 : Colors.teal.shade600);

    final title = isRed
        ? '🚨 ACTIVE ADVISORY ZONE'
        : (isYellow ? '⚠️ CAUTION: APPROACHING ADVISORY' : '✅ SAFE FISHING WATERS');

    final subtitle = isRed
        ? 'Inside ${_currentAlert.zoneTitle ?? "Active Zone"}. Severe Ciguatera / Marine Biotoxin Risk.'
        : (isYellow
            ? 'Within ${_currentAlert.distanceMeters.toStringAsFixed(0)}m of ${_currentAlert.zoneTitle ?? "advisory boundary"}.'
            : 'No active fish poisoning advisories within 500 meters.');

    return Card(
      elevation: 4,
      color: bgColor,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          children: [
            Icon(
              isRed
                  ? Icons.gpp_bad_rounded
                  : (isYellow ? Icons.warning_rounded : Icons.verified_rounded),
              size: 56,
              color: Colors.white,
            ),
            const SizedBox(height: 12),
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 20,
                fontWeight: FontWeight.bold,
                letterSpacing: 0.5,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              subtitle,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white, fontSize: 14),
            ),
            if (!isRed && _currentAlert.distanceMeters.isFinite) ...[
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                decoration: BoxDecoration(
                  color: Colors.black26,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  'Distance to Boundary: ${_currentAlert.distanceMeters.toStringAsFixed(0)} meters',
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                ),
              ),
            ],
            if (_currentAlert.suppressAudioAlert) ...[
              const SizedBox(height: 8),
              const Text(
                'Anti-Jitter Active: Repeated notification throttled',
                style: TextStyle(color: Colors.white70, fontSize: 11),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildRiskDetailsCard() {
    return Card(
      elevation: 2,
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.biotech_rounded, color: Colors.red),
                const SizedBox(width: 8),
                Text(
                  'Species Under Advisory in this Zone',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.bold,
                      ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: _currentAlert.affectedSpecies.map((sp) {
                return Chip(
                  backgroundColor: Colors.red.shade50,
                  avatar: const CircleAvatar(
                    backgroundColor: Colors.red,
                    radius: 10,
                    child: Text('!', style: TextStyle(color: Colors.white, fontSize: 10)),
                  ),
                  label: Text(
                    '${sp.kiribatiName} (${sp.englishName})',
                    style: const TextStyle(color: Colors.red, fontWeight: FontWeight.bold),
                  ),
                );
              }).toList(),
            ),
            const Divider(height: 24),
            if (_currentAlert.concern != null) ...[
              const Text(
                'Reported Hazard / Concern:',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
              ),
              const SizedBox(height: 4),
              Text(
                _currentAlert.concern!,
                style: const TextStyle(color: Colors.black87),
              ),
              const SizedBox(height: 8),
            ],
            if (_currentAlert.evidence != null) ...[
              const Text(
                'Clinical & Laboratory Evidence:',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
              ),
              const SizedBox(height: 4),
              Text(
                _currentAlert.evidence!,
                style: TextStyle(color: Colors.grey.shade800, fontSize: 13),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildSimulationControls() {
    return Card(
      elevation: 2,
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.navigation_outlined, color: Colors.blue),
                const SizedBox(width: 8),
                Text(
                  'Simulate GPS Vessel Movement',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.bold,
                      ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              'Current GPS: ${_currentLat.toStringAsFixed(4)}° N, ${_currentLon.toStringAsFixed(4)}° E (±${_currentAccuracy.toStringAsFixed(0)}m)',
              style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                ActionChip(
                  avatar: const Icon(Icons.check_circle_outline, color: Colors.teal, size: 18),
                  label: const Text('1. Safe Lagoon (>1km)'),
                  onPressed: () => _setSimulatedLocation(
                    lat: 1.3300,
                    lon: 172.9800,
                    accuracy: 10.0,
                    label: 'Safe Lagoon',
                  ),
                ),
                ActionChip(
                  avatar: const Icon(Icons.warning_amber_rounded, color: Colors.orange, size: 18),
                  label: const Text('2. Approach Betio (400m)'),
                  onPressed: () => _setSimulatedLocation(
                    lat: 1.3480,
                    lon: 172.9350,
                    accuracy: 12.0,
                    label: 'Approaching Betio Reef',
                  ),
                ),
                ActionChip(
                  avatar: const Icon(Icons.error_outline_rounded, color: Colors.red, size: 18),
                  label: const Text('3. Enter Betio Zone'),
                  onPressed: () => _setSimulatedLocation(
                    lat: 1.3530,
                    lon: 172.9330,
                    accuracy: 8.0,
                    label: 'Inside Betio Reef',
                  ),
                ),
                ActionChip(
                  avatar: const Icon(Icons.gps_off, color: Colors.deepOrange, size: 18),
                  label: const Text('4. Low Accuracy (±75m)'),
                  onPressed: () => _setSimulatedLocation(
                    lat: 1.3490,
                    lon: 172.9360,
                    accuracy: 75.0,
                    label: 'Inaccurate GPS Drift',
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
