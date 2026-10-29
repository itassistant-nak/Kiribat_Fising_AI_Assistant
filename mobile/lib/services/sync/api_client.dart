import 'dart:convert';
import 'package:http/http.dart' as http;

import 'multipart_photo.dart';
import '../../models/advisory_zone.dart';
import '../../models/species.dart';
import '../../models/catch_record.dart';
import '../../models/trip_record.dart';

class ApiClient {
  final String baseUrl;
  final http.Client _client;

  ApiClient({
    this.baseUrl = 'http://127.0.0.1:8000',
    http.Client? client,
  }) : _client = client ?? http.Client();

  /// Check server health
  Future<bool> checkHealth() async {
    try {
      final res = await _client
          .get(
            Uri.parse('$baseUrl/api/health'),
            headers: {'Accept': 'application/json'},
          )
          .timeout(const Duration(seconds: 4));
      return res.statusCode == 200;
    } catch (_) {
      return false;
    }
  }

  /// Download published advisory zones
  Future<List<AdvisoryZone>> fetchAdvisoryZones({String? token}) async {
    final headers = {
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };

    final res = await _client
        .get(
          Uri.parse('$baseUrl/api/advisory-zones'),
          headers: headers,
        )
        .timeout(const Duration(seconds: 10));

    if (res.statusCode != 200) {
      throw Exception('Failed to fetch advisory zones: HTTP ${res.statusCode}');
    }

    final data = jsonDecode(res.body);
    final List list = data['data'] ?? [];
    return list.map((item) => AdvisoryZone.fromJson(item)).toList();
  }

  /// Download verified fish species catalog
  Future<List<SpeciesItem>> fetchSpecies({String? token}) async {
    final headers = {
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };

    final res = await _client
        .get(
          Uri.parse('$baseUrl/api/species'),
          headers: headers,
        )
        .timeout(const Duration(seconds: 10));

    if (res.statusCode != 200) {
      throw Exception('Failed to fetch species catalog: HTTP ${res.statusCode}');
    }

    final data = jsonDecode(res.body);
    final List list = data['data'] ?? [];
    return list.map((item) => SpeciesItem.fromJson(item)).toList();
  }

  /// Upload an artisanal catch record to Laravel
  Future<Map<String, dynamic>> uploadCatch(LocalCatch catchRecord, {String? token}) async {
    final uri = Uri.parse('$baseUrl/api/catches');
    final request = http.MultipartRequest('POST', uri);

    request.headers.addAll({
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    });

    // Local catch UUID is the idempotency key: retried uploads replay
    // the original server row instead of duplicating it (P0-10).
    request.fields['client_uuid'] = catchRecord.id;
    if (catchRecord.speciesId != null) {
      request.fields['species_id'] = catchRecord.speciesId.toString();
    }
    request.fields['species_name_raw'] = catchRecord.speciesNameRaw;
    request.fields['weight_kg'] = catchRecord.weightKg.toString();
    request.fields['quantity'] = catchRecord.quantity.toString();
    if (catchRecord.latitude != null) {
      request.fields['latitude'] = catchRecord.latitude.toString();
    }
    if (catchRecord.longitude != null) {
      request.fields['longitude'] = catchRecord.longitude.toString();
    }
    if (catchRecord.gpsAccuracy != null) {
      request.fields['gps_accuracy'] = catchRecord.gpsAccuracy.toString();
    }
    request.fields['captured_at'] = catchRecord.capturedAt.toIso8601String();
    if (catchRecord.photoUrl != null) {
      request.fields['photo_url'] = catchRecord.photoUrl!;
    }
    if (catchRecord.aiSpeciesSuggestion != null) {
      request.fields['ai_species_suggestion'] = catchRecord.aiSpeciesSuggestion!;
    }
    if (catchRecord.aiConfidence != null) {
      request.fields['ai_confidence'] = catchRecord.aiConfidence.toString();
    }
    // Captured camera photo: attach device bytes for the server's `photo`
    // file field (server stores it and sets photo_url). Null on web or
    // when the local file is gone — upload proceeds without it.
    final photoFile = await loadPhotoFile(catchRecord.photoLocalPath);
    if (photoFile != null) {
      request.files.add(photoFile);
    }

    final streamedRes = await _client.send(request).timeout(const Duration(seconds: 15));
    final res = await http.Response.fromStream(streamedRes);

    if (res.statusCode != 200 && res.statusCode != 201) {
      throw Exception('Failed to upload catch: HTTP ${res.statusCode} ${res.body}');
    }

    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  /// Start a fishing trip on the server (P1-05). Returns the server trip JSON.
  Future<Map<String, dynamic>> createTrip(LocalTrip trip, {String? token}) async {
    final uri = Uri.parse('$baseUrl/api/trips');
    final res = await _client
        .post(
          uri,
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            if (token != null) 'Authorization': 'Bearer $token',
          },
          body: jsonEncode({
            if (trip.vesselName != null) 'vessel_name': trip.vesselName,
            'started_at': trip.startedAt.toIso8601String(),
            if (trip.notes != null) 'notes': trip.notes,
          }),
        )
        .timeout(const Duration(seconds: 15));

    if (res.statusCode != 200 && res.statusCode != 201) {
      throw Exception('Failed to create trip: HTTP ${res.statusCode} ${res.body}');
    }
    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  /// Batch-upload breadcrumb points for a server trip (P1-05).
  Future<Map<String, dynamic>> uploadRoutePoints(
    int serverTripId,
    List<TripPoint> points, {
    String? token,
  }) async {
    final uri = Uri.parse('$baseUrl/api/trips/$serverTripId/route-points');
    final res = await _client
        .post(
          uri,
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            if (token != null) 'Authorization': 'Bearer $token',
          },
          body: jsonEncode({
            'points': points.map((p) => p.toServerJson()).toList(),
          }),
        )
        .timeout(const Duration(seconds: 20));

    if (res.statusCode != 200 && res.statusCode != 201) {
      throw Exception('Failed to upload route points: HTTP ${res.statusCode} ${res.body}');
    }
    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  /// Mark a server trip completed (P1-05).
  Future<Map<String, dynamic>> endTrip(
    int serverTripId, {
    DateTime? endedAt,
    String? token,
  }) async {
    final uri = Uri.parse('$baseUrl/api/trips/$serverTripId');
    final res = await _client
        .patch(
          uri,
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            if (token != null) 'Authorization': 'Bearer $token',
          },
          body: jsonEncode({
            'ended_at': (endedAt ?? DateTime.now()).toIso8601String(),
            'status': 'completed',
          }),
        )
        .timeout(const Duration(seconds: 15));

    if (res.statusCode != 200) {
      throw Exception('Failed to end trip: HTTP ${res.statusCode} ${res.body}');
    }
    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  /// Invoke Meta AI Vision endpoint to identify a fish photograph
  Future<Map<String, dynamic>> identifyFish({
    String? imageUrl,
    String? hint,
    String? token,
  }) async {
    final uri = Uri.parse('$baseUrl/api/ai/identify-fish');
    final headers = {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };

    final payload = <String, dynamic>{};
    if (imageUrl != null) payload['image_url'] = imageUrl;
    if (hint != null) payload['hint'] = hint;
    final body = jsonEncode(payload);

    final res = await _client
        .post(uri, headers: headers, body: body)
        .timeout(const Duration(seconds: 15));

    if (res.statusCode != 200) {
      throw Exception('Failed to identify fish: HTTP ${res.statusCode} ${res.body}');
    }

    return jsonDecode(res.body) as Map<String, dynamic>;
  }
}
