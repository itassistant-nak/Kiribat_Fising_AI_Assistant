import 'package:flutter/material.dart';
import 'data/database_helper.dart';
import 'services/sync/sync_service.dart';
import 'ui/screens/radar_screen.dart';
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
    _screens = [
      RadarScreen(syncService: widget.syncService),
      const SpeciesCatalogScreen(),
      LogCatchScreen(syncService: widget.syncService),
      TripScreen(syncService: widget.syncService),
      SyncScreen(syncService: widget.syncService),
    ];
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: _screens,
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
