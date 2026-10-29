import 'package:http/http.dart' as http;

/// Web/default implementation: no readable file paths — the catch uploads
/// without photo bytes (photo_url field still carries preset photos).
Future<http.MultipartFile?> loadPhotoFile(String? path) async {
  return null;
}
