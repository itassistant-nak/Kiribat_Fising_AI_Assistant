import 'dart:async';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'package:mobile/data/database_helper.dart';
import 'package:mobile/models/advisory_zone.dart';
import 'package:mobile/models/catch_record.dart';
import 'package:mobile/models/species.dart';
import 'package:mobile/models/trip_record.dart';
import 'package:mobile/services/sync/api_client.dart';
import 'package:mobile/services/sync/sync_service.dart';

class MockAutoSyncApiClient extends ApiClient {
  bool loginCalled = false;
  String? lastLoginEmail;
  String? lastTokenPassedToSpecies;
  String? lastTokenPassedToZones;
  String? lastTokenPassedToCatches;
  String? lastTokenPassedToTrips;
  int uploadCatchCount = 0;
  String? lastClientUuidUploaded;

  @override
  Future<Map<String, dynamic>> login(String email, String password) async {
    loginCalled = true;
    lastLoginEmail = email;
    return {
      'token': 'mock-sanctum-token-xyz-123',
      'user': {
        'id': 2,
        'name': 'Teititeki Fisherman',
        'email': email,
        'role': 'fisher',
      },
    };
  }

  @override
  Future<List<AdvisoryZone>> fetchAdvisoryZones({String? token}) async {
    lastTokenPassedToZones = token;
    return [
      AdvisoryZone(
        id: 1,
        title: 'Betio Warning Reef',
        description: 'High CFP risk',
        concern: 'Ciguatera',
        evidence: 'Clinical CFP cases',
        classification: 'authority-confirmed',
        status: 'published',
        polygonGeoJson: const {
          'type': 'Polygon',
          'coordinates': [
            [
              [172.91, 1.35],
              [172.93, 1.35],
              [172.93, 1.37],
              [172.91, 1.37],
              [172.91, 1.35],
            ]
          ]
        },
        reviewDate: '2026-11-20',
        cachedAt: DateTime.now().toIso8601String(),
      ),
    ];
  }

  @override
  Future<List<SpeciesItem>> fetchSpecies({String? token}) async {
    lastTokenPassedToSpecies = token;
    return [
      const SpeciesItem(
        id: 1,
        kiribatiName: 'Te Atun',
        englishName: 'Skipjack Tuna',
        scientificName: 'Katsuwonus pelamis',
        ciguateraRisk: 'low',
        verificationStatus: 'verified',
        recordType: 'species',
        cachedAt: '2026-10-29',
      ),
      const SpeciesItem(
        id: 15,
        kiribatiName: 'Te Nimatang',
        englishName: 'Moon Turban Snail',
        scientificName: 'Turbo argyrostomus',
        ciguateraRisk: 'unknown',
        verificationStatus: 'needs_review',
        recordType: 'non_fish_organism',
        cachedAt: '2026-10-29',
      ),
    ];
  }

  @override
  Future<Map<String, dynamic>> uploadCatch(LocalCatch localCatch, {String? token}) async {
    lastTokenPassedToCatches = token;
    uploadCatchCount++;
    lastClientUuidUploaded = localCatch.id;
    return {
      'success': true,
      'catch': {
        'id': 101,
        'client_uuid': localCatch.id,
        'species_id': localCatch.speciesId,
        'verification_status': 'pending',
      },
    };
  }

  @override
  Future<Map<String, dynamic>> createTrip(LocalTrip trip, {String? token}) async {
    lastTokenPassedToTrips = token;
    return {
      'trip': {
        'id': 42,
        'status': 'in_progress',
      }
    };
  }

  @override
  Future<Map<String, dynamic>> uploadRoutePoints(
    int serverTripId,
    List<TripPoint> points, {
    String? token,
  }) async {
    lastTokenPassedToTrips = token;
    return {
      'status': 'ok',
      'accepted_count': points.length,
    };
  }

  @override
  Future<Map<String, dynamic>> endTrip(
    int serverTripId, {
    DateTime? endedAt,
    String? token,
  }) async {
    lastTokenPassedToTrips = token;
    return {
      'trip': {
        'id': serverTripId,
        'status': 'completed',
      }
    };
  }
}

void main() {
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  late Database db;
  late DatabaseHelper dbHelper;
  late MockAutoSyncApiClient mockApi;
  late SyncService syncService;

  setUp(() async {
    db = await databaseFactoryFfi.openDatabase(inMemoryDatabasePath);
    dbHelper = DatabaseHelper.forTesting(db);

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
      CREATE TABLE species_catalog (
        id INTEGER PRIMARY KEY,
        kiribati_name TEXT NOT NULL,
        english_name TEXT NOT NULL,
        scientific_name TEXT NOT NULL,
        ciguatera_risk TEXT NOT NULL,
        photo_url TEXT,
        visual_features TEXT,
        ai_trained INTEGER NOT NULL DEFAULT 0,
        verification_status TEXT NOT NULL DEFAULT 'verified',
        record_type TEXT NOT NULL DEFAULT 'species',
        cached_at TEXT NOT NULL
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
        notes TEXT,
        FOREIGN KEY (zone_id) REFERENCES advisory_zones (id) ON DELETE CASCADE
      )
    ''');

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

    await db.execute('''
      CREATE TABLE cache_metadata (
        key TEXT PRIMARY KEY,
        version TEXT,
        last_synced_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
      )
    ''');

    mockApi = MockAutoSyncApiClient();
    syncService = SyncService(db: dbHelper, api: mockApi);
  });

  tearDown(() async {
    syncService.stopAutoSync();
    syncService.dispose();
    await db.close();
  });

  group('Offline Auto-Sync & Authentication Engine', () {
    test('1. SyncService.login authenticates and retains fisher credentials and token', () async {
      expect(syncService.isAuthenticated, isFalse);
      expect(syncService.authToken, isNull);

      final ok = await syncService.login(
        email: 'teititeki@fisher.ki',
        password: 'FisherPass2026!',
      );

      expect(ok, isTrue);
      expect(syncService.isAuthenticated, isTrue);
      expect(syncService.authToken, equals('mock-sanctum-token-xyz-123'));
      expect(syncService.authenticatedUserEmail, equals('teititeki@fisher.ki'));
      expect(syncService.authenticatedUserName, equals('Teititeki Fisherman'));
      expect(mockApi.loginCalled, isTrue);
    });

    test('2. performFullSync auto-authenticates if token omitted and injects into endpoints', () async {
      // Add a pending catch
      await dbHelper.insertCatch(LocalCatch(
        id: 'client-uuid-sync-001',
        speciesNameRaw: 'Te Atun',
        weightKg: 4.5,
        quantity: 1,
        capturedAt: DateTime(2026, 10, 29, 9, 0),
        syncStatus: 'pending',
        createdAt: DateTime(2026, 10, 29, 9, 0),
      ));

      final result = await syncService.performFullSync();

      expect(result.success, isTrue);
      expect(mockApi.loginCalled, isTrue);
      expect(mockApi.lastTokenPassedToZones, equals('mock-sanctum-token-xyz-123'));
      expect(mockApi.lastTokenPassedToSpecies, equals('mock-sanctum-token-xyz-123'));
      expect(mockApi.lastTokenPassedToCatches, equals('mock-sanctum-token-xyz-123'));
      expect(mockApi.uploadCatchCount, equals(1));
      expect(mockApi.lastClientUuidUploaded, equals('client-uuid-sync-001'));

      // Verify catch is marked synced in SQLite
      final syncedCatches = await dbHelper.getLocalCatches(syncStatus: 'synced');
      expect(syncedCatches.length, equals(1));
      expect(syncedCatches.first.serverCatchId, equals(101));
    });

    test('3. Species sync caches verification_status and record_type in SQLite', () async {
      await syncService.performFullSync();

      final verified = await dbHelper.getCachedSpecies(query: 'Te Atun');
      expect(verified.isNotEmpty, isTrue);
      expect(verified.first.verificationStatus, equals('verified'));
      expect(verified.first.recordType, equals('species'));

      final nonFish = await dbHelper.getCachedSpecies(query: 'Te Nimatang');
      expect(nonFish.isNotEmpty, isTrue);
      expect(nonFish.first.verificationStatus, equals('needs_review'));
      expect(nonFish.first.recordType, equals('non_fish_organism'));
    });

    test('4. Airplane mode blocks auto-sync and network calls safely', () async {
      syncService.setAirplaneMode(true);
      expect(syncService.isAirplaneMode, isTrue);

      final loginSuccess = await syncService.login();
      expect(loginSuccess, isFalse);
      expect(syncService.isAuthenticated, isFalse);

      final result = await syncService.performFullSync();
      expect(result.success, isFalse);
      expect(result.message, contains('Airplane Mode'));
    });

    test('5. Retrying failed catches passes client_uuid to prevent duplicate server records', () async {
      // Catch in 'failed' status
      await dbHelper.insertCatch(LocalCatch(
        id: 'client-uuid-failed-retry',
        speciesNameRaw: 'Te Okaoka',
        weightKg: 2.1,
        quantity: 1,
        capturedAt: DateTime(2026, 10, 29, 9, 30),
        syncStatus: 'failed',
        createdAt: DateTime(2026, 10, 29, 9, 30),
      ));

      final result = await syncService.performFullSync();

      expect(result.success, isTrue);
      expect(result.catchesUploaded, equals(1));
      expect(mockApi.lastClientUuidUploaded, equals('client-uuid-failed-retry'));

      final remainingFailed = await dbHelper.getLocalCatches(syncStatus: 'failed');
      expect(remainingFailed.isEmpty, isTrue);
    });
  });
}
