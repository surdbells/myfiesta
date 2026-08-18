import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:myfiesta/core/session.dart';
import 'package:myfiesta/design/tokens.dart';
import 'package:myfiesta/features/door/door_screen.dart';
import 'package:myfiesta/main.dart';

void main() {
  group('token scope decides what the app offers', () {
    test('a door session is locked and cannot see sales', () {
      const session = Session(
        scope: TokenScope.door,
        displayName: 'Door staff',
        eventId: 'evt_1',
      );

      expect(session.isLocked, isTrue);
      expect(session.canScan, isTrue);
      // The reason door mode exists as its own scope: venue staff hired for one
      // night must not be able to read takings.
      expect(session.canSeeSales, isFalse);
    });

    test('an organizer can scan without being locked into it', () {
      const session = Session(scope: TokenScope.organizer, displayName: 'Ada');

      expect(session.canSeeSales, isTrue);
      expect(session.canScan, isTrue, reason: 'Organizers often work their own door.');
      expect(session.isLocked, isFalse);
      expect(session.hasQuickScanOnly, isTrue);
    });

    test('an attendee can neither scan nor see sales', () {
      const session = Session(scope: TokenScope.attendee, displayName: 'Buyer');

      expect(session.canScan, isFalse);
      expect(session.canSeeSales, isFalse);
    });
  });

  group('door screen', () {
    Widget wrap(Widget child) => MaterialApp(home: child);

    testWidgets('opens with nothing admitted', (tester) async {
      await tester.pumpWidget(wrap(DoorScreen(
        session: const Session(
          scope: TokenScope.door,
          displayName: 'Door',
          eventId: 'e',
          eventTitle: 'Afro Fest',
        ),
        onSignOut: () {},
      )));

      expect(find.text('Afro Fest'), findsOneWidget);
      expect(find.text('0'), findsOneWidget);
      expect(find.text('Nothing scanned yet'), findsOneWidget);
    });

    testWidgets('a successful scan increments the count', (tester) async {
      await tester.pumpWidget(wrap(DoorScreen(
        session: const Session(scope: TokenScope.door, displayName: 'Door', eventId: 'e'),
        onSignOut: () {},
      )));

      await tester.tap(find.text('Scan ticket'));
      await tester.pump();

      expect(find.text('1'), findsOneWidget);
      expect(find.text('Admitted'), findsOneWidget);
    });

    testWidgets('a duplicate is reported and does not admit anyone', (tester) async {
      await tester.pumpWidget(wrap(DoorScreen(
        session: const Session(scope: TokenScope.door, displayName: 'Door', eventId: 'e'),
        onSignOut: () {},
      )));

      await tester.tap(find.text('Simulate duplicate'));
      await tester.pump();

      expect(find.text('Already scanned'), findsOneWidget);
      // The count must not move. A door that quietly admits a second person on
      // the same ticket is the failure this screen exists to prevent.
      expect(find.text('0'), findsOneWidget);
    });

    testWidgets('door mode offers no way back into the app', (tester) async {
      await tester.pumpWidget(wrap(DoorScreen(
        session: const Session(scope: TokenScope.door, displayName: 'Door', eventId: 'e'),
        onSignOut: () {},
      )));

      // Sign-out is the only exit, and the back gesture is refused, because a
      // stray swipe should not put organizer screens in front of venue staff.
      expect(find.text('Sign out'), findsOneWidget);
      expect(find.byType(BackButton), findsNothing);

      final pop = tester.widget<PopScope>(find.byType(PopScope));
      expect(pop.canPop, isFalse);
    });
  });

  group('design tokens', () {
    test('reach Flutter from the shared source', () {
      // Generated from packages/tokens/tokens.json, which also emits the CSS
      // the Angular apps use. If this drifts, the two surfaces stop looking
      // like one product.
      expect(Tokens.colorBrand500, const Color(0xFF4C5FD7));
      expect(Tokens.space4, 16.0);
      expect(Tokens.radiusMd, 8.0);
    });
  });

  testWidgets('the app boots to the mode picker', (tester) async {
    await tester.pumpWidget(const MyFiestaApp());

    expect(find.text('myFiesta'), findsOneWidget);
    expect(find.text('Door'), findsOneWidget);
  });
}
