import 'dart:async';
import 'package:flutter/foundation.dart';
import '../../data/database_helper.dart';
import '../../models/advisory_zone.dart';
import '../../models/species.dart';
import '../../models/trip_record.dart';
import 'api_client.dart';

class SyncResult {
  final bool success;
  final int zonesSynced;
  final int speciesSynced;
  final int catchesUploaded;
  final int tripsSynced;
  final int routePointsUploaded;
  final String message;
  final DateTime timestamp;

  const SyncResult({
    required this.success,
    this.zonesSynced = 0,
    this.speciesSynced = 0,
    this.catchesUploaded = 0,
    this.tripsSynced = 0,
    this.routePointsUploaded = 0,
    required this.message,
    required this.timestamp,
  });
}

class SyncService extends ChangeNotifier {
  final DatabaseHelper _db;
  final ApiClient _api;

  bool _isAirplaneMode = false;
  bool _isSyncing = false;
  DateTime? _lastSyncTime;
  String? _lastError;
  int _pendingCatchesCount = 0;
  int _pendingTripsCount = 0;
  bool _isCacheStale = false;
  String? _authToken;
  String? _authenticatedUserEmail;
  String? _authenticatedUserName;
  Timer? _autoSyncTimer;

  bool get isAirplaneMode => _isAirplaneMode;
  bool get isSyncing => _isSyncing;
  DateTime? get lastSyncTime => _lastSyncTime;
  String? get lastError => _lastError;
  int get pendingCatchesCount => _pendingCatchesCount;
  int get pendingTripsCount => _pendingTripsCount;
  bool get isCacheStale => _isCacheStale;
  String? get authToken => _authToken;
  String? get authenticatedUserEmail => _authenticatedUserEmail;
  String? get authenticatedUserName => _authenticatedUserName;
  bool get isAuthenticated => _authToken != null;

  SyncService({
    DatabaseHelper? db,
    ApiClient? api,
  })  : _db = db ?? DatabaseHelper.instance,
        _api = api ?? ApiClient();

  @override
  void dispose() {
    stopAutoSync();
    super.dispose();
  }

  void setAirplaneMode(bool enabled) {
    _isAirplaneMode = enabled;
    notifyListeners();
  }

  /// Authenticate with Laravel API using fisher credentials
  Future<bool> login({
    String email = 'teititeki@fisher.ki',
    String password = 'FisherPass2026!',
  }) async {
    if (_isAirplaneMode) return false;
    try {
      final res = await _api.login(email, password);
      _authToken = res['token'] as String?;
      final user = res['user'] as Map<String, dynamic>?;
      _authenticatedUserEmail = user?['email'] as String? ?? email;
      _authenticatedUserName = user?['name'] as String?;
      notifyListeners();
      return _authToken != null;
    } catch (e) {
      debugPrint('SyncService login failed: $e');
      return false;
    }
  }

  /// Ensure we have a valid token when online
  Future<void> ensureAuthenticated() async {
    if (_authToken == null && !_isAirplaneMode) {
      await login();
    }
  }

  /// Start periodic auto-synchronization when online
  void startAutoSync({Duration interval = const Duration(seconds: 30)}) {
    _autoSyncTimer?.cancel();
    _autoSyncTimer = Timer.periodic(interval, (_) async {
      if (!_isAirplaneMode && !_isSyncing) {
        final pending = await _db.getLocalCatches();
        final pendingTrips = await _db.getLocalTrips(syncStatus: 'pending');
        final hasPendingWork = pending.any((c) => c.syncStatus == 'pending' || c.syncStatus == 'failed') ||
            pendingTrips.isNotEmpty ||
            await _db.isCacheStale('advisory_zones');

        if (hasPendingWork) {
          await ensureAuthenticated();
          if (_authToken != null) {
            await performFullSync(token: _authToken);
          }
        }
      }
    });
  }

  /// Stop periodic auto-synchronization
  void stopAutoSync() {
    _autoSyncTimer?.cancel();
    _autoSyncTimer = null;
  }

  Future<void> refreshPendingCount() async {
    final all = await _db.getLocalCatches();
    _pendingCatchesCount =
        all.where((c) => c.syncStatus == 'pending' || c.syncStatus == 'failed').length;
    final pendingTrips = await _db.getLocalTrips(syncStatus: 'pending');
    _pendingTripsCount = pendingTrips.length;
    _isCacheStale = await _db.isCacheStale('advisory_zones');
    notifyListeners();
  }

  /// Full synchronization workflow:
  /// 1. Download & cache published advisory zones
  /// 2. Download & cache species reference catalog
  /// 3. Upload queued offline catch records & photos
  /// 4. Upload queued offline fishing trips & breadcrumb route points (P1-05)
  Future<SyncResult> performFullSync({String? token}) async {
    final now = DateTime.now();

    if (_isAirplaneMode) {
      const msg = 'Device is in Airplane Mode. Offline cache preserved.';
      _lastError = msg;
      notifyListeners();
      return SyncResult(
        success: false,
        message: msg,
        timestamp: now,
      );
    }

    if (token == null && _authToken == null) {
      await ensureAuthenticated();
    }
    final activeToken = token ?? _authToken;

    _isSyncing = true;
    _lastError = null;
    notifyListeners();

    try {
      // Step 1: Inbound Advisory Zones Sync
      int zonesCount = 0;
      try {
        final zones = await _api.fetchAdvisoryZones(token: activeToken);
        await _db.saveAdvisoryZones(zones);
        await _db.updateCacheMetadata('advisory_zones');
        zonesCount = zones.length;
      } catch (e) {
        debugPrint('Warning: Could not fetch advisory zones: $e');
      }

      // Step 2: Inbound Species Catalog Sync
      int speciesCount = 0;
      try {
        final species = await _api.fetchSpecies(token: activeToken);
        await _db.saveSpeciesCatalog(species);
        await _db.updateCacheMetadata('species_catalog');
        speciesCount = species.length;
      } catch (e) {
        debugPrint('Warning: Could not fetch species catalog: $e');
      }

      // Step 3: Outbound Pending Catches Sync (pending + failed retries)
      final allCatches = await _db.getLocalCatches();
      final pendingCatches = allCatches
          .where((c) => c.syncStatus == 'pending' || c.syncStatus == 'failed')
          .toList();
      int catchesUploaded = 0;

      for (final catchRecord in pendingCatches) {
        try {
          // If catch is waiting for online AI identification, run Meta AI identification first
          String? aiSuggestion = catchRecord.aiSpeciesSuggestion;
          double? aiConfidence = catchRecord.aiConfidence;
          String aiStatus = catchRecord.aiStatus;
          String? ciguateraRisk = catchRecord.ciguateraRisk;
          String? ciguateraWarning = catchRecord.ciguateraWarning;

          if (aiStatus == 'waiting_for_online_ai' && catchRecord.photoUrl != null) {
            try {
              final aiRes = await _api.identifyFish(
                imageUrl: catchRecord.photoUrl,
                hint: catchRecord.speciesNameRaw,
                token: activeToken,
              );
              // Laravel returns the suggestion under `identification`
              // (SAM segmentation nested alongside it, never a name).
              final primary = (aiRes['identification'] ??
                  aiRes['primary_identification']) as Map<String, dynamic>?;
              if (primary != null) {
                aiSuggestion = '${primary['kiribati_name']} (${primary['english_name']})';
                aiConfidence = (primary['confidence'] as num?)?.toDouble() ?? 0.94;
                ciguateraRisk = primary['ciguatera_risk'] as String?;
                ciguateraWarning = primary['ciguatera_warning'] as String?;
                aiStatus = 'online_identified';
              }
            } catch (aiErr) {
              debugPrint('Warning: Could not run online AI for catch ${catchRecord.id}: $aiErr');
            }
          }

          // Persist the AI result BEFORE uploading so a failed upload
          // never re-bills identify on retry (aiStatus flips first).
          if (aiStatus == 'online_identified' &&
              catchRecord.aiStatus == 'waiting_for_online_ai') {
            await _db.updateCatchAiResult(
              catchRecord.id,
              aiSuggestion: aiSuggestion,
              aiConfidence: aiConfidence,
              aiStatus: aiStatus,
              ciguateraRisk: ciguateraRisk,
              ciguateraWarning: ciguateraWarning,
            );
          }

          final updatedCatch = catchRecord.copyWith(
            aiSpeciesSuggestion: aiSuggestion,
            aiConfidence: aiConfidence,
            aiStatus: aiStatus,
            ciguateraRisk: ciguateraRisk,
            ciguateraWarning: ciguateraWarning,
          );

          final serverRes = await _api.uploadCatch(updatedCatch, token: activeToken);
          final serverId = serverRes['catch']?['id'] as int? ??
              serverRes['data']?['id'] as int? ??
              serverRes['id'] as int?;
          final serverPhotoUrl = serverRes['catch']?['photo_url'] as String? ??
              serverRes['data']?['photo_url'] as String?;

          await _db.updateCatchAiResult(
            catchRecord.id,
            aiSuggestion: aiSuggestion,
            aiConfidence: aiConfidence,
            aiStatus: aiStatus,
            ciguateraRisk: ciguateraRisk,
            ciguateraWarning: ciguateraWarning,
            serverCatchId: serverId,
            syncStatus: 'synced',
            photoUrl: serverPhotoUrl,
          );
          catchesUploaded++;
        } catch (e) {
          debugPrint('Error uploading catch ${catchRecord.id}: $e');
          await _db.updateCatchSyncStatus(catchRecord.id, 'failed');
        }
      }

      // Step 4: Outbound Fishing Trips & Route Points Sync (P1-05)
      final pendingTrips = await _db.getLocalTrips(syncStatus: 'pending');
      int tripsUploaded = 0;
      int pointsUploaded = 0;

      for (final trip in pendingTrips) {
        try {
          final tripResult = await syncSingleTrip(trip, token: activeToken);
          tripsUploaded++;
          pointsUploaded += tripResult;
        } catch (e) {
          debugPrint('Error uploading trip ${trip.id}: $e');
          await _db.updateTrip(trip.copyWith(syncStatus: 'failed'));
        }
      }

      _lastSyncTime = now;
      await refreshPendingCount();

      final result = SyncResult(
        success: true,
        zonesSynced: zonesCount,
        speciesSynced: speciesCount,
        catchesUploaded: catchesUploaded,
        tripsSynced: tripsUploaded,
        routePointsUploaded: pointsUploaded,
        message: 'Sync completed: $zonesCount zones, $speciesCount species cached, '
            '$catchesUploaded catches, $tripsUploaded trips ($pointsUploaded points) uploaded.',
        timestamp: now,
      );

      _isSyncing = false;
      notifyListeners();
      return result;
    } catch (e) {
      _lastError = 'Sync failed: $e';
      _isSyncing = false;
      notifyListeners();
      return SyncResult(
        success: false,
        message: _lastError!,
        timestamp: now,
      );
    }
  }

  /// Upload one offline trip: create the server trip (once), push all
  /// breadcrumb points (idempotent on sequence), close it if completed.
  /// Returns the number of route points uploaded.
  Future<int> syncSingleTrip(LocalTrip trip, {String? token}) async {
    int serverTripId;
    if (trip.serverTripId != null) {
      serverTripId = trip.serverTripId!;
    } else {
      final created = await _api.createTrip(trip, token: token);
      serverTripId = (created['data']?['id'] ?? created['id']) as int;
      await _db.updateTrip(trip.copyWith(serverTripId: serverTripId));
    }

    final points = await _db.getTripPoints(trip.id);
    if (points.isNotEmpty) {
      // Upload in batches to stay well under request limits.
      const batchSize = 500;
      for (var i = 0; i < points.length; i += batchSize) {
        final batch = points.skip(i).take(batchSize).toList();
        await _api.uploadRoutePoints(serverTripId, batch, token: token);
      }
      await _db.markTripPointsSynced(trip.id);
    }

    if (trip.status == 'completed') {
      try {
        await _api.endTrip(serverTripId, endedAt: trip.endedAt, token: token);
      } catch (e) {
        debugPrint('Warning: could not close server trip $serverTripId: $e');
      }
    }

    final refreshed = await _db.getTrip(trip.id);
    await _db.updateTrip((refreshed ?? trip).copyWith(
      serverTripId: serverTripId,
      syncStatus: 'synced',
    ));
    return points.length;
  }

  /// Populate sample / synthetic Kiribati data offline if database is fresh
  Future<void> seedInitialOfflineDataIfEmpty() async {
    final existingZones = await _db.getCachedAdvisoryZones();
    if (existingZones.isEmpty) {
      // Seed Betio Reef Advisory Zone (Synthetic CFP Warning)
      final sampleZone = AdvisoryZone(
        id: 1,
        title: 'Betio Western Reef & Harbor [DEMO - SYNTHETIC]',
        description: 'High ciguatera fish poisoning advisory zone near Betio Port and ship channel.',
        concern: 'Ciguatera fish poisoning (CFP) detected in reef predators',
        evidence: 'Clinical CFP admissions at Betio Hospital following consumption of Te Okaoka (Orange-spotted Trevally) and Te Bwere.',
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
        source: 'Ministry of Fisheries & Marine Resources Development (MFMRD)',
        reviewDate: '2026-12-31',
        cachedAt: DateTime.now().toIso8601String(),
        species: const [
          AdvisorySpecies(
            speciesId: 5,
            kiribatiName: 'Te Okaoka',
            englishName: 'Orange-spotted Trevally',
            scientificName: 'Carangoides bajad',
            riskLevel: 'high',
            notes: 'High ciguatoxin bioaccumulation in digestive viscera and liver',
          ),
          AdvisorySpecies(
            speciesId: 2,
            kiribatiName: 'Te Bwere',
            englishName: 'Plectropomus leopardus',
            scientificName: 'Plectropomus leopardus',
            riskLevel: 'high',
            notes: 'Apex predator associated with acute marine neurotoxin cases',
          ),
        ],
      );

      await _db.saveAdvisoryZones([sampleZone]);
      await _db.updateCacheMetadata('advisory_zones');
    }

    final existingSpecies = await _db.getCachedSpecies();
    if (existingSpecies.isEmpty) {
      final sampleSpecies = [
        const SpeciesItem(
          id: 1,
          kiribatiName: 'Te Atun',
          englishName: 'Skipjack Tuna',
          scientificName: 'Katsuwonus pelamis',
          ciguateraRisk: 'low',
          photoUrl: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80',
          visualFeatures: ['Dark longitudinal belly stripes', 'Metallic dark blue back', 'Robust torpedo body'],
          aiTrained: true,
          cachedAt: '2026-10-28',
        ),
        const SpeciesItem(
          id: 2,
          kiribatiName: 'Te Bwere',
          englishName: 'Leopard Coral Grouper',
          scientificName: 'Plectropomus leopardus',
          ciguateraRisk: 'high',
          photoUrl: 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?auto=format&fit=crop&w=600&q=80',
          visualFeatures: ['Reddish body with small blue spots', 'Slender emarginate tail', 'Large mouth'],
          aiTrained: true,
          cachedAt: '2026-10-28',
        ),
        const SpeciesItem(
          id: 3,
          kiribatiName: 'Te Ikari',
          englishName: 'Bonefish',
          scientificName: 'Albula glossodonta',
          ciguateraRisk: 'low',
          photoUrl: 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?auto=format&fit=crop&w=600&q=80',
          visualFeatures: ['Conical snout overhang', 'Silvery reflective flanks', 'Deeply forked tail'],
          aiTrained: true,
          cachedAt: '2026-10-28',
        ),
        const SpeciesItem(
          id: 4,
          kiribatiName: 'Te Kuau',
          englishName: 'Blacktip Grouper',
          scientificName: 'Epinephelus fasciatus',
          ciguateraRisk: 'medium',
          photoUrl: 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80',
          visualFeatures: ['Black tips on dorsal fin spines', 'Pale reddish with vertical bands', 'Truncate tail'],
          aiTrained: true,
          cachedAt: '2026-10-28',
        ),
        const SpeciesItem(
          id: 5,
          kiribatiName: 'Te Okaoka',
          englishName: 'Orange-spotted Trevally',
          scientificName: 'Carangoides bajad',
          ciguateraRisk: 'high',
          photoUrl: 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?auto=format&fit=crop&w=600&q=80',
          visualFeatures: ['Prominent yellow-orange spots on sides', 'Deeply notched tail fin', 'Steep head profile'],
          aiTrained: true,
          cachedAt: '2026-10-28',
        ),
      ];

      await _db.saveSpeciesCatalog(sampleSpecies);
      await _db.updateCacheMetadata('species_catalog');
    }

    await refreshPendingCount();
  }

  /// Identifies a fish photo online via Meta AI Vision, or falls back to offline SQLite species cache
  Future<Map<String, dynamic>> identifyFishOnlineOrOffline(String photoUrl, {String? hint, String? token}) async {
    if (!_isAirplaneMode) {
      try {
        final res = await _api.identifyFish(imageUrl: photoUrl, hint: hint, token: token);
        if (res['status'] == 'success') {
          final identification = (res['identification'] ??
              res['primary_identification']) as Map<String, dynamic>?;
          final meta = identification?['meta_model'];
          return {
            'mode': 'online_ai',
            'meta_model': meta is Map
                ? (meta['model'] ?? 'muse-wonder-1.0')
                : (res['meta_model'] ?? 'muse-wonder-1.0'),
            'primary': identification,
            'candidates': identification?['reference_candidates'] ??
                identification?['candidates'] ??
                res['candidate_matches'] ??
                [],
            'segmentation': identification?['segmentation'],
            'disclaimer': res['disclaimer'],
          };
        }
      } catch (e) {
        debugPrint('Online AI identification failed, falling back to cache: $e');
      }
    }

    // Offline cache fallback
    final cachedSpecies = await _db.findCachedSpeciesByNameOrTrait(hint ?? '');
    if (cachedSpecies != null) {
      return {
        'mode': 'offline_cache',
        'meta_model': 'SQLite Offline Catalog Cache',
        'primary': {
          'kiribati_name': cachedSpecies.kiribatiName,
          'english_name': cachedSpecies.englishName,
          'scientific_name': cachedSpecies.scientificName,
          'confidence': 0.85,
          'confidence_percentage': '85.0% (Offline Cache)',
          'ciguatera_risk': cachedSpecies.ciguateraRisk,
          'ciguatera_warning': cachedSpecies.ciguateraRisk == 'high'
              ? 'High CFP bioaccumulation risk recorded in offline catalog for this species.'
              : 'Low risk species according to offline reference catalog.',
          'visual_features': cachedSpecies.visualFeatures,
        },
        'candidates': <Map<String, dynamic>>[],
        'disclaimer': 'Offline Cache Match: Full Meta AI Vision identification is queued and will execute automatically when online.',
      };
    }

    return {
      'mode': 'offline_unknown',
      'meta_model': 'None',
      'primary': null,
      'candidates': <Map<String, dynamic>>[],
      'disclaimer': 'Offline: No matching species found in local SQLite cache. Queued for Meta AI identification when online.',
    };
  }
}
