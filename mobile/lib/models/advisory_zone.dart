import 'dart:convert';

class AdvisorySpecies {
  final int speciesId;
  final String kiribatiName;
  final String englishName;
  final String scientificName;
  final String riskLevel;
  final String? notes;

  const AdvisorySpecies({
    required this.speciesId,
    required this.kiribatiName,
    required this.englishName,
    required this.scientificName,
    required this.riskLevel,
    this.notes,
  });

  factory AdvisorySpecies.fromJson(Map<String, dynamic> json) {
    return AdvisorySpecies(
      speciesId: json['id'] as int? ?? json['species_id'] as int? ?? 0,
      kiribatiName: json['kiribati_name'] as String? ?? '',
      englishName: json['english_name'] as String? ?? '',
      scientificName: json['scientific_name'] as String? ?? '',
      riskLevel: json['pivot']?['risk_level'] as String? ??
          json['risk_level'] as String? ??
          'high',
      notes: json['pivot']?['notes'] as String? ?? json['notes'] as String?,
    );
  }

  Map<String, dynamic> toMap(int zoneId) {
    return {
      'zone_id': zoneId,
      'species_id': speciesId,
      'kiribati_name': kiribatiName,
      'english_name': englishName,
      'scientific_name': scientificName,
      'risk_level': riskLevel,
      'notes': notes,
    };
  }

  factory AdvisorySpecies.fromMap(Map<String, dynamic> map) {
    return AdvisorySpecies(
      speciesId: map['species_id'] as int,
      kiribatiName: map['kiribati_name'] as String? ?? '',
      englishName: map['english_name'] as String? ?? '',
      scientificName: map['scientific_name'] as String? ?? '',
      riskLevel: map['risk_level'] as String? ?? 'high',
      notes: map['notes'] as String?,
    );
  }
}

class AdvisoryZone {
  final int id;
  final String title;
  final String? description;
  final String concern;
  final String? evidence;
  final String classification;
  final String status;
  final Map<String, dynamic> polygonGeoJson;
  final String? source;
  final String? reviewDate;
  final String? updatedAt;
  final String cachedAt;
  final List<AdvisorySpecies> species;

  const AdvisoryZone({
    required this.id,
    required this.title,
    this.description,
    required this.concern,
    this.evidence,
    required this.classification,
    required this.status,
    required this.polygonGeoJson,
    this.source,
    this.reviewDate,
    this.updatedAt,
    required this.cachedAt,
    this.species = const [],
  });

  factory AdvisoryZone.fromJson(Map<String, dynamic> json) {
    Map<String, dynamic> geoJson = {};
    if (json['polygon_geojson'] is Map<String, dynamic>) {
      geoJson = json['polygon_geojson'] as Map<String, dynamic>;
    } else if (json['polygon_geojson'] is String) {
      try {
        geoJson = jsonDecode(json['polygon_geojson'] as String) as Map<String, dynamic>;
      } catch (_) {
        geoJson = {};
      }
    }

    final speciesList = <AdvisorySpecies>[];
    if (json['species'] is List) {
      for (final sp in json['species'] as List) {
        if (sp is Map<String, dynamic>) {
          speciesList.add(AdvisorySpecies.fromJson(sp));
        }
      }
    }

    return AdvisoryZone(
      id: json['id'] as int,
      title: json['title'] as String? ?? 'Untitled Advisory',
      description: json['description'] as String?,
      concern: json['concern'] as String? ?? 'Unspecified biohazard',
      evidence: json['evidence'] as String?,
      classification: json['classification'] as String? ?? 'suspected',
      status: json['status'] as String? ?? 'published',
      polygonGeoJson: geoJson,
      source: json['source'] as String?,
      reviewDate: json['review_date'] as String?,
      updatedAt: json['updated_at'] as String?,
      cachedAt: DateTime.now().toIso8601String(),
      species: speciesList,
    );
  }

  Map<String, dynamic> toMap() {
    return {
      'id': id,
      'title': title,
      'description': description,
      'concern': concern,
      'evidence': evidence,
      'classification': classification,
      'status': status,
      'polygon_geojson': jsonEncode(polygonGeoJson),
      'source': source,
      'review_date': reviewDate,
      'updated_at': updatedAt,
      'cached_at': cachedAt,
    };
  }

  factory AdvisoryZone.fromMap(
    Map<String, dynamic> map, {
    List<AdvisorySpecies> species = const [],
  }) {
    Map<String, dynamic> geoJson = {};
    if (map['polygon_geojson'] is String) {
      try {
        geoJson = jsonDecode(map['polygon_geojson'] as String) as Map<String, dynamic>;
      } catch (_) {
        geoJson = {};
      }
    }

    return AdvisoryZone(
      id: map['id'] as int,
      title: map['title'] as String? ?? '',
      description: map['description'] as String?,
      concern: map['concern'] as String? ?? '',
      evidence: map['evidence'] as String?,
      classification: map['classification'] as String? ?? 'suspected',
      status: map['status'] as String? ?? 'published',
      polygonGeoJson: geoJson,
      source: map['source'] as String?,
      reviewDate: map['review_date'] as String?,
      updatedAt: map['updated_at'] as String?,
      cachedAt: map['cached_at'] as String? ?? DateTime.now().toIso8601String(),
      species: species,
    );
  }
}
