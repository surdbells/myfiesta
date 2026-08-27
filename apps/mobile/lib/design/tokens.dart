// GENERATED FROM tokens.json — DO NOT EDIT. Run: npm run build

import 'dart:ui' show Color;

/// Raw values. Prefer [LightTheme] / [DarkTheme] in widgets — a widget that
/// reaches for a primitive directly is one that will be unreadable in one
/// of the two themes.
abstract final class Tokens {
  static const Color colorBrand50 = Color(0xFFF1F8F4);
  static const Color colorBrand100 = Color(0xFFDBF0E4);
  static const Color colorBrand200 = Color(0xFFB7E1C9);
  static const Color colorBrand300 = Color(0xFF7ECEA0);
  static const Color colorBrand400 = Color(0xFF34B26A);
  static const Color colorBrand500 = Color(0xFF198F4C);
  static const Color colorBrand600 = Color(0xFF0E753B);
  static const Color colorBrand700 = Color(0xFF0A5C2E);
  static const Color colorBrand800 = Color(0xFF084522);
  static const Color colorBrand900 = Color(0xFF062D17);
  static const Color colorGold50 = Color(0xFFFCFCED);
  static const Color colorGold100 = Color(0xFFF6F5CA);
  static const Color colorGold200 = Color(0xFFEBE88E);
  static const Color colorGold300 = Color(0xFFDEDA54);
  static const Color colorGold400 = Color(0xFFD0CB29);
  static const Color colorGold500 = Color(0xFFAFAB1D);
  static const Color colorGold600 = Color(0xFF8A8614);
  static const Color colorGold700 = Color(0xFF68650D);
  static const Color colorNeutral0 = Color(0xFFFFFFFF);
  static const Color colorNeutral50 = Color(0xFFF6F8F6);
  static const Color colorNeutral100 = Color(0xFFECEFEC);
  static const Color colorNeutral200 = Color(0xFFDCE1DC);
  static const Color colorNeutral300 = Color(0xFFC3CAC3);
  static const Color colorNeutral400 = Color(0xFF99A199);
  static const Color colorNeutral500 = Color(0xFF6F776F);
  static const Color colorNeutral600 = Color(0xFF545B54);
  static const Color colorNeutral700 = Color(0xFF3D433D);
  static const Color colorNeutral800 = Color(0xFF232823);
  static const Color colorNeutral900 = Color(0xFF131713);
  static const Color colorNeutral950 = Color(0xFF0B0F0C);
  static const Color colorSemanticSuccess = Color(0xFF0E753B);
  static const Color colorSemanticSuccessLight = Color(0xFF72CA98);
  static const Color colorSemanticWarning = Color(0xFFB45309);
  static const Color colorSemanticWarningLight = Color(0xFFE8A33D);
  static const Color colorSemanticDanger = Color(0xFFC0392B);
  static const Color colorSemanticDangerLight = Color(0xFFEC7263);
  static const Color colorSemanticInfo = Color(0xFF1D6FA5);
  static const Color colorSemanticInfoLight = Color(0xFF6DB4E0);
  static const double space0 = 0.0;
  static const double space1 = 4.0;
  static const double space2 = 8.0;
  static const double space3 = 12.0;
  static const double space4 = 16.0;
  static const double space5 = 24.0;
  static const double space6 = 32.0;
  static const double space7 = 48.0;
  static const double space8 = 64.0;
  static const double space9 = 96.0;
  static const double radiusSm = 6.0;
  static const double radiusMd = 10.0;
  static const double radiusLg = 16.0;
  static const double radiusXl = 24.0;
  static const double radiusFull = 9999.0;
  static const String fontFamilySans = "Poppins, system-ui, -apple-system, 'Segoe UI', sans-serif";
  static const String fontFamilyMono = "ui-monospace, 'Cascadia Mono', Consolas, monospace";
  static const double fontSizeXs = 12.0;
  static const double fontSizeSm = 14.0;
  static const double fontSizeBase = 16.0;
  static const double fontSizeLg = 18.0;
  static const double fontSizeXl = 22.0;
  static const double fontSize2xl = 28.0;
  static const double fontSize3xl = 36.0;
  static const double fontSize4xl = 48.0;
  static const double fontWeightRegular = 400.0;
  static const double fontWeightMedium = 500.0;
  static const double fontWeightSemibold = 600.0;
  static const double fontWeightBold = 700.0;
  static const double fontLeadingTight = 1.1;
  static const double fontLeadingSnug = 1.4;
  static const double fontLeadingNormal = 1.6;
  static const String fontTrackingTight = "-0.02em";
  static const String fontTrackingNormal = "0";
  static const String fontTrackingWide = "0.08em";
  static const String shadowSm = "0 1px 2px rgba(9, 30, 14, 0.06)";
  static const String shadowMd = "0 2px 8px rgba(9, 30, 14, 0.08)";
  static const String shadowLg = "0 8px 24px rgba(9, 30, 14, 0.10)";
  static const String shadowFocus = "0 0 0 3px rgba(8, 145, 31, 0.28)";
  static const String motionFast = "120ms";
  static const String motionBase = "200ms";
  static const String motionSlow = "320ms";
  static const String motionEase = "cubic-bezier(0.2, 0, 0.13, 1)";
}

/// The semantic layer, light.
abstract final class LightTheme {
  static const Color surface = Color(0xFFFFFFFF);
  static const Color surfaceSunken = Color(0xFFF6F8F6);
  static const Color surfaceRaised = Color(0xFFFFFFFF);
  static const Color surfaceInset = Color(0xFFECEFEC);
  static const Color border = Color(0xFFDCE1DC);
  static const Color borderStrong = Color(0xFFC3CAC3);
  static const Color fieldBorder = Color(0xFF6F776F);
  static const Color text = Color(0xFF131713);
  static const Color textMuted = Color(0xFF545B54);
  static const Color textSubtle = Color(0xFF6F776F);
  static const Color textInverse = Color(0xFFFFFFFF);
  static const Color primary = Color(0xFF0E753B);
  static const Color primaryHover = Color(0xFF0A5C2E);
  static const Color primaryActive = Color(0xFF084522);
  static const Color primaryText = Color(0xFF0A5C2E);
  static const Color primarySoft = Color(0xFFF1F8F4);
  static const Color primarySoftText = Color(0xFF0A5C2E);
  static const Color onPrimary = Color(0xFFFFFFFF);
  static const Color accent = Color(0xFFD0CB29);
  static const Color accentSoft = Color(0xFFFCFCED);
  static const Color onAccent = Color(0xFF131713);
  static const Color success = Color(0xFF0E753B);
  static const Color warning = Color(0xFFB45309);
  static const Color danger = Color(0xFFC0392B);
  static const Color info = Color(0xFF1D6FA5);
  static const String focusRing = "0 0 0 3px rgba(8, 145, 31, 0.28)";
  static const String shadowCard = "0 1px 2px rgba(9, 30, 14, 0.06)";
  static const String shadowRaised = "0 2px 8px rgba(9, 30, 14, 0.08)";
  static const String shadowOverlay = "0 8px 24px rgba(9, 30, 14, 0.10)";
  static const Color borderSubtle = Color(0xFFECEFEC);
  static const Color surfaceHover = Color(0xFFF6F8F6);
  static const Color dangerText = Color(0xFFC0392B);
}

/// The semantic layer, dark.
abstract final class DarkTheme {
  static const Color surface = Color(0xFF131713);
  static const Color surfaceSunken = Color(0xFF0B0F0C);
  static const Color surfaceRaised = Color(0xFF1B211C);
  static const Color surfaceInset = Color(0xFF242B25);
  static const Color border = Color(0xFF2C332D);
  static const Color borderStrong = Color(0xFF3D453E);
  static const Color fieldBorder = Color(0xFF7F887F);
  static const Color text = Color(0xFFE9EDE9);
  static const Color textMuted = Color(0xFFA5AEA6);
  static const Color textSubtle = Color(0xFF7F887F);
  static const Color textInverse = Color(0xFF131713);
  static const Color primary = Color(0xFF7ECEA0);
  static const Color primaryHover = Color(0xFFB7E1C9);
  static const Color primaryActive = Color(0xFFDBF0E4);
  static const Color primaryText = Color(0xFF7ECEA0);
  static const Color primarySoft = Color(0xFF12281A);
  static const Color primarySoftText = Color(0xFF7ECEA0);
  static const Color onPrimary = Color(0xFF062D17);
  static const Color accent = Color(0xFFDEDA54);
  static const Color accentSoft = Color(0xFF2A2807);
  static const Color onAccent = Color(0xFF131713);
  static const Color success = Color(0xFF72CA98);
  static const Color warning = Color(0xFFE8A33D);
  static const Color danger = Color(0xFFEC7263);
  static const Color info = Color(0xFF6DB4E0);
  static const String focusRing = "0 0 0 3px rgba(52, 174, 81, 0.35)";
  static const String shadowCard = "0 1px 2px rgba(0, 0, 0, 0.4)";
  static const String shadowRaised = "0 2px 8px rgba(0, 0, 0, 0.45)";
  static const String shadowOverlay = "0 8px 24px rgba(0, 0, 0, 0.55)";
  static const Color borderSubtle = Color(0xFF232823);
  static const Color surfaceHover = Color(0xFF232823);
  static const Color dangerText = Color(0xFFEC7263);
}
