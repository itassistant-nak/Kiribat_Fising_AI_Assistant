import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/models/catch_record.dart';
import 'package:mobile/services/sync/api_client.dart';
import 'package:mobile/services/sync/multipart_photo.dart';

void main() {
  group('Captured photo multipart loader', () {
    test('1. Null, empty, or missing paths attach nothing (no crash)', () async {
      expect(await loadPhotoFile(null), isNull);
      expect(await loadPhotoFile(''), isNull);
      expect(await loadPhotoFile('/tmp/definitely_missing_catch_photo_9x7.jpg'), isNull);
    });

    test('2. Existing photo file becomes the `photo` multipart part', () async {
      final dir = await Directory.systemTemp.createTemp('kiri_photo');
      final file = await File('${dir.path}/catch_test.jpg').writeAsBytes([0xFF, 0xD8, 0xFF, 0x00], flush: true);

      final part = await loadPhotoFile(file.path);
      expect(part, isNotNull);
      expect(part!.field, 'photo');
      expect(part.filename, 'catch_test.jpg');

      await dir.delete(recursive: true);
    });
  });

  group('Catch upload request shape', () {
    late HttpServer server;
    late List<int> capturedBody;
    late String capturedContentType;

    setUp(() async {
      capturedBody = [];
      capturedContentType = '';
      server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      server.listen((req) async {
        capturedContentType = req.headers.value('content-type') ?? '';
        await for (final chunk in req) {
          capturedBody.addAll(chunk);
        }
        req.response.statusCode = 201;
        req.response.headers.contentType = ContentType.json;
        req.response.write('{"message":"Catch recorded successfully","catch":{"id":42,"sync_status":"synced"}}');
        await req.response.close();
      });
    });

    tearDown(() async {
      await server.close(force: true);
    });

    LocalCatch testCatch({String? localPath, String? photoUrl}) {
      final now = DateTime.now();
      return LocalCatch(
        id: 'catch-upload-001',
        speciesNameRaw: 'Unknown Fish Specimen',
        weightKg: 2.5,
        capturedAt: now,
        photoLocalPath: localPath,
        photoUrl: photoUrl,
        createdAt: now,
      );
    }

    test('3. Camera-captured catch uploads photo bytes to the server', () async {
      final dir = await Directory.systemTemp.createTemp('kiri_upload');
      final file = await File('${dir.path}/catch_shot.jpg').writeAsBytes([0xFF, 0xD8, 0xFF, 0x01, 0x02], flush: true);

      final api = ApiClient(baseUrl: 'http://127.0.0.1:${server.port}');
      final res = await api.uploadCatch(testCatch(localPath: file.path));

      expect(res['catch']['id'], 42);
      expect(capturedContentType, contains('multipart/form-data'));
      final body = utf8.decode(capturedBody, allowMalformed: true);
      expect(body, contains('name="photo"'));
      expect(body, contains('filename="catch_shot.jpg"'));
      expect(body, contains('name="species_name_raw"'));
      expect(body, contains('name="client_uuid"'));
      expect(body, contains('catch-upload-001'));

      await dir.delete(recursive: true);
    });

    test('4. Preset catch (no local file) uploads fields only', () async {
      final api = ApiClient(baseUrl: 'http://127.0.0.1:${server.port}');
      final res = await api.uploadCatch(
        testCatch(photoUrl: 'https://images.example/fish.jpg'),
      );

      expect(res['catch']['id'], 42);
      final body = utf8.decode(capturedBody, allowMalformed: true);
      expect(body, isNot(contains('name="photo"')));
      expect(body, contains('name="photo_url"'));
      expect(body, contains('https://images.example/fish.jpg'));
    });
  });
}
