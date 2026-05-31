import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:yawote_app/main.dart';

void main() {
  testWidgets('app builds', (WidgetTester tester) async {
    await tester.pumpWidget(const YawoteApp());
    await tester.pump();
    // SplashScreen waits up to 5s then navigates; advance fake clock so no timers remain.
    await tester.pump(const Duration(seconds: 6));
    await tester.pump();
    expect(find.byType(MaterialApp), findsOneWidget);
  });
}
