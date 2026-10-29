import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:mobile/data/database_helper.dart';
import 'package:mobile/models/advisory_zone.dart';
import 'package:mobile/models/species.dart';
import 'package:mobile/models/trip_record.dart';
import 'package:mobile/services/sync/api_client.dart';
import 'package:mobile/services/sync/sync_service.dart';
import 'package:mobile/services/trip/trip_recorder.dart';

class MockTripApiClient extends ApiClient {
  int createTripCalls = 0;
  int uploadPointsCalls = 0;
  int endTripCalls = 0;
  int uploadedPointCount = 0;

  @override
  Future<bool> checkHealth() async => true;

  @override
  Future<List<AdvisoryZone>> fetchAdvisoryZones({String? token}) async => [];

  @override
  Future<List<SpeciesItem>> fetchSpecies({String? token}) async => [];

  @override
  Future<Map<String, dynamic>> createTrip(LocalTrip trip, {String? token}) async {
    createTripCalls++;
    return {
      'message': 'Fishing trip started.',
      'data': {'id': 77, 'status': 'in_progress'},
    };
  }

  @override
  Future<Map<String, dynamic>> uploadRoutePoints(
    int serverTripId,
    List<TripPoint> points, {
    String? token,
  }) async {
    uploadPointsCalls++;
    uploadedPointCount += points.length;
    return {
      'message': 'Route points synced.',
      'trip_id': serverTripId,
      'synced_points': points.length,
    };
  }

  @override
  Future<Map<String, dynamic>> endTrip(
    int serverTripId, {
    DateTime? endedAt,
    String? token,
  }) async {
    endTripCalls++;
    return {
      'message': 'Fishing trip updated.',
      'data': {'id': serverTripId, 'status': 'completed'},
    };
  }
}

void main() {
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  late Database db;
  late DatabaseHelper dbHelper;
  late MockTripApiClient mockApi;
  late SyncService syncService;

  setUp(() async {
    db = await databaseFactoryFfi.openDatabase(inMemoryDatabasePath);
    dbHelper = DatabaseHelper.forTesting(db);

    await db.execute('''
      CREATE TABLE local_trips (
        id TEXT PRIMARY KEY,
        vessel_name TEXT,
        started_at TEXT NOT NULL,
        ended_at TEXT,
        status TEXT NOT NULL DEFAULT 'in_progress',
        sync_status TEXT NOT NULL DEFAULT 'pending',
        server_trip_id INTEGER,
        notes TEXT,
        created_at TEXT NOT NULL
      )
    ''');

    await db.execute('''
      CREATE TABLE local_trip_points (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        trip_id TEXT NOT NULL,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        gps_accuracy REAL,
        sequence INTEGER NOT NULL,
        recorded_at TEXT NOT NULL,
        sync_status TEXT NOT NULL DEFAULT 'pending',
        UNIQUE (trip_id, sequence)
      )
    ''');

    // Tables touched by performFullSync's other steps
    await db.execute('''
      CREATE TABLE local_catches (
        id TEXT PRIMARY KEY,
        species_id INTEGER,
        species_name_raw TEXT NOT NULL,
        weight_kg REAL NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 1,
        latitude REAL,
        longitude REAL,
        gps_accuracy REAL,
        captured_at TEXT NOT NULL,
        photo_local_path TEXT,
        photo_url TEXT,
        sync_status TEXT NOT NULL DEFAULT 'pending',
        server_catch_id INTEGER,
        ai_species_suggestion TEXT,
        ai_confidence REAL,
        ai_status TEXT NOT NULL DEFAULT 'waiting_for_online_ai',
        ciguatera_risk TEXT,
        ciguatera_warning TEXT,
        photo_quality TEXT,
        photo_blur_score REAL,
        created_at TEXT NOT NULL
      )
    ''');
    await db.execute('''
      CREATE TABLE advisory_zones (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL,
        description TEXT,
        concern TEXT NOT NULL,
        evidence TEXT,
        classification TEXT NOT NULL,
        status TEXT NOT NULL,
        polygon_geojson TEXT NOT NULL,
        source TEXT,
        review_date TEXT,
        updated_at TEXT,
        cached_at TEXT NOT NULL
      )
    ''');
    await db.execute('''
      CREATE TABLE advisory_zone_species (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        zone_id INTEGER NOT NULL,
        species_id INTEGER NOT NULL,
        kiribati_name TEXT,
        english_name TEXT,
        scientific_name TEXT,
        risk_level TEXT NOT NULL,
        notes TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE species_catalog (
        id INTEGER PRIMARY KEY,
        kiribati_name TEXT NOT NULL,
        english_name TEXT NOT NULL,
        scientific_name TEXT NOT NULL,
        ciguatera_risk TEXT NOT NULL,
        photo_url TEXT,
        visual_features TEXT,
        ai_trained INTEGER NOT NULL DEFAULT 0,
        cached_at TEXT NOT NULL
      )
    ''');
    await db.execute('''
      CREATE TABLE cache_metadata (
        key TEXT PRIMARY KEY,
        version TEXT,
        last_synced_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
      )
    ''');

    mockApi = MockTripApiClient();
    syncService = SyncService(db: dbHelper, api: mockApi);
  });

  tearDown(() async {
    await db.close();
  });

  TripRecorder newRecorder() => TripRecorder(
        db: dbHelper,
        minInterval: Duration.zero,
        minDistanceMeters: 10.0,
      );

  group('Offline Trip Route Recording & Sync Tests (P1-05)', () {
    test('1. Breadcrumb route persists offline in SQLite in sequence order', () async {
      final recorder = newRecorder();
      final trip = await recorder.startTrip(vesselName: 'Te Raoi II');

      var t = DateTime(2026, 10, 28, 6, 0, 0);
      for (var i = 0; i < 3; i++) {
        final outcome = await recorder.recordPoint(
          latitude: 1.3300 + i * 0.002,
          longitude: 172.9800 + i * 0.002,
          accuracy: 12.0,
          recordedAt: t,
        );
        expect(outcome, BreadcrumbOutcome.recorded);
        t = t.add(const Duration(minutes: 5));
      }

      final points = await dbHelper.getTripPoints(trip.id);
      expect(points.length, 3);
      expect(points.map((p) => p.sequence).toList(), [0, 1, 2]);
      expect(points.last.latitude, closeTo(1.334, 0.0001));

      final stored = await dbHelper.getTrip(trip.id);
      expect(stored?.syncStatus, 'pending');
      expect(stored?.status, 'in_progress');
    });

    test('2. Recorder filters stationary, rapid, and low-accuracy fixes', () async {
      final recorder = TripRecorder(db: dbHelper); // real 5s / 10m filters
      await recorder.startTrip();
      final base = DateTime(2026, 10, 28, 7, 0, 0);

      expect(
        await recorder.recordPoint(latitude: 1.33, longitude: 172.98, recordedAt: base),
        BreadcrumbOutcome.recorded,
      );
      // Same spot 1 minute later: too close (<10 m)
      expect(
        await recorder.recordPoint(
            latitude: 1.33, longitude: 172.98, recordedAt: base.add(const Duration(minutes: 1))),
        BreadcrumbOutcome.tooClose,
      );
      // Far away but only 1 second later: too soon (<5 s)
      expect(
        await recorder.recordPoint(
            latitude: 1.34, longitude: 172.99, recordedAt: base.add(const Duration(seconds: 1))),
        BreadcrumbOutcome.tooSoon,
      );
      // Far away, late enough, but GPS accuracy 80 m: rejected
      expect(
        await recorder.recordPoint(
          latitude: 1.34,
          longitude: 172.99,
          accuracy: 80.0,
          recordedAt: base.add(const Duration(minutes: 2)),
        ),
        BreadcrumbOutcome.lowAccuracy,
      );

      final points = await dbHelper.getTripPoints(recorder.activeTrip!.id);
      expect(points.length, 1);
    });

    test('3. Active trip resumes after an app restart', () async {
      final recorder = newRecorder();
      final trip = await recorder.startTrip(vesselName: 'Te Raoi II');
      await recorder.recordPoint(latitude: 1.33, longitude: 172.98);
      await recorder.recordPoint(latitude: 1.34, longitude: 172.99);

      // Simulate restart: brand-new recorder on the same database
      final restarted = newRecorder();
      expect(restarted.isRecording, false);
      final resumed = await restarted.resumeActiveTrip();

      expect(resumed?.id, trip.id);
      expect(restarted.isRecording, true);
      expect(restarted.pointCount, 2);

      // New breadcrumbs continue the same sequence (no duplicates, no gaps)
      await restarted.recordPoint(latitude: 1.35, longitude: 173.00);
      final points = await dbHelper.getTripPoints(trip.id);
      expect(points.map((p) => p.sequence).toList(), [0, 1, 2]);
    });

    test('4. Sync uploads trip once, pushes points, closes completed trip', () async {
      final recorder = newRecorder();
      final trip = await recorder.startTrip(vesselName: 'Te Raoi II');
      await recorder.recordPoint(latitude: 1.33, longitude: 172.98);
      await recorder.recordPoint(latitude: 1.34, longitude: 172.99);
      await recorder.endTrip();

      final stored = await dbHelper.getTrip(trip.id);
      final uploaded = await syncService.syncSingleTrip(stored!);

      expect(uploaded, 2);
      expect(mockApi.createTripCalls, 1);
      expect(mockApi.uploadPointsCalls, 1);
      expect(mockApi.uploadedPointCount, 2);
      expect(mockApi.endTripCalls, 1); // trip was completed

      final after = await dbHelper.getTrip(trip.id);
      expect(after?.syncStatus, 'synced');
      expect(after?.serverTripId, 77);
      final points = await dbHelper.getTripPoints(trip.id);
      expect(points.every((p) => p.syncStatus == 'synced'), true);
    });

    test('5. Incremental sync reuses the server trip for new breadcrumbs', () async {
      final recorder = newRecorder();
      final trip = await recorder.startTrip();
      await recorder.recordPoint(latitude: 1.33, longitude: 172.98);

      final stored = await dbHelper.getTrip(trip.id);
      await syncService.syncSingleTrip(stored!);
      expect(mockApi.createTripCalls, 1);

      // Fisher keeps sailing: new breadcrumb marks the trip dirty again
      await recorder.recordPoint(latitude: 1.335, longitude: 172.985);
      final dirty = await dbHelper.getTrip(trip.id);
      expect(dirty?.syncStatus, 'pending');

      await syncService.syncSingleTrip(dirty!);
      expect(mockApi.createTripCalls, 1); // no duplicate server trip
      expect(mockApi.uploadedPointCount, 3); // full polyline re-pushed (idempotent)
      final after = await dbHelper.getTrip(trip.id);
      expect(after?.syncStatus, 'synced');

      // Ending the trip after a sync preserves the server id, so closing
      // the trip reuses it instead of creating a duplicate server trip.
      await recorder.endTrip();
      final ended = await dbHelper.getTrip(trip.id);
      expect(ended?.status, 'completed');
      expect(ended?.serverTripId, 77);
      expect(ended?.syncStatus, 'pending');
      await syncService.syncSingleTrip(ended!);
      expect(mockApi.createTripCalls, 1);
      expect(mockApi.endTripCalls, 1);
    });

    test('6. Full sync reports trip counts alongside catches', () async {
      final recorder = newRecorder();
      await recorder.startTrip();
      await recorder.recordPoint(latitude: 1.33, longitude: 172.98);

      final result = await syncService.performFullSync();

      expect(result.success, true);
      expect(result.tripsSynced, 1);
      expect(result.routePointsUploaded, 1);
      expect(result.message, contains('1 trips (1 points)'));
    });
  });
}
