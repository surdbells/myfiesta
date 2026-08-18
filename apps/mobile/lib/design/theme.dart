import 'package:flutter/material.dart';

import 'tokens.dart';

/// Flutter's half of the shared design system.
///
/// Tokens are generated from packages/tokens/tokens.json, which also emits the
/// CSS custom properties the Angular apps consume. Flutter cannot import a
/// TypeScript package, so this generated Dart file is what keeps the two
/// surfaces looking like one product rather than two.
///
/// Nothing here invents a colour. If a value is needed and missing, it belongs
/// in tokens.json so both platforms get it.
abstract final class AppTheme {
  static ThemeData light() => _build(Brightness.light);

  static ThemeData dark() => _build(Brightness.dark);

  static ThemeData _build(Brightness brightness) {
    final isDark = brightness == Brightness.dark;

    final scheme = ColorScheme.fromSeed(
      seedColor: Tokens.colorBrand500,
      brightness: brightness,
      primary: Tokens.colorBrand500,
      secondary: Tokens.colorAccent500,
      error: Tokens.colorSemanticDanger,
      surface: isDark ? Tokens.colorNeutral900 : Tokens.colorNeutral0,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: isDark ? Tokens.colorNeutral900 : Tokens.colorNeutral50,
      fontFamily: 'Inter',
      cardTheme: CardThemeData(
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Tokens.radiusLg),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(Tokens.radiusMd),
          ),
        ),
      ),
    );
  }
}
