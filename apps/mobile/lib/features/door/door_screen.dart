import 'package:flutter/material.dart';

import '../../core/session.dart';
import '../../design/tokens.dart';

/// Door mode: the scanner, and nothing else.
///
/// Deliberately the first screen built. It is the smallest well-scoped surface
/// in the app, which makes it the right place to learn Flutter — the expensive
/// way would be to learn it on checkout, where a mistake costs money.
///
/// The screen has no navigation away from itself. Sign-out is the only exit,
/// because the person holding this phone is often venue staff hired for the
/// night rather than someone entitled to see the rest of the app.
class DoorScreen extends StatefulWidget {
  const DoorScreen({super.key, required this.session, required this.onSignOut});

  final Session session;
  final VoidCallback onSignOut;

  @override
  State<DoorScreen> createState() => _DoorScreenState();
}

class _DoorScreenState extends State<DoorScreen> {
  final List<_ScanOutcome> _recent = <_ScanOutcome>[];
  int _admitted = 0;

  /// Stands in for the camera during the spike.
  ///
  /// The real implementation reads a code and posts it; every result including
  /// a rejection is recorded server-side, because a scanner that only logs
  /// successes cannot answer what happened at a contested door.
  void _simulateScan(_ScanResult result) {
    setState(() {
      _recent.insert(0, _ScanOutcome(result: result, at: DateTime.now()));
      if (result == _ScanResult.accepted) _admitted++;
      if (_recent.length > 8) _recent.removeLast();
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return PopScope(
      // No accidental exit. There is nowhere else in the app for this session
      // to go, so swiping back must not look like it might work.
      canPop: false,
      child: Scaffold(
        appBar: AppBar(
          automaticallyImplyLeading: false,
          title: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Door'),
              Text(
                widget.session.eventTitle ?? 'Event',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: widget.onSignOut,
              child: const Text('Sign out'),
            ),
          ],
        ),
        body: Padding(
          padding: const EdgeInsets.all(Tokens.space4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(Tokens.space5),
                  child: Column(
                    children: [
                      Text('$_admitted', style: theme.textTheme.displaySmall),
                      Text('admitted', style: theme.textTheme.bodyMedium),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: Tokens.space4),
              FilledButton.icon(
                onPressed: () => _simulateScan(_ScanResult.accepted),
                icon: const Icon(Icons.qr_code_scanner),
                label: const Text('Scan ticket'),
              ),
              const SizedBox(height: Tokens.space2),
              // Present in the spike so the rejection paths are visible from the
              // start rather than bolted on once the happy path feels finished.
              OverflowBar(
                alignment: MainAxisAlignment.center,
                children: [
                  TextButton(
                    onPressed: () => _simulateScan(_ScanResult.duplicate),
                    child: const Text('Simulate duplicate'),
                  ),
                  TextButton(
                    onPressed: () => _simulateScan(_ScanResult.notFound),
                    child: const Text('Simulate unknown'),
                  ),
                ],
              ),
              const SizedBox(height: Tokens.space4),
              Text('Recent scans', style: theme.textTheme.titleSmall),
              const SizedBox(height: Tokens.space2),
              Expanded(
                child: _recent.isEmpty
                    ? Center(
                        child: Text(
                          'Nothing scanned yet',
                          style: theme.textTheme.bodyMedium,
                        ),
                      )
                    : ListView.separated(
                        itemCount: _recent.length,
                        separatorBuilder: (_, _) => const Divider(height: 1),
                        itemBuilder: (context, i) => _ScanTile(outcome: _recent[i]),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

enum _ScanResult { accepted, duplicate, notFound }

class _ScanOutcome {
  const _ScanOutcome({required this.result, required this.at});

  final _ScanResult result;
  final DateTime at;
}

class _ScanTile extends StatelessWidget {
  const _ScanTile({required this.outcome});

  final _ScanOutcome outcome;

  @override
  Widget build(BuildContext context) {
    // Colour alone is not the signal. A door is dark, loud, and rushed, so the
    // icon and the wording have to carry the outcome too.
    final (Color colour, IconData icon, String label) = switch (outcome.result) {
      _ScanResult.accepted => (Tokens.colorSemanticSuccess, Icons.check_circle, 'Admitted'),
      _ScanResult.duplicate => (Tokens.colorSemanticWarning, Icons.error, 'Already scanned'),
      _ScanResult.notFound => (Tokens.colorSemanticDanger, Icons.cancel, 'Not recognised'),
    };

    return ListTile(
      leading: Icon(icon, color: colour),
      title: Text(label),
      trailing: Text(
        TimeOfDay.fromDateTime(outcome.at).format(context),
        style: Theme.of(context).textTheme.bodySmall,
      ),
    );
  }
}
