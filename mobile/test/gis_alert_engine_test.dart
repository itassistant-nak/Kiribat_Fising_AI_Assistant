import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/models/advisory_zone.dart';
import 'package:mobile/models/alert_state.dart';
import 'package:mobile/services/gis/point_in_polygon.dart';
import 'package:mobile/services/gis/distance_calculator.dart';
import 'package:mobile/services/gis/zone_alert_engine.dart';

void main() {
  group('PointInPolygonEngine', () {
    // Betio Reef Synthetic Polygon: Longitude 172.9200 to 172.9400, Latitude 1.3500 to 1.3650
    final samplePolygon = {
      'type': 'Polygon',
      'coordinates': [
        [
          [172.9200, 1.3500],
          [172.9400, 1.3500],
          [172.9400, 1.3650],
          [172.9200, 1.3650],
          [172.9200, 1.3500],
        ]
      ]
    };

    test('correctly identifies point inside polygon', () {
      final inside = PointInPolygonEngine.containsPoint(1.3550, 172.9300, samplePolygon);
      expect(inside, isTrue);
    });

    test('correctly identifies point outside polygon', () {
      final outside = PointInPolygonEngine.containsPoint(1.3300, 172.9800, samplePolygon);
      expect(outside, isFalse);
    });

    test('handles polygon with interior hole exclusion', () {
      final polygonWithHole = {
        'type': 'Polygon',
        'coordinates': [
          // Outer ring
          [
            [172.9000, 1.3000],
            [172.9500, 1.3000],
            [172.9500, 1.3500],
            [172.9000, 1.3500],
            [172.9000, 1.3000],
          ],
          // Hole ring
          [
            [172.9200, 1.3200],
            [172.9300, 1.3200],
            [172.9300, 1.3300],
            [172.9200, 1.3300],
            [172.9200, 1.3200],
          ]
        ]
      };

      // Point in outer ring but outside hole -> inside
      expect(PointInPolygonEngine.containsPoint(1.3100, 172.9100, polygonWithHole), isTrue);

      // Point inside the hole -> outside
      expect(PointInPolygonEngine.containsPoint(1.3250, 172.9250, polygonWithHole), isFalse);
    });
  });

  group('DistanceCalculator', () {
    test('calculates accurate Haversine distance', () {
      // Distance between South Tarawa and Betio (~5.5 km)
      final dist = DistanceCalculator.haversineMeters(1.3300, 172.9800, 1.3500, 172.9400);
      expect(dist, greaterThan(4500));
      expect(dist, lessThan(6500));
    });

    test('calculates distance to polygon boundary', () {
      final polygon = {
        'type': 'Polygon',
        'coordinates': [
          [
            [172.9200, 1.3500],
            [172.9400, 1.3500],
            [172.9400, 1.3650],
            [172.9200, 1.3650],
            [172.9200, 1.3500],
          ]
        ]
      };

      // Point just south of the southern boundary (lat 1.3500)
      // Lat 1.3480 is ~222 meters south of 1.3500 (1 deg lat ~= 111km)
      final dist = DistanceCalculator.distanceToPolygonBoundaryMeters(1.3480, 172.9300, polygon);
      expect(dist, greaterThan(200));
      expect(dist, lessThan(250));
    });
  });

  group('ZoneAlertEngine', () {
    late ZoneAlertEngine engine;
    late List<AdvisoryZone> sampleZones;

    setUp(() {
      engine = ZoneAlertEngine();
      sampleZones = [
        AdvisoryZone(
          id: 1,
          title: 'Betio Western Reef [DEMO - SYNTHETIC]',
          concern: 'Ciguatera fish poisoning',
          evidence: 'Hospital admission records',
          classification: 'authority-confirmed',
          status: 'published',
          polygonGeoJson: {
            'type': 'Polygon',
            'coordinates': [
              [
                [172.9200, 1.3500],
                [172.9400, 1.3500],
                [172.9400, 1.3650],
                [172.9200, 1.3650],
                [172.9200, 1.3500],
              ]
            ]
          },
          cachedAt: DateTime.now().toIso8601String(),
          species: const [
            AdvisorySpecies(
              speciesId: 5,
              kiribatiName: 'Te Okaoka',
              englishName: 'Orange-spotted Trevally',
              scientificName: 'Carangoides bajad',
              riskLevel: 'high',
            ),
          ],
        ),
      ];
    });

    test('returns GREEN when vessel is in safe open lagoon (> 500m)', () {
      final res = engine.evaluateLocation(
        latitude: 1.3300,
        longitude: 172.9800,
        publishedZones: sampleZones,
      );

      expect(res.level, equals(AlertLevel.green));
      expect(res.inside, isFalse);
      expect(res.distanceMeters, greaterThan(500.0));
    });

    test('triggers YELLOW caution warning when approaching zone (<= 500m)', () {
      // 1.3480, 172.9300 is ~222m from southern boundary
      final res = engine.evaluateLocation(
        latitude: 1.3480,
        longitude: 172.9300,
        publishedZones: sampleZones,
      );

      expect(res.level, equals(AlertLevel.yellow));
      expect(res.inside, isFalse);
      expect(res.distanceMeters, lessThanOrEqualTo(500.0));
      expect(res.affectedSpecies.first.kiribatiName, equals('Te Okaoka'));
    });

    test('triggers RED active warning when entering advisory zone', () {
      final res = engine.evaluateLocation(
        latitude: 1.3550,
        longitude: 172.9300,
        publishedZones: sampleZones,
      );

      expect(res.level, equals(AlertLevel.red));
      expect(res.inside, isTrue);
      expect(res.distanceMeters, equals(0.0));
      expect(res.concern, equals('Ciguatera fish poisoning'));
      expect(res.affectedSpecies.first.kiribatiName, equals('Te Okaoka'));
    });

    test('anti-jitter hysteresis: prevents rapid alert flipping', () {
      final t0 = DateTime(2026, 10, 28, 10, 0, 0);

      // 1. Enter zone -> RED
      var res = engine.evaluateLocation(
        latitude: 1.3550,
        longitude: 172.9300,
        publishedZones: sampleZones,
        overrideTimestamp: t0,
      );
      expect(res.level, equals(AlertLevel.red));

      // 2. Small GPS fluctuation just 15m outside boundary (1.34985, 172.9300 is ~16m outside)
      // Because distance (16m) is <= hysteresisBuffer (50m), it stays RED
      res = engine.evaluateLocation(
        latitude: 1.34985,
        longitude: 172.9300,
        publishedZones: sampleZones,
        overrideTimestamp: t0.add(const Duration(seconds: 5)),
      );
      expect(res.level, equals(AlertLevel.red));

      // 3. Move cleanly beyond 50m buffer -> now transitions to YELLOW
      res = engine.evaluateLocation(
        latitude: 1.3490, // ~111m outside
        longitude: 172.9300,
        publishedZones: sampleZones,
        overrideTimestamp: t0.add(const Duration(seconds: 10)),
      );
      expect(res.level, equals(AlertLevel.yellow));

      // 4. Move to 520m distance from boundary:
      // In yellow, to downgrade to GREEN distance must exceed cautionDistance (500m) + buffer (50m) = 550m
      // At 520m, it remains YELLOW
      // 1 deg lat = 111,000m => 520m = 0.00468 deg lat. Boundary is at 1.3500 => lat = 1.3453
      res = engine.evaluateLocation(
        latitude: 1.3453,
        longitude: 172.9300,
        publishedZones: sampleZones,
        overrideTimestamp: t0.add(const Duration(seconds: 15)),
      );
      expect(res.level, equals(AlertLevel.yellow));

      // 5. Move to 600m distance (> 550m) -> transitions to GREEN
      res = engine.evaluateLocation(
        latitude: 1.3440,
        longitude: 172.9300,
        publishedZones: sampleZones,
        overrideTimestamp: t0.add(const Duration(seconds: 20)),
      );
      expect(res.level, equals(AlertLevel.green));
    });

    test('suppresses duplicate audio alerts within 60 second window', () {
      final t0 = DateTime(2026, 10, 28, 10, 0, 0);

      // Initial yellow alert -> not throttled
      var res = engine.evaluateLocation(
        latitude: 1.3480,
        longitude: 172.9300,
        publishedZones: sampleZones,
        overrideTimestamp: t0,
      );
      expect(res.level, equals(AlertLevel.yellow));
      expect(res.suppressAudioAlert, isFalse);

      // Repeat yellow alert 15 seconds later -> throttled
      res = engine.evaluateLocation(
        latitude: 1.3481,
        longitude: 172.9300,
        publishedZones: sampleZones,
        overrideTimestamp: t0.add(const Duration(seconds: 15)),
      );
      expect(res.level, equals(AlertLevel.yellow));
      expect(res.suppressAudioAlert, isTrue);

      // Escalation to RED at 30 seconds -> NEVER throttled
      res = engine.evaluateLocation(
        latitude: 1.3550,
        longitude: 172.9300,
        publishedZones: sampleZones,
        overrideTimestamp: t0.add(const Duration(seconds: 30)),
      );
      expect(res.level, equals(AlertLevel.red));
      expect(res.suppressAudioAlert, isFalse);
    });

    test('flags inaccurate GPS readings (> 50m accuracy)', () {
      final res = engine.evaluateLocation(
        latitude: 1.3480,
        longitude: 172.9300,
        gpsAccuracy: 75.0,
        publishedZones: sampleZones,
      );

      expect(res.accuracyWarning, isTrue);
    });

    test('identifies stale advisory cache', () {
      final res = engine.evaluateLocation(
        latitude: 1.3300,
        longitude: 172.9800,
        publishedZones: sampleZones,
        isCacheStale: true,
      );

      expect(res.isStaleCache, isTrue);
    });
  });
}
