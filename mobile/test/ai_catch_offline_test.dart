import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:mobile/data/database_helper.dart';
import 'package:mobile/models/catch_record.dart';
import 'package:mobile/models/species.dart';
import 'package:mobile/services/sync/api_client.dart';
import 'package:mobile/services/sync/sync_service.dart';

class MockAiApiClient extends ApiClient {
  bool uploadCalled = false;
  bool identifyCalled = false;
  bool shouldFail = false;
  bool failUpload = false;
  int identifyCount = 0;
  int uploadCount = 0;

  @override
  Future<bool> checkHealth() async => !shouldFail;

  @override
  Future<Map<String, dynamic>> identifyFish({
    String? imageUrl,
    String? hint,
    String? token,
  }) async {
    identifyCalled = true;
    identifyCount++;
    if (shouldFail) throw Exception('Simulated network error');
    // Real Laravel shape: suggestion under `identification`, SAM
    // segmentation nested alongside it (evidence only, not a name).
    return {
      'status': 'success',
      'identification': {
        'kiribati_name': 'Te Bwere',
        'english_name': 'Paddletail Snapper',
        'scientific_name': 'Lutjanus gibbus',
        'confidence': 0.94,
        'confidence_percentage': '94.0%',
        'ciguatera_risk': 'high',
        'ciguatera_warning': 'High CFP bioaccumulation risk reported in reef drop-offs.',
        'verification_status': 'verified',
        'visual_features': [
          'Distinctive paddle-shaped caudal fin',
          'Deep reddish-pink coloration',
        ],
        'meta_model': {'model': 'muse-wonder-1.0', 'status': 'completed'},
        'segmentation': {
          'status': 'unconfigured',
          'fish_detected': null,
          'object_count': 0,
        },
      },
      'disclaimer': 'AI cannot determine fish toxicity from photographs.',
    };
  }

  @override
  Future<Map<String, dynamic>> uploadCatch(LocalCatch catchRecord, {String? token}) async {
    uploadCalled = true;
    uploadCount++;
    if (shouldFail || failUpload) throw Exception('Network timeout');
    return {
      'status': 'success',
      'catch': {
        'id': 101,
        'photo_url': 'http://127.0.0.1:8000/uploads/catches/catch_101.jpg',
      },
    };
  }

  @override
  Future<List<SpeciesItem>> fetchSpecies({String? token}) async {
    return [
      const SpeciesItem(
        id: 1,
        kiribatiName: 'Te Bwere',
        englishName: 'Paddletail Snapper',
        scientificName: 'Lutjanus gibbus',
        ciguateraRisk: 'high',
        photoUrl: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5',
        visualFeatures: ['Paddle-shaped caudal fin', 'Reddish-pink coloration'],
        aiTrained: true,
        cachedAt: '2026-10-28',
      ),
    ];
  }
}

void main() {
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  late Database db;
  late DatabaseHelper dbHelper;
  late MockAiApiClient mockApi;
  late SyncService syncService;

  setUp(() async {
    db = await databaseFactoryFfi.openDatabase(inMemoryDatabasePath);
    dbHelper = DatabaseHelper.forTesting(db);

    // Run schema creation
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
        approved_at TEXT,
        published_at TEXT,
        review_date TEXT,
        created_at TEXT NOT NULL,
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

    mockApi = MockAiApiClient();
    syncService = SyncService(db: dbHelper, api: mockApi);
  });

  tearDown(() async {
    await db.close();
  });

  group('Offline Camera Catch & AI Identification Tests', () {
    test('1. Catch saved offline with photo local path defaults to waiting_for_online_ai', () async {
      final offlineCatch = LocalCatch(
        id: 'catch-001',
        speciesNameRaw: 'Te Bwere',
        weightKg: 3.5,
        quantity: 2,
        capturedAt: DateTime.now(),
        photoLocalPath: '/data/user/0/mobile/photos/catch_001.jpg',
        photoUrl: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5',
        syncStatus: 'pending',
        aiStatus: 'waiting_for_online_ai',
        createdAt: DateTime.now(),
      );

      await dbHelper.insertCatch(offlineCatch);
      final stored = await dbHelper.getLocalCatches();

      expect(stored.length, 1);
      expect(stored.first.id, 'catch-001');
      expect(stored.first.aiStatus, 'waiting_for_online_ai');
      expect(stored.first.syncStatus, 'pending');
      expect(stored.first.photoLocalPath, '/data/user/0/mobile/photos/catch_001.jpg');
    });

    test('2. Offline species cache fallback matches Kiribati taxonomy and traits', () async {
      // Seed species catalog
      await dbHelper.saveSpeciesCatalog([
        const SpeciesItem(
          id: 1,
          kiribatiName: 'Te Bwere',
          englishName: 'Paddletail Snapper',
          scientificName: 'Lutjanus gibbus',
          ciguateraRisk: 'high',
          photoUrl: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5',
          visualFeatures: ['Paddle-shaped caudal fin', 'Reddish-pink coloration'],
          aiTrained: true,
          cachedAt: '2026-10-28',
        ),
      ]);

      // Enable Airplane mode
      syncService.setAirplaneMode(true);

      final result = await syncService.identifyFishOnlineOrOffline(
        'https://images.unsplash.com/photo-1544551763-46a013bb70d5',
        hint: 'Te Bwere',
      );

      expect(result['mode'], 'offline_cache');
      expect(result['primary']['kiribati_name'], 'Te Bwere');
      expect(result['primary']['english_name'], 'Paddletail Snapper');
      expect(result['primary']['ciguatera_risk'], 'high');
      expect(result['disclaimer'], contains('Offline Cache Match'));
    });

    test('3. Sync engine runs Meta AI identification on pending catches and updates SQLite', () async {
      // Seed offline catch waiting for AI
      final offlineCatch = LocalCatch(
        id: 'catch-002',
        speciesNameRaw: 'Te Bwere',
        weightKg: 4.2,
        quantity: 1,
        capturedAt: DateTime.now(),
        photoLocalPath: '/data/user/0/mobile/photos/catch_002.jpg',
        photoUrl: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5',
        syncStatus: 'pending',
        aiStatus: 'waiting_for_online_ai',
        createdAt: DateTime.now(),
      );

      await dbHelper.insertCatch(offlineCatch);

      // Trigger sync
      final syncRes = await syncService.performFullSync();

      expect(syncRes.success, true);
      expect(mockApi.identifyCalled, true);
      expect(mockApi.uploadCalled, true);

      // Verify SQLite record was updated with Meta AI identification
      final updatedCatches = await dbHelper.getLocalCatches();
      expect(updatedCatches.length, 1);
      final updated = updatedCatches.first;

      expect(updated.syncStatus, 'synced');
      expect(updated.serverCatchId, 101);
      expect(updated.aiStatus, 'online_identified');
      expect(updated.aiSpeciesSuggestion, 'Te Bwere (Paddletail Snapper)');
      expect(updated.aiConfidence, 0.94);
      expect(updated.ciguateraRisk, 'high');
    });

    test('4. Online direct identification returns Meta AI Vision results immediately', () async {
      syncService.setAirplaneMode(false);

      final result = await syncService.identifyFishOnlineOrOffline(
        'https://images.unsplash.com/photo-1544551763-46a013bb70d5',
        hint: 'Te Bwere',
      );

      expect(result['mode'], 'online_ai');
      expect(result['meta_model'], 'muse-wonder-1.0');
      expect(result['primary']['kiribati_name'], 'Te Bwere');
      expect(result['primary']['confidence'], 0.94);
      expect(result['primary']['visual_features'], contains('Distinctive paddle-shaped caudal fin'));
      expect(result['segmentation']['status'], 'unconfigured');
    });

    test('5. Failed upload is retried without re-billing AI identification', () async {
      final retryCatch = LocalCatch(
        id: 'catch-retry-001',
        speciesNameRaw: 'Te Bwere',
        weightKg: 2.0,
        quantity: 1,
        capturedAt: DateTime.now(),
        photoUrl: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5',
        syncStatus: 'pending',
        aiStatus: 'waiting_for_online_ai',
        createdAt: DateTime.now(),
      );
      await dbHelper.insertCatch(retryCatch);

      mockApi.failUpload = true;
      await syncService.performFullSync();
      var stored = (await dbHelper.getLocalCatches()).firstWhere((c) => c.id == 'catch-retry-001');
      expect(stored.syncStatus, 'failed');
      expect(stored.aiStatus, 'online_identified');
      expect(mockApi.identifyCount, 1);

      mockApi.failUpload = false;
      final second = await syncService.performFullSync();
      expect(second.success, true);
      stored = (await dbHelper.getLocalCatches()).firstWhere((c) => c.id == 'catch-retry-001');
      expect(stored.syncStatus, 'synced');
      expect(mockApi.identifyCount, 1, reason: 'AI must not be billed twice for one catch');
      expect(mockApi.uploadCount, 2);
    });
  });
}
