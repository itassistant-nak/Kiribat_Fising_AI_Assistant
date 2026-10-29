import 'dart:io' show File;

import 'package:http/http.dart' as http;

/// Native implementation: reads the captured photo from durable app storage
/// and attaches it as the `photo` multipart part the Laravel API expects.
Future<http.MultipartFile?> loadPhotoFile(String? path) async {
  if (path == null || path.isEmpty) return null;
  final file = File(path);
  if (!await file.exists()) return null; // cache cleared → upload without photo, no crash
  return http.MultipartFile.fromPath('photo', path);
}
