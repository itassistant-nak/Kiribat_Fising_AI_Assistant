import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../data/database_helper.dart';
import '../../models/trip_record.dart';
import '../gis/distance_calculator.dart';

/// Outcome of a [TripRecorder.recordPoint] attempt.
enum BreadcrumbOutcome {
  recorded,
  noActiveTrip,
  tooClose, // moved less than minDistanceMeters since last point
  tooSoon, // less than minInterval since last point
  lowAccuracy, // GPS accuracy worse than maxAccuracyMeters
}

/// Offline-first GPS breadcrumb recorder (P1-05).
///
/// Points are appended to SQLite immediately, so a route survives app
/// restarts and airplane mode. Recording resumes via [resumeActiveTrip].
/// Upload to Laravel happens in [SyncService.performFullSync].
class TripRecorder extends ChangeNotifier {
  final DatabaseHelper _db;

  /// Minimum movement to store a new breadcrumb (anti-spam at anchor).
  final double minDistanceMeters;

  /// Minimum time between breadcrumbs.
  final Duration minInterval;

  /// Readings worse than this are skipped (same 50 m rule as ADR-013).
  final double maxAccuracyMeters;

  LocalTrip? _activeTrip;
  TripPoint? _lastPoint;
  DateTime? _lastRecordedAt;
  int _pointCount = 0;

  LocalTrip? get activeTrip => _activeTrip;
  bool get isRecording => _activeTrip != null;
  int get pointCount => _pointCount;

  TripRecorder({
    DatabaseHelper? db,
    this.minDistanceMeters = 10.0,
    this.minInterval = const Duration(seconds: 5),
    this.maxAccuracyMeters = 50.0,
  }) : _db = db ?? DatabaseHelper.instance;

  /// Reload the unfinished trip after an app restart (offline route resume).
  Future<LocalTrip?> resumeActiveTrip() async {
    final trip = await _db.getActiveTrip();
    _activeTrip = trip;
    if (trip != null) {
      final points = await _db.getTripPoints(trip.id);
      _pointCount = points.length;
      if (points.isNotEmpty) {
        _lastPoint = points.last;
        _lastRecordedAt = points.last.recordedAt;
      }
    }
    notifyListeners();
    return _activeTrip;
  }

  Future<LocalTrip> startTrip({String? vesselName, String? notes}) async {
    final now = DateTime.now();
    final trip = LocalTrip(
      id: const Uuid().v4(),
      vesselName: vesselName,
      startedAt: now,
      notes: notes,
      createdAt: now,
    );
    await _db.insertTrip(trip);
    _activeTrip = trip;
    _lastPoint = null;
    _lastRecordedAt = null;
    _pointCount = 0;
    notifyListeners();
    return trip;
  }

  Future<BreadcrumbOutcome> recordPoint({
    required double latitude,
    required double longitude,
    double? accuracy,
    DateTime? recordedAt,
  }) async {
    final trip = _activeTrip;
    if (trip == null) return BreadcrumbOutcome.noActiveTrip;
    if (accuracy != null && accuracy > maxAccuracyMeters) {
      return BreadcrumbOutcome.lowAccuracy;
    }

    final now = recordedAt ?? DateTime.now();
    if (_lastRecordedAt != null && now.difference(_lastRecordedAt!) < minInterval) {
      return BreadcrumbOutcome.tooSoon;
    }
    if (_lastPoint != null) {
      final moved = DistanceCalculator.haversineMeters(
        _lastPoint!.latitude,
        _lastPoint!.longitude,
        latitude,
        longitude,
      );
      if (moved < minDistanceMeters) return BreadcrumbOutcome.tooClose;
    }

    final sequence = await _db.getNextTripSequence(trip.id);
    final point = TripPoint(
      tripId: trip.id,
      latitude: latitude,
      longitude: longitude,
      gpsAccuracy: accuracy,
      sequence: sequence,
      recordedAt: now,
    );
    await _db.insertTripPoint(point);
    _lastPoint = point;
    _lastRecordedAt = now;
    _pointCount++;

    // New breadcrumbs make the trip dirty again (incremental sync).
    // Refresh from DB first: SyncService may have marked it synced
    // (and stored serverTripId) since this recorder cached it.
    final fresh = await _db.getTrip(trip.id);
    final current = fresh ?? trip;
    if (current.syncStatus == 'synced') {
      _activeTrip = current.copyWith(syncStatus: 'pending');
      await _db.updateTrip(_activeTrip!);
    } else {
      _activeTrip = current;
    }

    notifyListeners();
    return BreadcrumbOutcome.recorded;
  }

  Future<LocalTrip?> endTrip() async {
    final trip = _activeTrip;
    if (trip == null) return null;
    // Refresh from DB so a serverTripId stored by SyncService is preserved
    // (otherwise the next sync would create a duplicate server trip).
    final fresh = await _db.getTrip(trip.id);
    final ended = (fresh ?? trip).copyWith(
      endedAt: DateTime.now(),
      status: 'completed',
      syncStatus: 'pending',
    );
    await _db.updateTrip(ended);
    _activeTrip = null;
    _lastPoint = null;
    _lastRecordedAt = null;
    _pointCount = 0;
    notifyListeners();
    return ended;
  }
}
