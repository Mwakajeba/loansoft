import 'dart:async';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/app_colors.dart';
import 'login_screen.dart';

/// YAWOTE splash screen — shown first, then navigates to login.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  static const String _sloganFull = 'KARIBU SMARTFINANCE APP';
  String _sloganTyped = '';
  Timer? _typingTimer;
  Timer? _cursorTimer;
  bool _cursorOn = true;
  final Completer<void> _typingDone = Completer<void>();

  @override
  void initState() {
    super.initState();
    _startTyping();
    _navigateAfterDelay();
  }

  Future<void> _navigateAfterDelay() async {
    await Future.wait([
      Future.delayed(const Duration(seconds: 5)),
      _typingDone.future,
    ]);
    if (!mounted) return;
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }

  void _startTyping() {
    _typingTimer?.cancel();
    _cursorTimer?.cancel();
    _sloganTyped = '';
    _cursorOn = true;

    // Cursor blink
    _cursorTimer = Timer.periodic(const Duration(milliseconds: 450), (_) {
      if (!mounted) return;
      setState(() => _cursorOn = !_cursorOn);
    });

    // Type out slogan
    int i = 0;
    _typingTimer = Timer.periodic(const Duration(milliseconds: 45), (t) {
      if (!mounted) return;
      if (i >= _sloganFull.length) {
        t.cancel();
        _cursorTimer?.cancel();
        if (!_typingDone.isCompleted) _typingDone.complete();
        setState(() {
          _cursorOn = false;
        });
        return;
      }
      setState(() {
        i++;
        _sloganTyped = _sloganFull.substring(0, i);
      });
    });
  }

  @override
  void dispose() {
    _typingTimer?.cancel();
    _cursorTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        width: double.infinity,
        height: double.infinity,
        decoration: const BoxDecoration(
          gradient: RadialGradient(
            center: Alignment.center,
            radius: 1.0,
            colors: [
              Color(0xFF1A2333),
              AppColors.backgroundDark,
            ],
          ),
        ),
        child: SafeArea(
          child: Column(
            children: [
              // Status bar area
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 8),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      '9:41',
                      style: GoogleFonts.manrope(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: Colors.white.withOpacity(0.4),
                      ),
                    ),
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.signal_cellular_alt, size: 14, color: Colors.white.withOpacity(0.4)),
                        const SizedBox(width: 6),
                        Icon(Icons.wifi, size: 14, color: Colors.white.withOpacity(0.4)),
                        const SizedBox(width: 6),
                        Icon(Icons.battery_full, size: 18, color: Colors.white.withOpacity(0.4)),
                      ],
                    ),
                  ],
                ),
              ),
              // Center: logo + brand
              Expanded(
                child: Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      // Logo (same style as login, no container behind)
                      SizedBox(
                        height: 130,
                        width: 240,
                        child: Image.asset(
                          'logoapp.png',
                          fit: BoxFit.contain,
                          errorBuilder: (_, __, ___) => Icon(
                            Icons.image_not_supported_outlined,
                            size: 56,
                            color: Colors.white.withOpacity(0.6),
                          ),
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        _sloganTyped + (_cursorOn ? '|' : ''),
                        textAlign: TextAlign.center,
                        style: GoogleFonts.manrope(
                          fontSize: 12,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 4,
                          color: AppColors.primary.withOpacity(0.9),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
