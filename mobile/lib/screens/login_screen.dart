import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;

import '../core/api_config.dart';
import '../core/app_colors.dart';
import '../core/user_photo.dart';
import 'dashboard_screen.dart';

/// YAWOTE login screen — phone + password; calls customer login API.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;
  bool _isLoading = false;
  String? _errorMessage;

  @override
  void dispose() {
    _phoneController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  /// Normalize phone to 255xxxxxxxxx (e.g. 0682563985 or 682563985 -> 255682563985).
  static String _normalizePhone(String input) {
    String digits = input.replaceAll(RegExp(r'\D'), '');
    digits = digits.startsWith('0') ? digits.substring(1) : digits;
    if (digits.startsWith('255') && digits.length >= 12) {
      return digits.substring(0, 12);
    }
    if (digits.length >= 9) {
      return '255${digits.substring(0, 9)}';
    }
    return digits.isEmpty ? input : '255$digits';
  }

  Future<void> _submit() async {
    _errorMessage = null;
    if (_formKey.currentState?.validate() ?? false) {
      setState(() => _isLoading = true);
      try {
        final url = Uri.parse(ApiConfig.customerUrl('login'));
        final response = await http.post(
          url,
          headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: jsonEncode({
            'username': _normalizePhone(_phoneController.text.trim()),
            'password': _passwordController.text,
          }),
        );
        final data = jsonDecode(response.body) as Map<String, dynamic>? ?? {};

        if (response.statusCode == 200 && (data['status'] == 200)) {
          if (!mounted) return;
          final photoUrl = data['photo']?.toString();
          if (photoUrl != null && photoUrl.isNotEmpty) {
            UserPhotoHolder.currentPhotoUrl = photoUrl;
          }
          final totalBalance = data['total_loan_balance'];
          final nextDueDays = data['next_due_days'];
          final nextDueAmount = data['next_due_amount'];
          Navigator.of(context).pushReplacement(
            MaterialPageRoute(
              builder: (_) => DashboardScreen(
                userId: data['user_id'] as int?,
                name: data['name'] as String? ?? '',
                phone: data['phone'] as String? ?? '',
                photo: photoUrl,
                groupId: (data['group_id'] is int)
                    ? data['group_id'] as int
                    : int.tryParse(data['group_id']?.toString() ?? ''),
                groupName: data['group_name']?.toString(),
                loans: (data['loans'] as List<dynamic>?) ?? [],
                totalLoanBalance: totalBalance is num ? totalBalance.toDouble() : (totalBalance != null ? double.tryParse(totalBalance.toString()) : null),
                nextDueDays: nextDueDays is int ? nextDueDays : (nextDueDays != null ? int.tryParse(nextDueDays.toString()) : null),
                nextDueAmount: nextDueAmount is num ? nextDueAmount.toDouble() : (nextDueAmount != null ? double.tryParse(nextDueAmount.toString()) : null),
              ),
            ),
          );
          return;
        }

        if (response.statusCode == 401) {
          setState(() {
            _errorMessage = data['message'] as String? ?? 'Samahani, namba au neno la siri si sahihi.';
            _isLoading = false;
          });
          return;
        }

        setState(() {
          _errorMessage = data['message'] as String? ?? 'Hitilafu ya mtandao. Jaribu tena.';
          _isLoading = false;
        });
      } catch (e) {
        if (!mounted) return;
        setState(() {
          _errorMessage = ApiConfig.networkErrorMessage(e, 'Hitilafu ya mtandao. Jaribu tena.');
          _isLoading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        width: double.infinity,
        height: double.infinity,
        decoration: const BoxDecoration(
          color: AppColors.backgroundDark,
        ),
        child: Stack(
          children: [
            // Background decoration
            Positioned(
              top: -96,
              right: -96,
              child: Container(
                width: 384,
                height: 384,
                decoration: BoxDecoration(
                  color: AppColors.primary.withOpacity(0.1),
                  shape: BoxShape.circle,
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.primary.withOpacity(0.1),
                      blurRadius: 96,
                      spreadRadius: 24,
                    ),
                  ],
                ),
              ),
            ),
            Positioned(
              bottom: -96,
              left: -96,
              child: Container(
                width: 384,
                height: 384,
                decoration: BoxDecoration(
                  color: AppColors.brandRed.withOpacity(0.05),
                  shape: BoxShape.circle,
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.brandRed.withOpacity(0.05),
                      blurRadius: 96,
                      spreadRadius: 24,
                    ),
                  ],
                ),
              ),
            ),
            // Content
            SafeArea(
              child: Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 400),
                    child: Form(
                      key: _formKey,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          // Logo
                          SizedBox(
                            height: 130,
                            child: Image.asset(
                              'logoapp.png',
                              fit: BoxFit.contain,
                              width: 180,
                              errorBuilder: (context, error, stackTrace) {
                                debugPrint('Error loading logoapp.png: $error');
                                return Icon(
                                  Icons.image_not_supported_outlined,
                                  size: 64,
                                  color: Colors.white54,
                                );
                              },
                            ),
                          ),
                          const SizedBox(height: 40),
                          // Phone field
                          Align(
                            alignment: Alignment.centerLeft,
                            child: Text(
                              'Namba ya Simu',
                              style: GoogleFonts.manrope(
                                fontSize: 15,
                                fontWeight: FontWeight.w700,
                                color: Colors.white,
                                letterSpacing: 0.3,
                              ),
                            ),
                          ),
                          const SizedBox(height: 10),
                          TextFormField(
                            controller: _phoneController,
                            keyboardType: TextInputType.phone,
                            style: GoogleFonts.manrope(
                              color: Colors.white,
                              fontSize: 16,
                              fontWeight: FontWeight.w500,
                            ),
                            decoration: InputDecoration(
                              hintText: '07xx xxx xxx',
                              hintStyle: GoogleFonts.manrope(
                                color: Colors.white.withOpacity(0.4),
                                fontSize: 16,
                              ),
                              prefixIcon: Container(
                                margin: const EdgeInsets.all(12),
                                padding: const EdgeInsets.all(8),
                                decoration: BoxDecoration(
                                  color: AppColors.primary.withOpacity(0.15),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: const Icon(
                                  Icons.phone_iphone,
                                  color: AppColors.primary,
                                  size: 20,
                                ),
                              ),
                              filled: true,
                              fillColor: AppColors.navyDeep.withOpacity(0.8),
                              border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(14),
                                borderSide: BorderSide(
                                  color: Colors.white.withOpacity(0.15),
                                  width: 1.5,
                                ),
                              ),
                              enabledBorder: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(14),
                                borderSide: BorderSide(
                                  color: Colors.white.withOpacity(0.15),
                                  width: 1.5,
                                ),
                              ),
                              focusedBorder: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(14),
                                borderSide: const BorderSide(
                                  color: AppColors.primary,
                                  width: 2.5,
                                ),
                              ),
                              contentPadding: const EdgeInsets.symmetric(
                                horizontal: 20,
                                vertical: 18,
                              ),
                            ),
                            validator: (v) {
                              if (v == null || v.trim().isEmpty) return 'Ingiza namba ya simu';
                              return null;
                            },
                          ),
                          const SizedBox(height: 24),
                          // Password label + forgot link
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                'Neno la Siri',
                                style: GoogleFonts.manrope(
                                  fontSize: 15,
                                  fontWeight: FontWeight.w700,
                                  color: Colors.white,
                                  letterSpacing: 0.3,
                                ),
                              ),
                              TextButton(
                                onPressed: () {},
                                style: TextButton.styleFrom(
                                  padding: EdgeInsets.zero,
                                  minimumSize: Size.zero,
                                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                                ),
                                child: Text(
                                  'Umesahau Nywila?',
                                  style: GoogleFonts.manrope(
                                    fontSize: 13,
                                    fontWeight: FontWeight.w700,
                                    color: AppColors.primary,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 10),
                          TextFormField(
                            controller: _passwordController,
                            obscureText: _obscurePassword,
                            style: GoogleFonts.manrope(
                              color: Colors.white,
                              fontSize: 16,
                              fontWeight: FontWeight.w500,
                            ),
                            decoration: InputDecoration(
                              hintText: '••••••••',
                              hintStyle: GoogleFonts.manrope(
                                color: Colors.white.withOpacity(0.4),
                                fontSize: 16,
                              ),
                              prefixIcon: Container(
                                margin: const EdgeInsets.all(12),
                                padding: const EdgeInsets.all(8),
                                decoration: BoxDecoration(
                                  color: AppColors.primary.withOpacity(0.15),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: const Icon(
                                  Icons.lock_outline,
                                  color: AppColors.primary,
                                  size: 20,
                                ),
                              ),
                              suffixIcon: Container(
                                margin: const EdgeInsets.only(right: 8),
                                child: IconButton(
                                  icon: Icon(
                                    _obscurePassword ? Icons.visibility_off : Icons.visibility,
                                    color: Colors.white.withOpacity(0.6),
                                    size: 22,
                                  ),
                                  onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                                ),
                              ),
                              filled: true,
                              fillColor: AppColors.navyDeep.withOpacity(0.8),
                              border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(14),
                                borderSide: BorderSide(
                                  color: Colors.white.withOpacity(0.15),
                                  width: 1.5,
                                ),
                              ),
                              enabledBorder: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(14),
                                borderSide: BorderSide(
                                  color: Colors.white.withOpacity(0.15),
                                  width: 1.5,
                                ),
                              ),
                              focusedBorder: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(14),
                                borderSide: const BorderSide(
                                  color: AppColors.primary,
                                  width: 2.5,
                                ),
                              ),
                              contentPadding: const EdgeInsets.symmetric(
                                horizontal: 20,
                                vertical: 18,
                              ),
                            ),
                            validator: (v) {
                              if (v == null || v.isEmpty) return 'Ingiza neno la siri';
                              return null;
                            },
                          ),
                          if (_errorMessage != null) ...[
                            const SizedBox(height: 16),
                            Text(
                              _errorMessage!,
                              style: GoogleFonts.manrope(
                                fontSize: 14,
                                color: Colors.red.shade300,
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                          ],
                          const SizedBox(height: 25),
                          // Primary button
                          SizedBox(
                            width: double.infinity,
                            height: 56,
                            child: ElevatedButton(
                              onPressed: _isLoading ? null : _submit,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: AppColors.primary,
                                foregroundColor: Colors.white,
                                elevation: 8,
                                shadowColor: AppColors.primary.withOpacity(0.3),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(12),
                                ),
                              ),
                              child: _isLoading
                                  ? const SizedBox(
                                      width: 24,
                                      height: 24,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                        color: Colors.white,
                                      ),
                                    )
                                  : Row(
                                      mainAxisAlignment: MainAxisAlignment.center,
                                      children: [
                                        Text(
                                          'Ingia Sasa',
                                          style: GoogleFonts.manrope(
                                            fontSize: 16,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                        const SizedBox(width: 8),
                                        const Icon(Icons.login, size: 22),
                                      ],
                                    ),
                            ),
                          ),
                           ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
