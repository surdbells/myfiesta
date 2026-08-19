import 'package:flutter/material.dart';
import 'package:timezone/data/latest_all.dart' as tzdata;

import 'core/api.dart';
import 'core/session.dart';
import 'design/theme.dart';
import 'design/tokens.dart';
import 'features/attendee/my_tickets_screen.dart';
import 'features/door/door_screen.dart';
import 'features/organizer/organizer_screen.dart';

void main() {
  // Event times are rendered in the event's own zone, which needs the IANA
  // database loaded before anything draws.
  tzdata.initializeTimeZones();

  runApp(const MyFiestaApp());
}

/// One app, three modes.
///
/// Attendee, organizer and door ship in a single binary under the existing
/// bundle id, so the store listing, its reviews and the forced-update gate all
/// carry over rather than needing every user to find and install something new.
///
/// Which mode a session gets is decided by the scope the server granted, not by
/// a build flavour and not by anything the app asks for. The app hides what a
/// scope should not see; the API refuses it. Only the second is a boundary.
class MyFiestaApp extends StatefulWidget {
  const MyFiestaApp({super.key});

  @override
  State<MyFiestaApp> createState() => _MyFiestaAppState();
}

class _MyFiestaAppState extends State<MyFiestaApp> {
  final Api _api = Api();
  Session? _session;

  void _start(Session session) {
    _api.token = session.token;
    setState(() => _session = session);
  }

  Future<void> _signOut() async {
    await _api.signOut();
    _api.token = null;
    setState(() => _session = null);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'myFiesta',
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      home: _session == null ? _SignIn(api: _api, onSignedIn: _start) : _routeFor(_session!),
    );
  }

  Widget _routeFor(Session session) {
    if (session.isLocked) {
      return DoorScreen(session: session, onSignOut: _signOut);
    }

    if (session.canSeeSales) {
      return OrganizerScreen(
        api: _api,
        organizationName: session.organizationName,
        onSignOut: _signOut,
      );
    }

    return MyTicketsScreen(api: _api, onSignOut: _signOut);
  }
}

class _SignIn extends StatefulWidget {
  const _SignIn({required this.api, required this.onSignedIn});

  final Api api;
  final ValueChanged<Session> onSignedIn;

  @override
  State<_SignIn> createState() => _SignInState();
}

class _SignInState extends State<_SignIn> {
  final _email = TextEditingController();
  final _password = TextEditingController();

  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final session = await widget.api.signIn(_email.text.trim(), _password.text);
      widget.onSignedIn(session);
    } on ApiException catch (e) {
      // ApiException has already decided what is safe to show: a 4xx message
      // is written for the reader, a 5xx one is not repeated.
      if (mounted) {
        setState(() {
          _busy = false;
          _error = e.message;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(Tokens.space6),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('myFiesta', style: theme.textTheme.displaySmall),
                const SizedBox(height: Tokens.space6),

                if (_error != null) ...[
                  Container(
                    padding: const EdgeInsets.all(Tokens.space3),
                    decoration: BoxDecoration(
                      color: Tokens.colorSemanticDanger.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(Tokens.radiusMd),
                    ),
                    child: Text(_error!, style: TextStyle(color: Tokens.colorSemanticDanger)),
                  ),
                  const SizedBox(height: Tokens.space4),
                ],

                TextField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  autocorrect: false,
                  decoration: const InputDecoration(labelText: 'Email'),
                ),
                const SizedBox(height: Tokens.space3),

                TextField(
                  controller: _password,
                  obscureText: true,
                  onSubmitted: (_) => _submit(),
                  decoration: const InputDecoration(labelText: 'Password'),
                ),
                const SizedBox(height: Tokens.space5),

                FilledButton(
                  onPressed: _busy ? null : _submit,
                  child: Text(_busy ? 'Signing in…' : 'Sign in'),
                ),

                const SizedBox(height: Tokens.space4),
                Text(
                  'Buying a ticket needs no account. Sign in to see tickets you already hold.',
                  style: theme.textTheme.bodySmall,
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
