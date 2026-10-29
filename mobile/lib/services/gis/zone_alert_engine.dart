import '../../models/advisory_zone.dart';
import '../../models/alert_state.dart';
import 'point_in_polygon.dart';
import 'distance_calculator.dart';

class ZoneAlertEngine {
  // Alert Distance Thresholds (Meters)
  static const double cautionDistanceMeters = 500.0;
  static const double hysteresisBufferMeters = 50.0;
  static const double maxReliableGpsAccuracyMeters = 50.0;
  static const Duration duplicateAlertThrottleDuration = Duration(seconds: 60);

  // State Machine Memory
  AlertLevel _currentLevel = AlertLevel.green;
  int? _currentZoneId;
  DateTime? _lastAlertTimestamp;

  AlertLevel get currentLevel => _currentLevel;
  int? get currentZoneId => _currentZoneId;

  void resetState() {
    _currentLevel = AlertLevel.green;
    _currentZoneId = null;
    _lastAlertTimestamp = null;
  }

  /// Evaluates GPS position against all cached published advisory zones.
  ZoneAlertResult evaluateLocation({
    required double latitude,
    required double longitude,
    double? gpsAccuracy,
    required List<AdvisoryZone> publishedZones,
    bool isCacheStale = false,
    DateTime? overrideTimestamp,
  }) {
    final now = overrideTimestamp ?? DateTime.now();
    final bool accuracyWarning =
        gpsAccuracy != null && gpsAccuracy > maxReliableGpsAccuracyMeters;

    if (publishedZones.isEmpty) {
      _currentLevel = AlertLevel.green;
      _currentZoneId = null;
      return ZoneAlertResult(
        level: AlertLevel.green,
        distanceMeters: double.infinity,
        inside: false,
        isStaleCache: isCacheStale,
        accuracyWarning: accuracyWarning,
      );
    }

    // 1. Check for containment inside any zone (RED ALERT)
    for (final zone in publishedZones) {
      final isInside = PointInPolygonEngine.containsPoint(
        latitude,
        longitude,
        zone.polygonGeoJson,
      );

      if (isInside) {
        final bool shouldThrottle = _shouldThrottleDuplicate(
          targetLevel: AlertLevel.red,
          targetZoneId: zone.id,
          now: now,
        );

        _currentLevel = AlertLevel.red;
        _currentZoneId = zone.id;
        _lastAlertTimestamp = now;

        return ZoneAlertResult(
          level: AlertLevel.red,
          zoneId: zone.id,
          zoneTitle: zone.title,
          distanceMeters: 0.0,
          inside: true,
          concern: zone.concern,
          evidence: zone.evidence,
          classification: zone.classification,
          source: zone.source,
          affectedSpecies: zone.species,
          isStaleCache: isCacheStale,
          accuracyWarning: accuracyWarning,
          suppressAudioAlert: shouldThrottle,
        );
      }
    }

    // 2. Not inside any zone: Calculate distance to nearest boundary
    AdvisoryZone? nearestZone;
    double minDistance = double.infinity;

    for (final zone in publishedZones) {
      final dist = DistanceCalculator.distanceToPolygonBoundaryMeters(
        latitude,
        longitude,
        zone.polygonGeoJson,
      );

      if (dist < minDistance) {
        minDistance = dist;
        nearestZone = zone;
      }
    }

    // 3. Apply Hysteresis to determine level transition
    AlertLevel newLevel;

    if (_currentLevel == AlertLevel.red) {
      // Must be outside AND distance > hysteresisBuffer (50m) to leave RED
      if (minDistance > hysteresisBufferMeters) {
        newLevel = minDistance <= cautionDistanceMeters
            ? AlertLevel.yellow
            : AlertLevel.green;
      } else {
        // Remain in RED due to proximity jitter
        newLevel = AlertLevel.red;
      }
    } else if (_currentLevel == AlertLevel.yellow) {
      // Must exceed cautionDistance + hysteresis (500m + 50m = 550m) to downgrade to GREEN
      if (minDistance > (cautionDistanceMeters + hysteresisBufferMeters)) {
        newLevel = AlertLevel.green;
      } else {
        newLevel = AlertLevel.yellow;
      }
    } else {
      // Current level is GREEN: enters YELLOW at <= 500m
      if (minDistance <= cautionDistanceMeters) {
        newLevel = AlertLevel.yellow;
      } else {
        newLevel = AlertLevel.green;
      }
    }

    // 4. Time Throttling check for duplicate non-escalating alerts
    final int? activeZoneId = nearestZone?.id;
    final bool shouldThrottle = _shouldThrottleDuplicate(
      targetLevel: newLevel,
      targetZoneId: activeZoneId,
      now: now,
    );

    _currentLevel = newLevel;
    _currentZoneId = activeZoneId;
    if (newLevel != AlertLevel.green) {
      _lastAlertTimestamp = now;
    }

    return ZoneAlertResult(
      level: newLevel,
      zoneId: nearestZone?.id,
      zoneTitle: nearestZone?.title,
      distanceMeters: minDistance,
      inside: false,
      concern: nearestZone?.concern,
      evidence: nearestZone?.evidence,
      classification: nearestZone?.classification,
      source: nearestZone?.source,
      affectedSpecies: nearestZone?.species ?? [],
      isStaleCache: isCacheStale,
      accuracyWarning: accuracyWarning,
      suppressAudioAlert: shouldThrottle,
    );
  }

  bool _shouldThrottleDuplicate({
    required AlertLevel targetLevel,
    required int? targetZoneId,
    required DateTime now,
  }) {
    // Escalation (Green -> Yellow, or Yellow -> Red) is NEVER throttled
    if (targetLevel.index > _currentLevel.index) {
      return false;
    }

    // If same level and same zone occurred recently, throttle audio/vibrate
    if (targetLevel == _currentLevel && targetZoneId == _currentZoneId) {
      if (_lastAlertTimestamp != null) {
        final elapsed = now.difference(_lastAlertTimestamp!);
        if (elapsed < duplicateAlertThrottleDuration) {
          return true;
        }
      }
    }

    return false;
  }
}
