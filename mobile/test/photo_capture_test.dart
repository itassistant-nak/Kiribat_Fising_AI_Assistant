import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:mobile/data/database_helper.dart';
import 'package:mobile/models/catch_record.dart';
import 'package:mobile/services/capture/photo_capture_service.dart';

/// Fake camera for tests: scripted capture outcomes, no platform channels.
class FakePhotoCapture implements PhotoCaptureService {
  CapturedPhoto? photoToReturn;
  PhotoCaptureException? errorToThrow;
  int takePhotoCalls = 0;

  @override
  Future<CapturedPhoto?> takePhoto() async {
    takePhotoCalls++;
    if (errorToThrow != null) throw errorToThrow!;
    return photoToReturn;
  }
}

CapturedPhoto samplePhoto({bool persisted = true}) => CapturedPhoto(
      localPath: persisted ? '/app/docs/catches/catch_a1b2c3d4.jpg' : '',
      fileName: 'catch_a1b2c3d4.jpg',
      bytes: Uint8List.fromList([0xFF, 0xD8, 0xFF, 0x00]),
      persisted: persisted,
    );

void main() {
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  group('Photo capture contract', () {
    test('1. Successful capture returns photo with path, name, and bytes', () async {
      final fake = FakePhotoCapture()..photoToReturn = samplePhoto();

      final shot = await fake.takePhoto();

      expect(fake.takePhotoCalls, 1);
      expect(shot, isNotNull);
      expect(shot!.localPath, endsWith('.jpg'));
      expect(shot.fileName, 'catch_a1b2c3d4.jpg');
      expect(shot.bytes.lengthInBytes, greaterThan(0));
      expect(shot.persisted, true);
    });

    test('2. Cancelled capture returns null (screen keeps prior photo)', () async {
      final fake = FakePhotoCapture()..photoToReturn = null;

      expect(await fake.takePhoto(), isNull);
    });

    test('3. Failures surface typed reasons for honest UI messages', () async {
      final fake = FakePhotoCapture()
        ..errorToThrow = const PhotoCaptureException(CaptureFailure.permissionDenied);

      try {
        await fake.takePhoto();
        fail('expected PhotoCaptureException');
      } on PhotoCaptureException catch (e) {
        expect(e.failure, CaptureFailure.permissionDenied);
      }
    });

    test('4. Every failure has distinct, actionable guidance text', () {
      final messages = CaptureFailure.values.map(captureFailureMessage).toList();

      expect(messages.toSet().length, messages.length); // all distinct
      for (final m in messages) {
        expect(m.isNotEmpty, true);
      }
      expect(captureFailureMessage(CaptureFailure.permissionDenied), contains('permission'));
      expect(captureFailureMessage(CaptureFailure.unavailable), contains('not available'));
    });

    test('5. Web-style preview-only photo carries bytes without a path', () {
      final shot = samplePhoto(persisted: false);

      expect(shot.persisted, false);
      expect(shot.localPath, isEmpty);
      expect(shot.bytes.lengthInBytes, greaterThan(0));
    });
  });

  group('Captured photo persistence', () {
    late Database db;
    late DatabaseHelper dbHelper;

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
    });

    tearDown(() async {
      await db.close();
    });

    test('6. Saved catch keeps the captured device path with no remote URL', () async {
      final shot = samplePhoto();
      final now = DateTime.now();
      await dbHelper.insertCatch(LocalCatch(
        id: 'catch-cam-001',
        speciesNameRaw: 'Unknown Fish Specimen',
        weightKg: 2.5,
        capturedAt: now,
        photoLocalPath: shot.localPath,
        photoUrl: null, // camera photo: no remote URL yet (never a stranger preset)
        createdAt: now,
      ));

      final stored = await dbHelper.getLocalCatches();

      expect(stored.length, 1);
      expect(stored.first.photoLocalPath, shot.localPath);
      expect(stored.first.photoUrl, isNull);
      expect(stored.first.syncStatus, 'pending');
    });
  });
}
