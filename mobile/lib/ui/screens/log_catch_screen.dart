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

  const LogCatchScreen({super.key, required this.syncService, this.captureService});

  @override
  State<LogCatchScreen> createState() => _LogCatchScreenState();
}

class _LogCatchScreenState extends State<LogCatchScreen> {
  final DatabaseHelper _db = DatabaseHelper.instance;
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
    _currentPhotoUrl = _specimenPresets.first['url']!;
    _loadData();
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
    final catches = await _db.getLocalCatches();
    if (mounted) {
      setState(() {
        _localCatches = catches;
      });
    }
  }

  Future<void> _loadData() async {
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
      );

      if (mounted) {
        setState(() {
          _lastAiResult = res;
          final primary = res['primary'] as Map<String, dynamic>?;

          if (primary != null) {
            final kiribatiName = primary['kiribati_name'] as String? ?? 'Unknown';
            final englishName = primary['english_name'] as String? ?? 'Fish';
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

            // Sync dropdown selection with AI match
            final matchingSpecies = _availableSpecies.cast<SpeciesItem?>().firstWhere(
                  (sp) => sp?.kiribatiName.toLowerCase() == kiribatiName.toLowerCase(),
                  orElse: () => null,
                );
            if (matchingSpecies != null) {
              _selectedSpecies = matchingSpecies;
            }
          } else {
            _aiStatus = 'waiting_for_online_ai';
            _aiSpeciesSuggestion = 'Pending Online AI Analysis';
          }
          _isIdentifying = false;
        });

        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              res['mode'] == 'online_ai'
                  ? '✨ Meta AI Vision: Identified ${_aiSpeciesSuggestion ?? "Fish"} (${((_aiConfidence ?? 0) * 100).toStringAsFixed(1)}%)'
                  : '📦 Offline Cache: Matched ${_aiSpeciesSuggestion ?? "Specimen"}. Online AI analysis queued.',
            ),
            backgroundColor: res['mode'] == 'online_ai' ? Colors.teal.shade800 : Colors.amber.shade900,
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

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _aiStatus == 'online_identified'
                ? 'Catch ($speciesName, ${weight}kg) saved offline with verified AI identification!'
                : 'Catch saved offline! Queued for Meta AI identification when online.',
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
            if (_lastAiResult != null) ...[
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
                                    ? 'Meta AI Vision Result'
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
                              _lastAiResult!['mode'] == 'online_ai' ? 'ONLINE (Meta AI)' : 'OFFLINE CACHE',
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
                              '(${_lastAiResult!['primary']['english_name'] ?? ''})',
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
                      if (_lastAiResult!['primary'] != null && _lastAiResult!['primary']['verification_status'] != null) ...[
                        const SizedBox(height: 8),
                        Text(
                          _lastAiResult!['primary']['verification_status'] == 'verified'
                              ? 'Reference: verified record — please confirm or correct the species below.'
                              : 'Reference: needs review — please confirm or correct the species below.',
                          style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                        ),
                      ],
                      if (_lastAiResult!['segmentation'] != null) ...[
                        const SizedBox(height: 8),
                        Text(
                          (_lastAiResult!['segmentation']['status'] == 'ok')
                              ? 'SAM 3.1 segmentation: ${_lastAiResult!['segmentation']['object_count'] ?? 0} fish object(s) segmented (evidence only — SAM does not name species).'
                              : 'SAM 3.1 segmentation: ${_lastAiResult!['segmentation']['status']} — no segmentation evidence.',
                          style: const TextStyle(fontSize: 11, color: Colors.black87),
                        ),
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
              const SizedBox(height: 12),
            ],

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
