import 'package:intl/intl.dart';

/// An amount and its currency, never one without the other.
///
/// Amounts are integers in minor units — cents, kobo — and are only divided
/// here, at the edge, for display. A Lagos event listed beside a Toronto one is
/// exactly when a bare number becomes a wrong number.
class Money {
  const Money(this.amount, this.currency);

  final int amount;
  final String currency;

  factory Money.fromJson(Map<String, dynamic> json) =>
      Money(json['amount'] as int? ?? 0, json['currency'] as String? ?? 'CAD');

  String format() =>
      NumberFormat.simpleCurrency(name: currency).format(amount / 100);

  @override
  String toString() => format();
}
