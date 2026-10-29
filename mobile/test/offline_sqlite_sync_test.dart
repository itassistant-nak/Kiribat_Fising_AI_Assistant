import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:mobile/data/database_helper.dart';
import 'package:mobile/models/advisory_zone.dart';
import 'package:mobile/models/species.dart';
import 'package:mobile/models/catch_record.dart';

void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  group('Offline SQLite Caching & Sync Tests', () {
    late Database db;
    late DatabaseHelper dbHelper;

    setUp(() async {
      // Create fresh in-memory database for testing
      db = await openDatabase(
        inMemoryDatabasePath,
        version: 1,
        onCreate: (db, version) async {
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
        },
      );

      dbHelper = DatabaseHelper.forTesting(db);
    });

    tearDown(() async {
      await db.close();
    });

    test('caches published advisory zones with species and GeoJSON', () async {
      final sampleZone = AdvisoryZone(
        id: 1,
        title: 'Betio Western Reef [DEMO - SYNTHETIC]',
        concern: 'Ciguatera fish poisoning',
        evidence: 'Clinical CFP admissions at Betio Hospital',
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
      );

      await dbHelper.saveAdvisoryZones([sampleZone]);

      // Query from SQLite
      final retrieved = await dbHelper.getCachedAdvisoryZones();
      expect(retrieved.length, equals(1));
      expect(retrieved.first.id, equals(1));
      expect(retrieved.first.title, contains('Betio Western Reef'));
      expect(retrieved.first.species.length, equals(1));
      expect(retrieved.first.species.first.kiribatiName, equals('Te Okaoka'));
    });

    test('offline species search works with keywords and risk filters', () async {
      final catalog = [
        const SpeciesItem(
          id: 1,
          kiribatiName: 'Te Atun',
          englishName: 'Skipjack Tuna',
          scientificName: 'Katsuwonus pelamis',
          ciguateraRisk: 'low',
          visualFeatures: ['Dark belly stripes'],
          cachedAt: '2026-10-28',
        ),
        const SpeciesItem(
          id: 2,
          kiribatiName: 'Te Bwere',
          englishName: 'Leopard Coral Grouper',
          scientificName: 'Plectropomus leopardus',
          ciguateraRisk: 'high',
          visualFeatures: ['Small blue spots'],
          cachedAt: '2026-10-28',
        ),
        const SpeciesItem(
          id: 5,
          kiribatiName: 'Te Okaoka',
          englishName: 'Orange-spotted Trevally',
          scientificName: 'Carangoides bajad',
          ciguateraRisk: 'high',
          visualFeatures: ['Yellow-orange spots'],
          cachedAt: '2026-10-28',
        ),
      ];

      await dbHelper.saveSpeciesCatalog(catalog);

      // Search by Kiribati name
      final okaoka = await dbHelper.getCachedSpecies(query: 'Okaoka');
      expect(okaoka.length, equals(1));
      expect(okaoka.first.kiribatiName, equals('Te Okaoka'));

      // Search by English name
      final grouper = await dbHelper.getCachedSpecies(query: 'Grouper');
      expect(grouper.length, equals(1));
      expect(grouper.first.kiribatiName, equals('Te Bwere'));

      // Filter by high ciguatera risk
      final highRisk = await dbHelper.getCachedSpecies(riskFilter: 'high');
      expect(highRisk.length, equals(2));
    });

    test('offline catch registration and photo queue persists in SQLite', () async {
      final catch1 = LocalCatch(
        id: 'uuid-1234-catch',
        speciesId: 5,
        speciesNameRaw: 'Te Okaoka',
        weightKg: 3.8,
        quantity: 2,
        latitude: 1.3340,
        longitude: 172.9750,
        gpsAccuracy: 8.5,
        capturedAt: DateTime(2026, 10, 28, 8, 30),
        photoLocalPath: '/local/storage/photos/catch_1.jpg',
        photoUrl: 'https://example.com/photos/te_okaoka.jpg',
        syncStatus: 'pending',
        createdAt: DateTime(2026, 10, 28, 8, 30),
      );

      await dbHelper.insertCatch(catch1);

      // Retrieve pending catches
      final pending = await dbHelper.getLocalCatches(syncStatus: 'pending');
      expect(pending.length, equals(1));
      expect(pending.first.id, equals('uuid-1234-catch'));
      expect(pending.first.speciesNameRaw, equals('Te Okaoka'));
      expect(pending.first.weightKg, equals(3.8));
      expect(pending.first.latitude, equals(1.3340));
      expect(pending.first.photoLocalPath, equals('/local/storage/photos/catch_1.jpg'));
      expect(pending.first.syncStatus, equals('pending'));

      // Update sync status on server acknowledgment
      await dbHelper.updateCatchSyncStatus(
        'uuid-1234-catch',
        'synced',
        serverId: 99,
        serverPhotoUrl: 'https://server.domain/uploads/catch_1.jpg',
      );

      final synced = await dbHelper.getLocalCatches(syncStatus: 'synced');
      expect(synced.length, equals(1));
      expect(synced.first.serverCatchId, equals(99));
      expect(synced.first.syncStatus, equals('synced'));

      final stillPending = await dbHelper.getLocalCatches(syncStatus: 'pending');
      expect(stillPending.isEmpty, isTrue);
    });

    test('identifies stale advisory data when cache exceeds expiry', () async {
      // 1. Fresh cache: valid for 7 days
      await dbHelper.updateCacheMetadata(
        'advisory_zones',
        validDuration: const Duration(days: 7),
      );
      expect(await dbHelper.isCacheStale('advisory_zones'), isFalse);

      // 2. Expired / Stale cache: simulate past date
      await db.update(
        'cache_metadata',
        {
          'expires_at': DateTime.now().subtract(const Duration(days: 1)).toIso8601String(),
        },
        where: 'key = ?',
        whereArgs: ['advisory_zones'],
      );

      expect(await dbHelper.isCacheStale('advisory_zones'), isTrue);
    });
  });
}
