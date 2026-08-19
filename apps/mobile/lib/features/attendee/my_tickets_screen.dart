import 'package:flutter/material.dart';

import '../../core/api.dart';
import '../../core/event_time.dart';
import '../../design/tokens.dart';

/// The tickets somebody is holding.
///
/// The screen a person opens while standing in a queue, so the one they need
/// next is at the top and the code is large enough to be read off a cracked
/// screen in the dark.
class MyTicketsScreen extends StatefulWidget {
  const MyTicketsScreen({super.key, required this.api, required this.onSignOut});

  final Api api;
  final VoidCallback onSignOut;

  @override
  State<MyTicketsScreen> createState() => _MyTicketsScreenState();
}

class _MyTicketsScreenState extends State<MyTicketsScreen> {
  late Future<List<MyTicket>> _tickets;

  @override
  void initState() {
    super.initState();
    _tickets = widget.api.myTickets();
  }

  Future<void> _refresh() async {
    final reloaded = widget.api.myTickets();
    setState(() => _tickets = reloaded);
    await reloaded;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('My tickets'),
        actions: [
          TextButton(onPressed: widget.onSignOut, child: const Text('Sign out')),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<List<MyTicket>>(
          future: _tickets,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }

            if (snapshot.hasError) {
              return _Message(
                text: snapshot.error.toString(),
                // Always scrollable, or pull-to-refresh has nothing to grab and
                // the only recovery is force-quitting the app.
                onRetry: _refresh,
              );
            }

            final tickets = snapshot.data ?? const [];

            if (tickets.isEmpty) {
              return const _Message(text: 'Nothing here yet. Tickets you buy will show up here.');
            }

            return ListView.separated(
              padding: const EdgeInsets.all(Tokens.space4),
              itemCount: tickets.length,
              separatorBuilder: (_, _) => const SizedBox(height: Tokens.space3),
              itemBuilder: (context, i) => _TicketCard(ticket: tickets[i]),
            );
          },
        ),
      ),
    );
  }
}

class _TicketCard extends StatelessWidget {
  const _TicketCard({required this.ticket});

  final MyTicket ticket;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(Tokens.space5),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(ticket.eventTitle, style: theme.textTheme.titleLarge),
            const SizedBox(height: Tokens.space1),
            Text(
              // The event's own zone. Turning up on time depends on it.
              '${eventTimeIn(ticket.startsAt, ticket.timezone, long: true)} · ${ticket.city}',
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: Tokens.space4),

            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: Tokens.space4),
              decoration: BoxDecoration(
                color: ticket.used
                    ? Tokens.colorNeutral100
                    : Tokens.colorBrand50,
                borderRadius: BorderRadius.circular(Tokens.radiusMd),
              ),
              child: Column(
                children: [
                  Text(
                    ticket.code,
                    style: theme.textTheme.headlineSmall?.copyWith(
                      // Read off a cracked screen, in the dark, by someone in a
                      // hurry. Wide letter spacing is worth the room.
                      letterSpacing: 2,
                      fontWeight: FontWeight.w700,
                      color: ticket.used ? Tokens.colorNeutral400 : Tokens.colorBrand700,
                    ),
                  ),
                  if (ticket.ticketType != null) ...[
                    const SizedBox(height: Tokens.space1),
                    Text(ticket.ticketType!, style: theme.textTheme.bodySmall),
                  ],
                ],
              ),
            ),

            if (ticket.used) ...[
              const SizedBox(height: Tokens.space2),
              Row(
                children: [
                  const Icon(Icons.check_circle, size: 16, color: Tokens.colorSemanticSuccess),
                  const SizedBox(width: Tokens.space1),
                  // Said plainly. Somebody looking at a used ticket is usually
                  // checking whether they already went in.
                  Text('Already scanned', style: theme.textTheme.bodySmall),
                ],
              ),
            ],
          ],
        ),
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
      // Scrollable even when short, so pull-to-refresh still works on an empty
      // or failed screen.
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
