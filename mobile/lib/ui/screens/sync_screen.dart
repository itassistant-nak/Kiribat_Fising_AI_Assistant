import 'package:flutter/material.dart';
import '../../services/sync/sync_service.dart';

class SyncScreen extends StatefulWidget {
  final SyncService syncService;

  const SyncScreen({super.key, required this.syncService});

  @override
  State<SyncScreen> createState() => _SyncScreenState();
}

class _SyncScreenState extends State<SyncScreen> {
  String? _syncMessage;
  bool _lastSuccess = true;

  @override
  void initState() {
    super.initState();
    widget.syncService.addListener(_onServiceUpdate);
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

  Future<void> _handleSync() async {
    final result = await widget.syncService.performFullSync();
    if (mounted) {
      setState(() {
        _syncMessage = result.message;
        _lastSuccess = result.success;
      });

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(result.message),
          backgroundColor: result.success ? Colors.teal.shade700 : Colors.red.shade700,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final sync = widget.syncService;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Offline Sync & Airplane Mode'),
        backgroundColor: Colors.indigo.shade800,
        foregroundColor: Colors.white,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Airplane Mode Switch Card
            Card(
              elevation: 3,
              color: sync.isAirplaneMode ? Colors.blueGrey.shade900 : Colors.indigo.shade50,
              child: SwitchListTile(
                secondary: Icon(
                  Icons.airplanemode_active_rounded,
                  size: 32,
                  color: sync.isAirplaneMode ? Colors.amber : Colors.indigo,
                ),
                title: Text(
                  'Airplane Mode (Force Offline)',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    color: sync.isAirplaneMode ? Colors.white : Colors.black87,
                  ),
                ),
                subtitle: Text(
                  sync.isAirplaneMode
                      ? 'Network disabled. App operating entirely from SQLite cache.'
                      : 'Network active. Can synchronize with Laravel API.',
                  style: TextStyle(
                    color: sync.isAirplaneMode ? Colors.white70 : Colors.black54,
                    fontSize: 12,
                  ),
                ),
                value: sync.isAirplaneMode,
                onChanged: (val) {
                  sync.setAirplaneMode(val);
                },
              ),
            ),

            const SizedBox(height: 16),

            // Sync Status & Stale Cache Metrics Card
            Card(
              elevation: 2,
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Cache Freshness & Sync Status',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.bold,
                          ),
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Icon(
                          sync.isCacheStale ? Icons.warning_amber_rounded : Icons.check_circle_rounded,
                          color: sync.isCacheStale ? Colors.amber.shade900 : Colors.teal,
                        ),
                        const SizedBox(width: 8),
                        Text(
                          sync.isCacheStale ? 'Cache Status: STALE (> 7 days)' : 'Cache Status: FRESH',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            color: sync.isCacheStale ? Colors.amber.shade900 : Colors.teal,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Last Synchronized: ${sync.lastSyncTime != null ? sync.lastSyncTime!.toLocal().toString().split(".")[0] : "Never (using seeded offline cache)"}',
                      style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
                    ),
                    const Divider(height: 24),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      children: [
                        _buildMetricColumn(
                          '${sync.pendingCatchesCount}',
                          'Pending Catches',
                          sync.pendingCatchesCount > 0 ? Colors.orange.shade800 : Colors.teal,
                        ),
                        _buildMetricColumn(
                          '${sync.pendingTripsCount}',
                          'Pending Trips',
                          sync.pendingTripsCount > 0 ? Colors.orange.shade800 : Colors.teal,
                        ),
                        _buildMetricColumn(
                          sync.isAirplaneMode ? 'OFFLINE' : 'ONLINE',
                          'Network State',
                          sync.isAirplaneMode ? Colors.grey : Colors.indigo,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 16),

            // Sync Button
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.indigo.shade700,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 16),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
              icon: sync.isSyncing
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                    )
                  : const Icon(Icons.sync_rounded),
              label: Text(
                sync.isSyncing ? 'Synchronizing...' : 'Synchronize Now (Advisories, Catches & Trips)',
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
              onPressed: sync.isSyncing ? null : _handleSync,
            ),

            const SizedBox(height: 16),

            // Sync Output Message Card
            if (_syncMessage != null)
              Card(
                color: _lastSuccess ? Colors.teal.shade50 : Colors.red.shade50,
                child: Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Row(
                    children: [
                      Icon(
                        _lastSuccess ? Icons.check_circle_outline : Icons.error_outline,
                        color: _lastSuccess ? Colors.teal.shade800 : Colors.red.shade800,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          _syncMessage!,
                          style: TextStyle(
                            color: _lastSuccess ? Colors.teal.shade900 : Colors.red.shade900,
                            fontSize: 13,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildMetricColumn(String value, String label, Color color) {
    return Column(
      children: [
        Text(
          value,
          style: TextStyle(
            fontSize: 22,
            fontWeight: FontWeight.bold,
            color: color,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          label,
          style: const TextStyle(fontSize: 12, color: Colors.grey),
        ),
      ],
    );
  }
}
