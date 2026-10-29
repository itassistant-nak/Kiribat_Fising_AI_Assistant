import 'dart:io' show Directory, File;

import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

/// Native implementation: copies captured bytes into durable app storage.
Future<String?> persistCapturedBytes({
  required String fileName,
  required Uint8List bytes,
}) async {
  final dir = await getApplicationDocumentsDirectory();
  final catchesDir = await Directory(p.join(dir.path, 'catches')).create(recursive: true);
  final saved = await File(p.join(catchesDir.path, fileName)).writeAsBytes(bytes, flush: true);
  return saved.path;
}
