import 'package:timezone/timezone.dart' as tz;

/// Event times, always in the event's own zone.
///
/// A Lagos event at 10pm says 10pm to somebody reading in Toronto, because that
/// is when to turn up. Showing it in the reader's zone would be technically
/// accurate and practically useless.
String eventTimeIn(DateTime utc, String timeZone, {bool long = false}) {
  final tz.Location location;

  try {
    location = tz.getLocation(timeZone);
  } catch (_) {
    // An unknown zone is wrong by hours; showing nothing is wrong by
    // everything. UTC, and the zone name is displayed beside it anyway.
    return _format(utc, long: long);
  }

  return _format(tz.TZDateTime.from(utc, location), long: long);
}

String _format(DateTime at, {required bool long}) {
  const months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];
  const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  final hour12 = at.hour % 12 == 0 ? 12 : at.hour % 12;
  final minute = at.minute.toString().padLeft(2, '0');
  final period = at.hour < 12 ? 'am' : 'pm';
  final weekday = days[at.weekday - 1];
  final month = months[at.month - 1];

  return long
      ? '$weekday ${at.day} $month ${at.year}, $hour12:$minute$period'
      : '$weekday ${at.day} $month, $hour12:$minute$period';
}
