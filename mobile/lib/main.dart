import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'core/app_colors.dart';
import 'screens/splash_screen.dart';

void main() {
  runApp(const YawoteApp());
}

class YawoteApp extends StatelessWidget {
  const YawoteApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'YAWOTE',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        brightness: Brightness.dark,
        colorScheme: ColorScheme.dark(
          primary: AppColors.primary,
          surface: AppColors.backgroundDark,
          onSurface: Colors.white,
          onPrimary: Colors.white,
        ),
        fontFamily: GoogleFonts.manrope().fontFamily,
        textTheme: GoogleFonts.manropeTextTheme(ThemeData.dark().textTheme),
      ),
      home: const SplashScreen(),
    );
  }
}
