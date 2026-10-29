class LocalCatch {
  final String id; // UUID
  final int? speciesId;
  final String speciesNameRaw;
  final double weightKg;
  final int quantity;
  final double? latitude;
  final double? longitude;
  final double? gpsAccuracy;
  final DateTime capturedAt;
  final String? photoLocalPath;
  final String? photoUrl;
  final String syncStatus; // 'pending', 'synced', 'failed'
  final int? serverCatchId;
  final String? aiSpeciesSuggestion;
  final double? aiConfidence;
  final String aiStatus; // 'online_identified', 'cached_match', 'waiting_for_online_ai', 'unidentified'
  final String? ciguateraRisk;
  final String? ciguateraWarning;
  final String? photoQuality; // 'clear', 'blurry', 'unknown' (on-device check)
  final double? photoBlurScore; // Laplacian variance at capture time
  final DateTime createdAt;

  const LocalCatch({
    required this.id,
    this.speciesId,
    required this.speciesNameRaw,
    required this.weightKg,
    this.quantity = 1,
    this.latitude,
    this.longitude,
    this.gpsAccuracy,
    required this.capturedAt,
    this.photoLocalPath,
    this.photoUrl,
    this.syncStatus = 'pending',
    this.serverCatchId,
    this.aiSpeciesSuggestion,
    this.aiConfidence,
    this.aiStatus = 'waiting_for_online_ai',
    this.ciguateraRisk,
    this.ciguateraWarning,
    this.photoQuality,
    this.photoBlurScore,
    required this.createdAt,
  });

  Map<String, dynamic> toMap() {
    return {
      'id': id,
      'species_id': speciesId,
      'species_name_raw': speciesNameRaw,
      'weight_kg': weightKg,
      'quantity': quantity,
      'latitude': latitude,
      'longitude': longitude,
      'gps_accuracy': gpsAccuracy,
      'captured_at': capturedAt.toIso8601String(),
      'photo_local_path': photoLocalPath,
      'photo_url': photoUrl,
      'sync_status': syncStatus,
      'server_catch_id': serverCatchId,
      'ai_species_suggestion': aiSpeciesSuggestion,
      'ai_confidence': aiConfidence,
      'ai_status': aiStatus,
      'ciguatera_risk': ciguateraRisk,
      'ciguatera_warning': ciguateraWarning,
      'photo_quality': photoQuality,
      'photo_blur_score': photoBlurScore,
      'created_at': createdAt.toIso8601String(),
    };
  }

  factory LocalCatch.fromMap(Map<String, dynamic> map) {
    return LocalCatch(
      id: map['id'] as String,
      speciesId: map['species_id'] as int?,
      speciesNameRaw: map['species_name_raw'] as String? ?? 'Unknown',
      weightKg: (map['weight_kg'] as num?)?.toDouble() ?? 0.0,
      quantity: map['quantity'] as int? ?? 1,
      latitude: (map['latitude'] as num?)?.toDouble(),
      longitude: (map['longitude'] as num?)?.toDouble(),
      gpsAccuracy: (map['gps_accuracy'] as num?)?.toDouble(),
      capturedAt: DateTime.tryParse(map['captured_at'] as String? ?? '') ?? DateTime.now(),
      photoLocalPath: map['photo_local_path'] as String?,
      photoUrl: map['photo_url'] as String?,
      syncStatus: map['sync_status'] as String? ?? 'pending',
      serverCatchId: map['server_catch_id'] as int?,
      aiSpeciesSuggestion: map['ai_species_suggestion'] as String?,
      aiConfidence: (map['ai_confidence'] as num?)?.toDouble(),
      aiStatus: map['ai_status'] as String? ?? 'waiting_for_online_ai',
      ciguateraRisk: map['ciguatera_risk'] as String?,
      ciguateraWarning: map['ciguatera_warning'] as String?,
      photoQuality: map['photo_quality'] as String?,
      photoBlurScore: (map['photo_blur_score'] as num?)?.toDouble(),
      createdAt: DateTime.tryParse(map['created_at'] as String? ?? '') ?? DateTime.now(),
    );
  }

  LocalCatch copyWith({
    String? syncStatus,
    int? serverCatchId,
    String? photoUrl,
    String? aiSpeciesSuggestion,
    double? aiConfidence,
    String? aiStatus,
    String? ciguateraRisk,
    String? ciguateraWarning,
  }) {
    return LocalCatch(
      id: id,
      speciesId: speciesId,
      speciesNameRaw: speciesNameRaw,
      weightKg: weightKg,
      quantity: quantity,
      latitude: latitude,
      longitude: longitude,
      gpsAccuracy: gpsAccuracy,
      capturedAt: capturedAt,
      photoLocalPath: photoLocalPath,
      photoUrl: photoUrl ?? this.photoUrl,
      syncStatus: syncStatus ?? this.syncStatus,
      serverCatchId: serverCatchId ?? this.serverCatchId,
      aiSpeciesSuggestion: aiSpeciesSuggestion ?? this.aiSpeciesSuggestion,
      aiConfidence: aiConfidence ?? this.aiConfidence,
      aiStatus: aiStatus ?? this.aiStatus,
      ciguateraRisk: ciguateraRisk ?? this.ciguateraRisk,
      ciguateraWarning: ciguateraWarning ?? this.ciguateraWarning,
      photoQuality: photoQuality,
      photoBlurScore: photoBlurScore,
      createdAt: createdAt,
    );
  }
}
