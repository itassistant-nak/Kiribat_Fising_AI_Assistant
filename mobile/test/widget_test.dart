import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/services/sync/sync_service.dart';
import 'package:mobile/ui/screens/sync_screen.dart';

void main() {
  testWidgets('SyncScreen renders offline airplane mode controls', (WidgetTester tester) async {
    final syncService = SyncService();

    await tester.pumpWidget(
      MaterialApp(
        home: SyncScreen(syncService: syncService),
      ),
    );
    await tester.pump();

    expect(find.text('Offline Sync & Airplane Mode'), findsOneWidget);
    expect(find.text('Airplane Mode (Force Offline)'), findsOneWidget);
    expect(find.text('Cache Freshness & Sync Status'), findsOneWidget);
  });
}
