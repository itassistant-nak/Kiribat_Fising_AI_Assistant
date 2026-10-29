import 'package:flutter/material.dart';
import '../../data/database_helper.dart';
import '../../models/trip_record.dart';
import '../../services/sync/sync_service.dart';
import '../../services/trip/trip_recorder.dart';

/// Fishing trip GPS route recorder (P1-05).
/// Start a trip, record breadcrumb points offline, end the trip, then sync.
/// Positions are simulated (South Tarawa lagoon) following the Radar screen
/// pattern; the recorder itself only needs lat/lon/accuracy.
class TripScreen extends StatefulWidget {
  final SyncService syncService;

  const TripScreen({super.key, required this.syncService});

  @override
  State<TripScreen> createState() => _TripScreenState();
}

class _TripScreenState extends State<TripScreen> {
  late final TripRecorder _recorder;
  final DatabaseHelper _db = DatabaseHelper.instance;
  final TextEditingController _vesselController = TextEditingController();

  List<LocalTrip> _trips = [];
  List<TripPoint> _activePoints = [];
  bool _isLoading = true;
  String? _lastOutcome;

  // Simulated vessel positions drifting across the lagoon.
  double _simLat = 1.3300;
  double _simLon = 172.9800;

  @override
  void initState() {
    super.initState();
    _recorder = TripRecorder();
    _recorder.addListener(_refresh);
    _boot();
  }

  @override
  void dispose() {
    _recorder.removeListener(_refresh);
    _recorder.dispose();
    _vesselController.dispose();
    super.dispose();
  }

  Future<void> _boot() async {
    await _recorder.resumeActiveTrip();
    await _refresh();
    if (mounted) setState(() => _isLoading = false);
  }

  Future<void> _refresh() async {
    final trips = await _db.getLocalTrips();
    List<TripPoint> points = [];
    final active = _recorder.activeTrip;
    if (active != null) {
      points = await _db.getTripPoints(active.id);
    }
    if (mounted) {
      setState(() {
        _trips = trips;
        _activePoints = points;
      });
    }
    await widget.syncService.refreshPendingCount();
  }

  Future<void> _startTrip() async {
    final vessel = _vesselController.text.trim();
    await _recorder.startTrip(vesselName: vessel.isEmpty ? null : vessel);
    setState(() => _lastOutcome = 'Trip started — recording offline breadcrumbs.');
    await _refresh();
  }

  Future<void> _recordSimulatedPoint() async {
    // Drift northeast ~150 m per tap so distance filtering passes.
    _simLat += 0.0012;
    _simLon += 0.0012;
    final outcome = await _recorder.recordPoint(
      latitude: _simLat,
      longitude: _simLon,
      accuracy: 12.0,
    );
    setState(() => _lastOutcome = 'Breadcrumb: ${outcome.name} '
        '(${_simLat.toStringAsFixed(4)}, ${_simLon.toStringAsFixed(4)})');
    await _refresh();
  }

  Future<void> _endTrip() async {
    await _recorder.endTrip();
    setState(() => _lastOutcome = 'Trip ended — queued for sync.');
    await _refresh();
  }

  Future<void> _syncNow() async {
    final result = await widget.syncService.performFullSync();
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(result.message)),
      );
    }
    await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Center(child: CircularProgressIndicator());
    }

    final recording = _recorder.isRecording;
    final active = _recorder.activeTrip;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Trip Routes'),
        backgroundColor: recording ? Colors.teal : Colors.blueGrey,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.sync),
            tooltip: 'Sync trips now',
            onPressed: _syncNow,
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Icon(
                        recording ? Icons.radio_button_checked : Icons.radio_button_off,
                        color: recording ? Colors.red : Colors.grey,
                      ),
                      const SizedBox(width: 8),
                      Text(
                        recording ? 'RECORDING' : 'NOT RECORDING',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                      const Spacer(),
                      Chip(label: Text('${_activePoints.length} points')),
                    ],
                  ),
                  if (active != null) ...[
                    const SizedBox(height: 8),
                    Text('Trip: ${active.vesselName ?? 'Unnamed vessel'}'),
                    Text(
                      'Started: ${active.startedAt.toLocal()}',
                      style: const TextStyle(color: Colors.grey),
                    ),
                    Text(
                      'Sync: ${active.syncStatus}${active.serverTripId != null ? ' (#${active.serverTripId})' : ''}',
                      style: const TextStyle(color: Colors.grey),
                    ),
                  ],
                  if (_lastOutcome != null) ...[
                    const SizedBox(height: 8),
                    Text(_lastOutcome!, style: const TextStyle(color: Colors.teal)),
                  ],
                  const SizedBox(height: 12),
                  if (!recording)
                    TextField(
                      controller: _vesselController,
                      decoration: const InputDecoration(
                        labelText: 'Vessel name (optional)',
                        border: OutlineInputBorder(),
                      ),
                    ),
                  if (!recording) const SizedBox(height: 12),
                  if (!recording)
                    ElevatedButton.icon(
                      onPressed: _startTrip,
                      icon: const Icon(Icons.play_arrow),
                      label: const Text('Start Trip (Offline)'),
                    ),
                  if (recording)
                    ElevatedButton.icon(
                      onPressed: _recordSimulatedPoint,
                      icon: const Icon(Icons.add_location_alt),
                      label: const Text('Record Breadcrumb (Simulated GPS)'),
                    ),
                  if (recording) const SizedBox(height: 8),
                  if (recording)
                    OutlinedButton.icon(
                      onPressed: _endTrip,
                      icon: const Icon(Icons.stop),
                      label: const Text('End Trip'),
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Saved trips (${_trips.length})',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
          ),
          const SizedBox(height: 8),
          ..._trips.map((trip) => Card(
                child: ListTile(
                  leading: Icon(
                    trip.status == 'in_progress' ? Icons.directions_boat : Icons.check_circle,
                    color: trip.status == 'in_progress' ? Colors.teal : Colors.green,
                  ),
                  title: Text(trip.vesselName ?? 'Unnamed vessel'),
                  subtitle: Text(
                    '${trip.status} • ${trip.syncStatus}'
                    '${trip.serverTripId != null ? ' • server #${trip.serverTripId}' : ''}\n'
                    'Started ${trip.startedAt.toLocal().toString().substring(0, 16)}',
                  ),
                  isThreeLine: true,
                ),
              )),
        ],
      ),
    );
  }
}
