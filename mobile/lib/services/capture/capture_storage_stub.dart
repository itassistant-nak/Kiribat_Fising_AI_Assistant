import 'package:flutter/foundation.dart';

/// Web/default implementation: no durable filesystem, preview-only.
/// Returns null so callers store no misleading local path.
Future<String?> persistCapturedBytes({
  required String fileName,
  required Uint8List bytes,
}) async {
  return null;
}
