import 'advisory_zone.dart';

enum AlertLevel {
  green,  // Safe: > 500m away from all published advisory zones
  yellow, // Caution: approaching zone (<= 500m from boundary)
  red,    // Active Warning: inside active published advisory zone
}

class ZoneAlertResult {
  final AlertLevel level;
  final int? zoneId;
  final String? zoneTitle;
  final double distanceMeters;
  final bool inside;
  final String? concern;
  final String? evidence;
  final String? classification;
  final String? source;
  final List<AdvisorySpecies> affectedSpecies;
  final bool isStaleCache;
  final bool accuracyWarning;
  final bool suppressAudioAlert; // True if duplicate alert within anti-jitter window

  const ZoneAlertResult({
    required this.level,
    this.zoneId,
    this.zoneTitle,
    required this.distanceMeters,
    this.inside = false,
    this.concern,
    this.evidence,
    this.classification,
    this.source,
    this.affectedSpecies = const [],
    this.isStaleCache = false,
    this.accuracyWarning = false,
    this.suppressAudioAlert = false,
  });

  static const ZoneAlertResult safe = ZoneAlertResult(
    level: AlertLevel.green,
    distanceMeters: double.infinity,
    inside: false,
  );
}
