import 'dart:io' show Platform;
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:path/path.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:sqflite_common_ffi_web/sqflite_ffi_web.dart';
import '../models/advisory_zone.dart';
import '../models/species.dart';
import '../models/catch_record.dart';
import '../models/trip_record.dart';

class DatabaseHelper {
  static final DatabaseHelper instance = DatabaseHelper._init();
  static Database? _database;

  DatabaseHelper._init();

  // Constructor for custom or in-memory database (useful for testing)
  DatabaseHelper.forTesting(Database db) {
    _database = db;
  }

  Future<Database> get database async {
    if (_database != null) return _database!;
    initializeFfi();
    _database = await _initDB('kiribati_fisheries.db');
    return _database!;
  }

  static void initializeFfi() {
    if (kIsWeb) {
      databaseFactory = databaseFactoryFfiWebNoWebWorker;
    } else if (Platform.isLinux || Platform.isWindows || Platform.isMacOS) {
      sqfliteFfiInit();
      databaseFactory = databaseFactoryFfi;
    }
  }

  Future<Database> _initDB(String filePath) async {
    final String path = kIsWeb ? filePath : join(await getDatabasesPath(), filePath);
    return await openDatabase(
      path,
      version: 1,
      onCreate: _createDB,
      onOpen: _migrateDB,
    );
  }

  Future<void> _migrateDB(Database db) async {
    // P1-05: trip tables for installs created before v1.0.0+2
    await db.execute('''
      CREATE TABLE IF NOT EXISTS local_trips (
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
      CREATE TABLE IF NOT EXISTS local_trip_points (
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
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_trip_points_trip ON local_trip_points (trip_id, sync_status)');

    final columns = [
      'ai_species_suggestion TEXT',
      'ai_confidence REAL',
      'ai_status TEXT NOT NULL DEFAULT \'waiting_for_online_ai\'',
      'ciguatera_risk TEXT',
      'ciguatera_warning TEXT',
      'photo_quality TEXT',
      'photo_blur_score REAL',
    ];
    for (final col in columns) {
      try {
        await db.execute('ALTER TABLE local_catches ADD COLUMN $col');
      } catch (_) {
        // Column already exists
      }
    }

    final speciesCols = [
      'verification_status TEXT NOT NULL DEFAULT \'verified\'',
      'record_type TEXT NOT NULL DEFAULT \'species\'',
    ];
    for (final col in speciesCols) {
      try {
        await db.execute('ALTER TABLE species_catalog ADD COLUMN $col');
      } catch (_) {}
    }
  }

  Future<void> _createDB(Database db, int version) async {
    // 1. Advisory Zones
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

    // 2. Advisory Zone Species Pivot
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

    // 3. Species Reference Catalog
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

    // 4. Local Catch Submissions Queue
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

    // 5. Local Fishing Trips (P1-05)
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

    // 6. Local Trip Breadcrumb Points (P1-05)
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
    await db.execute(
        'CREATE INDEX idx_trip_points_trip ON local_trip_points (trip_id, sync_status)');

    // 7. Cache Metadata & Expiry tracking
    await db.execute('''
      CREATE TABLE cache_metadata (
        key TEXT PRIMARY KEY,
        version TEXT,
        last_synced_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
      )
    ''');
  }

  // --- ADVISORY ZONES METHODS ---

  Future<void> saveAdvisoryZones(List<AdvisoryZone> zones) async {
    final db = await database;
    await db.transaction((txn) async {
      // Clear existing cached zones
      await txn.delete('advisory_zone_species');
      await txn.delete('advisory_zones');

      for (final zone in zones) {
        await txn.insert(
          'advisory_zones',
          zone.toMap(),
          conflictAlgorithm: ConflictAlgorithm.replace,
        );

        for (final sp in zone.species) {
          await txn.insert(
            'advisory_zone_species',
            sp.toMap(zone.id),
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      }
    });
  }

  Future<List<AdvisoryZone>> getCachedAdvisoryZones() async {
    final db = await database;
    final zoneMaps = await db.query('advisory_zones');

    final zones = <AdvisoryZone>[];
    for (final map in zoneMaps) {
      final zoneId = map['id'] as int;
      final speciesMaps = await db.query(
        'advisory_zone_species',
        where: 'zone_id = ?',
        whereArgs: [zoneId],
      );

      final species = speciesMaps.map((sm) => AdvisorySpecies.fromMap(sm)).toList();
      zones.add(AdvisoryZone.fromMap(map, species: species));
    }
    return zones;
  }

  // --- SPECIES CATALOG METHODS ---

  Future<void> saveSpeciesCatalog(List<SpeciesItem> species) async {
    final db = await database;
    final batch = db.batch();
    for (final sp in species) {
      batch.insert(
        'species_catalog',
        sp.toMap(),
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  Future<List<SpeciesItem>> getCachedSpecies({String? query, String? riskFilter}) async {
    final db = await database;
    String whereClause = '';
    List<dynamic> whereArgs = [];

    if (query != null && query.trim().isNotEmpty) {
      final q = '%${query.trim()}%';
      whereClause = '(kiribati_name LIKE ? OR english_name LIKE ? OR scientific_name LIKE ?)';
      whereArgs = [q, q, q];
    }

    if (riskFilter != null && riskFilter != 'all') {
      if (whereClause.isNotEmpty) {
        whereClause += ' AND ';
      }
      whereClause += 'ciguatera_risk = ?';
      whereArgs.add(riskFilter);
    }

    final maps = await db.query(
      'species_catalog',
      where: whereClause.isEmpty ? null : whereClause,
      whereArgs: whereArgs.isEmpty ? null : whereArgs,
      orderBy: 'kiribati_name ASC',
    );

    return maps.map((m) => SpeciesItem.fromMap(m)).toList();
  }

  // --- CATCH RECORDS METHODS ---

  Future<void> insertCatch(LocalCatch catchRecord) async {
    final db = await database;
    await db.insert(
      'local_catches',
      catchRecord.toMap(),
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<List<LocalCatch>> getLocalCatches({String? syncStatus}) async {
    final db = await database;
    final maps = await db.query(
      'local_catches',
      where: syncStatus != null ? 'sync_status = ?' : null,
      whereArgs: syncStatus != null ? [syncStatus] : null,
      orderBy: 'captured_at DESC',
    );
    return maps.map((m) => LocalCatch.fromMap(m)).toList();
  }

  Future<void> updateCatchSyncStatus(String catchId, String status, {int? serverId, String? serverPhotoUrl}) async {
    final db = await database;
    final updateData = <String, dynamic>{
      'sync_status': status,
    };
    if (serverId != null) {
      updateData['server_catch_id'] = serverId;
    }
    if (serverPhotoUrl != null) {
      updateData['photo_url'] = serverPhotoUrl;
    }
    await db.update(
      'local_catches',
      updateData,
      where: 'id = ?',
      whereArgs: [catchId],
    );
  }

  Future<void> updateCatchAiResult(
    String catchId, {
    String? aiSuggestion,
    double? aiConfidence,
    String? aiStatus,
    String? ciguateraRisk,
    String? ciguateraWarning,
    int? serverCatchId,
    String? syncStatus,
    String? photoUrl,
  }) async {
    final db = await database;
    final updateData = <String, dynamic>{};
    if (aiSuggestion != null) updateData['ai_species_suggestion'] = aiSuggestion;
    if (aiConfidence != null) updateData['ai_confidence'] = aiConfidence;
    if (aiStatus != null) updateData['ai_status'] = aiStatus;
    if (ciguateraRisk != null) updateData['ciguatera_risk'] = ciguateraRisk;
    if (ciguateraWarning != null) updateData['ciguatera_warning'] = ciguateraWarning;
    if (serverCatchId != null) updateData['server_catch_id'] = serverCatchId;
    if (syncStatus != null) updateData['sync_status'] = syncStatus;
    if (photoUrl != null) updateData['photo_url'] = photoUrl;

    if (updateData.isNotEmpty) {
      await db.update(
        'local_catches',
        updateData,
        where: 'id = ?',
        whereArgs: [catchId],
      );
    }
  }

  Future<SpeciesItem?> findCachedSpeciesByNameOrTrait(String query) async {
    final all = await getCachedSpecies();
    final q = query.toLowerCase().trim();
    if (q.isEmpty && all.isNotEmpty) return all.first;

    for (final sp in all) {
      if (sp.kiribatiName.toLowerCase().contains(q) ||
          sp.englishName.toLowerCase().contains(q) ||
          sp.scientificName.toLowerCase().contains(q)) {
        return sp;
      }
    }
    // Check visual features
    for (final sp in all) {
      for (final trait in sp.visualFeatures) {
        if (trait.toLowerCase().contains(q)) {
          return sp;
        }
      }
    }
    return all.isNotEmpty ? all.first : null;
  }

  // --- FISHING TRIP METHODS (P1-05) ---

  Future<void> insertTrip(LocalTrip trip) async {
    final db = await database;
    await db.insert(
      'local_trips',
      trip.toMap(),
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<LocalTrip?> getTrip(String tripId) async {
    final db = await database;
    final maps = await db.query(
      'local_trips',
      where: 'id = ?',
      whereArgs: [tripId],
      limit: 1,
    );
    if (maps.isEmpty) return null;
    return LocalTrip.fromMap(maps.first);
  }

  Future<List<LocalTrip>> getLocalTrips({String? syncStatus}) async {
    final db = await database;
    final maps = await db.query(
      'local_trips',
      where: syncStatus != null ? 'sync_status = ?' : null,
      whereArgs: syncStatus != null ? [syncStatus] : null,
      orderBy: 'started_at DESC',
    );
    return maps.map((m) => LocalTrip.fromMap(m)).toList();
  }

  /// Active (unfinished) trip, used to resume recording after an app restart.
  Future<LocalTrip?> getActiveTrip() async {
    final db = await database;
    final maps = await db.query(
      'local_trips',
      where: "status = 'in_progress'",
      orderBy: 'started_at DESC',
      limit: 1,
    );
    if (maps.isEmpty) return null;
    return LocalTrip.fromMap(maps.first);
  }

  Future<void> updateTrip(LocalTrip trip) async {
    final db = await database;
    await db.update(
      'local_trips',
      trip.toMap(),
      where: 'id = ?',
      whereArgs: [trip.id],
    );
  }

  Future<void> insertTripPoint(TripPoint point) async {
    final db = await database;
    await db.insert(
      'local_trip_points',
      point.toMap(),
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<List<TripPoint>> getTripPoints(String tripId, {String? syncStatus}) async {
    final db = await database;
    String? where;
    List<dynamic>? whereArgs;
    if (syncStatus != null) {
      where = 'trip_id = ? AND sync_status = ?';
      whereArgs = [tripId, syncStatus];
    } else {
      where = 'trip_id = ?';
      whereArgs = [tripId];
    }
    final maps = await db.query(
      'local_trip_points',
      where: where,
      whereArgs: whereArgs,
      orderBy: 'sequence ASC',
    );
    return maps.map((m) => TripPoint.fromMap(m)).toList();
  }

  Future<int> getNextTripSequence(String tripId) async {
    final db = await database;
    final maps = await db.rawQuery(
      'SELECT COALESCE(MAX(sequence), -1) + 1 AS next_seq FROM local_trip_points WHERE trip_id = ?',
      [tripId],
    );
    return (maps.first['next_seq'] as int?) ?? 0;
  }

  Future<void> markTripPointsSynced(String tripId) async {
    final db = await database;
    await db.update(
      'local_trip_points',
      {'sync_status': 'synced'},
      where: 'trip_id = ?',
      whereArgs: [tripId],
    );
  }

  // --- CACHE METADATA & EXPIRY METHODS ---

  Future<void> updateCacheMetadata(String key, {String? version, Duration? validDuration}) async {
    final db = await database;
    final now = DateTime.now();
    final expiry = now.add(validDuration ?? const Duration(days: 7));

    await db.insert(
      'cache_metadata',
      {
        'key': key,
        'version': version ?? '1.0',
        'last_synced_at': now.toIso8601String(),
        'expires_at': expiry.toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<Map<String, dynamic>?> getCacheMetadata(String key) async {
    final db = await database;
    final maps = await db.query(
      'cache_metadata',
      where: 'key = ?',
      whereArgs: [key],
    );
    if (maps.isEmpty) return null;
    return maps.first;
  }

  Future<bool> isCacheStale(String key) async {
    final meta = await getCacheMetadata(key);
    if (meta == null) return true;
    final expiresAtStr = meta['expires_at'] as String?;
    if (expiresAtStr == null) return true;
    final expiresAt = DateTime.tryParse(expiresAtStr);
    if (expiresAt == null) return true;
    return DateTime.now().isAfter(expiresAt);
  }

  Future<void> close() async {
    final db = _database;
    if (db != null && db.isOpen) {
      await db.close();
      _database = null;
    }
  }
}
