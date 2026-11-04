import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';
import '../../models/catch_record.dart';
import '../../models/species.dart';
import '../../data/database_helper.dart';
import '../../services/capture/photo_capture_service.dart';
import '../../services/capture/photo_quality.dart';
import '../../services/sync/sync_service.dart';

class LogCatchScreen extends StatefulWidget {
  final SyncService syncService;

  /// Overridable for tests; defaults to the real device camera.
  final PhotoCaptureService? captureService;
  final DatabaseHelper? db;
  final List<SpeciesItem>? initialSpecies;

  const LogCatchScreen({
    super.key,
    required this.syncService,
    this.captureService,
    this.db,
    this.initialSpecies,
  });

  @override
  State<LogCatchScreen> createState() => _LogCatchScreenState();
}

class _LogCatchScreenState extends State<LogCatchScreen> {
  late final DatabaseHelper _db;
  final _formKey = GlobalKey<FormState>();

  final TextEditingController _weightController = TextEditingController(text: '4.5');
  final TextEditingController _quantityController = TextEditingController(text: '1');
  final TextEditingController _customSpeciesController = TextEditingController();

  List<SpeciesItem> _availableSpecies = [];
  SpeciesItem? _selectedSpecies;
  bool _recordGps = true;
  final double _lat = 1.3320;
  final double _lon = 172.9780;
  final double _accuracy = 10.0;

  // Specimen presets representing authentic Kiribati species
  final List<Map<String, String>> _specimenPresets = [
    {
      'name': 'Te Bwere',
      'desc': 'Paddletail Snapper',
      'url': 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=700&q=80',
    },
    {
      'name': 'Te Ingimea',
      'desc': 'Yellowfin Tuna',
      'url': 'https://images.unsplash.com/photo-1534043464124-3be32fe000c9?auto=format&fit=crop&w=700&q=80',
    },
    {
      'name': 'Te Okaoka',
      'desc': 'Titan Triggerfish',
      'url': 'https://images.unsplash.com/photo-1576675466969-38eeae4b41f6?auto=format&fit=crop&w=700&q=80',
    },
    {
      'name': 'Te Kuau',
      'desc': 'Blacktip Grouper',
      'url': 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?auto=format&fit=crop&w=700&q=80',
    },
    {
      'name': 'Te Atun',
      'desc': 'Skipjack Tuna',
      'url': 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=700&q=80',
    },
    {
      'name': 'Te Ikari',
      'desc': 'Bonefish',
      'url': 'https://images.unsplash.com/photo-1522069169874-c58ec4b76be5?auto=format&fit=crop&w=700&q=80',
    },
  ];

  late String _currentPhotoUrl;
  String _currentPhotoLocalPath = '/data/user/0/mobile/photos/specimen_001.jpg';

  // Device-camera capture state (null until the fisher snaps a photo)
  CapturedPhoto? _capturedPhoto;
  bool _isCapturing = false;

  // On-device clarity verdict for the captured photo
  PhotoQuality? _photoQuality;

  PhotoCaptureService get _capture => widget.captureService ?? ImagePickerPhotoCapture();

  // AI Identification State
  bool _isIdentifying = false;
  String _selectedAiProvider = 'compare'; // 'compare', 'muse_glimmer', 'openai'
  Map<String, dynamic>? _lastAiResult;
  String? _aiSpeciesSuggestion;
  double? _aiConfidence;
  String _aiStatus = 'waiting_for_online_ai';
  String? _ciguateraRisk;
  String? _ciguateraWarning;

  List<LocalCatch> _localCatches = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _db = widget.db ?? DatabaseHelper.instance;
    _currentPhotoUrl = _specimenPresets.first['url']!;
    if (widget.initialSpecies != null) {
      _availableSpecies = widget.initialSpecies!;
      if (_availableSpecies.isNotEmpty) {
        _selectedSpecies = _availableSpecies.first;
      }
      _isLoading = false;
    } else {
      _loadData();
    }
    widget.syncService.addListener(_loadCatchesOnly);
  }

  @override
  void dispose() {
    widget.syncService.removeListener(_loadCatchesOnly);
    _weightController.dispose();
    _quantityController.dispose();
    _customSpeciesController.dispose();
    super.dispose();
  }

  Future<void> _loadCatchesOnly() async {
    try {
      final catches = await _db.getLocalCatches();
      if (mounted) {
        setState(() {
          _localCatches = catches;
        });
      }
    } catch (e) {
      debugPrint('LogCatchScreen _loadCatchesOnly error: $e');
    }
  }

  Future<void> _loadData() async {
    try {
      final species = await _db.getCachedSpecies();
      final catches = await _db.getLocalCatches();

      if (mounted) {
        setState(() {
          _availableSpecies = species;
          if (species.isNotEmpty) {
            _selectedSpecies = species.first;
          }
          _localCatches = catches;
          _isLoading = false;
        });
      }
    } catch (e) {
      debugPrint('LogCatchScreen _loadData error: $e');
      if (mounted) {
        setState(() {
          _isLoading = false;
        });
      }
    }
  }

  void _onSelectSpecimen(Map<String, String> preset) {
    setState(() {
      _currentPhotoUrl = preset['url']!;
      _currentPhotoLocalPath = '/data/user/0/mobile/photos/${preset['name']?.toLowerCase().replaceAll(' ', '_')}.jpg';
      _capturedPhoto = null;
      _photoQuality = null;
      _lastAiResult = null;
      _aiSpeciesSuggestion = null;
      _aiConfidence = null;
      _aiStatus = 'waiting_for_online_ai';
      _ciguateraRisk = null;
      _ciguateraWarning = null;

      // Find matching species in catalog if available
      final match = _availableSpecies.cast<SpeciesItem?>().firstWhere(
            (sp) => sp?.kiribatiName == preset['name'],
            orElse: () => null,
          );
      if (match != null) {
        _selectedSpecies = match;
      }
    });
  }

  /// Opens the device camera so the fisher can photograph the catch.
  /// The photo previews instantly and persists to app storage (native).
  Future<void> _takePhoto() async {
    setState(() {
      _isCapturing = true;
    });

    try {
      final shot = await _capture.takePhoto();
      if (!mounted) return;
      if (shot == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Photo cancelled — no picture taken.')),
        );
        return;
      }
      final quality = assessPhotoQuality(shot.bytes);
      setState(() {
        _capturedPhoto = shot;
        _photoQuality = quality;
        _currentPhotoUrl = '';
        _lastAiResult = null;
        _aiSpeciesSuggestion = null;
        _aiConfidence = null;
        _aiStatus = 'waiting_for_online_ai';
        _ciguateraRisk = null;
        _ciguateraWarning = null;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(quality.retakeRecommended
              ? 'Photo looks blurry (sharpness ${quality.blurScore?.toStringAsFixed(0) ?? '?'}). Retake for a reliable identification.'
              : (shot.persisted
                  ? 'Photo captured and saved on this device (${shot.fileName}).'
                  : 'Photo captured (preview only on web — save the catch before leaving).')),
          backgroundColor: quality.retakeRecommended ? Colors.orange.shade800 : Colors.teal.shade700,
        ),
      );
    } on PhotoCaptureException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(captureFailureMessage(e.failure)),
            backgroundColor: Colors.red.shade700,
          ),
        );
      }
    } finally {
      if (mounted) {
        setState(() {
          _isCapturing = false;
        });
      }
    }
  }

  /// Preset name for a URL, or null (never throws — captured photos
  /// intentionally match no preset).
  String? _presetNameForUrl(String url) {
    for (final preset in _specimenPresets) {
      if (preset['url'] == url) return preset['name'];
    }
    return null;
  }

  Future<void> _identifyFish() async {
    setState(() {
      _isIdentifying = true;
    });

    try {
      final hint = _selectedSpecies?.kiribatiName ?? _presetNameForUrl(_currentPhotoUrl);
      final res = await widget.syncService.identifyFishOnlineOrOffline(
        _capturedPhoto != null ? '' : _currentPhotoUrl,
        hint: hint,
        provider: _selectedAiProvider,
      );

      if (mounted) {
        setState(() {
          _lastAiResult = res;
          final primary = res['primary'] as Map<String, dynamic>?;
          final comparisonStatus = res['comparison_status'] as String?;

          if (primary != null) {
            final kiribatiName = primary['kiribati_name'] as String? ?? 'Unknown';
            final englishName = primary['english_name'] as String? ?? primary['common_name'] as String? ?? 'Fish';
            _aiSpeciesSuggestion = '$kiribatiName ($englishName)';
            _aiConfidence = (primary['confidence'] as num?)?.toDouble() ?? 0.85;
            _ciguateraRisk = primary['ciguatera_risk'] as String?;
            _ciguateraWarning = primary['ciguatera_warning'] as String?;

            if (res['mode'] == 'online_ai') {
              _aiStatus = 'online_identified';
            } else if (res['mode'] == 'offline_cache') {
              _aiStatus = 'cached_match';
            } else {
              _aiStatus = 'waiting_for_online_ai';
            }

            // Sync dropdown selection ONLY if NOT in disagreement.
            // On disagreement, human confirmation is required; do NOT auto-select!
            if (comparisonStatus != 'disagreement') {
              final matchingSpecies = _availableSpecies.cast<SpeciesItem?>().firstWhere(
                    (sp) => sp?.kiribatiName.toLowerCase() == kiribatiName.toLowerCase(),
                    orElse: () => null,
                  );
              if (matchingSpecies != null) {
                _selectedSpecies = matchingSpecies;
              }
            } else {
              _selectedSpecies = null;
            }
          } else {
            _aiStatus = 'waiting_for_online_ai';
            _aiSpeciesSuggestion = 'Pending Online AI Analysis';
          }
          _isIdentifying = false;
        });

        String snackMessage;
        Color snackColor = Colors.teal.shade800;

        if (res['mode'] == 'online_ai') {
          final comp = res['comparison_status'] as String?;
          if (comp == 'agreement') {
            snackMessage = '✨ AI Vision: Models agree on ${_aiSpeciesSuggestion ?? "Fish"}';
            snackColor = Colors.teal.shade800;
          } else if (comp == 'disagreement') {
            snackMessage = '⚠️ AI Vision: Models disagree — please verify with your own knowledge or select manually.';
            snackColor = Colors.orange.shade900;
          } else if (comp == 'both_failed') {
            snackMessage = '❌ AI Vision: Both models failed to identify the fish photograph.';
            snackColor = Colors.red.shade800;
          } else if (comp == 'insufficient_evidence') {
            snackMessage = 'ℹ️ AI Vision: Low confidence across models. Manual selection required.';
            snackColor = Colors.blueGrey.shade800;
          } else {
            snackMessage = '✨ AI Vision: Identified ${_aiSpeciesSuggestion ?? "Fish"}';
            snackColor = Colors.teal.shade800;
          }
        } else {
          snackMessage = '📦 Offline Cache: Matched ${_aiSpeciesSuggestion ?? "Specimen"}. Online AI analysis queued.';
          snackColor = Colors.amber.shade900;
        }

        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(snackMessage),
            backgroundColor: snackColor,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _isIdentifying = false;
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Identification error: $e'),
            backgroundColor: Colors.red.shade700,
          ),
        );
      }
    }
  }

  Future<void> _saveCatchOffline() async {
    if (!_formKey.currentState!.validate()) return;

    final speciesName = _selectedSpecies?.kiribatiName ??
        (_customSpeciesController.text.isNotEmpty
            ? _customSpeciesController.text
            : (_aiSpeciesSuggestion ?? 'Unknown Fish Specimen'));

    final weight = double.tryParse(_weightController.text) ?? 1.0;
    final quantity = int.tryParse(_quantityController.text) ?? 1;

    final newCatch = LocalCatch(
      id: const Uuid().v4(),
      speciesId: _selectedSpecies?.id,
      speciesNameRaw: speciesName,
      weightKg: weight,
      quantity: quantity,
      latitude: _recordGps ? _lat : null,
      longitude: _recordGps ? _lon : null,
      gpsAccuracy: _recordGps ? _accuracy : null,
      capturedAt: DateTime.now(),
      // Camera capture: durable device path, no remote URL yet (photo bytes
      // upload is a follow-up; never attribute a stranger's preset photo).
      photoLocalPath: (_capturedPhoto != null && _capturedPhoto!.persisted)
          ? _capturedPhoto!.localPath
          : _currentPhotoLocalPath,
      photoUrl: _capturedPhoto != null ? null : _currentPhotoUrl,
      photoQuality: _photoQuality?.status.name,
      photoBlurScore: _photoQuality?.blurScore,
      syncStatus: 'pending',
      aiSpeciesSuggestion: _aiSpeciesSuggestion ?? (_selectedSpecies != null ? '${_selectedSpecies!.kiribatiName} (${_selectedSpecies!.englishName})' : null),
      aiConfidence: _aiConfidence,
      aiStatus: _aiStatus,
      ciguateraRisk: _ciguateraRisk ?? _selectedSpecies?.ciguateraRisk,
      ciguateraWarning: _ciguateraWarning,
      createdAt: DateTime.now(),
    );

    await _db.insertCatch(newCatch);
    await widget.syncService.refreshPendingCount();
    await _loadCatchesOnly();

    // If online, immediately trigger live background upload to Laravel/MySQL
    if (widget.syncService.isOnline) {
      widget.syncService.performFullSync().catchError((e) {
        debugPrint('Live catch sync background upload: $e');
        return SyncResult(
          success: false,
          message: 'Offline sync queued',
          timestamp: DateTime.now(),
        );
      });
    }

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            widget.syncService.isOnline
                ? 'Catch ($speciesName, ${weight}kg) saved & syncing live to Laravel backend!'
                : (_aiStatus == 'online_identified'
                    ? 'Catch ($speciesName, ${weight}kg) saved offline with verified AI identification!'
                    : 'Catch saved offline! Queued for Meta AI identification when online.'),
          ),
          backgroundColor: Colors.teal.shade700,
        ),
      );
    }
  }

  /// Viewfinder image: captured camera bytes when present, else the
  /// selected specimen preset URL.
  Widget _buildPhotoView() {
    if (_capturedPhoto != null) {
      return Image.memory(
        _capturedPhoto!.bytes,
        height: 220,
        width: double.infinity,
        fit: BoxFit.cover,
        errorBuilder: (context, error, stackTrace) => Container(
          height: 220,
          color: Colors.grey.shade800,
          child: const Center(
            child: Icon(Icons.broken_image, size: 48, color: Colors.white54),
          ),
        ),
      );
    }
    return Image.network(
      _currentPhotoUrl,
      height: 220,
      width: double.infinity,
      fit: BoxFit.cover,
      errorBuilder: (context, error, stackTrace) => Container(
        height: 220,
        color: Colors.grey.shade800,
        child: const Center(
          child: Icon(Icons.broken_image, size: 48, color: Colors.white54),
        ),
      ),
    );
  }

  void _applyProviderSuggestion(Map<String, dynamic> providerData) {
    final kiribatiName = providerData['kiribati_name'] as String?;
    final englishName = providerData['common_name'] as String? ?? providerData['english_name'] as String? ?? '';
    final scientificName = providerData['scientific_name'] as String?;
    final confidence = (providerData['confidence'] as num?)?.toDouble();
    final risk = providerData['ciguatera_risk'] as String?;

    setState(() {
      _aiSpeciesSuggestion = '$kiribatiName ($englishName)';
      _aiConfidence = confidence;
      _ciguateraRisk = risk;

      SpeciesItem? match;
      if (kiribatiName != null && kiribatiName.isNotEmpty) {
        match = _availableSpecies.cast<SpeciesItem?>().firstWhere(
              (sp) => sp?.kiribatiName.toLowerCase() == kiribatiName.toLowerCase(),
              orElse: () => null,
            );
      }
      if (match == null && scientificName != null && scientificName.isNotEmpty) {
        match = _availableSpecies.cast<SpeciesItem?>().firstWhere(
              (sp) => sp?.scientificName.toLowerCase() == scientificName.toLowerCase(),
              orElse: () => null,
            );
      }
      if (match != null) {
        _selectedSpecies = match;
        _customSpeciesController.clear();
      } else {
        _selectedSpecies = null;
        if (kiribatiName != null) {
          _customSpeciesController.text = '$kiribatiName ($englishName)';
        }
      }
    });

    ScaffoldMessenger.of(context).hideCurrentSnackBar();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('Selected: ${providerData['display_name'] ?? "Provider"} suggestion ($kiribatiName)'),
        backgroundColor: Colors.teal.shade800,
        duration: const Duration(seconds: 2),
      ),
    );
  }

  Widget _buildComparisonBanner(String status) {
    Color bgColor;
    Color borderColor;
    Color textColor;
    IconData icon;
    String title;
    String subtitle;

    switch (status) {
      case 'agreement':
        bgColor = Colors.green.shade50;
        borderColor = Colors.green.shade400;
        textColor = Colors.green.shade900;
        icon = Icons.verified;
        title = 'AGREEMENT';
        subtitle = 'Both Meta Muse Glimmer and OpenAI Vision identified the same species.';
        break;
      case 'disagreement':
        bgColor = Colors.orange.shade50;
        borderColor = Colors.orange.shade400;
        textColor = Colors.deepOrange.shade900;
        icon = Icons.warning_amber_rounded;
        title = 'DISAGREEMENT';
        subtitle = 'Models disagree — please verify with your own knowledge or select manually.';
        break;
      case 'single_provider_result':
        bgColor = Colors.blue.shade50;
        borderColor = Colors.blue.shade300;
        textColor = Colors.blue.shade900;
        icon = Icons.info_outline;
        title = 'SINGLE PROVIDER RESULT';
        subtitle = 'Only one model returned a species identification.';
        break;
      case 'both_failed':
        bgColor = Colors.amber.shade50;
        borderColor = Colors.amber.shade400;
        textColor = Colors.amber.shade900;
        icon = Icons.cloud_off;
        title = 'AI IDENTIFICATION UNAVAILABLE';
        subtitle = 'Live AI identification is currently unavailable. Please try again when internet connectivity is restored or upload another photograph.';
        break;
      case 'insufficient_evidence':
      default:
        bgColor = Colors.grey.shade100;
        borderColor = Colors.grey.shade400;
        textColor = Colors.blueGrey.shade800;
        icon = Icons.help_outline;
        title = 'INSUFFICIENT EVIDENCE';
        subtitle = 'Both models returned low confidence scores or insufficient evidence to confirm species.';
        break;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: borderColor, width: 1.5),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: textColor, size: 22),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: TextStyle(
                    color: textColor,
                    fontWeight: FontWeight.bold,
                    fontSize: 13,
                    letterSpacing: 0.5,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  subtitle,
                  style: TextStyle(
                    color: textColor.withOpacity(0.9),
                    fontSize: 12,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildProviderCard(
    Map<String, dynamic>? data, {
    required String defaultName,
    required IconData providerIcon,
    required Color accentColor,
  }) {
    if (data == null) {
      return Card(
        color: Colors.grey.shade50,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(8),
          side: BorderSide(color: Colors.grey.shade300),
        ),
        child: Padding(
          padding: const EdgeInsets.all(12.0),
          child: Text('$defaultName: Not evaluated', style: const TextStyle(fontSize: 12, color: Colors.grey)),
        ),
      );
    }

    final displayName = data['display_name'] as String? ?? defaultName;
    final status = (data['status'] as String? ?? 'unknown').toLowerCase();
    final isSuccess = status == 'success';
    final isUnavailable = status == 'unavailable' || status == 'unconfigured';
    final isTimeout = status == 'timeout';
    final isFallback = status == 'fallback';
    final isInsufficient = status == 'insufficient_evidence';
    final latency = data['latency_ms'] as int?;
    final suggested = data['suggested_species'] as Map<String, dynamic>?;
    final kiribatiName = data['kiribati_name'] as String? ?? suggested?['kiribati_name'] as String?;
    final commonName = data['common_name'] as String? ?? data['english_name'] as String? ?? suggested?['common_name'] as String?;
    final scientificName = data['scientific_name'] as String? ?? suggested?['scientific_name'] as String?;
    final confidencePct = data['confidence_percentage'] as String? ??
        (data['confidence'] != null ? '${((data['confidence'] as num) * 100).toStringAsFixed(1)}%' : null);
    final risk = data['ciguatera_risk'] as String? ?? suggested?['ciguatera_risk'] as String?;
    final traits = (data['visible_traits'] as List?)?.cast<String>() ?? [];
    final alternatives = (data['alternatives'] as List?)?.cast<String>() ?? [];
    final errorMessage = data['error_message'] as String? ?? data['error'] as String?;

    String badgeText;
    Color badgeBg;
    Color badgeFg;
    if (isSuccess) {
      badgeText = 'SUCCESS';
      badgeBg = Colors.green.shade100;
      badgeFg = Colors.green.shade900;
    } else if (isUnavailable) {
      badgeText = 'UNAVAILABLE';
      badgeBg = Colors.orange.shade100;
      badgeFg = Colors.orange.shade900;
    } else if (isTimeout) {
      badgeText = 'TIMEOUT';
      badgeBg = Colors.deepOrange.shade100;
      badgeFg = Colors.deepOrange.shade900;
    } else if (isFallback) {
      badgeText = 'FALLBACK';
      badgeBg = Colors.blue.shade100;
      badgeFg = Colors.blue.shade900;
    } else if (isInsufficient) {
      badgeText = 'INSUFFICIENT EVIDENCE';
      badgeBg = Colors.grey.shade200;
      badgeFg = Colors.grey.shade800;
    } else {
      badgeText = 'FAILED';
      badgeBg = Colors.red.shade100;
      badgeFg = Colors.red.shade900;
    }

    final cardBorderColor = isSuccess
        ? accentColor.withOpacity(0.5)
        : (isUnavailable ? Colors.orange.shade300 : (isInsufficient ? Colors.grey.shade400 : Colors.red.shade200));

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(8),
        side: BorderSide(color: cardBorderColor),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    Icon(providerIcon, color: accentColor, size: 18),
                    const SizedBox(width: 6),
                    Text(
                      displayName,
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: accentColor),
                    ),
                  ],
                ),
                Row(
                  children: [
                    if (latency != null && latency > 0) ...[
                      Text(
                        '${latency}ms',
                        style: TextStyle(fontSize: 10, color: Colors.grey.shade600, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(width: 6),
                    ],
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                        color: badgeBg,
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(
                        badgeText,
                        style: TextStyle(
                          fontSize: 9,
                          fontWeight: FontWeight.bold,
                          color: badgeFg,
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
            const Divider(height: 12),
            if (isSuccess) ...[
              Row(
                crossAxisAlignment: CrossAxisAlignment.baseline,
                textBaseline: TextBaseline.alphabetic,
                children: [
                  Expanded(
                    child: Text(
                      kiribatiName ?? 'Unknown',
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                    ),
                  ),
                  if (commonName != null && commonName.isNotEmpty)
                    Text(
                      '($commonName)',
                      style: const TextStyle(fontSize: 12, color: Colors.black87),
                    ),
                ],
              ),
              if (scientificName != null && scientificName.isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  scientificName,
                  style: const TextStyle(fontSize: 11, fontStyle: FontStyle.italic, color: Colors.black54),
                ),
              ],
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                runSpacing: 4,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: Colors.grey.shade200,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      'Confidence: ${confidencePct ?? "Not available"}',
                      style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold),
                    ),
                  ),
                  if (risk != null)
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                        color: risk == 'high'
                            ? Colors.red.shade100
                            : (risk == 'medium' ? Colors.orange.shade100 : Colors.green.shade100),
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(
                        'CFP Risk: ${risk.toUpperCase()}',
                        style: TextStyle(
                          fontSize: 10,
                          fontWeight: FontWeight.bold,
                          color: risk == 'high'
                              ? Colors.red.shade900
                              : (risk == 'medium' ? Colors.orange.shade900 : Colors.green.shade900),
                        ),
                      ),
                    ),
                ],
              ),
              if (traits.isNotEmpty) ...[
                const SizedBox(height: 8),
                const Text('Visual Traits:', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold)),
                ...traits.take(3).map(
                      (t) => Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text('• $t', style: const TextStyle(fontSize: 10, color: Colors.black87)),
                      ),
                    ),
              ],
              if (alternatives.isNotEmpty) ...[
                const SizedBox(height: 6),
                Text(
                  'Alternatives: ${alternatives.join(", ")}',
                  style: const TextStyle(fontSize: 10, fontStyle: FontStyle.italic, color: Colors.black54),
                ),
              ],
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerRight,
                child: ElevatedButton.icon(
                  onPressed: () => _applyProviderSuggestion(data),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: accentColor,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    visualDensity: VisualDensity.compact,
                    textStyle: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                  ),
                  icon: const Icon(Icons.check, size: 14),
                  label: Text('Use $displayName'),
                ),
              ),
            ] else ...[
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Identification: Not performed',
                      style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.blueGrey.shade800),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      'Confidence: Not available',
                      style: TextStyle(fontSize: 11, color: Colors.blueGrey.shade600),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      errorMessage ?? (isUnavailable ? 'Provider unconfigured or unavailable: API connection failed' : 'Identification failed for this provider.'),
                      style: TextStyle(
                        fontSize: 11,
                        color: isUnavailable ? Colors.orange.shade900 : Colors.red.shade800,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildOfflineCatalogueCard(Map<String, dynamic> catalogue) {
    final suggested = catalogue['suggested_species'] as Map<String, dynamic>?;
    final reasoning = catalogue['reasoning'] as String? ?? 'Offline catalogue reference.';
    final kName = suggested?['kiribati_name'] as String?;
    final cName = suggested?['common_name'] as String?;
    final sName = suggested?['scientific_name'] as String?;

    return Card(
      elevation: 1,
      color: Colors.blueGrey.shade50,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(8),
        side: BorderSide(color: Colors.blueGrey.shade300),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    Icon(Icons.menu_book, color: Colors.blueGrey.shade800, size: 18),
                    const SizedBox(width: 6),
                    Text(
                      'Offline Catalogue Reference',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.blueGrey.shade900),
                    ),
                  ],
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: Colors.blueGrey.shade200,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    suggested != null ? 'HINT REFERENCE' : 'NO RELIABLE MATCH',
                    style: TextStyle(
                      fontSize: 9,
                      fontWeight: FontWeight.bold,
                      color: Colors.blueGrey.shade900,
                    ),
                  ),
                ),
              ],
            ),
            const Divider(height: 12),
            if (suggested != null) ...[
              Row(
                crossAxisAlignment: CrossAxisAlignment.baseline,
                textBaseline: TextBaseline.alphabetic,
                children: [
                  Expanded(
                    child: Text(
                      kName ?? 'Unknown',
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                    ),
                  ),
                  if (cName != null && cName.isNotEmpty)
                    Text(
                      '($cName)',
                      style: const TextStyle(fontSize: 12, color: Colors.black87),
                    ),
                ],
              ),
              if (sName != null && sName.isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  sName,
                  style: const TextStyle(fontSize: 11, fontStyle: FontStyle.italic, color: Colors.black54),
                ),
              ],
              const SizedBox(height: 6),
            ] else ...[
              const Text(
                'Identification: No reliable match',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Colors.black87),
              ),
              const SizedBox(height: 4),
            ],
            Text(
              'Confidence: Not available',
              style: TextStyle(fontSize: 11, color: Colors.blueGrey.shade700, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 4),
            Text(
              reasoning,
              style: const TextStyle(fontSize: 11, color: Colors.black54),
            ),
            if (suggested != null) ...[
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerRight,
                child: ElevatedButton.icon(
                  onPressed: () => _applyProviderSuggestion(suggested),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.blueGrey.shade700,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    visualDensity: VisualDensity.compact,
                    textStyle: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                  ),
                  icon: const Icon(Icons.check, size: 14),
                  label: const Text('Use Catalogue Hint'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
  }

  Widget _buildComparisonDetailsCard(Map<String, dynamic> comparison) {
    final sciMatch = comparison['scientific_name_match'] as bool? ?? false;
    final genusMatch = comparison['genus_match'] as bool? ?? false;
    final notes = comparison['notes'] as String? ?? '';
    final sharedTraits = (comparison['shared_traits'] as List?)?.cast<String>() ?? [];

    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: Colors.blueGrey.shade50,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: Colors.blueGrey.shade200),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.compare_arrows, size: 16, color: Colors.blueGrey.shade800),
              const SizedBox(width: 6),
              const Text(
                'Comparison Details & Feature Matrix',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(
                  color: sciMatch ? Colors.green.shade100 : Colors.orange.shade100,
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  'Species Match: ${sciMatch ? "YES" : "NO"}',
                  style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    color: sciMatch ? Colors.green.shade900 : Colors.orange.shade900,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(
                  color: genusMatch ? Colors.green.shade100 : Colors.grey.shade200,
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  'Genus Match: ${genusMatch ? "YES" : "NO"}',
                  style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    color: genusMatch ? Colors.green.shade900 : Colors.black87,
                  ),
                ),
              ),
            ],
          ),
          if (sharedTraits.isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(
              'Shared Morphological Traits: ${sharedTraits.join(", ")}',
              style: const TextStyle(fontSize: 10, color: Colors.black87),
            ),
          ],
          if (notes.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              notes,
              style: TextStyle(fontSize: 10, fontStyle: FontStyle.italic, color: Colors.blueGrey.shade700),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildAdvisoryBanner() {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: Colors.amber.shade50,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: Colors.amber.shade300),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber_rounded, size: 18, color: Colors.amber.shade900),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'AI identification is advisory only. Ciguatera safety cannot be determined by AI. Human confirmation required.',
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Colors.amber.shade900),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildAiIdentificationSection() {
    if (_lastAiResult == null) return const SizedBox.shrink();

    final providers = _lastAiResult!['providers'] as Map<String, dynamic>?;

    // Dual-provider comparison view
    if (providers != null && providers.isNotEmpty) {
      final museData = providers['muse_glimmer'] as Map<String, dynamic>?;
      final openAiData = providers['openai'] as Map<String, dynamic>?;
      final comparisonStatus = (_lastAiResult!['comparison_status'] as String? ?? 'single_provider_result').toLowerCase();
      final comparison = _lastAiResult!['comparison'] as Map<String, dynamic>?;
      final isDisagreement = comparisonStatus == 'disagreement';

      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // 1. Comparison Status Banner
          _buildComparisonBanner(comparisonStatus),
          const SizedBox(height: 10),

          // Disagreement explicit prompt banner
          if (isDisagreement) ...[
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Colors.orange.shade50,
                borderRadius: BorderRadius.circular(6),
                border: Border.all(color: Colors.orange.shade300),
              ),
              child: Row(
                children: [
                  Icon(Icons.rule, color: Colors.orange.shade900, size: 20),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Models disagree — please verify with your own knowledge or select manually.',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                        color: Colors.orange.shade900,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 10),
          ],

          // 2. Dual Provider Outputs (Meta Muse Glimmer & OpenAI Vision)
          _buildProviderCard(
            museData,
            defaultName: 'Meta Muse Glimmer',
            providerIcon: Icons.auto_awesome,
            accentColor: Colors.teal.shade800,
          ),
          const SizedBox(height: 8),
          _buildProviderCard(
            openAiData,
            defaultName: 'OpenAI Vision',
            providerIcon: Icons.psychology,
            accentColor: Colors.indigo.shade800,
          ),
          const SizedBox(height: 10),

          // Render offline catalogue reference card if available
          if (_lastAiResult!['offline_catalogue'] != null) ...[
            _buildOfflineCatalogueCard(_lastAiResult!['offline_catalogue'] as Map<String, dynamic>),
            const SizedBox(height: 10),
          ],

          // 3. Comparison Details & Feature Matrix
          if (comparison != null) ...[
            _buildComparisonDetailsCard(comparison),
            const SizedBox(height: 10),
          ],

          // 4. SAM Segmentation note (if present)
          if (_lastAiResult!['segmentation'] != null) ...[
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4.0),
              child: Text(
                (_lastAiResult!['segmentation']['status'] == 'ok')
                    ? 'SAM 3.1 segmentation: ${_lastAiResult!['segmentation']['object_count'] ?? 0} fish object(s) segmented (evidence only — SAM does not name species).'
                    : 'SAM 3.1 segmentation: ${_lastAiResult!['segmentation']['status']} — no segmentation evidence.',
                style: const TextStyle(fontSize: 11, color: Colors.black87),
              ),
            ),
            const SizedBox(height: 8),
          ],

          // 5. Advisory Banner
          _buildAdvisoryBanner(),
          const SizedBox(height: 12),
        ],
      );
    }

    // Fallback: Offline Cache Species Match / Legacy Single-Model Display
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Card(
          elevation: 2,
          color: _lastAiResult!['mode'] == 'online_ai' ? Colors.teal.shade50 : Colors.amber.shade50,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(10),
            side: BorderSide(
              color: _lastAiResult!['mode'] == 'online_ai' ? Colors.teal.shade300 : Colors.amber.shade400,
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.all(14.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Row(
                      children: [
                        Icon(
                          _lastAiResult!['mode'] == 'online_ai' ? Icons.check_circle : Icons.inventory_2,
                          color: _lastAiResult!['mode'] == 'online_ai' ? Colors.teal.shade800 : Colors.amber.shade900,
                          size: 18,
                        ),
                        const SizedBox(width: 6),
                        Text(
                          _lastAiResult!['mode'] == 'online_ai'
                              ? 'AI Vision Result'
                              : 'Offline Cache Species Match',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            color: _lastAiResult!['mode'] == 'online_ai' ? Colors.teal.shade900 : Colors.amber.shade900,
                            fontSize: 14,
                          ),
                        ),
                      ],
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: _lastAiResult!['mode'] == 'online_ai' ? Colors.teal.shade700 : Colors.amber.shade800,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Text(
                        _lastAiResult!['mode'] == 'online_ai' ? 'ONLINE AI' : 'OFFLINE CACHE',
                        style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
                const Divider(height: 16),
                if (_lastAiResult!['primary'] != null) ...[
                  Row(
                    children: [
                      Text(
                        _lastAiResult!['primary']['kiribati_name'] ?? '',
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        '(${_lastAiResult!['primary']['english_name'] ?? _lastAiResult!['primary']['common_name'] ?? ''})',
                        style: const TextStyle(fontSize: 14, color: Colors.black87),
                      ),
                    ],
                  ),
                  Text(
                    _lastAiResult!['primary']['scientific_name'] ?? '',
                    style: const TextStyle(fontSize: 12, fontStyle: FontStyle.italic, color: Colors.black54),
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(
                          color: Colors.grey.shade200,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(
                          'Confidence: ${_lastAiResult!['primary']['confidence_percentage'] ?? "${((_lastAiResult!['primary']['confidence'] ?? 0) * 100).toStringAsFixed(1)}%"}',
                          style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                        ),
                      ),
                      const SizedBox(width: 8),
                      if (_lastAiResult!['primary']['ciguatera_risk'] != null)
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: _lastAiResult!['primary']['ciguatera_risk'] == 'high'
                                ? Colors.red.shade100
                                : (_lastAiResult!['primary']['ciguatera_risk'] == 'medium' ? Colors.orange.shade100 : Colors.green.shade100),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: Text(
                            'CFP Risk: ${_lastAiResult!['primary']['ciguatera_risk']?.toUpperCase()}',
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.bold,
                              color: _lastAiResult!['primary']['ciguatera_risk'] == 'high'
                                  ? Colors.red.shade900
                                  : (_lastAiResult!['primary']['ciguatera_risk'] == 'medium' ? Colors.orange.shade900 : Colors.green.shade900),
                            ),
                          ),
                        ),
                    ],
                  ),
                  if (_lastAiResult!['primary']['visual_features'] != null && (_lastAiResult!['primary']['visual_features'] as List).isNotEmpty) ...[
                    const SizedBox(height: 8),
                    const Text('Detected Morphological Traits:', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                    ...(_lastAiResult!['primary']['visual_features'] as List).take(2).map(
                          (f) => Padding(
                            padding: const EdgeInsets.only(top: 2),
                            child: Text('• $f', style: const TextStyle(fontSize: 11, color: Colors.black87)),
                          ),
                        ),
                  ],
                ],
                if (_lastAiResult!['disclaimer'] != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    _lastAiResult!['disclaimer'],
                    style: TextStyle(fontSize: 10, fontStyle: FontStyle.italic, color: Colors.blueGrey.shade800),
                  ),
                ],
              ],
            ),
          ),
        ),
        const SizedBox(height: 8),
        _buildAdvisoryBanner(),
        const SizedBox(height: 12),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Center(child: CircularProgressIndicator());
    }

    final isAirplane = widget.syncService.isAirplaneMode;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Capture & Log Catch (Offline)'),
        backgroundColor: Colors.teal.shade800,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: Icon(
              isAirplane ? Icons.airplanemode_active : Icons.wifi,
              color: isAirplane ? Colors.amberAccent : Colors.lightGreenAccent,
            ),
            tooltip: isAirplane ? 'Airplane Mode Active' : 'Connected',
            onPressed: null,
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // CAMERA / SPECIMEN CAPTURE VIEWFINDER
            Card(
              elevation: 3,
              clipBehavior: Clip.antiAlias,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Stack(
                    alignment: Alignment.bottomLeft,
                    children: [
                      _buildPhotoView(),
                      // Viewfinder Gradient & Badges
                      Container(
                        height: 70,
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            begin: Alignment.bottomCenter,
                            end: Alignment.topCenter,
                            colors: [Colors.black.withAlpha(200), Colors.transparent],
                          ),
                        ),
                      ),
                      Positioned(
                        left: 12,
                        bottom: 12,
                        child: Row(
                          children: [
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                              decoration: BoxDecoration(
                                color: Colors.black.withAlpha(160),
                                borderRadius: BorderRadius.circular(6),
                                border: Border.all(color: Colors.white24),
                              ),
                              child: Row(
                                children: [
                                  const Icon(Icons.camera_alt, color: Colors.white, size: 14),
                                  const SizedBox(width: 4),
                                  Text(
                                    _capturedPhoto != null
                                        ? _capturedPhoto!.fileName
                                        : _currentPhotoLocalPath.split('/').last,
                                    style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                      Positioned(
                        right: 12,
                        bottom: 12,
                        child: ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.teal.shade700,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                            textStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                          ),
                          icon: _isIdentifying
                              ? const SizedBox(
                                  width: 14,
                                  height: 14,
                                  child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                                )
                              : const Icon(Icons.auto_awesome, size: 16),
                          label: Text(_isIdentifying ? 'Analyzing...' : 'Identify with AI'),
                          onPressed: _isIdentifying ? null : _identifyFish,
                        ),
                      ),
                    ],
                  ),

                  // Blurry-photo warning with one-tap retake
                  if (_photoQuality != null && _photoQuality!.retakeRecommended)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      child: Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: Colors.orange.shade50,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: Colors.orange.shade300),
                        ),
                        child: Row(
                          children: [
                            Icon(Icons.blur_on, color: Colors.orange.shade800, size: 18),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                'This photo is blurry (sharpness ${_photoQuality!.blurScore?.toStringAsFixed(0) ?? '?'}). Identification may be unreliable.',
                                style: TextStyle(fontSize: 11, color: Colors.orange.shade900),
                              ),
                            ),
                            TextButton.icon(
                              onPressed: _isCapturing ? null : _takePhoto,
                              icon: const Icon(Icons.camera_alt, size: 16),
                              label: const Text('Retake'),
                            ),
                          ],
                        ),
                      ),
                    ),

                  // AI Vision Engine Selector
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    child: Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: Colors.teal.shade50.withOpacity(0.5),
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: Colors.teal.shade200),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Row(
                                children: [
                                  Icon(Icons.tune, size: 14, color: Colors.teal.shade800),
                                  const SizedBox(width: 4),
                                  const Text(
                                    'AI Vision Engine:',
                                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.black87),
                                  ),
                                ],
                              ),
                              Text(
                                _selectedAiProvider == 'compare'
                                    ? '⚖️ Both (Parallel)'
                                    : _selectedAiProvider == 'muse_glimmer'
                                        ? '🔮 Meta Muse Glimmer'
                                        : '⚡ OpenAI (GPT-4o)',
                                style: TextStyle(
                                  fontSize: 10,
                                  fontWeight: FontWeight.bold,
                                  color: _selectedAiProvider == 'compare'
                                      ? Colors.teal.shade900
                                      : _selectedAiProvider == 'muse_glimmer'
                                          ? Colors.purple.shade900
                                          : Colors.indigo.shade900,
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Row(
                            children: [
                              Expanded(
                                child: ChoiceChip(
                                  key: const Key('ai_provider_compare'),
                                  label: const FittedBox(
                                    fit: BoxFit.scaleDown,
                                    child: Text('⚖️ Both (Compare)', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                                  ),
                                  selected: _selectedAiProvider == 'compare',
                                  selectedColor: Colors.teal.shade200,
                                  onSelected: (selected) {
                                    if (selected) setState(() => _selectedAiProvider = 'compare');
                                  },
                                ),
                              ),
                              const SizedBox(width: 4),
                              Expanded(
                                child: ChoiceChip(
                                  key: const Key('ai_provider_meta'),
                                  label: const FittedBox(
                                    fit: BoxFit.scaleDown,
                                    child: Text('🔮 Meta Muse', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                                  ),
                                  selected: _selectedAiProvider == 'muse_glimmer',
                                  selectedColor: Colors.purple.shade100,
                                  onSelected: (selected) {
                                    if (selected) setState(() => _selectedAiProvider = 'muse_glimmer');
                                  },
                                ),
                              ),
                              const SizedBox(width: 4),
                              Expanded(
                                child: ChoiceChip(
                                  key: const Key('ai_provider_openai'),
                                  label: const FittedBox(
                                    fit: BoxFit.scaleDown,
                                    child: Text('⚡ OpenAI', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                                  ),
                                  selected: _selectedAiProvider == 'openai',
                                  selectedColor: Colors.indigo.shade100,
                                  onSelected: (selected) {
                                    if (selected) setState(() => _selectedAiProvider = 'openai');
                                  },
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),

                  // Specimen Presets Bar
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Select Caught Fish Specimen (or snap photo):',
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.black54),
                        ),
                        const SizedBox(height: 6),
                        SingleChildScrollView(
                          scrollDirection: Axis.horizontal,
                          child: Row(
                            children: _specimenPresets.map((preset) {
                              final isSelected = _currentPhotoUrl == preset['url'];
                              return Padding(
                                padding: const EdgeInsets.only(right: 6),
                                child: ChoiceChip(
                                  label: Text(preset['name']!),
                                  selected: isSelected,
                                  avatar: CircleAvatar(
                                    backgroundImage: NetworkImage(preset['url']!),
                                  ),
                                  selectedColor: Colors.teal.shade100,
                                  onSelected: (_) => _onSelectSpecimen(preset),
                                ),
                              );
                            }).toList(),
                          ),
                        ),
                        const SizedBox(height: 8),
                        // Device camera capture for fishermen
                        SizedBox(
                          width: double.infinity,
                          child: ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: Colors.teal.shade700,
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 12),
                              textStyle: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold),
                            ),
                            icon: _isCapturing
                                ? const SizedBox(
                                    width: 16,
                                    height: 16,
                                    child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                                  )
                                : const Icon(Icons.camera_alt, size: 20),
                            label: Text(_isCapturing ? 'Opening camera...' : 'Take Photo of Fish'),
                            onPressed: _isCapturing ? null : _takePhoto,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),

            const SizedBox(height: 12),

            // AI IDENTIFICATION RESULT CARD (IF ANALYZED)
            _buildAiIdentificationSection(),

            // CATCH REGISTRATION FORM
            Card(
              elevation: 2,
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        'Catch Log Details',
                        style: Theme.of(context).textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.bold,
                            ),
                      ),
                      const SizedBox(height: 12),

                      // Species Selection
                      DropdownButtonFormField<SpeciesItem>(
                        initialValue: _selectedSpecies,
                        decoration: const InputDecoration(
                          labelText: 'Select Fish Species (Kiribati Name)',
                          border: OutlineInputBorder(),
                        ),
                        items: _availableSpecies.map((sp) {
                          return DropdownMenuItem<SpeciesItem>(
                            value: sp,
                            child: Text('${sp.kiribatiName} (${sp.englishName})'),
                          );
                        }).toList(),
                        onChanged: (sp) {
                          setState(() {
                            _selectedSpecies = sp;
                          });
                        },
                      ),
                      const SizedBox(height: 12),

                      // Weight & Quantity Row
                      Row(
                        children: [
                          Expanded(
                            child: TextFormField(
                              controller: _weightController,
                              keyboardType: TextInputType.number,
                              decoration: const InputDecoration(
                                labelText: 'Weight (kg)',
                                border: OutlineInputBorder(),
                              ),
                              validator: (val) {
                                if (val == null || double.tryParse(val) == null) {
                                  return 'Enter valid kg';
                                }
                                return null;
                              },
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: TextFormField(
                              controller: _quantityController,
                              keyboardType: TextInputType.number,
                              decoration: const InputDecoration(
                                labelText: 'Quantity',
                                border: OutlineInputBorder(),
                              ),
                              validator: (val) {
                                if (val == null || int.tryParse(val) == null) {
                                  return 'Enter count';
                                }
                                return null;
                              },
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),

                      // GPS Consent Toggle
                      SwitchListTile(
                        title: const Text('Record Catch GPS Location'),
                        subtitle: Text(
                          _recordGps
                              ? '${_lat.toStringAsFixed(4)}° N, ${_lon.toStringAsFixed(4)}° E (±${_accuracy.toStringAsFixed(0)}m)'
                              : 'Location capture disabled',
                          style: const TextStyle(fontSize: 12),
                        ),
                        value: _recordGps,
                        onChanged: (val) {
                          setState(() {
                            _recordGps = val;
                          });
                        },
                      ),

                      const SizedBox(height: 8),

                      ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.teal.shade700,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                        ),
                        icon: const Icon(Icons.save_alt_rounded),
                        label: const Text('Save Catch Offline (SQLite)'),
                        onPressed: _saveCatchOffline,
                      ),
                    ],
                  ),
                ),
              ),
            ),

            const SizedBox(height: 16),

            // QUEUE STATUS HEADER
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Local Stored Catches (${_localCatches.length})',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.bold,
                      ),
                ),
                Text(
                  '${_localCatches.where((c) => c.syncStatus == "pending").length} Pending Sync',
                  style: TextStyle(
                    color: Colors.orange.shade800,
                    fontWeight: FontWeight.bold,
                    fontSize: 12,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),

            // CATCHES LIST
            _localCatches.isEmpty
                ? const Card(
                    child: Padding(
                      padding: EdgeInsets.all(24.0),
                      child: Center(child: Text('No catches logged yet.')),
                    ),
                  )
                : ListView.builder(
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    itemCount: _localCatches.length,
                    itemBuilder: (context, index) {
                      final c = _localCatches[index];
                      final isPending = c.syncStatus == 'pending';
                      final isAiIdentified = c.aiStatus == 'online_identified';
                      final isCachedMatch = c.aiStatus == 'cached_match';

                      return Card(
                        margin: const EdgeInsets.only(bottom: 8),
                        child: Padding(
                          padding: const EdgeInsets.all(12.0),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  // Specimen thumbnail
                                  ClipRRect(
                                    borderRadius: BorderRadius.circular(8),
                                    child: c.photoUrl != null
                                        ? Image.network(
                                            c.photoUrl!,
                                            width: 54,
                                            height: 54,
                                            fit: BoxFit.cover,
                                            errorBuilder: (context, error, stackTrace) => Container(
                                              width: 54,
                                              height: 54,
                                              color: Colors.grey.shade300,
                                              child: const Icon(Icons.photo, size: 28),
                                            ),
                                          )
                                        : Container(
                                            width: 54,
                                            height: 54,
                                            color: Colors.grey.shade300,
                                            child: const Icon(Icons.photo, size: 28),
                                          ),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Row(
                                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                          children: [
                                            Text(
                                              c.speciesNameRaw,
                                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                                            ),
                                            Text(
                                              '${c.weightKg.toStringAsFixed(1)} kg',
                                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                            ),
                                          ],
                                        ),
                                        const SizedBox(height: 2),
                                        Text(
                                          '${c.quantity} fish • Captured ${c.capturedAt.hour.toString().padLeft(2, '0')}:${c.capturedAt.minute.toString().padLeft(2, '0')}'
                                          '${c.latitude != null ? " • GPS (±10m)" : ""}',
                                          style: const TextStyle(fontSize: 11, color: Colors.black54),
                                        ),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                              const SizedBox(height: 8),
                              // Status Chips Row
                              Wrap(
                                spacing: 6,
                                runSpacing: 4,
                                children: [
                                  // Sync Status Badge
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                    decoration: BoxDecoration(
                                      color: isPending ? Colors.orange.shade50 : Colors.teal.shade50,
                                      borderRadius: BorderRadius.circular(10),
                                      border: Border.all(
                                        color: isPending ? Colors.orange.shade300 : Colors.teal.shade300,
                                      ),
                                    ),
                                    child: Row(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        Icon(
                                          isPending ? Icons.cloud_upload_outlined : Icons.cloud_done_rounded,
                                          size: 12,
                                          color: isPending ? Colors.orange.shade800 : Colors.teal.shade800,
                                        ),
                                        const SizedBox(width: 4),
                                        Text(
                                          isPending ? 'PENDING SYNC' : 'SYNCED',
                                          style: TextStyle(
                                            fontSize: 10,
                                            fontWeight: FontWeight.bold,
                                            color: isPending ? Colors.orange.shade800 : Colors.teal.shade800,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),

                                  // AI Identification Status Badge
                                  if (isAiIdentified) ...[
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                      decoration: BoxDecoration(
                                        color: Colors.teal.shade50,
                                        borderRadius: BorderRadius.circular(10),
                                        border: Border.all(color: Colors.teal.shade300),
                                      ),
                                      child: Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          const Icon(Icons.auto_awesome, size: 12, color: Colors.teal),
                                          const SizedBox(width: 4),
                                          Text(
                                            'AI: ${c.aiSpeciesSuggestion ?? c.speciesNameRaw}'
                                            '${c.aiConfidence != null ? " (${(c.aiConfidence! * 100).toStringAsFixed(0)}%)" : ""}',
                                            style: TextStyle(
                                              fontSize: 10,
                                              fontWeight: FontWeight.bold,
                                              color: Colors.teal.shade800,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                  ] else if (isCachedMatch) ...[
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                      decoration: BoxDecoration(
                                        color: Colors.amber.shade50,
                                        borderRadius: BorderRadius.circular(10),
                                        border: Border.all(color: Colors.amber.shade400),
                                      ),
                                      child: Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Icon(Icons.inventory_2_outlined, size: 12, color: Colors.amber.shade900),
                                          const SizedBox(width: 4),
                                          Text(
                                            'Cached: ${c.aiSpeciesSuggestion ?? c.speciesNameRaw} • AI Queued',
                                            style: TextStyle(
                                              fontSize: 10,
                                              fontWeight: FontWeight.bold,
                                              color: Colors.amber.shade900,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                  ] else ...[
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                      decoration: BoxDecoration(
                                        color: Colors.blueGrey.shade50,
                                        borderRadius: BorderRadius.circular(10),
                                        border: Border.all(color: Colors.blueGrey.shade300),
                                      ),
                                      child: Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Icon(Icons.hourglass_empty_rounded, size: 12, color: Colors.blueGrey.shade700),
                                          const SizedBox(width: 4),
                                          Text(
                                            'Waiting for Online AI Identification',
                                            style: TextStyle(
                                              fontSize: 10,
                                              fontWeight: FontWeight.bold,
                                              color: Colors.blueGrey.shade800,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                  ],

                                  // Ciguatera Risk Badge
                                  if (c.ciguateraRisk != null)
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
                                      decoration: BoxDecoration(
                                        color: c.ciguateraRisk == 'high'
                                            ? Colors.red.shade50
                                            : (c.ciguateraRisk == 'medium' ? Colors.orange.shade50 : Colors.green.shade50),
                                        borderRadius: BorderRadius.circular(10),
                                        border: Border.all(
                                          color: c.ciguateraRisk == 'high'
                                              ? Colors.red.shade300
                                              : (c.ciguateraRisk == 'medium' ? Colors.orange.shade300 : Colors.green.shade300),
                                        ),
                                      ),
                                      child: Text(
                                        'CFP: ${c.ciguateraRisk?.toUpperCase()}',
                                        style: TextStyle(
                                          fontSize: 9,
                                          fontWeight: FontWeight.bold,
                                          color: c.ciguateraRisk == 'high'
                                              ? Colors.red.shade900
                                              : (c.ciguateraRisk == 'medium' ? Colors.orange.shade900 : Colors.green.shade900),
                                        ),
                                      ),
                                    ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
          ],
        ),
      ),
    );
  }
}
