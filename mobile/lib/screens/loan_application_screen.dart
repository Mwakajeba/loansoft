import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;

import '../core/api_config.dart';
import '../core/app_colors.dart';

/// File Type (KYC Document)
class FileTypeItem {
  final int id;
  final String name;
  final String? description;

  FileTypeItem({
    required this.id,
    required this.name,
    this.description,
  });

  static FileTypeItem fromJson(Map<String, dynamic> j) {
    return FileTypeItem(
      id: (j['id'] is int) ? j['id'] as int : int.tryParse(j['id']?.toString() ?? '0') ?? 0,
      name: j['name']?.toString() ?? '—',
      description: j['description']?.toString(),
    );
  }
}

/// Loan Product from API
class LoanProductItem {
  final int id;
  final String name;
  final double minAmount;
  final double maxAmount;
  final double minInterestRate;
  final double maxInterestRate;
  final double defaultInterestRate;
  final int minPeriod;
  final int maxPeriod;
  final String? interestCycle;
  final List<FileTypeItem> filetypes;
  final bool allowedInApp;

  LoanProductItem({
    required this.id,
    required this.name,
    required this.minAmount,
    required this.maxAmount,
    required this.minInterestRate,
    required this.maxInterestRate,
    required this.defaultInterestRate,
    required this.minPeriod,
    required this.maxPeriod,
    this.interestCycle,
    this.filetypes = const [],
    this.allowedInApp = false,
  });

  static LoanProductItem fromJson(Map<String, dynamic> j) {
    final filetypesList = j['filetypes'] as List<dynamic>? ?? [];
    return LoanProductItem(
      id: (j['id'] is int) ? j['id'] as int : int.tryParse(j['id']?.toString() ?? '0') ?? 0,
      name: j['name']?.toString() ?? '—',
      minAmount: _parseDouble(j['min_amount'] ?? j['minimum_principal'] ?? 0),
      maxAmount: _parseDouble(j['max_amount'] ?? j['maximum_principal'] ?? 0),
      minInterestRate: _parseDouble(j['min_interest_rate'] ?? j['minimum_interest_rate'] ?? 0),
      maxInterestRate: _parseDouble(j['max_interest_rate'] ?? j['maximum_interest_rate'] ?? 0),
      defaultInterestRate: _parseDouble(j['default_interest_rate'] ?? j['minimum_interest_rate'] ?? 0),
      minPeriod: (j['min_period'] ?? j['minimum_period'] ?? 0) as int,
      maxPeriod: (j['max_period'] ?? j['maximum_period'] ?? 0) as int,
      interestCycle: j['interest_cycle']?.toString(),
      allowedInApp: (j['allowed_in_app'] ?? false) as bool,
      filetypes: filetypesList
          .map((e) => FileTypeItem.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }

  static double _parseDouble(dynamic v) {
    if (v is num) return v.toDouble();
    if (v is String) return double.tryParse(v) ?? 0.0;
    return 0.0;
  }
}

/// Loan Application Screen
class LoanApplicationScreen extends StatefulWidget {
  final int? customerId;
  final int? groupId;

  const LoanApplicationScreen({super.key, this.customerId, this.groupId});

  @override
  State<LoanApplicationScreen> createState() => _LoanApplicationScreenState();
}

class _LoanApplicationScreenState extends State<LoanApplicationScreen> {
  final _formKey = GlobalKey<FormState>();
  final _amountController = TextEditingController();
  final _interestRateController = TextEditingController();
  final _durationController = TextEditingController();

  List<LoanProductItem> _products = [];
  LoanProductItem? _selectedProduct;
  String? _selectedInterestCycle;
  bool _loading = false;
  bool _submitting = false;
  String? _error;
  String? _message;

  final List<Map<String, String>> _interestCycles = [
    {'value': 'daily', 'label': 'Kila siku'},
    {'value': 'weekly', 'label': 'Kila wiki'},
    {'value': 'monthly', 'label': 'Kila mwezi'},
    {'value': 'quarterly', 'label': 'Kila robo mwaka'},
    {'value': 'semi_annually', 'label': 'Kila nusu mwaka'},
    {'value': 'annually', 'label': 'Kila mwaka'},
  ];

  @override
  void initState() {
    super.initState();
    _loadProducts();
  }

  @override
  void dispose() {
    _amountController.dispose();
    _interestRateController.dispose();
    _durationController.dispose();
    super.dispose();
  }

  Future<void> _loadProducts() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final url = Uri.parse(ApiConfig.customerUrl('loan-products'));
      final res = await http.get(url, headers: {'Accept': 'application/json'});
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
      if (mounted) {
        setState(() {
          _loading = false;
          if (res.statusCode == 200 && (data['status'] == 200)) {
            final list = (data['products'] as List<dynamic>?) ?? [];
            _products = list
                .map((e) => LoanProductItem.fromJson(e as Map<String, dynamic>))
                .toList();
            if (_products.isNotEmpty && _selectedProduct == null) {
              _selectedProduct = _products.first;
              _interestRateController.text = _selectedProduct!.defaultInterestRate.toStringAsFixed(2);
              _selectedInterestCycle = _selectedProduct!.interestCycle ?? 'monthly';
            }
          } else {
            _error = data['message']?.toString() ?? 'Hitilafu ya kupakia bidhaa za mkopo.';
          }
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = ApiConfig.networkErrorMessage(e);
        });
      }
    }
  }

  Future<void> _submit() async {
    if (widget.customerId == null) {
      setState(() => _message = 'Hakuna kitambulisho cha mteja.');
      return;
    }
    if (_selectedProduct == null) {
      setState(() => _message = 'Chagua aina ya mkopo.');
      return;
    }
    if (_selectedInterestCycle == null) {
      setState(() => _message = 'Chagua mzunguko wa riba.');
      return;
    }
    if (!(_formKey.currentState?.validate() ?? false)) return;

    final amount = double.tryParse(_amountController.text.trim()) ?? 0;
    final interestRate = double.tryParse(_interestRateController.text.trim()) ?? 0;
    final duration = int.tryParse(_durationController.text.trim()) ?? 0;

    // Validate against product limits
    if (amount < _selectedProduct!.minAmount || amount > _selectedProduct!.maxAmount) {
      setState(() => _message =
          'Kiasi lazima kiwe kati ya TZS ${_formatAmount(_selectedProduct!.minAmount)} na TZS ${_formatAmount(_selectedProduct!.maxAmount)}.');
      return;
    }
    if (interestRate < _selectedProduct!.minInterestRate ||
        interestRate > _selectedProduct!.maxInterestRate) {
      setState(() => _message =
          'Kiwango cha riba lazima kiwe kati ya ${_selectedProduct!.minInterestRate}% na ${_selectedProduct!.maxInterestRate}%.');
      return;
    }
    // Convert duration to months based on interest cycle
    final durationInMonths = _convertDurationToMonths(duration, _selectedInterestCycle!);

    setState(() {
      _submitting = true;
      _message = null;
    });

    try {
      final url = Uri.parse(ApiConfig.customerUrl('submit-loan-application'));
      final res = await http.post(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: jsonEncode({
          'customer_id': widget.customerId,
          'product_id': _selectedProduct!.id,
          'amount': amount,
          'interest': interestRate,
          'period': durationInMonths,
          'interest_cycle': _selectedInterestCycle,
          'date_applied': DateTime.now().toIso8601String().split('T')[0],
          'sector': 'General', // Default sector, can be made configurable
          'group_id': widget.groupId,
        }),
      );
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
      if (!mounted) return;
      setState(() => _submitting = false);
      if (res.statusCode == 200 && (data['status'] == 200)) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                data['message']?.toString() ?? 'Ombi la mkopo limetumwa kwa mafanikio.',
                style: GoogleFonts.manrope(),
              ),
              backgroundColor: AppColors.primary,
              duration: const Duration(seconds: 3),
            ),
          );
          Navigator.of(context).pop();
        }
      } else {
        setState(() => _message =
            data['message']?.toString() ?? data['errors']?.toString() ?? 'Imeshindwa kutuma ombi.');
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _submitting = false;
          _message = ApiConfig.networkErrorMessage(e);
        });
      }
    }
  }

  String _formatAmount(double value) {
    final int n = value.round();
    if (n == 0) return '0';
    final s = n.abs().toString();
    final buf = StringBuffer();
    for (var i = 0; i < s.length; i++) {
      if (i > 0 && (s.length - i) % 3 == 0) buf.write(',');
      buf.write(s[i]);
    }
    return n < 0 ? '-$buf' : buf.toString();
  }

  String _getDurationLabel() {
    switch (_selectedInterestCycle) {
      case 'daily':
        return 'Muda (Siku)';
      case 'weekly':
        return 'Muda (Wiki)';
      case 'monthly':
        return 'Muda (Miezi)';
      case 'quarterly':
        return 'Muda (Robo mwaka)';
      case 'semi_annually':
        return 'Muda (Nusu mwaka)';
      case 'annually':
        return 'Muda (Mwaka)';
      default:
        return 'Muda';
    }
  }

  String _getDurationHint() {
    switch (_selectedInterestCycle) {
      case 'daily':
        return 'Ingiza muda kwa siku';
      case 'weekly':
        return 'Ingiza muda kwa wiki';
      case 'monthly':
        return 'Ingiza muda kwa miezi';
      case 'quarterly':
        return 'Ingiza muda kwa robo mwaka';
      case 'semi_annually':
        return 'Ingiza muda kwa nusu mwaka';
      case 'annually':
        return 'Ingiza muda kwa mwaka';
      default:
        return 'Ingiza muda';
    }
  }

  String _getDurationExample() {
    switch (_selectedInterestCycle) {
      case 'daily':
        return 'Mf: 30';
      case 'weekly':
        return 'Mf: 4';
      case 'monthly':
        return 'Mf: 12';
      case 'quarterly':
        return 'Mf: 4';
      case 'semi_annually':
        return 'Mf: 2';
      case 'annually':
        return 'Mf: 1';
      default:
        return 'Mf: 12';
    }
  }

  int _convertDurationToMonths(int duration, String cycle) {
    switch (cycle) {
      case 'daily':
        // Approximate: 30 days = 1 month
        return (duration / 30).round();
      case 'weekly':
        // Approximate: 4.33 weeks = 1 month
        return (duration / 4.33).round();
      case 'monthly':
        return duration;
      case 'quarterly':
        // 1 quarter = 3 months
        return duration * 3;
      case 'semi_annually':
        // 1 semi-annual = 6 months
        return duration * 6;
      case 'annually':
        // 1 year = 12 months
        return duration * 12;
      default:
        return duration;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: AppColors.backgroundDark,
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
        title: Text(
          'Omba Mkopo',
          style: GoogleFonts.manrope(
            fontSize: 18,
            fontWeight: FontWeight.w700,
            color: Colors.white,
          ),
        ),
        centerTitle: true,
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          _error!,
                          textAlign: TextAlign.center,
                          style: GoogleFonts.manrope(fontSize: 14, color: Colors.white70),
                        ),
                        const SizedBox(height: 16),
                        TextButton.icon(
                          onPressed: _loadProducts,
                          icon: Icon(Icons.refresh_rounded, color: AppColors.primary, size: 20),
                          label: Text('Jaribu tena', style: TextStyle(color: AppColors.primary)),
                        ),
                      ],
                    ),
                  ),
                )
              : _products.isEmpty
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(24),
                        child: Text(
                          'Hakuna bidhaa za mkopo zilizopatikana.',
                          textAlign: TextAlign.center,
                          style: GoogleFonts.manrope(fontSize: 14, color: Colors.white54),
                        ),
                      ),
                    )
                  : SingleChildScrollView(
                      padding: const EdgeInsets.all(24),
                      child: Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            // Product Selection
                            Text(
                              'Aina ya Mkopo',
                              style: GoogleFonts.manrope(
                                fontSize: 14,
                                fontWeight: FontWeight.w600,
                                color: Colors.white70,
                              ),
                            ),
                            const SizedBox(height: 8),
                            DropdownButtonFormField<LoanProductItem>(
                              value: _selectedProduct,
                              decoration: InputDecoration(
                                labelText: 'Chagua aina ya mkopo',
                                labelStyle: GoogleFonts.manrope(color: Colors.white54),
                                filled: true,
                                fillColor: Colors.white.withOpacity(0.06),
                                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                                ),
                                focusedBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: AppColors.primary, width: 2),
                                ),
                              ),
                              dropdownColor: AppColors.backgroundDark,
                              style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                              items: _products
                                  .map((p) => DropdownMenuItem<LoanProductItem>(
                                        value: p,
                                        child: Text(p.name),
                                      ))
                                  .toList(),
                              onChanged: (p) {
                                setState(() {
                                  _selectedProduct = p;
                                  if (p != null) {
                                    if (!p.allowedInApp) {
                                      _message = 'Dirisha la mamombi limefungwa';
                                      _selectedProduct = null;
                                    } else {
                                      _message = null;
                                      _interestRateController.text =
                                          p.defaultInterestRate.toStringAsFixed(2);
                                      _selectedInterestCycle = p.interestCycle ?? 'monthly';
                                    }
                                  }
                                });
                              },
                            ),
                            if (_selectedProduct != null) ...[
                              const SizedBox(height: 8),
                              Text(
                                'Kiwango: TZS ${_formatAmount(_selectedProduct!.minAmount)} - TZS ${_formatAmount(_selectedProduct!.maxAmount)}',
                                style: GoogleFonts.manrope(fontSize: 11, color: Colors.white54),
                              ),
                            ],
                            const SizedBox(height: 24),

                            // Interest Cycle (moved to appear early)
                            Text(
                              'Mzunguko wa Riba',
                              style: GoogleFonts.manrope(
                                fontSize: 14,
                                fontWeight: FontWeight.w600,
                                color: Colors.white70,
                              ),
                            ),
                            const SizedBox(height: 8),
                            DropdownButtonFormField<String>(
                              value: _selectedInterestCycle,
                              decoration: InputDecoration(
                                labelText: 'Chagua mzunguko wa riba',
                                labelStyle: GoogleFonts.manrope(color: Colors.white54),
                                filled: true,
                                fillColor: Colors.white.withOpacity(0.06),
                                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                                ),
                                focusedBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: AppColors.primary, width: 2),
                                ),
                              ),
                              dropdownColor: AppColors.backgroundDark,
                              style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                              items: _interestCycles
                                  .map((cycle) => DropdownMenuItem<String>(
                                        value: cycle['value'],
                                        child: Text(cycle['label']!),
                                      ))
                                  .toList(),
                              onChanged: (v) {
                                setState(() {
                                  _selectedInterestCycle = v;
                                  // Clear duration when cycle changes
                                  _durationController.clear();
                                });
                              },
                            ),
                            const SizedBox(height: 24),

                            // Amount
                            Text(
                              'Kiasi cha Mkopo',
                              style: GoogleFonts.manrope(
                                fontSize: 14,
                                fontWeight: FontWeight.w600,
                                color: Colors.white70,
                              ),
                            ),
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _amountController,
                              keyboardType: TextInputType.number,
                              decoration: InputDecoration(
                                labelText: 'Ingiza kiasi (TZS)',
                                labelStyle: GoogleFonts.manrope(color: Colors.white54),
                                hintText: 'Mf: 1000000',
                                hintStyle: GoogleFonts.manrope(color: Colors.white38),
                                filled: true,
                                fillColor: Colors.white.withOpacity(0.06),
                                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                                ),
                                focusedBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: AppColors.primary, width: 2),
                                ),
                                prefixIcon: Container(
                                  margin: const EdgeInsets.all(12),
                                  padding: const EdgeInsets.symmetric(horizontal: 8),
                                  decoration: BoxDecoration(
                                    color: AppColors.primary.withOpacity(0.1),
                                    borderRadius: BorderRadius.circular(8),
                                  ),
                                  child: Icon(Icons.attach_money_rounded,
                                      color: AppColors.primary, size: 20),
                                ),
                              ),
                              style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                              validator: (v) {
                                if (v == null || v.trim().isEmpty) return 'Ingiza kiasi.';
                                final amt = double.tryParse(v.trim());
                                if (amt == null || amt <= 0) return 'Kiasi si sahihi.';
                                return null;
                              },
                            ),
                            const SizedBox(height: 24),

                            // Interest Rate
                            Text(
                              'Kiwango cha Riba (%)',
                              style: GoogleFonts.manrope(
                                fontSize: 14,
                                fontWeight: FontWeight.w600,
                                color: Colors.white70,
                              ),
                            ),
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _interestRateController,
                              keyboardType: TextInputType.numberWithOptions(decimal: true),
                              decoration: InputDecoration(
                                labelText: 'Ingiza kiwango cha riba',
                                labelStyle: GoogleFonts.manrope(color: Colors.white54),
                                hintText: 'Mf: 12.5',
                                hintStyle: GoogleFonts.manrope(color: Colors.white38),
                                filled: true,
                                fillColor: Colors.white.withOpacity(0.06),
                                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                                ),
                                focusedBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: AppColors.primary, width: 2),
                                ),
                                prefixIcon: Container(
                                  margin: const EdgeInsets.all(12),
                                  padding: const EdgeInsets.symmetric(horizontal: 8),
                                  decoration: BoxDecoration(
                                    color: AppColors.primary.withOpacity(0.1),
                                    borderRadius: BorderRadius.circular(8),
                                  ),
                                  child: Icon(Icons.percent_rounded,
                                      color: AppColors.primary, size: 20),
                                ),
                              ),
                              style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                              validator: (v) {
                                if (v == null || v.trim().isEmpty) return 'Ingiza kiwango cha riba.';
                                final rate = double.tryParse(v.trim());
                                if (rate == null || rate < 0) return 'Kiwango cha riba si sahihi.';
                                return null;
                              },
                            ),
                            if (_selectedProduct != null) ...[
                              const SizedBox(height: 8),
                              Text(
                                'Kiwango: ${_selectedProduct!.minInterestRate}% - ${_selectedProduct!.maxInterestRate}%',
                                style: GoogleFonts.manrope(fontSize: 11, color: Colors.white54),
                              ),
                            ],
                            const SizedBox(height: 24),

                            // Duration (dynamic based on interest cycle)
                            Text(
                              _getDurationLabel(),
                              style: GoogleFonts.manrope(
                                fontSize: 14,
                                fontWeight: FontWeight.w600,
                                color: Colors.white70,
                              ),
                            ),
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _durationController,
                              keyboardType: TextInputType.number,
                              decoration: InputDecoration(
                                labelText: _getDurationHint(),
                                labelStyle: GoogleFonts.manrope(color: Colors.white54),
                                hintText: _getDurationExample(),
                                hintStyle: GoogleFonts.manrope(color: Colors.white38),
                                filled: true,
                                fillColor: Colors.white.withOpacity(0.06),
                                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                                ),
                                focusedBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  borderSide: BorderSide(color: AppColors.primary, width: 2),
                                ),
                                prefixIcon: Container(
                                  margin: const EdgeInsets.all(12),
                                  padding: const EdgeInsets.symmetric(horizontal: 8),
                                  decoration: BoxDecoration(
                                    color: AppColors.primary.withOpacity(0.1),
                                    borderRadius: BorderRadius.circular(8),
                                  ),
                                  child: Icon(Icons.calendar_today_rounded,
                                      color: AppColors.primary, size: 20),
                                ),
                              ),
                              style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                              validator: (v) {
                                if (v == null || v.trim().isEmpty) return 'Ingiza muda.';
                                final dur = int.tryParse(v.trim());
                                if (dur == null || dur <= 0) return 'Muda si sahihi.';
                                return null;
                              },
                            ),
                            const SizedBox(height: 24),

                            // KYC Required Documents
                            if (_selectedProduct != null && _selectedProduct!.filetypes.isNotEmpty) ...[
                              Text(
                                'KYC required (Viambatisho Muhimu)',
                                style: GoogleFonts.manrope(
                                  fontSize: 14,
                                  fontWeight: FontWeight.w600,
                                  color: Colors.white70,
                                ),
                              ),
                              const SizedBox(height: 8),
                              Container(
                                padding: const EdgeInsets.all(16),
                                decoration: BoxDecoration(
                                  color: Colors.white.withOpacity(0.06),
                                  borderRadius: BorderRadius.circular(12),
                                  border: Border.all(color: AppColors.primary.withOpacity(0.3)),
                                ),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      'Viambatisho vinavyohitajika kwa aina hii ya mkopo:',
                                      style: GoogleFonts.manrope(
                                        fontSize: 12,
                                        color: Colors.white70,
                                      ),
                                    ),
                                    const SizedBox(height: 12),
                                    ..._selectedProduct!.filetypes.map((filetype) {
                                      return Padding(
                                        padding: const EdgeInsets.only(bottom: 8),
                                        child: Row(
                                          children: [
                                            Icon(
                                              Icons.check_circle_outline,
                                              color: AppColors.primary,
                                              size: 18,
                                            ),
                                            const SizedBox(width: 8),
                                            Expanded(
                                              child: Text(
                                                filetype.name,
                                                style: GoogleFonts.manrope(
                                                  fontSize: 13,
                                                  color: Colors.white,
                                                ),
                                              ),
                                            ),
                                          ],
                                        ),
                                      );
                                    }).toList(),
                                  ],
                                ),
                              ),
                              const SizedBox(height: 24),
                            ],

                            // Error/Message
                            if (_message != null) ...[
                              Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: Colors.red.withOpacity(0.1),
                                  borderRadius: BorderRadius.circular(8),
                                  border: Border.all(color: Colors.red.withOpacity(0.3)),
                                ),
                                child: Text(
                                  _message!,
                                  style: GoogleFonts.manrope(fontSize: 13, color: Colors.red.shade300),
                                ),
                              ),
                              const SizedBox(height: 16),
                            ],

                            // Submit Button
                            SizedBox(
                              height: 52,
                              child: ElevatedButton(
                                onPressed: _submitting ? null : _submit,
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: AppColors.primary,
                                  foregroundColor: Colors.white,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(14),
                                  ),
                                  elevation: 4,
                                  shadowColor: AppColors.primary.withOpacity(0.3),
                                ),
                                child: _submitting
                                    ? const SizedBox(
                                        height: 24,
                                        width: 24,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          color: Colors.white,
                                        ),
                                      )
                                    : Text(
                                        'Tuma Ombi',
                                        style: GoogleFonts.manrope(
                                          fontWeight: FontWeight.w700,
                                          fontSize: 16,
                                        ),
                                      ),
                              ),
                            ),
                            const SizedBox(height: 24),
                          ],
                        ),
                      ),
                    ),
    );
  }
}
