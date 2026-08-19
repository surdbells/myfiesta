import 'package:flutter/material.dart';

import '../../core/api.dart';
import '../../core/event_time.dart';
import '../../design/tokens.dart';

/// The organizer's phone view.
///
/// Deliberately a companion rather than a second console. Event creation,
/// ticket configuration and payout forms are desk work and stay on the web;
/// what a phone is good at is the answer to "how is tonight going", checked
/// standing near the door.
class OrganizerScreen extends StatefulWidget {
  const OrganizerScreen({
    super.key,
    required this.api,
    required this.organizationName,
    required this.onSignOut,
  });

  final Api api;
  final String? organizationName;
  final VoidCallback onSignOut;

  @override
  State<OrganizerScreen> createState() => _OrganizerScreenState();
}

class _OrganizerScreenState extends State<OrganizerScreen> {
  late Future<List<OrganizerEvent>> _events;

  @override
  void initState() {
    super.initState();
    _events = widget.api.events();
  }

  Future<void> _refresh() async {
    final reloaded = widget.api.events();
    setState(() => _events = reloaded);
    await reloaded;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Events'),
            if (widget.organizationName != null)
              Text(widget.organizationName!, style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
        actions: [
          TextButton(onPressed: widget.onSignOut, child: const Text('Sign out')),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<List<OrganizerEvent>>(
          future: _events,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }

            if (snapshot.hasError) {
              return _Message(text: snapshot.error.toString(), onRetry: _refresh);
            }

            final all = snapshot.data ?? const [];
            final now = DateTime.now().toUtc();

            // Upcoming first: that is what needs attention. Past events are
            // records, and scrolling past history to reach the work is wrong.
            final upcoming = all.where((e) => e.startsAt.isAfter(now)).toList();
            final past = all.where((e) => !e.startsAt.isAfter(now)).toList();

            if (all.isEmpty) {
              return const _Message(
                text: 'No events yet. Create one on the web console, then manage the night here.',
              );
            }

            return ListView(
              padding: const EdgeInsets.all(Tokens.space4),
              children: [
                if (upcoming.isNotEmpty) ...[
                  const _SectionLabel('Upcoming'),
                  ...upcoming.map((e) => _EventCard(api: widget.api, event: e)),
                ],
                if (past.isNotEmpty) ...[
                  const SizedBox(height: Tokens.space5),
                  const _SectionLabel('Past'),
                  ...past.map((e) => _EventCard(api: widget.api, event: e, dimmed: true)),
                ],
              ],
            );
          },
        ),
      ),
    );
  }
}

class _EventCard extends StatelessWidget {
  const _EventCard({required this.api, required this.event, this.dimmed = false});

  final Api api;
  final OrganizerEvent event;
  final bool dimmed;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rate = event.arrivalRate;

    return Opacity(
      opacity: dimmed ? 0.65 : 1,
      child: Card(
        margin: const EdgeInsets.only(bottom: Tokens.space3),
        child: Padding(
          padding: const EdgeInsets.all(Tokens.space4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(child: Text(event.title, style: theme.textTheme.titleMedium)),
                  if (event.status != 'published') _StatusChip(status: event.status),
                ],
              ),
              const SizedBox(height: Tokens.space1),
              Text(
                '${eventTimeIn(event.startsAt, event.timezone)} · ${event.city}',
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(height: Tokens.space3),

              // The number an organizer actually opens their phone for.
              Row(
                children: [
                  _Stat(label: 'Issued', value: '${event.ticketsIssued}'),
                  const SizedBox(width: Tokens.space5),
                  _Stat(label: 'Arrived', value: '${event.checkedIn}'),
                  if (rate != null) ...[
                    const SizedBox(width: Tokens.space5),
                    _Stat(label: 'Rate', value: '$rate%'),
                  ],
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(value, style: theme.textTheme.titleLarge),
        Text(label, style: theme.textTheme.bodySmall),
      ],
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: Tokens.space2, vertical: 2),
      decoration: BoxDecoration(
        color: Tokens.colorNeutral100,
        borderRadius: BorderRadius.circular(Tokens.radiusFull),
      ),
      child: Text(status.toUpperCase(), style: Theme.of(context).textTheme.labelSmall),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: Tokens.space2),
      child: Text(
        text.toUpperCase(),
        style: Theme.of(context).textTheme.labelMedium?.copyWith(letterSpacing: 1),
      ),
    );
  }
}

class _Message extends StatelessWidget {
  const _Message({required this.text, this.onRetry});

  final String text;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(Tokens.space6),
      children: [
        const SizedBox(height: Tokens.space8),
        Text(text, textAlign: TextAlign.center),
        if (onRetry != null) ...[
          const SizedBox(height: Tokens.space4),
          Center(child: FilledButton(onPressed: onRetry, child: const Text('Try again'))),
        ],
      ],
    );
  }
}
