import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:mobile/data/database_helper.dart';
import 'package:mobile/models/catch_record.dart';
import 'package:mobile/services/capture/photo_quality.dart';

img.Image checkerboard({int cell = 16}) {
  final image = img.Image(width: 320, height: 240);
  for (var y = 0; y < 240; y++) {
    for (var x = 0; x < 320; x++) {
      final on = ((x ~/ cell) + (y ~/ cell)) % 2 == 0;
      final v = on ? 255 : 0;
      image.setPixelRgb(x, y, v, v, v);
    }
  }
  return image;
}

Uint8List pngBytes(img.Image image) => Uint8List.fromList(img.encodePng(image));

void main() {
  group('On-device photo clarity', () {
    test('1. Sharp edge-rich photo is clear', () {
      final q = assessPhotoQuality(pngBytes(checkerboard()));

      expect(q.status, PhotoQualityStatus.clear);
      expect(q.blurScore, greaterThan(kBlurThreshold));
      expect(q.retakeRecommended, false);
    });

    test('2. Blurred photo is flagged, retake advised', () {
      final blurred = img.gaussianBlur(checkerboard(), radius: 6);

      final q = assessPhotoQuality(pngBytes(blurred));

      expect(q.status, PhotoQualityStatus.blurry);
      expect(q.retakeRecommended, true);
    });

    test('3. Sharp scores far above blurred (separation margin)', () {
      final sharpScore = assessPhotoQuality(pngBytes(checkerboard())).blurScore!;
      final blurredScore =
          assessPhotoQuality(pngBytes(img.gaussianBlur(checkerboard(), radius: 6))).blurScore!;

      expect(sharpScore, greaterThan(blurredScore * 5));
    });

    test('4. Undecodable or empty bytes are honestly unknown', () {
      expect(assessPhotoQuality(Uint8List(0)).status, PhotoQualityStatus.unknown);
      expect(
        assessPhotoQuality(Uint8List.fromList([1, 2, 3, 4, 5])).status,
        PhotoQualityStatus.unknown,
      );
      expect(assessPhotoQuality(Uint8List.fromList([1, 2, 3])).blurScore, isNull);
    });
  });

  group('Quality persistence', () {
    late Database db;
    late DatabaseHelper dbHelper;

    setUp(() async {
      sqfliteFfiInit();
      databaseFactory = databaseFactoryFfi;
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

    test('5. Saved catch keeps its clarity verdict and score', () async {
      final now = DateTime.now();
      await dbHelper.insertCatch(LocalCatch(
        id: 'catch-blur-001',
        speciesNameRaw: 'Unknown Fish Specimen',
        weightKg: 1.5,
        capturedAt: now,
        photoLocalPath: '/app/docs/catches/catch_blur.jpg',
        photoQuality: 'blurry',
        photoBlurScore: 36.1,
        createdAt: now,
      ));

      final stored = await dbHelper.getLocalCatches();
      expect(stored.length, 1);
      expect(stored.first.photoQuality, 'blurry');
      expect(stored.first.photoBlurScore, closeTo(36.1, 0.01));
    });
  });
}
