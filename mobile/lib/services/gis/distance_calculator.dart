import 'dart:math';

/// Calculates geographic distances on the Earth's surface using the Haversine formula
/// and line segment projection for polygon boundary proximity.
class DistanceCalculator {
  static const double earthRadiusMeters = 6371000.0;

  /// Calculates Haversine distance in meters between two lat/lng points.
  static double haversineMeters(
    double lat1,
    double lon1,
    double lat2,
    double lon2,
  ) {
    final dLat = _toRadians(lat2 - lat1);
    final dLon = _toRadians(lon2 - lon1);

    final a = sin(dLat / 2) * sin(dLat / 2) +
        cos(_toRadians(lat1)) *
            cos(_toRadians(lat2)) *
            sin(dLon / 2) *
            sin(dLon / 2);

    final c = 2 * atan2(sqrt(a), sqrt(1 - a));
    return earthRadiusMeters * c;
  }

  /// Calculates the shortest distance in meters from point (lat, lon) to a line segment
  /// connecting point A (latA, lonA) and point B (latB, lonB).
  static double distanceToSegmentMeters(
    double lat,
    double lon,
    double latA,
    double lonA,
    double latB,
    double lonB,
  ) {
    // Convert to local equirectangular Cartesian approximation (accurate for local distances)
    final midLatRad = _toRadians((latA + latB + lat) / 3.0);
    final cosMidLat = cos(midLatRad);

    // X: East-West meters, Y: North-South meters
    final px = _toRadians(lon) * earthRadiusMeters * cosMidLat;
    final py = _toRadians(lat) * earthRadiusMeters;

    final ax = _toRadians(lonA) * earthRadiusMeters * cosMidLat;
    final ay = _toRadians(latA) * earthRadiusMeters;

    final bx = _toRadians(lonB) * earthRadiusMeters * cosMidLat;
    final by = _toRadians(latB) * earthRadiusMeters;

    final dx = bx - ax;
    final dy = by - ay;
    final segmentLengthSquared = dx * dx + dy * dy;

    if (segmentLengthSquared <= 1e-6) {
      // Degenerate segment: A and B are the same point
      return haversineMeters(lat, lon, latA, lonA);
    }

    // Projection parameter t of point P onto segment AB
    final t = ((px - ax) * dx + (py - ay) * dy) / segmentLengthSquared;
    final clampedT = t.clamp(0.0, 1.0);

    // Projected point coordinates
    final projX = ax + clampedT * dx;
    final projY = ay + clampedT * dy;

    final distSq = (px - projX) * (px - projX) + (py - projY) * (py - projY);
    return sqrt(distSq);
  }

  /// Calculates the shortest distance in meters from a point (lat, lon)
  /// to any segment of a GeoJSON Polygon boundary.
  static double distanceToPolygonBoundaryMeters(
    double lat,
    double lon,
    Map<String, dynamic> polygonGeoJson,
  ) {
    dynamic coordinates = polygonGeoJson['coordinates'];
    if (polygonGeoJson['geometry'] is Map &&
        (polygonGeoJson['geometry'] as Map).containsKey('coordinates')) {
      coordinates = polygonGeoJson['geometry']['coordinates'];
    }

    if (coordinates is! List || coordinates.isEmpty) {
      return double.infinity;
    }

    final outerRing = coordinates[0];
    if (outerRing is! List || outerRing.length < 2) {
      return double.infinity;
    }

    double minDistance = double.infinity;

    for (int i = 0; i < outerRing.length - 1; i++) {
      final pA = outerRing[i];
      final pB = outerRing[i + 1];

      if (pA is! List || pA.length < 2 || pB is! List || pB.length < 2) {
        continue;
      }

      // GeoJSON coordinate order is [longitude, latitude]
      final lonA = (pA[0] as num).toDouble();
      final latA = (pA[1] as num).toDouble();
      final lonB = (pB[0] as num).toDouble();
      final latB = (pB[1] as num).toDouble();

      final dist = distanceToSegmentMeters(lat, lon, latA, lonA, latB, lonB);
      if (dist < minDistance) {
        minDistance = dist;
      }
    }

    return minDistance;
  }

  static double _toRadians(double degrees) => degrees * pi / 180.0;
}
