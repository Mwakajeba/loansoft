import 'package:flutter/material.dart';

/// YAWOTE brand colors extracted from logo.png
/// Dominant color: #13EC5B (RGB: 19, 236, 91) - Green from logo
class AppColors {
  AppColors._();

  // Primary color extracted from logo.png dominant color
  static const Color primary = Color(0xFF13EC5B);
  // Secondary/accent color (slightly lighter green from logo average)
  static const Color secondary = Color(0xFF35EE72);
  static const Color brandRed = Color(0xFFEF4444);
  static const Color backgroundLight = Color(0xFFF6F6F8);
  static const Color backgroundDark = Color(0xFF101622);
  static const Color navyDeep = Color(0xFF0A0E17);
}
