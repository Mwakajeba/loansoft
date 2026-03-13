import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;

import '../core/api_config.dart';
import '../core/app_colors.dart';
import 'loan_details_screen.dart';
import 'loan_list_screen.dart' show LoanItem, LoanStatusStyle;

/// Lists loans for a selected group member. Fetches from API. Tapping a loan opens
/// loan details (Taarifa za Mkopo + Marejesho) for that member's loan.
class MemberLoansScreen extends StatefulWidget {
  /// Customer ID of the member (used for API loans + loan-detail).
  final int memberId;
  final String memberName;

  const MemberLoansScreen({
    super.key,
    required this.memberId,
    required this.memberName,
  });

  @override
  State<MemberLoansScreen> createState() => _MemberLoansScreenState();
}

class _MemberLoansScreenState extends State<MemberLoansScreen> {
  List<LoanItem> _loans = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _fetchLoans();
  }

  Future<void> _fetchLoans() async {
    setState(() { _loading = true; _error = null; });
    try {
      final url = Uri.parse(ApiConfig.customerUrl('loans'));
      final res = await http.post(
        url,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'customer_id': widget.memberId}),
      );
      final body = res.body;
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
        setState(() {
          _loans = loansList;
          _loading = false;
          _error = null;
        });
      } else {
        setState(() {
          _error = data['message']?.toString() ?? 'Hitilafu ya kupakia mikopo';
          _loading = false;
        });
      }
    } catch (e) {
      setState(() {
        _error = ApiConfig.networkErrorMessage(e);
        _loading = false;
      });
    }
  }

  String _formatAmount(num n) {
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

  String _formatDate(String? d) {
    if (d == null || d.isEmpty) return '—';
    try {
      final dt = DateTime.tryParse(d);
      if (dt == null) return d;
      const months = ['Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ago', 'Sep', 'Okt', 'Nov', 'Des'];
      return '${dt.day} ${months[dt.month - 1]}, ${dt.year}';
    } catch (_) {
      return d;
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
          'Mikopo - ${widget.memberName}',
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
                    child: Text(
                      _error!,
                      textAlign: TextAlign.center,
                      style: GoogleFonts.manrope(color: Colors.white70, fontSize: 14),
                    ),
                  ),
                )
              : _loans.isEmpty
                  ? Center(
                      child: Text(
                        'Hakuna mikopo inayoendelea',
                        style: GoogleFonts.manrope(fontSize: 15, color: Colors.white54),
                      ),
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
                      itemCount: _loans.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 16),
                      itemBuilder: (context, index) {
                        final loan = _loans[index];
                        return _buildLoanCard(context, loan);
                      },
                    ),
    );
  }

  Widget _buildLoanCard(BuildContext context, LoanItem loan) {
    const cardColor = Color(0xFF1E293B);
    final amountStr = _formatAmount(loan.totalAmount > 0 ? loan.totalAmount : loan.amount);
    final date = _formatDate(loan.lastRepaymentDate ?? loan.disbursedOn);
    final actionIcon = loan.statusStyle == LoanStatusStyle.completed
        ? Icons.check_circle_rounded
        : loan.statusStyle == LoanStatusStyle.pending
            ? Icons.hourglass_empty_rounded
            : Icons.chevron_right_rounded;
    final actionColor = loan.statusStyle == LoanStatusStyle.completed
        ? Colors.white54
        : loan.statusStyle == LoanStatusStyle.pending
            ? AppColors.primary
            : AppColors.primary;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: () {
          Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => LoanDetailsScreen(
                loanNo: loan.loanNo,
                amount: amountStr,
                loanId: loan.loanId,
                customerId: widget.memberId,
              ),
            ),
          );
        },
        borderRadius: BorderRadius.circular(14),
        child: Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: cardColor,
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
                        'NAMBARI YA MKOPO: ${loan.loanNo}',
                        style: GoogleFonts.manrope(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 0.5,
                          color: Colors.white54,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'TSh $amountStr',
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
                      color: actionColor.withOpacity(0.2),
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text(
                      loan.statusLabel,
                      style: GoogleFonts.manrope(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: actionColor,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Kilicholipwa',
                          style: GoogleFonts.manrope(
                            fontSize: 10,
                            fontWeight: FontWeight.w600,
                            color: Colors.white54,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'TSh ${_formatAmount(loan.totalRepaid)}',
                          style: GoogleFonts.manrope(
                            fontSize: 13,
                            fontWeight: FontWeight.w700,
                            color: const Color(0xFF22C55E),
                          ),
                        ),
                      ],
                    ),
                  ),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Kilichobaki',
                          style: GoogleFonts.manrope(
                            fontSize: 10,
                            fontWeight: FontWeight.w600,
                            color: Colors.white54,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'TSh ${_formatAmount(loan.totalDue)}',
                          style: GoogleFonts.manrope(
                            fontSize: 13,
                            fontWeight: FontWeight.w700,
                            color: Colors.white,
                          ),
                        ),
                      ],
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
                        loan.statusStyle == LoanStatusStyle.completed ? 'Umekwisha' : 'Angalia',
                        style: GoogleFonts.manrope(
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                          color: AppColors.primary,
                        ),
                      ),
                      const SizedBox(width: 4),
                      Icon(actionIcon, size: 18, color: AppColors.primary),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
