import 'package:flutter/material.dart';

import 'core/session.dart';
import 'design/theme.dart';
import 'features/door/door_screen.dart';

void main() => runApp(const MyFiestaApp());

/// One app, three modes.
///
/// Attendee, organizer, and door ship in a single binary under the existing
/// bundle id, so the store listing, its reviews, and the forced-update gate all
/// carry over rather than needing every user to find and install something new.
///
/// Which mode a session gets is decided by the scope on its token, not by a
/// build flavour. The app hides what a scope should not see; the API refuses it.
/// Only the second of those is a security boundary.
class MyFiestaApp extends StatefulWidget {
  const MyFiestaApp({super.key});

  @override
  State<MyFiestaApp> createState() => _MyFiestaAppState();
}

class _MyFiestaAppState extends State<MyFiestaApp> {
  Session? _session;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'myFiesta',
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      home: _session == null
          ? _ModePicker(onPick: (s) => setState(() => _session = s))
          : _routeFor(_session!),
    );
  }

  Widget _routeFor(Session session) {
    if (session.isLocked) {
      return DoorScreen(
        session: session,
        onSignOut: () => setState(() => _session = null),
      );
    }

    return _NotYetBuilt(
      session: session,
      onSignOut: () => setState(() => _session = null),
    );
  }
}

/// Stands in for sign-in during the spike.
///
/// In the real app a door session is entered by redeeming a per-event invite
/// from an organizer, never by sharing organizer credentials — which is how the
/// platform this replaces ended up with venue staff holding the owner's login.
class _ModePicker extends StatelessWidget {
  const _ModePicker({required this.onPick});

  final ValueChanged<Session> onPick;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('myFiesta', style: Theme.of(context).textTheme.displaySmall),
              const SizedBox(height: 8),
              Text(
                'Spike build — choose a token scope',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              const SizedBox(height: 32),
              FilledButton(
                onPressed: () => onPick(const Session(
                  scope: TokenScope.door,
                  displayName: 'Door staff',
                  eventId: 'evt_demo',
                  eventTitle: 'Afro Fest — Lagos',
                )),
                child: const Text('Door'),
              ),
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: () => onPick(const Session(
                  scope: TokenScope.organizer,
                  displayName: 'Organizer',
                )),
                child: const Text('Organizer'),
              ),
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: () => onPick(const Session(
                  scope: TokenScope.attendee,
                  displayName: 'Attendee',
                )),
                child: const Text('Attendee'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NotYetBuilt extends StatelessWidget {
  const _NotYetBuilt({required this.session, required this.onSignOut});

  final Session session;
  final VoidCallback onSignOut;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(session.displayName),
        actions: [
          TextButton(onPressed: onSignOut, child: const Text('Sign out')),
        ],
      ),
      body: const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'Door mode was built first, on purpose: it is the smallest '
            'well-scoped surface in the app, and learning Flutter on the '
            'checkout flow would be the expensive way round.',
            textAlign: TextAlign.center,
          ),
        ),
      ),
    );
  }
}
