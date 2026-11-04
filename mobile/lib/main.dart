import 'package:flutter/material.dart';
import 'data/database_helper.dart';
import 'services/sync/sync_service.dart';
import 'ui/screens/radar_screen.dart';
import 'ui/screens/live_map_screen.dart';
import 'ui/screens/species_catalog_screen.dart';
import 'ui/screens/log_catch_screen.dart';
import 'ui/screens/trip_screen.dart';
import 'ui/screens/sync_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  DatabaseHelper.initializeFfi();

  final syncService = SyncService();
  await syncService.seedInitialOfflineDataIfEmpty();
  syncService.startAutoSync();

  // Initialize live backend connection asynchronously on startup
  syncService.initializeLiveConnection().catchError((e) {
    debugPrint('Initial live backend connection: $e');
  });

  runApp(KiribatiFisheriesApp(syncService: syncService));
}

class KiribatiFisheriesApp extends StatelessWidget {
  final SyncService syncService;

  const KiribatiFisheriesApp({super.key, required this.syncService});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Kiribati Fisheries Assistant',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF007A87),
          primary: const Color(0xFF007A87),
        ),
        useMaterial3: true,
      ),
      home: MainNavigationScaffold(syncService: syncService),
    );
  }
}

class MainNavigationScaffold extends StatefulWidget {
  final SyncService syncService;

  const MainNavigationScaffold({super.key, required this.syncService});

  @override
  State<MainNavigationScaffold> createState() => _MainNavigationScaffoldState();
}

class _MainNavigationScaffoldState extends State<MainNavigationScaffold> {
  int _currentIndex = 0;

  late final List<Widget> _screens;

  @override
  void initState() {
    super.initState();
    widget.syncService.addListener(_onServiceUpdate);
    _screens = [
      RadarScreen(syncService: widget.syncService),
      LiveMapScreen(syncService: widget.syncService),
      SpeciesCatalogScreen(syncService: widget.syncService),
      LogCatchScreen(syncService: widget.syncService),
      TripScreen(syncService: widget.syncService),
      SyncScreen(syncService: widget.syncService),
    ];
  }

  @override
  void dispose() {
    widget.syncService.removeListener(_onServiceUpdate);
    super.dispose();
  }

  void _onServiceUpdate() {
    if (mounted) {
      setState(() {});
    }
  }

  Widget _buildLiveConnectionBanner(SyncService sync) {
    final isOnline = sync.isOnline;
    final isAirplane = sync.isAirplaneMode;
    final Color bgColor;
    final Color textColor;
    final IconData icon;
    final String text;

    if (isAirplane) {
      bgColor = Colors.blueGrey.shade900;
      textColor = Colors.amber.shade300;
      icon = Icons.airplanemode_active_rounded;
      text = 'Airplane Mode Active • Offline SQLite Cache';
    } else if (isOnline) {
      bgColor = const Color(0xFF0F766E);
      textColor = Colors.white;
      icon = Icons.cloud_done_rounded;
      text = 'Backend Online • ${sync.baseUrl} • ${sync.authenticatedUserName ?? "Teititeki (Fisher)"}';
    } else {
      bgColor = Colors.amber.shade800;
      textColor = Colors.white;
      icon = Icons.cloud_off_rounded;
      text = 'Connecting to Backend (${sync.baseUrl})... Tap to test';
    }

    return InkWell(
      onTap: () => _showConnectionStatusModal(context, sync),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        color: bgColor,
        child: Row(
          children: [
            Icon(icon, size: 16, color: textColor),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                text,
                style: TextStyle(
                  color: textColor,
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                ),
                overflow: TextOverflow.ellipsis,
              ),
            ),
            if (!isAirplane && isOnline)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Text(
                  'LIVE',
                  style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                ),
              ),
            if (!isOnline && !isAirplane)
              InkWell(
                onTap: () => sync.checkConnection(),
                child: const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 4),
                  child: Icon(Icons.refresh_rounded, size: 16, color: Colors.white),
                ),
              ),
          ],
        ),
      ),
    );
  }

  void _showConnectionStatusModal(BuildContext context, SyncService sync) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) {
          return Padding(
            padding: EdgeInsets.only(
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
              top: 20,
              left: 20,
              right: 20,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Row(
                      children: [
                        Icon(
                          sync.isOnline ? Icons.cloud_done_rounded : Icons.cloud_off_rounded,
                          color: sync.isOnline ? Colors.teal : Colors.amber.shade900,
                        ),
                        const SizedBox(width: 8),
                        Text(
                          'Backend Connection',
                          style: Theme.of(ctx).textTheme.titleMedium?.copyWith(
                                fontWeight: FontWeight.bold,
                              ),
                        ),
                      ],
                    ),
                    IconButton(
                      icon: const Icon(Icons.close),
                      onPressed: () => Navigator.pop(ctx),
                    ),
                  ],
                ),
                const Divider(),
                ListTile(
                  dense: true,
                  leading: const Icon(Icons.dns_rounded),
                  title: const Text('API Host URL'),
                  subtitle: Text(sync.baseUrl),
                ),
                ListTile(
                  dense: true,
                  leading: const Icon(Icons.account_circle_rounded),
                  title: const Text('Authenticated Fisher'),
                  subtitle: Text(
                    sync.isAuthenticated
                        ? '${sync.authenticatedUserName ?? "Artisanal Fisher"} (${sync.authenticatedUserEmail ?? "teititeki@fisher.ki"})'
                        : 'Not logged in',
                  ),
                ),
                ListTile(
                  dense: true,
                  leading: const Icon(Icons.storage_rounded),
                  title: const Text('Database Stack'),
                  subtitle: const Text('MySQL 8.0 (kiribati_fisheries) + Client SQLite'),
                ),
                ListTile(
                  dense: true,
                  leading: const Icon(Icons.pending_actions_rounded),
                  title: const Text('Pending Sync Work'),
                  subtitle: Text(
                    '${sync.pendingCatchesCount} pending catches, ${sync.pendingTripsCount} pending trips',
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: ElevatedButton.icon(
                        icon: const Icon(Icons.refresh_rounded),
                        label: const Text('Test Connection'),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.teal.shade700,
                          foregroundColor: Colors.white,
                        ),
                        onPressed: () async {
                          final ok = await sync.checkConnection();
                          if (ctx.mounted) {
                            setModalState(() {});
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                content: Text(ok
                                    ? '🟢 Connected to Laravel API (${sync.baseUrl})!'
                                    : '🔴 Cannot reach backend at ${sync.baseUrl}'),
                                backgroundColor: ok ? Colors.teal.shade800 : Colors.red.shade800,
                              ),
                            );
                          }
                        },
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton.icon(
                        icon: const Icon(Icons.sync_rounded),
                        label: const Text('Sync Now'),
                        onPressed: () async {
                          Navigator.pop(ctx);
                          final res = await sync.performFullSync();
                          if (context.mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                content: Text(res.message),
                                backgroundColor: res.success ? Colors.teal.shade800 : Colors.red.shade800,
                              ),
                            );
                          }
                        },
                      ),
                    ),
                  ],
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            _buildLiveConnectionBanner(widget.syncService),
            Expanded(
              child: IndexedStack(
                index: _currentIndex,
                children: _screens,
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _currentIndex,
        onDestinationSelected: (idx) {
          setState(() {
            _currentIndex = idx;
          });
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.radar_rounded),
            selectedIcon: Icon(Icons.radar_rounded, color: Colors.teal),
            label: 'Radar & Alerts',
          ),
          NavigationDestination(
            icon: Icon(Icons.map_rounded),
            selectedIcon: Icon(Icons.map_rounded, color: Color(0xFF007A87)),
            label: 'Live GPS Map',
          ),
          NavigationDestination(
            icon: Icon(Icons.phishing_rounded),
            selectedIcon: Icon(Icons.phishing_rounded, color: Colors.blueGrey),
            label: 'Species Catalog',
          ),
          NavigationDestination(
            icon: Icon(Icons.add_circle_outline_rounded),
            selectedIcon: Icon(Icons.add_circle_rounded, color: Colors.teal),
            label: 'Log Catch',
          ),
          NavigationDestination(
            icon: Icon(Icons.route_rounded),
            selectedIcon: Icon(Icons.route_rounded, color: Colors.teal),
            label: 'Trips',
          ),
          NavigationDestination(
            icon: Icon(Icons.sync_rounded),
            selectedIcon: Icon(Icons.sync_rounded, color: Colors.indigo),
            label: 'Sync & Offline',
          ),
        ],
      ),
    );
  }
}
