/// What the signed-in token is allowed to do.
///
/// One app carries attendee, organizer, and door interfaces, so a door-staff
/// phone has the organizer screens compiled into it — hidden, but present.
/// Hiding them is a convenience for the person holding the phone; it is not the
/// security boundary. The API decides, by refusing anything a `door:{event}`
/// token asks for beyond scanning.
///
/// This class exists so the app never has to guess what it may show.
enum TokenScope { attendee, organizer, door }

class Session {
  const Session({
    required this.scope,
    required this.displayName,
    this.token,
    this.email,
    this.eventId,
    this.eventTitle,
    this.organizationName,
  });

  final TokenScope scope;
  final String displayName;
  final String? token;
  final String? email;

  /// Set only for door scope. A door token is issued for one event and expires
  /// after it, so there is no such thing as a door session in general.
  final String? eventId;
  final String? eventTitle;

  final String? organizationName;

  /// Door mode is a locked state, not a tab.
  ///
  /// Once signed in with a door token there is no route back to organizer
  /// screens without signing out. Venue staff are often handed a phone for one
  /// night; a stray back-swipe should not put sales figures in front of them.
  bool get isLocked => scope == TokenScope.door;

  bool get canSeeSales => scope == TokenScope.organizer;

  bool get canScan => scope == TokenScope.door || scope == TokenScope.organizer;

  /// Organizers running their own door get a quick-scan action rather than the
  /// full offline scanner. They already hold full access, so there is no
  /// boundary to protect — only convenience.
  bool get hasQuickScanOnly => scope == TokenScope.organizer;

  /// Built from what the server granted, never from what the app asked for.
  factory Session.fromJson(Map<String, dynamic> json) {
    final abilities = (json['abilities'] as List? ?? const []).cast<String>();
    final user = json['user'] as Map<String, dynamic>? ?? const {};
    final organizations = json['organizations'] as List? ?? const [];

    final first = organizations.isEmpty
        ? null
        : organizations.first as Map<String, dynamic>;

    return Session(
      // Organizer is the wider grant, so it wins when both are present.
      scope: abilities.contains('organizer') ? TokenScope.organizer : TokenScope.attendee,
      displayName: user['name'] as String? ?? 'You',
      email: user['email'] as String?,
      token: json['token'] as String?,
      organizationName: first?['name'] as String?,
    );
  }
}
