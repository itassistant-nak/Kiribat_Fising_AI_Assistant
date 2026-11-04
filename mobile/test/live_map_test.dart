import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:mobile/data/database_helper.dart';
import 'package:mobile/models/advisory_zone.dart';
import 'package:mobile/services/sync/sync_service.dart';
import 'package:mobile/ui/screens/live_map_screen.dart';

void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  group('LiveMapScreen Fisherman GPS Map Tests', () {
    late Database db;
    late DatabaseHelper dbHelper;
    late SyncService syncService;
    late AdvisoryZone sampleZone;

    setUp(() async {
      dbHelper = DatabaseHelper.instance;
      db = await openDatabase(
        inMemoryDatabasePath,
        version: 1,
        onCreate: dbHelper.createDbForTesting,
      );
      dbHelper.setDatabaseForTesting(db);

      sampleZone = AdvisoryZone(
        id: 101,
        title: 'Betio Lagoon Toxic Zone [DEMO]',
        concern: 'High ciguatoxin concentration detected',
        evidence: 'Betio hospital reported 8 clinical cases',
        classification: 'authority-confirmed',
        status: 'published',
        polygonGeoJson: const {
          'type': 'Polygon',
          'coordinates': [
            [
              [172.9300, 1.3500],
              [172.9600, 1.3500],
              [172.9600, 1.3700],
              [172.9300, 1.3700],
              [172.9300, 1.3500],
            ]
          ]
        },
        source: 'MFMRD Biosecurity Division',
        cachedAt: DateTime.now().toIso8601String(),
      );

      syncService = SyncService();
    });

    tearDown(() async {
      dbHelper.setDatabaseForTesting(null);
      await db.close();
    });

    testWidgets('1. Renders LiveMapScreen with title, coordinates, and safe status', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LiveMapScreen(
            syncService: syncService,
            initialLat: 1.3300,
            initialLon: 172.9800,
            initialZones: [sampleZone],
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      expect(find.text('Fisheries GPS Map'), findsOneWidget);
      expect(find.textContaining('South Tarawa Lagoon'), findsOneWidget);
      expect(find.textContaining('SAFE FISHING WATERS'), findsOneWidget);
      expect(find.textContaining('GPS: 1.3300°, 172.9800°'), findsOneWidget);
    });

    testWidgets('2. Opens layer toggles dialog and shows all layer switches', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LiveMapScreen(
            syncService: syncService,
            initialLat: 1.3300,
            initialLon: 172.9800,
            initialZones: [sampleZone],
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      // Tap layers icon in app bar
      await tester.tap(find.byIcon(Icons.layers_rounded));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      expect(find.text('Map Layer Toggles'), findsOneWidget);
      expect(find.text('🛡️ Published Advisories'), findsOneWidget);
      expect(find.text('🐟 Catch Markers'), findsOneWidget);
      expect(find.text('🧭 Trip Routes'), findsOneWidget);

      // Close dialog
      await tester.tap(find.text('Done'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));
      expect(find.text('Map Layer Toggles'), findsNothing);
    });

    testWidgets('3. Simulates vessel movement into Betio Red Zone and updates alert', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LiveMapScreen(
            syncService: syncService,
            initialLat: 1.3300,
            initialLon: 172.9800,
            initialZones: [sampleZone],
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      // Tap Move Vessel FAB
      await tester.tap(find.byIcon(Icons.alt_route_rounded));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      expect(find.text('Simulate Vessel Movement'), findsOneWidget);
      expect(find.text('🔴 Inside Betio Lagoon Red Zone'), findsOneWidget);

      // Tap to move inside Betio Lagoon Red Zone
      await tester.tap(find.text('🔴 Inside Betio Lagoon Red Zone'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      // Verify active alert banner
      expect(find.textContaining('ACTIVE POISONING ADVISORY'), findsOneWidget);
      expect(find.textContaining('GPS: 1.3650°, 172.9450°'), findsOneWidget);
    });

    testWidgets('4. Tapping vessel position opens live position bottom sheet', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LiveMapScreen(
            syncService: syncService,
            initialLat: 1.3300,
            initialLon: 172.9800,
            initialZones: [sampleZone],
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      // Tap on vessel boat icon
      await tester.tap(find.byIcon(Icons.directions_boat_rounded).first);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      expect(find.text('My Vessel Live Position'), findsOneWidget);
      expect(find.textContaining('Latitude: 1.330000°'), findsOneWidget);
      expect(find.textContaining('Longitude: 172.980000°'), findsOneWidget);
      expect(find.text('Center Map on Vessel'), findsOneWidget);
    });
  });
}
