/// Offline-first fishing trip + breadcrumb route models (P1-05).
/// A trip is recorded entirely in SQLite while at sea; points sync to
/// Laravel (`POST /api/trips`, `POST /api/trips/{id}/route-points`) later.
class LocalTrip {
  final String id; // UUID (client-side)
  final String? vesselName;
  final DateTime startedAt;
  final DateTime? endedAt;
  final String status; // 'in_progress', 'completed'
  final String syncStatus; // 'pending', 'synced', 'failed'
  final int? serverTripId;
  final String? notes;
  final DateTime createdAt;

  const LocalTrip({
    required this.id,
    this.vesselName,
    required this.startedAt,
    this.endedAt,
    this.status = 'in_progress',
    this.syncStatus = 'pending',
    this.serverTripId,
    this.notes,
    required this.createdAt,
  });

  Map<String, dynamic> toMap() {
    return {
      'id': id,
      'vessel_name': vesselName,
      'started_at': startedAt.toIso8601String(),
      'ended_at': endedAt?.toIso8601String(),
      'status': status,
      'sync_status': syncStatus,
      'server_trip_id': serverTripId,
      'notes': notes,
      'created_at': createdAt.toIso8601String(),
    };
  }

  factory LocalTrip.fromMap(Map<String, dynamic> map) {
    return LocalTrip(
      id: map['id'] as String,
      vesselName: map['vessel_name'] as String?,
      startedAt: DateTime.tryParse(map['started_at'] as String? ?? '') ?? DateTime.now(),
      endedAt: map['ended_at'] != null ? DateTime.tryParse(map['ended_at'] as String) : null,
      status: map['status'] as String? ?? 'in_progress',
      syncStatus: map['sync_status'] as String? ?? 'pending',
      serverTripId: map['server_trip_id'] as int?,
      notes: map['notes'] as String?,
      createdAt: DateTime.tryParse(map['created_at'] as String? ?? '') ?? DateTime.now(),
    );
  }

  LocalTrip copyWith({
    DateTime? endedAt,
    String? status,
    String? syncStatus,
    int? serverTripId,
    String? notes,
  }) {
    return LocalTrip(
      id: id,
      vesselName: vesselName,
      startedAt: startedAt,
      endedAt: endedAt ?? this.endedAt,
      status: status ?? this.status,
      syncStatus: syncStatus ?? this.syncStatus,
      serverTripId: serverTripId ?? this.serverTripId,
      notes: notes ?? this.notes,
      createdAt: createdAt,
    );
  }
}

/// One breadcrumb GPS point on a trip route. `sequence` is the stable
/// per-trip ordering key and doubles as the server idempotency key.
class TripPoint {
  final int? id; // local autoincrement row id
  final String tripId; // LocalTrip.id (client UUID)
  final double latitude;
  final double longitude;
  final double? gpsAccuracy;
  final int sequence;
  final DateTime recordedAt;
  final String syncStatus; // 'pending', 'synced', 'failed'

  const TripPoint({
    this.id,
    required this.tripId,
    required this.latitude,
    required this.longitude,
    this.gpsAccuracy,
    required this.sequence,
    required this.recordedAt,
    this.syncStatus = 'pending',
  });

  Map<String, dynamic> toMap() {
    return {
      if (id != null) 'id': id,
      'trip_id': tripId,
      'latitude': latitude,
      'longitude': longitude,
      'gps_accuracy': gpsAccuracy,
      'sequence': sequence,
      'recorded_at': recordedAt.toIso8601String(),
      'sync_status': syncStatus,
    };
  }

  factory TripPoint.fromMap(Map<String, dynamic> map) {
    return TripPoint(
      id: map['id'] as int?,
      tripId: map['trip_id'] as String,
      latitude: (map['latitude'] as num).toDouble(),
      longitude: (map['longitude'] as num).toDouble(),
      gpsAccuracy: (map['gps_accuracy'] as num?)?.toDouble(),
      sequence: map['sequence'] as int? ?? 0,
      recordedAt: DateTime.tryParse(map['recorded_at'] as String? ?? '') ?? DateTime.now(),
      syncStatus: map['sync_status'] as String? ?? 'pending',
    );
  }

  /// Payload shape expected by POST /api/trips/{id}/route-points.
  Map<String, dynamic> toServerJson() {
    return {
      'latitude': latitude,
      'longitude': longitude,
      'sequence': sequence,
      'recorded_at': recordedAt.toIso8601String(),
    };
  }
}
