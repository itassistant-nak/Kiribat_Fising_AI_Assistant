import 'dart:convert';

class SpeciesItem {
  final int id;
  final String kiribatiName;
  final String englishName;
  final String scientificName;
  final String ciguateraRisk;
  final String? photoUrl;
  final List<String> visualFeatures;
  final bool aiTrained;
  final String verificationStatus;
  final String recordType;
  final String cachedAt;

  const SpeciesItem({
    required this.id,
    required this.kiribatiName,
    required this.englishName,
    required this.scientificName,
    required this.ciguateraRisk,
    this.photoUrl,
    this.visualFeatures = const [],
    this.aiTrained = false,
    this.verificationStatus = 'verified',
    this.recordType = 'species',
    required this.cachedAt,
  });

  factory SpeciesItem.fromJson(Map<String, dynamic> json) {
    List<String> traits = [];
    if (json['visual_features'] is List) {
      traits = (json['visual_features'] as List)
          .map((e) => e.toString())
          .toList();
    } else if (json['visual_features'] is String) {
      try {
        final decoded = jsonDecode(json['visual_features'] as String);
        if (decoded is List) {
          traits = decoded.map((e) => e.toString()).toList();
        }
      } catch (_) {}
    }

    return SpeciesItem(
      id: json['id'] as int? ?? 0,
      kiribatiName: json['kiribati_name'] as String? ?? 'Inoa Akea',
      englishName: json['english_name'] as String? ?? 'Unknown Fish',
      scientificName: json['scientific_name'] as String? ?? 'Species sp.',
      ciguateraRisk: json['ciguatera_risk'] as String? ?? 'low',
      photoUrl: json['photo_url'] as String?,
      visualFeatures: traits,
      aiTrained: json['ai_trained'] == true || json['ai_trained'] == 1,
      verificationStatus: json['verification_status'] as String? ?? 'verified',
      recordType: json['record_type'] as String? ?? 'species',
      cachedAt: DateTime.now().toIso8601String(),
    );
  }

  Map<String, dynamic> toMap() {
    return {
      'id': id,
      'kiribati_name': kiribatiName,
      'english_name': englishName,
      'scientific_name': scientificName,
      'ciguatera_risk': ciguateraRisk,
      'photo_url': photoUrl,
      'visual_features': jsonEncode(visualFeatures),
      'ai_trained': aiTrained ? 1 : 0,
      'verification_status': verificationStatus,
      'record_type': recordType,
      'cached_at': cachedAt,
    };
  }

  factory SpeciesItem.fromMap(Map<String, dynamic> map) {
    List<String> traits = [];
    if (map['visual_features'] is String) {
      try {
        final decoded = jsonDecode(map['visual_features'] as String);
        if (decoded is List) {
          traits = decoded.map((e) => e.toString()).toList();
        }
      } catch (_) {}
    }

    return SpeciesItem(
      id: map['id'] as int,
      kiribatiName: map['kiribati_name'] as String? ?? '',
      englishName: map['english_name'] as String? ?? '',
      scientificName: map['scientific_name'] as String? ?? '',
      ciguateraRisk: map['ciguatera_risk'] as String? ?? 'low',
      photoUrl: map['photo_url'] as String?,
      visualFeatures: traits,
      aiTrained: (map['ai_trained'] as int? ?? 0) == 1,
      verificationStatus: map['verification_status'] as String? ?? 'verified',
      recordType: map['record_type'] as String? ?? 'species',
      cachedAt: map['cached_at'] as String? ?? DateTime.now().toIso8601String(),
    );
  }
}
