import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;

import '../core/api_config.dart';
import '../core/app_colors.dart';

/// Repayment screen with two tabs: Kwa Simu (Mobile) and Kwa Benki (Bank)
class RepaymentScreen extends StatefulWidget {
  final int? loanId;
  final int? customerId;
  final num? totalDue;

  const RepaymentScreen({
    super.key,
    this.loanId,
    this.customerId,
    this.totalDue,
  });

  @override
  State<RepaymentScreen> createState() => _RepaymentScreenState();
}

class BankItem {
  final int id;
  final String name;
  final String? accountNumber;

  BankItem({
    required this.id,
    required this.name,
    this.accountNumber,
  });

  static BankItem fromJson(Map<String, dynamic> j) {
    int parseInt(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is String) return int.tryParse(v) ?? 0;
      if (v is num) return v.toInt();
      return 0;
    }
    return BankItem(
      id: parseInt(j['id']),
      name: j['name']?.toString() ?? '—',
      accountNumber: j['account_number']?.toString(),
    );
  }
}

class _RepaymentScreenState extends State<RepaymentScreen> {
  int _tabIndex = 0;
  static const Color _surfaceDark = Color(0xFF16162A);

  // Kwa Simu tab fields
  final _phoneController = TextEditingController();
  final _amountSimuController = TextEditingController();

  // Kwa Benki tab fields
  final _bankAccountController = TextEditingController();
  final _amountBenkiController = TextEditingController();
  List<BankItem> _banks = [];
  BankItem? _selectedBank;
  bool _loadingBanks = false;

  @override
  void initState() {
    super.initState();
    if (widget.totalDue != null) {
      _amountSimuController.text = widget.totalDue!.toStringAsFixed(0);
      _amountBenkiController.text = widget.totalDue!.toStringAsFixed(0);
    }
    _loadBanks();
  }

  @override
  void dispose() {
    _phoneController.dispose();
    _amountSimuController.dispose();
    _bankAccountController.dispose();
    _amountBenkiController.dispose();
    super.dispose();
  }

  Future<void> _loadBanks() async {
    setState(() => _loadingBanks = true);
    try {
      // TODO: Replace with actual bank API endpoint when available
      // For now, using a placeholder endpoint - banks might come from a different API
      final url = Uri.parse('${ApiConfig.baseUrl}/api/bank-accounts');
      final res = await http.get(url, headers: {'Accept': 'application/json'});
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
        final banksList = data['banks'] as List<dynamic>? ?? 
                         data['data'] as List<dynamic>? ?? 
                         data['accounts'] as List<dynamic>? ?? [];
        setState(() {
          _banks = banksList
              .whereType<Map<String, dynamic>>()
              .map((e) => BankItem.fromJson(e))
              .toList();
          _loadingBanks = false;
        });
      } else {
        setState(() => _loadingBanks = false);
      }
    } catch (_) {
      // If bank API is not available, continue without banks
      setState(() => _loadingBanks = false);
    }
  }

  static String _formatAmount(num n) {
    final i = n.round();
    if (i == 0) return '0';
    final s = i.abs().toString();
    final buf = StringBuffer();
    for (var k = 0; k < s.length; k++) {
      if (k > 0 && (s.length - k) % 3 == 0) buf.write(',');
      buf.write(s[k]);
    }
    return i < 0 ? '-$buf' : buf.toString();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0A0A1A),
      appBar: AppBar(
        title: Text(
          'Lipa Mkopo',
          style: GoogleFonts.manrope(
            fontSize: 18,
            fontWeight: FontWeight.w700,
            color: Colors.white,
          ),
        ),
        backgroundColor: const Color(0xFF0A0A1A),
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: SafeArea(
        child: Column(
          children: [
            // Tabs
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
              child: Container(
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(
                  color: _surfaceDark,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(
                  children: [
                    _tab(0, 'Kwa Simu'),
                    _tab(1, 'Kwa Benki'),
                  ],
                ),
              ),
            ),
            // Content
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 100),
                child: _tabIndex == 0 ? _buildSimuTab() : _buildBenkiTab(),
              ),
            ),
            // Footer with submit button
            _buildFooter(),
          ],
        ),
      ),
    );
  }

  Widget _tab(int index, String label) {
    final selected = _tabIndex == index;
    return Expanded(
      child: Material(
        color: selected ? AppColors.primary : Colors.transparent,
        borderRadius: BorderRadius.circular(10),
        child: InkWell(
          onTap: () => setState(() => _tabIndex = index),
          borderRadius: BorderRadius.circular(10),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 12),
            child: Text(
              label,
              textAlign: TextAlign.center,
              style: GoogleFonts.manrope(
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: selected ? Colors.white : Colors.white54,
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildSimuTab() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          padding: const EdgeInsets.all(24),
          decoration: BoxDecoration(
            color: _surfaceDark,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: Colors.white.withOpacity(0.05)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Malipo kwa Simu',
                style: GoogleFonts.manrope(
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                ),
              ),
              const SizedBox(height: 24),
              // Phone Number Field
              Text(
                'Namba ya Simu',
                style: GoogleFonts.manrope(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Colors.white70,
                ),
              ),
              const SizedBox(height: 8),
              TextFormField(
                controller: _phoneController,
                keyboardType: TextInputType.phone,
                style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                decoration: InputDecoration(
                  hintText: 'Ingiza namba ya simu',
                  hintStyle: GoogleFonts.manrope(color: Colors.white38),
                  filled: true,
                  fillColor: Colors.white.withOpacity(0.06),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: AppColors.primary, width: 2),
                  ),
                  prefixIcon: Container(
                    margin: const EdgeInsets.all(12),
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    decoration: BoxDecoration(
                      color: AppColors.primary.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Icon(Icons.phone_rounded, color: AppColors.primary, size: 20),
                  ),
                ),
              ),
              const SizedBox(height: 24),
              // Amount Field
              Text(
                'Kiasi',
                style: GoogleFonts.manrope(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Colors.white70,
                ),
              ),
              const SizedBox(height: 8),
              TextFormField(
                controller: _amountSimuController,
                keyboardType: TextInputType.number,
                style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                decoration: InputDecoration(
                  hintText: 'Ingiza kiasi',
                  hintStyle: GoogleFonts.manrope(color: Colors.white38),
                  filled: true,
                  fillColor: Colors.white.withOpacity(0.06),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: AppColors.primary, width: 2),
                  ),
                  prefixIcon: Container(
                    margin: const EdgeInsets.all(12),
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    decoration: BoxDecoration(
                      color: AppColors.primary.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Icon(Icons.attach_money_rounded, color: AppColors.primary, size: 20),
                  ),
                  prefixText: 'TZS ',
                  prefixStyle: GoogleFonts.manrope(color: Colors.white54, fontSize: 14),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildBenkiTab() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          padding: const EdgeInsets.all(24),
          decoration: BoxDecoration(
            color: _surfaceDark,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: Colors.white.withOpacity(0.05)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Malipo kwa Benki',
                style: GoogleFonts.manrope(
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                ),
              ),
              const SizedBox(height: 24),
              // Select Bank Field
              Text(
                'Chagua Benki',
                style: GoogleFonts.manrope(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Colors.white70,
                ),
              ),
              const SizedBox(height: 8),
              DropdownButtonFormField<BankItem>(
                value: _selectedBank,
                decoration: InputDecoration(
                  hintText: 'Chagua benki',
                  hintStyle: GoogleFonts.manrope(color: Colors.white54),
                  filled: true,
                  fillColor: Colors.white.withOpacity(0.06),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: AppColors.primary, width: 2),
                  ),
                  prefixIcon: Container(
                    margin: const EdgeInsets.all(12),
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    decoration: BoxDecoration(
                      color: AppColors.primary.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Icon(Icons.account_balance_rounded, color: AppColors.primary, size: 20),
                  ),
                ),
                dropdownColor: _surfaceDark,
                style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                items: _banks
                    .map((bank) => DropdownMenuItem<BankItem>(
                          value: bank,
                          child: Text(bank.name),
                        ))
                    .toList(),
                onChanged: (bank) => setState(() => _selectedBank = bank),
              ),
              const SizedBox(height: 24),
              // Account Number Field
              Text(
                'Namba ya Akaunti',
                style: GoogleFonts.manrope(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Colors.white70,
                ),
              ),
              const SizedBox(height: 8),
              TextFormField(
                controller: _bankAccountController,
                keyboardType: TextInputType.text,
                style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                decoration: InputDecoration(
                  hintText: 'Ingiza namba ya akaunti',
                  hintStyle: GoogleFonts.manrope(color: Colors.white38),
                  filled: true,
                  fillColor: Colors.white.withOpacity(0.06),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: AppColors.primary, width: 2),
                  ),
                  prefixIcon: Container(
                    margin: const EdgeInsets.all(12),
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    decoration: BoxDecoration(
                      color: AppColors.primary.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Icon(Icons.account_circle_rounded, color: AppColors.primary, size: 20),
                  ),
                ),
              ),
              const SizedBox(height: 24),
              // Amount Field
              Text(
                'Kiasi',
                style: GoogleFonts.manrope(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Colors.white70,
                ),
              ),
              const SizedBox(height: 8),
              TextFormField(
                controller: _amountBenkiController,
                keyboardType: TextInputType.number,
                style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                decoration: InputDecoration(
                  hintText: 'Ingiza kiasi',
                  hintStyle: GoogleFonts.manrope(color: Colors.white38),
                  filled: true,
                  fillColor: Colors.white.withOpacity(0.06),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: AppColors.primary, width: 2),
                  ),
                  prefixIcon: Container(
                    margin: const EdgeInsets.all(12),
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    decoration: BoxDecoration(
                      color: AppColors.primary.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Icon(Icons.attach_money_rounded, color: AppColors.primary, size: 20),
                  ),
                  prefixText: 'TZS ',
                  prefixStyle: GoogleFonts.manrope(color: Colors.white54, fontSize: 14),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildFooter() {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      decoration: BoxDecoration(
        color: const Color(0xFF0A0A1A).withOpacity(0.9),
        border: Border(top: BorderSide(color: Colors.white.withOpacity(0.05))),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            width: double.infinity,
            height: 52,
            child: ElevatedButton(
              onPressed: _handleSubmit,
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: Colors.white,
                elevation: 8,
                shadowColor: AppColors.primary.withOpacity(0.3),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(Icons.send_rounded, size: 22),
                  const SizedBox(width: 8),
                  Text(
                    'Tuma',
                    style: GoogleFonts.manrope(
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Container(
            width: 128,
            height: 4,
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.2),
              borderRadius: BorderRadius.circular(2),
            ),
          ),
        ],
      ),
    );
  }

  void _handleSubmit() {
    if (_tabIndex == 0) {
      // Kwa Simu validation
      if (_phoneController.text.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Ingiza namba ya simu.', style: GoogleFonts.manrope()),
            backgroundColor: Colors.red.shade700,
          ),
        );
        return;
      }
      if (_amountSimuController.text.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Ingiza kiasi.', style: GoogleFonts.manrope()),
            backgroundColor: Colors.red.shade700,
          ),
        );
        return;
      }
      // TODO: Submit mobile payment
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Malipo yamepokea. Inasubiri uthibitisho.', style: GoogleFonts.manrope()),
          backgroundColor: AppColors.primary,
        ),
      );
    } else {
      // Kwa Benki validation
      if (_selectedBank == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Chagua benki.', style: GoogleFonts.manrope()),
            backgroundColor: Colors.red.shade700,
          ),
        );
        return;
      }
      if (_bankAccountController.text.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Ingiza namba ya akaunti.', style: GoogleFonts.manrope()),
            backgroundColor: Colors.red.shade700,
          ),
        );
        return;
      }
      if (_amountBenkiController.text.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Ingiza kiasi.', style: GoogleFonts.manrope()),
            backgroundColor: Colors.red.shade700,
          ),
        );
        return;
      }
      // TODO: Submit bank payment
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Malipo yamepokea. Inasubiri uthibitisho.', style: GoogleFonts.manrope()),
          backgroundColor: AppColors.primary,
        ),
      );
    }
  }
}
