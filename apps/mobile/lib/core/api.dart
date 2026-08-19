import 'dart:convert';

import 'package:http/http.dart' as http;

import 'money.dart';
import 'session.dart';

/// Everything the app knows about the server.
///
/// The base URL is supplied at build time with --dart-define, so one codebase
/// serves staging and production without a value compiled into the source that
/// nobody can see from the artifact.
const apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://10.0.2.2:8000',
);

class ApiException implements Exception {
  ApiException(this.message, this.statusCode);

  final String message;
  final int statusCode;

  @override
  String toString() => message;
}

class Api {
  Api({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

  /// The token, when there is one. Set on sign-in, cleared on 401.
  String? token;

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) async {
    final uri = Uri.parse('$apiBaseUrl$path').replace(queryParameters: query);

    final request = http.Request(method, uri)
      ..headers['Accept'] = 'application/json';

    if (token != null) {
      request.headers['Authorization'] = 'Bearer $token';
    }

    if (body != null) {
      request.headers['Content-Type'] = 'application/json';
      request.body = jsonEncode(body);
    }

    final http.Response response;

    try {
      response = await http.Response.fromStream(
        await _client.send(request).timeout(const Duration(seconds: 20)),
      );
    } catch (_) {
      // A door has bad wifi more often than it has a working connection, so
      // this is an expected state rather than an exceptional one.
      throw ApiException('No connection. Check signal and try again.', 0);
    }

    final decoded = response.body.isEmpty
        ? <String, dynamic>{}
        : jsonDecode(response.body) as Map<String, dynamic>;

    if (response.statusCode >= 400) {
      throw ApiException(_messageFor(response.statusCode, decoded), response.statusCode);
    }

    return decoded;
  }

  /// The same rule the web clients use, drawn at 500.
  ///
  /// A 4xx message is written for whoever made the request. A 5xx message is
  /// written for us, and passing it through is how a database error or a
  /// payment provider complaint about our API key ends up in front of somebody
  /// standing at a door.
  String _messageFor(int status, Map<String, dynamic> body) {
    if (status >= 500) {
      return 'Something went wrong at our end. Nothing you did caused it.';
    }

    final message = body['message'];

    if (message is String && message.trim().isNotEmpty) {
      return message;
    }

    final errors = body['errors'];

    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;

      if (first is List && first.isNotEmpty) {
        return first.first.toString();
      }
    }

    return 'That did not work.';
  }

  // --- auth --------------------------------------------------------------

  Future<Session> signIn(String email, String password) async {
    final body = await _send('POST', '/api/auth/login', body: {
      'email': email,
      'password': password,
      'device': 'mobile',
    });

    return Session.fromJson(body);
  }

  Future<void> signOut() async {
    try {
      await _send('POST', '/api/auth/logout');
    } on ApiException {
      // The local session is cleared regardless. A network failure must not
      // leave somebody signed in on a phone they are handing back.
    }
  }

  // --- attendee ----------------------------------------------------------

  Future<List<MyTicket>> myTickets() async {
    final body = await _send('GET', '/api/me/tickets');

    return (body['data'] as List)
        .map((json) => MyTicket.fromJson(json as Map<String, dynamic>))
        .toList();
  }

  Future<void> transferTicket(String ticketId, String email, String name) async {
    await _send('POST', '/api/tickets/$ticketId/transfer', body: {
      'email': email,
      'name': name,
    });
  }

  // --- organizer ---------------------------------------------------------

  Future<List<OrganizerEvent>> events() async {
    final body = await _send('GET', '/api/organizer/events');

    return (body['data'] as List)
        .map((json) => OrganizerEvent.fromJson(json as Map<String, dynamic>))
        .toList();
  }

  Future<EventTotals> summary(String eventId) async {
    final body = await _send('GET', '/api/organizer/events/$eventId/summary');

    return EventTotals.fromJson(body);
  }

  Future<List<GuestEntry>> guests(String eventId, {String? search}) async {
    final body = await _send(
      'GET',
      '/api/organizer/events/$eventId/guests',
      query: search != null && search.isNotEmpty ? {'q': search} : null,
    );

    return (body['data'] as List)
        .map((json) => GuestEntry.fromJson(json as Map<String, dynamic>))
        .toList();
  }

  // --- door --------------------------------------------------------------

  Future<ScanResponse> scan(String eventId, String code) async {
    final body = await _send('POST', '/api/events/$eventId/scan', body: {'code': code});

    return ScanResponse.fromJson(body);
  }
}

// --- shapes ---------------------------------------------------------------

class MyTicket {
  MyTicket({
    required this.id,
    required this.code,
    required this.status,
    required this.eventTitle,
    required this.startsAt,
    required this.timezone,
    required this.city,
    this.ticketType,
  });

  final String id;
  final String code;
  final String status;
  final String eventTitle;
  final DateTime startsAt;
  final String timezone;
  final String city;
  final String? ticketType;

  bool get used => status == 'checked_in';

  factory MyTicket.fromJson(Map<String, dynamic> json) {
    final event = json['event'] as Map<String, dynamic>? ?? const {};

    return MyTicket(
      id: json['id'] as String,
      code: json['code'] as String,
      status: json['status'] as String,
      ticketType: json['type'] as String?,
      eventTitle: event['title'] as String? ?? 'Event',
      startsAt: DateTime.parse(event['starts_at'] as String).toUtc(),
      timezone: event['timezone'] as String? ?? 'UTC',
      city: event['city'] as String? ?? '',
    );
  }
}

class OrganizerEvent {
  OrganizerEvent({
    required this.id,
    required this.title,
    required this.status,
    required this.startsAt,
    required this.timezone,
    required this.city,
    required this.ticketsIssued,
    required this.checkedIn,
  });

  final String id;
  final String title;
  final String status;
  final DateTime startsAt;
  final String timezone;
  final String city;
  final int ticketsIssued;
  final int checkedIn;

  /// Null rather than a confident zero when nothing has sold.
  int? get arrivalRate =>
      ticketsIssued == 0 ? null : ((checkedIn / ticketsIssued) * 100).round();

  factory OrganizerEvent.fromJson(Map<String, dynamic> json) => OrganizerEvent(
        id: json['id'] as String,
        title: json['title'] as String,
        status: json['status'] as String,
        startsAt: DateTime.parse(json['starts_at'] as String).toUtc(),
        timezone: json['timezone'] as String? ?? 'UTC',
        city: json['city'] as String? ?? '',
        ticketsIssued: json['tickets_issued'] as int? ?? 0,
        checkedIn: json['checked_in'] as int? ?? 0,
      );
}

class EventTotals {
  EventTotals({
    required this.gross,
    required this.net,
    required this.orders,
    required this.ticketsIssued,
    required this.checkedIn,
  });

  final Money gross;
  final Money net;
  final int orders;
  final int ticketsIssued;
  final int checkedIn;

  factory EventTotals.fromJson(Map<String, dynamic> json) => EventTotals(
        gross: Money.fromJson(json['gross'] as Map<String, dynamic>),
        net: Money.fromJson(json['net'] as Map<String, dynamic>),
        orders: json['orders'] as int? ?? 0,
        ticketsIssued: json['tickets_issued'] as int? ?? 0,
        checkedIn: json['checked_in'] as int? ?? 0,
      );
}

class GuestEntry {
  GuestEntry({required this.name, required this.email, required this.checkedIn, this.ticketType});

  final String name;
  final String email;
  final bool checkedIn;
  final String? ticketType;

  factory GuestEntry.fromJson(Map<String, dynamic> json) => GuestEntry(
        name: json['name'] as String? ?? '',
        email: json['email'] as String? ?? '',
        checkedIn: json['checked_in'] as bool? ?? false,
        ticketType: json['ticket_type'] as String?,
      );
}

class ScanResponse {
  ScanResponse({required this.result, required this.admitted, required this.message, this.holderName});

  final String result;
  final bool admitted;
  final String message;
  final String? holderName;

  factory ScanResponse.fromJson(Map<String, dynamic> json) => ScanResponse(
        result: json['result'] as String,
        admitted: json['admitted'] as bool? ?? false,
        message: json['message'] as String? ?? '',
        holderName: (json['ticket'] as Map<String, dynamic>?)?['holder_name'] as String?,
      );
}
