import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;

import '../core/api_config.dart';
import '../core/app_colors.dart';
import '../widgets/app_bottom_nav.dart';
import 'loan_details_screen.dart';
import 'loan_application_screen.dart';
import 'profile_screen.dart';

enum LoanStatusStyle { active, pending, completed }

/// One loan from API (customer/loans).
class LoanItem {
  final int loanId;
  final String loanNo;
  final num amount;
  final num totalAmount;
  final String status;
  final String? lastRepaymentDate;
  final String? disbursedOn;
  final num totalRepaid;
  final num totalDue;

  LoanItem({
    required this.loanId,
    required this.loanNo,
    required this.amount,
    required this.totalAmount,
    required this.status,
    this.lastRepaymentDate,
    this.disbursedOn,
    required this.totalRepaid,
    required this.totalDue,
  });

  static LoanItem fromJson(Map<String, dynamic> j) {
    int parseId(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is String) return int.tryParse(v) ?? 0;
      return 0;
    }
    num parseNum(dynamic v) {
      if (v == null) return 0;
      if (v is num) return v;
      if (v is String) return num.tryParse(v) ?? 0;
      return 0;
    }
    return LoanItem(
      loanId: parseId(j['loanid'] ?? j['loan_id']),
      loanNo: (j['loan_no'] ?? j['loanNo'] ?? '#').toString(),
      amount: parseNum(j['amount']),
      totalAmount: parseNum(j['total_amount'] ?? j['amount']),
      status: (j['status'] ?? 'active').toString(),
      lastRepaymentDate: j['last_repayment_date']?.toString(),
      disbursedOn: j['disbursed_on']?.toString(),
      totalRepaid: parseNum(j['total_repaid']),
      totalDue: parseNum(j['total_due']),
    );
  }

  LoanStatusStyle get statusStyle {
    if (status == 'active' || status == 'disbursed') return LoanStatusStyle.active;
    if (status == 'completed' || status == 'closed') return LoanStatusStyle.completed;
    return LoanStatusStyle.pending;
  }

  String get statusLabel {
    if (statusStyle == LoanStatusStyle.active) return 'Unaendelea';
    if (statusStyle == LoanStatusStyle.completed) return 'Umekwisha';
    return 'Inahakikiwa';
  }
}

/// YAWOTE - Hali ya Mikopo (loan list) screen. Fetches loans from API when customerId is set.
class LoanListScreen extends StatefulWidget {
  final int? customerId;

  const LoanListScreen({super.key, this.customerId});

  @override
  State<LoanListScreen> createState() => _LoanListScreenState();
}

class _LoanListScreenState extends State<LoanListScreen> {
  int _filterIndex = 0; // 0: Yote, 1: Inayoendelea, 2: Iliyopita
  List<LoanItem> _loans = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (widget.customerId != null) _fetchLoans();
    else {
      _loading = false;
      _error = 'Tafadhali ingia kwanza';
    }
  }

  Future<void> _fetchLoans() async {
    if (widget.customerId == null) return;
    setState(() { _loading = true; _error = null; });
    try {
      final url = Uri.parse(ApiConfig.customerUrl('loans'));
      final res = await http.post(
        url,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'customer_id': widget.customerId}),
      );
      final String body = res.body;
      if (body.trim().isEmpty) {
        setState(() { _error = 'Seva haikujibu'; _loading = false; });
        return;
      }
      Map<String, dynamic> data;
      try {
        data = jsonDecode(body) as Map<String, dynamic>? ?? {};
      } catch (_) {
        setState(() { _error = 'Majibu ya seva si sahihi'; _loading = false; });
        return;
      }
      final status = data['status'];
      final isOk = status == 200 || status == '200';
      if (res.statusCode == 200 && isOk) {
        final list = data['loans'];
        final loansList = list is List<dynamic>
            ? list.map((e) => LoanItem.fromJson(Map<String, dynamic>.from(e as Map))).toList()
            : <LoanItem>[];
        // Filter out restructured loans
        final filteredLoans = loansList.where((loan) => loan.status.toLowerCase() != 'restructured').toList();
        setState(() {
          _loans = filteredLoans;
          _loading = false;
          _error = null;
        });
      } else {
        setState(() {
          _error = data['message']?.toString() ?? 'Hitilafu ya kupakia mikopo';
          _loading = false;
        });
      }
    } on FormatException catch (_) {
      setState(() { _error = 'Majibu ya seva si sahihi'; _loading = false; });
    } catch (e) {
      setState(() {
        _error = ApiConfig.networkErrorMessage(e);
        _loading = false;
      });
    }
  }

  List<LoanItem> get _filteredLoans {
    if (_filterIndex == 1) return _loans.where((l) => l.statusStyle == LoanStatusStyle.active).toList();
    if (_filterIndex == 2) return _loans.where((l) => l.statusStyle == LoanStatusStyle.completed).toList();
    return _loans;
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

  static String _formatDate(String? d) {
    if (d == null || d.isEmpty) return '—';
    try {
      final dt = DateTime.tryParse(d);
      if (dt == null) return d;
      const months = ['Jan','Feb','Mac','Apr','Mei','Jun','Jul','Ago','Sep','Okt','Nov','Des'];
      return '${dt.day} ${months[dt.month - 1]}, ${dt.year}';
    } catch (_) { return d; }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.backgroundDark,
      appBar: AppBar(
        title: Text(
          'Mikopo Yangu',
          style: GoogleFonts.manrope(
            fontSize: 18,
            fontWeight: FontWeight.w700,
            color: Colors.white,
          ),
        ),
        backgroundColor: AppColors.backgroundDark,
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 24, 16, 140),
                children: [
                  // Filter tabs
                  Container(
                    padding: const EdgeInsets.all(4),
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(0.06),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Row(
                      children: [
                        _filterTab(0, 'Yote'),
                        _filterTab(1, 'Inayoendelea'),
                        _filterTab(2, 'Iliyopita'),
                      ],
                    ),
                  ),
                  const SizedBox(height: 32),
                  if (_loading)
                    const Padding(
                      padding: EdgeInsets.all(32),
                      child: Center(child: CircularProgressIndicator(color: AppColors.primary)),
                    )
                  else if (_error != null)
                    Padding(
                      padding: const EdgeInsets.all(24),
                      child: Center(
                        child: Text(
                          _error!,
                          style: GoogleFonts.manrope(fontSize: 14, color: Colors.white54),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    )
                  else ...[
                    ..._filteredLoans.map((loan) => Padding(
                      padding: const EdgeInsets.only(bottom: 16),
                      child: _buildLoanCardFromItem(context, loan),
                    )),
                    const SizedBox(height: 16),
                  ],
                  // CTA banner
                  Container(
                    padding: const EdgeInsets.all(24),
                    decoration: BoxDecoration(
                      gradient: const LinearGradient(
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                        colors: [AppColors.primary, Color(0xFF1D4ED8)],
                      ),
                      borderRadius: BorderRadius.circular(20),
                      boxShadow: [
                        BoxShadow(
                          color: AppColors.primary.withOpacity(0.3),
                          blurRadius: 20,
                          offset: const Offset(0, 8),
                        ),
                      ],
                    ),
                    child: Stack(
                      clipBehavior: Clip.none,
                      children: [
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Unahitaji Mkopo Zaidi?',
                              style: GoogleFonts.manrope(
                                fontSize: 18,
                                fontWeight: FontWeight.w700,
                                color: Colors.white,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Omba mkopo mpya sasa upate pesa ndani ya dakika 5.',
                              style: GoogleFonts.manrope(
                                fontSize: 12,
                                fontWeight: FontWeight.w500,
                                color: Colors.blue.shade100,
                              ),
                            ),
                            const SizedBox(height: 16),
                            Material(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(10),
                              child: InkWell(
                                onTap: () {
                                  if (widget.customerId == null) return;
                                  Navigator.of(context).push(
                                    MaterialPageRoute(
                                      builder: (_) => LoanApplicationScreen(
                                        customerId: widget.customerId,
                                      ),
                                    ),
                                  );
                                },
                                borderRadius: BorderRadius.circular(10),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                                  child: Text(
                                    'Omba Mkopo',
                                    style: GoogleFonts.manrope(
                                      fontSize: 14,
                                      fontWeight: FontWeight.w700,
                                      color: AppColors.primary,
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),
                        Positioned(
                          right: -16,
                          bottom: -16,
                          child: Opacity(
                            opacity: 0.2,
                            child: Icon(Icons.account_balance_wallet_rounded, color: Colors.white, size: 120),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            // Bottom nav (includes one home indicator)
            _buildBottomNav(),
          ],
        ),
      ),
    );
  }

  Widget _filterTab(int index, String label) {
    final selected = _filterIndex == index;
    return Expanded(
      child: Material(
        color: selected ? AppColors.primary : Colors.transparent,
        borderRadius: BorderRadius.circular(10),
        child: InkWell(
          onTap: () => setState(() => _filterIndex = index),
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

  Widget _buildLoanCardFromItem(BuildContext context, LoanItem loan) {
    final date = _formatDate(loan.lastRepaymentDate ?? loan.disbursedOn);
    final actionIcon = loan.statusStyle == LoanStatusStyle.completed
        ? Icons.check_circle_rounded
        : loan.statusStyle == LoanStatusStyle.pending
            ? Icons.hourglass_empty_rounded
            : Icons.chevron_right_rounded;
    final actionColor = loan.statusStyle == LoanStatusStyle.completed
        ? Colors.white54
        : loan.statusStyle == LoanStatusStyle.pending
            ? AppColors.brandRed
            : AppColors.primary;
    return _buildLoanCard(
      context: context,
      loanNo: loan.loanNo,
      amount: _formatAmount(loan.totalAmount > 0 ? loan.totalAmount : loan.amount),
      status: loan.statusLabel,
      statusStyle: loan.statusStyle,
      date: date,
      action: loan.statusStyle == LoanStatusStyle.completed ? 'Umerejeshwa' : 'Angalia',
      actionIcon: actionIcon,
      actionColor: actionColor,
      opacity: loan.statusStyle == LoanStatusStyle.completed ? 0.85 : 1,
      loanId: loan.loanId,
      customerId: widget.customerId,
    );
  }

  Widget _buildLoanCard({
    required BuildContext context,
    required String loanNo,
    required String amount,
    required String status,
    required LoanStatusStyle statusStyle,
    required String date,
    required String action,
    required IconData actionIcon,
    required Color actionColor,
    double opacity = 1,
    int? loanId,
    int? customerId,
  }) {
    Color statusBg;
    Color statusFg;
    switch (statusStyle) {
      case LoanStatusStyle.active:
        statusBg = AppColors.primary.withOpacity(0.2);
        statusFg = AppColors.primary;
        break;
      case LoanStatusStyle.pending:
        statusBg = Colors.transparent;
        statusFg = AppColors.brandRed;
        break;
      case LoanStatusStyle.completed:
        statusBg = Colors.white.withOpacity(0.1);
        statusFg = Colors.white54;
        break;
    }
    return Opacity(
      opacity: opacity,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: () {
            Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => LoanDetailsScreen(
                  loanNo: loanNo,
                  amount: amount,
                  loanId: loanId,
                  customerId: customerId,
                ),
              ),
            );
          },
          borderRadius: BorderRadius.circular(14),
          child: Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: const Color(0xFF1E293B),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Colors.white.withOpacity(0.08)),
            ),
            child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'NAMBA YA MKOPO: $loanNo',
                      style: GoogleFonts.manrope(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 0.5,
                        color: Colors.white54,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'TSh $amount',
                      style: GoogleFonts.manrope(
                        fontSize: 20,
                        fontWeight: FontWeight.w700,
                        color: Colors.white,
                      ),
                    ),
                  ],
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: statusBg,
                    borderRadius: BorderRadius.circular(999),
                    border: statusStyle == LoanStatusStyle.pending
                        ? Border.all(color: AppColors.brandRed)
                        : null,
                  ),
                  child: Text(
                    status,
                    style: GoogleFonts.manrope(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: statusFg,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    Icon(Icons.calendar_today_rounded, size: 14, color: Colors.white54),
                    const SizedBox(width: 6),
                    Text(
                      date,
                      style: GoogleFonts.manrope(
                        fontSize: 14,
                        fontWeight: FontWeight.w500,
                        color: Colors.white54,
                      ),
                    ),
                  ],
                ),
                Row(
                  children: [
                    Text(
                      action,
                      style: GoogleFonts.manrope(
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                        color: actionColor,
                      ),
                    ),
                    const SizedBox(width: 4),
                    Icon(actionIcon, size: 18, color: actionColor),
                  ],
                ),
              ],
            ),
          ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildBottomNav() {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        AppBottomNav(
          current: AppNavIndex.mikopo,
          userId: widget.customerId,
          onPlusPressed: () {
            Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => LoanApplicationScreen(customerId: widget.customerId),
              ),
            );
          },
        ),
        _buildHomeIndicator(),
      ],
    );
  }

  Widget _buildHomeIndicator() {
    return Center(
      child: Container(
        width: 128,
        height: 4,
        margin: const EdgeInsets.only(bottom: 8),
        decoration: BoxDecoration(
          color: Colors.white24,
          borderRadius: BorderRadius.circular(2),
        ),
      ),
    );
  }
}
