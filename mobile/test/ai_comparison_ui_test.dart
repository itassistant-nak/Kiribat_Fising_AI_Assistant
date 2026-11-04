import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:mobile/data/database_helper.dart';
import 'package:mobile/models/species.dart';
import 'package:mobile/services/sync/api_client.dart';
import 'package:mobile/services/sync/sync_service.dart';
import 'package:mobile/ui/screens/log_catch_screen.dart';

class TestHttpOverrides extends HttpOverrides {
  @override
  HttpClient createHttpClient(SecurityContext? context) {
    return _MockHttpClient();
  }
}

class _MockHttpClient implements HttpClient {
  @override
  Future<HttpClientRequest> getUrl(Uri url) async => _MockHttpClientRequest();

  @override
  Future<HttpClientRequest> postUrl(Uri url) async => _MockHttpClientRequest();

  @override
  Future<HttpClientRequest> openUrl(String method, Uri url) async => _MockHttpClientRequest();

  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class _MockHttpClientRequest implements HttpClientRequest {
  @override
  final HttpHeaders headers = _MockHttpHeaders();

  @override
  Future<HttpClientResponse> close() async => _MockHttpClientResponse();

  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class _MockHttpHeaders implements HttpHeaders {
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class _MockHttpClientResponse implements HttpClientResponse {
  @override
  int get statusCode => 200;

  @override
  int get contentLength => kTransparentImage.length;

  @override
  HttpClientResponseCompressionState get compressionState =>
      HttpClientResponseCompressionState.notCompressed;

  @override
  StreamSubscription<List<int>> listen(
    void Function(List<int> event)? onData, {
    Function? onError,
    void Function()? onDone,
    bool? cancelOnError,
  }) {
    return Stream<List<int>>.fromIterable([kTransparentImage]).listen(
      onData,
      onError: onError,
      onDone: onDone,
      cancelOnError: cancelOnError,
    );
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

final kTransparentImage = <int>[
  0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, 0x00, 0x00, 0x00, 0x0D, 0x49,
  0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01, 0x08, 0x06,
  0x00, 0x00, 0x00, 0x1F, 0x15, 0xC4, 0x89, 0x00, 0x00, 0x00, 0x0A, 0x49, 0x44,
  0x41, 0x54, 0x78, 0x9C, 0x63, 0x00, 0x01, 0x00, 0x00, 0x05, 0x00, 0x01, 0x0D,
  0x0A, 0x2D, 0xB4, 0x00, 0x00, 0x00, 0x00, 0x49, 0x45, 0x4E, 0x44, 0xAE, 0x42,
  0x60, 0x82,
];

class MockComparisonApiClient extends ApiClient {
  Map<String, dynamic>? mockIdentifyResponse;
  String? lastProvider;

  @override
  Future<bool> checkHealth() async => true;

  @override
  Future<Map<String, dynamic>> identifyFish({
    String? imageUrl,
    String? hint,
    String? token,
    bool compare = true,
    String? provider,
  }) async {
    lastProvider = provider;
    return mockIdentifyResponse ?? {};
  }

  @override
  Future<List<SpeciesItem>> fetchSpecies({String? token}) async {
    return [
      const SpeciesItem(
        id: 1,
        kiribatiName: 'Te Bwere',
        englishName: 'Paddletail Snapper',
        scientificName: 'Lutjanus gibbus',
        ciguateraRisk: 'high',
        photoUrl: '',
        visualFeatures: ['Paddle-shaped caudal fin', 'Deep red body'],
        aiTrained: true,
        cachedAt: '2026-11-03',
      ),
      const SpeciesItem(
        id: 2,
        kiribatiName: 'Te Okaoka',
        englishName: 'White-spotted puffer',
        scientificName: 'Arothron hispidus',
        ciguateraRisk: 'high',
        photoUrl: '',
        visualFeatures: ['White spots', 'Robust body'],
        aiTrained: true,
        cachedAt: '2026-11-03',
      ),
    ];
  }
}

const sampleSpecies = [
  SpeciesItem(
    id: 1,
    kiribatiName: 'Te Bwere',
    englishName: 'Paddletail Snapper',
    scientificName: 'Lutjanus gibbus',
    ciguateraRisk: 'high',
    photoUrl: '',
    visualFeatures: ['Paddle-shaped caudal fin', 'Deep red body'],
    aiTrained: true,
    cachedAt: '2026-11-03',
  ),
  SpeciesItem(
    id: 2,
    kiribatiName: 'Te Okaoka',
    englishName: 'White-spotted puffer',
    scientificName: 'Arothron hispidus',
    ciguateraRisk: 'high',
    photoUrl: '',
    visualFeatures: ['White spots', 'Robust body'],
    aiTrained: true,
    cachedAt: '2026-11-03',
  ),
];

void main() {
  setUpAll(() {
    HttpOverrides.global = TestHttpOverrides();
  });

  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  late Database db;
  late DatabaseHelper dbHelper;
  late MockComparisonApiClient mockApi;
  late SyncService syncService;

  setUp(() async {
    dbHelper = DatabaseHelper.instance;
    db = await openDatabase(
      inMemoryDatabasePath,
      version: 1,
      onCreate: dbHelper.createDbForTesting,
    );
    dbHelper.setDatabaseForTesting(db);

    await dbHelper.saveSpeciesCatalog(sampleSpecies);

    mockApi = MockComparisonApiClient();
    syncService = SyncService(db: dbHelper, api: mockApi);
  });

  tearDown(() async {
    dbHelper.setDatabaseForTesting(null);
    await db.close();
  });

  testWidgets('Parallel AI Comparison renders AGREEMENT status and dual provider cards', (tester) async {
    mockApi.mockIdentifyResponse = {
      'status': 'success',
      'comparison_status': 'agreement',
      'providers': {
        'muse_glimmer': {
          'provider': 'muse_glimmer',
          'display_name': 'Meta Muse Glimmer',
          'status': 'success',
          'latency_ms': 195,
          'scientific_name': 'Lutjanus gibbus',
          'common_name': 'Paddletail Snapper',
          'kiribati_name': 'Te Bwere',
          'confidence': 0.94,
          'confidence_percentage': '94.0%',
          'ciguatera_risk': 'high',
          'visible_traits': ['Paddle-shaped caudal fin', 'Pink-red body'],
          'alternatives': [],
        },
        'openai': {
          'provider': 'openai',
          'display_name': 'OpenAI Vision',
          'status': 'success',
          'latency_ms': 320,
          'scientific_name': 'Lutjanus gibbus',
          'common_name': 'Paddletail Snapper',
          'kiribati_name': 'Te Bwere',
          'confidence': 0.91,
          'confidence_percentage': '91.0%',
          'ciguatera_risk': 'high',
          'visible_traits': ['Deep reddish-pink coloration', 'Forked paddle tail'],
          'alternatives': ['Lutjanus bohar'],
        },
      },
      'comparison': {
        'scientific_name_match': true,
        'genus_match': true,
        'status': 'agreement',
        'notes': 'Both providers agree on Lutjanus gibbus.',
        'shared_traits': ['Paddle tail', 'Red body'],
      },
      'primary': {
        'kiribati_name': 'Te Bwere',
        'english_name': 'Paddletail Snapper',
        'scientific_name': 'Lutjanus gibbus',
        'confidence': 0.94,
        'confidence_percentage': '94.0%',
        'ciguatera_risk': 'high',
      },
      'disclaimer': 'AI cannot determine fish toxicity from photographs.',
    };

    await tester.pumpWidget(
      MaterialApp(
        home: LogCatchScreen(
          syncService: syncService,
          db: dbHelper,
          initialSpecies: sampleSpecies,
        ),
      ),
    );
    await tester.pump();

    // Trigger AI identification button
    final identifyButton = find.text('Identify with AI');
    expect(identifyButton, findsOneWidget);
    await tester.tap(identifyButton);
    await tester.runAsync(() async {
      await Future.delayed(const Duration(milliseconds: 100));
    });
    await tester.pump();

    // Verify Agreement Banner
    expect(find.text('AGREEMENT'), findsOneWidget);
    expect(find.text('Both Meta Muse Glimmer and OpenAI Vision identified the same species.'), findsOneWidget);

    // Verify Meta Muse Glimmer card
    expect(find.text('Meta Muse Glimmer'), findsOneWidget);
    expect(find.text('195ms'), findsOneWidget);
    expect(find.text('Confidence: 94.0%'), findsOneWidget);

    // Verify OpenAI Vision card
    expect(find.text('OpenAI Vision'), findsOneWidget);
    expect(find.text('320ms'), findsOneWidget);
    expect(find.text('Confidence: 91.0%'), findsOneWidget);

    // Verify Comparison Details & Feature Matrix
    expect(find.text('Comparison Details & Feature Matrix'), findsOneWidget);
    expect(find.text('Species Match: YES'), findsOneWidget);

    // Verify Advisory Banner
    expect(
      find.text('AI identification is advisory only. Ciguatera safety cannot be determined by AI. Human confirmation required.'),
      findsOneWidget,
    );
  });

  testWidgets('Parallel AI Comparison handles DISAGREEMENT and allows user selection', (tester) async {
    mockApi.mockIdentifyResponse = {
      'status': 'success',
      'comparison_status': 'disagreement',
      'providers': {
        'muse_glimmer': {
          'provider': 'muse_glimmer',
          'display_name': 'Meta Muse Glimmer',
          'status': 'success',
          'latency_ms': 180,
          'scientific_name': 'Lutjanus gibbus',
          'common_name': 'Paddletail Snapper',
          'kiribati_name': 'Te Bwere',
          'confidence': 0.88,
          'confidence_percentage': '88.0%',
          'ciguatera_risk': 'high',
          'visible_traits': ['Red body', 'Paddle tail'],
          'alternatives': [],
        },
        'openai': {
          'provider': 'openai',
          'display_name': 'OpenAI Vision',
          'status': 'success',
          'latency_ms': 290,
          'scientific_name': 'Arothron hispidus',
          'common_name': 'White-spotted puffer',
          'kiribati_name': 'Te Okaoka',
          'confidence': 0.82,
          'confidence_percentage': '82.0%',
          'ciguatera_risk': 'high',
          'visible_traits': ['White spots', 'Robust body'],
          'alternatives': [],
        },
      },
      'comparison': {
        'scientific_name_match': false,
        'genus_match': false,
        'status': 'disagreement',
        'notes': 'Providers suggest different species: Lutjanus gibbus vs Arothron hispidus.',
        'shared_traits': [],
      },
      'primary': {
        'kiribati_name': 'Te Bwere',
        'english_name': 'Paddletail Snapper',
        'scientific_name': 'Lutjanus gibbus',
        'confidence': 0.88,
        'confidence_percentage': '88.0%',
        'ciguatera_risk': 'high',
      },
      'disclaimer': 'AI cannot determine fish toxicity from photographs.',
    };

    await tester.pumpWidget(
      MaterialApp(
        home: LogCatchScreen(
          syncService: syncService,
          db: dbHelper,
          initialSpecies: sampleSpecies,
        ),
      ),
    );
    await tester.pump();

    // Trigger AI identification
    await tester.tap(find.text('Identify with AI'));
    await tester.runAsync(() async {
      await Future.delayed(const Duration(milliseconds: 100));
    });
    await tester.pump();

    // Verify Disagreement Banner & warning prompt
    expect(find.text('DISAGREEMENT'), findsOneWidget);
    expect(
      find.text('Models disagree — please verify with your own knowledge or select manually.'),
      findsNWidgets(2), // Banner subtitle + highlighted warning prompt
    );
    expect(find.text('Species Match: NO'), findsOneWidget);

    // Verify user can tap "Use OpenAI Vision"
    final useOpenAiBtn = find.text('Use OpenAI Vision');
    expect(useOpenAiBtn, findsOneWidget);
    await tester.ensureVisible(useOpenAiBtn);
    await tester.pump();
    await tester.tap(useOpenAiBtn);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 750));

    // Verify SnackBar confirmed selection
    expect(find.text('Selected: OpenAI Vision suggestion (Te Okaoka)'), findsOneWidget);
  });

  testWidgets('Fisher can select AI vision provider (Meta Muse / OpenAI / Both) before identifying', (tester) async {
    mockApi.mockIdentifyResponse = {
      'status': 'success',
      'mode': 'online_ai',
      'comparison_status': 'single_provider_result',
      'primary': {
        'kiribati_name': 'Te Bwere',
        'english_name': 'Paddletail Snapper',
        'scientific_name': 'Lutjanus gibbus',
        'confidence': 0.90,
      },
      'disclaimer': 'AI cannot determine fish toxicity from photographs.',
    };

    await tester.pumpWidget(
      MaterialApp(
        home: LogCatchScreen(
          syncService: syncService,
          db: dbHelper,
          initialSpecies: sampleSpecies,
        ),
      ),
    );
    await tester.pump();

    // Verify AI Vision Engine selector chips exist
    expect(find.byKey(const Key('ai_provider_compare')), findsOneWidget);
    expect(find.byKey(const Key('ai_provider_meta')), findsOneWidget);
    expect(find.byKey(const Key('ai_provider_openai')), findsOneWidget);

    // Default is 'compare'
    expect(find.text('⚖️ Both (Parallel)'), findsOneWidget);

    // Tap Meta Muse chip
    await tester.tap(find.byKey(const Key('ai_provider_meta')));
    await tester.pump();
    expect(find.text('🔮 Meta Muse Glimmer'), findsOneWidget);

    // Tap Identify with AI
    await tester.tap(find.text('Identify with AI'));
    await tester.runAsync(() async {
      await Future.delayed(const Duration(milliseconds: 100));
    });
    await tester.pump();

    // Verify mock received 'muse_glimmer' provider
    expect(mockApi.lastProvider, equals('muse_glimmer'));

    // Tap OpenAI chip
    await tester.tap(find.byKey(const Key('ai_provider_openai')));
    await tester.pump();
    expect(find.text('⚡ OpenAI (GPT-4o)'), findsOneWidget);

    // Tap Identify with AI again
    await tester.tap(find.text('Identify with AI'));
    await tester.runAsync(() async {
      await Future.delayed(const Duration(milliseconds: 100));
    });
    await tester.pump();

    // Verify mock received 'openai' provider
    expect(mockApi.lastProvider, equals('openai'));
  });
}
