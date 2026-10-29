/// Offline Point-In-Polygon containment engine.
/// Implements the Ray-Casting (crossing number) algorithm according to GeoJSON RFC 7946.
/// Coordinate ordering in GeoJSON is [longitude, latitude].
class PointInPolygonEngine {
  /// Checks whether a given (latitude, longitude) is contained within the GeoJSON Polygon.
  static bool containsPoint(double latitude, double longitude, Map<String, dynamic> polygonGeoJson) {
    dynamic coordinates = polygonGeoJson['coordinates'];
    if (polygonGeoJson['geometry'] is Map &&
        (polygonGeoJson['geometry'] as Map).containsKey('coordinates')) {
      coordinates = polygonGeoJson['geometry']['coordinates'];
    }

    if (coordinates is! List || coordinates.isEmpty) {
      return false;
    }

    // Outer ring is at index 0
    final outerRing = coordinates[0];
    if (outerRing is! List || outerRing.length < 4) {
      return false;
    }

    if (!_isPointInLinearRing(latitude, longitude, outerRing)) {
      return false;
    }

    // If there are interior rings (holes), the point must NOT be inside any hole
    for (int i = 1; i < coordinates.length; i++) {
      final hole = coordinates[i];
      if (hole is List && hole.length >= 4) {
        if (_isPointInLinearRing(latitude, longitude, hole)) {
          return false;
        }
      }
    }

    return true;
  }

  /// Ray-casting algorithm to test whether a point (lat, lng) is inside a single closed ring.
  /// Ring coordinates are [lng, lat].
  static bool _isPointInLinearRing(double lat, double lng, List ring) {
    bool inside = false;
    final count = ring.length;

    for (int i = 0, j = count - 1; i < count; j = i++) {
      final p1 = ring[i];
      final p2 = ring[j];

      if (p1 is! List || p1.length < 2 || p2 is! List || p2.length < 2) {
        continue;
      }

      final double xi = (p1[0] as num).toDouble(); // longitude i
      final double yi = (p1[1] as num).toDouble(); // latitude i
      final double xj = (p2[0] as num).toDouble(); // longitude j
      final double yj = (p2[1] as num).toDouble(); // latitude j

      // Exact vertex match check (within epsilon)
      if ((xi - lng).abs() < 1e-7 && (yi - lat).abs() < 1e-7) {
        return true;
      }

      // Ray-casting intersection formula
      final intersect = ((yi > lat) != (yj > lat)) &&
          (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi);

      if (intersect) {
        inside = !inside;
      }
    }

    return inside;
  }
}
