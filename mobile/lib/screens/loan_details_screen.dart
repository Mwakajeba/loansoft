import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:url_launcher/url_launcher.dart';

import '../core/api_config.dart';
import '../core/app_colors.dart';
import 'repayment_screen.dart';

class KycRequiredItem {
  final int id;
  final String name;
  final String? description;

  KycRequiredItem({required this.id, required this.name, this.description});

  static KycRequiredItem fromJson(Map<String, dynamic> j) {
    int parseInt(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is String) return int.tryParse(v) ?? 0;
      if (v is num) return v.toInt();
      return 0;
    }

    return KycRequiredItem(
      id: parseInt(j['id']),
      name: j['name']?.toString() ?? '—',
      description: j['description']?.toString(),
    );
  }
}

class OriginalLoanData {
  final int loanId;
  final String loanNo;
  final num amount;
  final num totalAmount;
  final num totalDue;
  final String status;

  OriginalLoanData({
    required this.loanId,
    required this.loanNo,
    required this.amount,
    required this.totalAmount,
    required this.totalDue,
    required this.status,
  });

  static OriginalLoanData? fromJson(Map<String, dynamic>? j) {
    if (j == null) return null;
    int parseInt(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is String) return int.tryParse(v) ?? 0;
      if (v is num) return v.toInt();
      return 0;
    }
    num parseNum(dynamic v) {
      if (v == null) return 0;
      if (v is num) return v;
      if (v is String) return num.tryParse(v) ?? 0;
      return 0;
    }
    return OriginalLoanData(
      loanId: parseInt(j['loanid']),
      loanNo: (j['loan_no'] ?? '#').toString(),
      amount: parseNum(j['amount']),
      totalAmount: parseNum(j['total_amount']),
      totalDue: parseNum(j['total_due']),
      status: (j['status'] ?? 'restructured').toString(),
    );
  }
}

class LoanKycDocumentItem {
  final int id;
  final int fileTypeId;
  final String? fileType;
  final String status; // pending, accepted, denied
  final String? url;
  final String? createdAt;

  LoanKycDocumentItem({
    required this.id,
    required this.fileTypeId,
    this.fileType,
    required this.status,
    this.url,
    this.createdAt,
  });

  static LoanKycDocumentItem fromJson(Map<String, dynamic> j) {
    int parseInt(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is String) return int.tryParse(v) ?? 0;
      if (v is num) return v.toInt();
      return 0;
    }

    return LoanKycDocumentItem(
      id: parseInt(j['id']),
      fileTypeId: parseInt(j['file_type_id']),
      fileType: j['file_type']?.toString(),
      status: (j['status'] ?? 'pending').toString(),
      url: j['url']?.toString(),
      createdAt: j['created_at']?.toString(),
    );
  }
}

/// Loan details from API (loan-detail).
class LoanDetailData {
  final int loanId;
  final String loanNo;
  final num amount;
  final num interestAmount;
  final num totalAmount;
  final String? period;
  final String? interestCycle; // daily, weekly, monthly - for "Muda wa Mkopo" label
  final String? disbursedOn;
  final String? lastRepaymentDate;
  final String status;
  final String? productName;
  final List<ScheduleItem> schedules;
  final List<RepaymentItem> repayments;
  final num totalRepaid;
  final num totalDue;
  final double progressPercent;
  final int? productId;
  final List<KycRequiredItem> kycRequired;
  final List<LoanKycDocumentItem> kycDocuments;
  final OriginalLoanData? originalLoan;

  LoanDetailData({
    required this.loanId,
    required this.loanNo,
    required this.amount,
    required this.interestAmount,
    required this.totalAmount,
    this.period,
    this.interestCycle,
    this.disbursedOn,
    this.lastRepaymentDate,
    required this.status,
    this.productName,
    required this.schedules,
    required this.repayments,
    required this.totalRepaid,
    required this.totalDue,
    required this.progressPercent,
    this.productId,
    this.kycRequired = const [],
    this.kycDocuments = const [],
    this.originalLoan,
  });

  static LoanDetailData? fromJson(Map<String, dynamic>? j) {
    if (j == null) return null;
    try {
      final loanRaw = j['loan'];
      final loan = loanRaw is Map<String, dynamic> ? loanRaw : (j is Map<String, dynamic> ? j : <String, dynamic>{});
      final schedulesRaw = loan['schedules'];
      final schedules = schedulesRaw is List<dynamic> ? schedulesRaw : <dynamic>[];
      final repsRaw = loan['repayments'];
      final reps = repsRaw is List<dynamic> ? repsRaw : <dynamic>[];
      int parseInt(dynamic v) {
        if (v == null) return 0;
        if (v is int) return v;
        if (v is String) return int.tryParse(v) ?? 0;
        if (v is num) return v.toInt();
        return 0;
      }
      num parseNum(dynamic v) {
        if (v == null) return 0;
        if (v is num) return v;
        if (v is String) return num.tryParse(v) ?? 0;
        return 0;
      }
      final scheduleList = <ScheduleItem>[];
      for (final e in schedules) {
        if (e is Map<String, dynamic>) scheduleList.add(ScheduleItem.fromJson(e));
      }
      final repList = <RepaymentItem>[];
      for (final e in reps) {
        if (e is Map<String, dynamic>) repList.add(RepaymentItem.fromJson(e));
      }

      final kycReqRaw = loan['kyc_required'];
      final kycReqListRaw = kycReqRaw is List<dynamic> ? kycReqRaw : <dynamic>[];
      final kycRequired = <KycRequiredItem>[];
      for (final e in kycReqListRaw) {
        if (e is Map<String, dynamic>) kycRequired.add(KycRequiredItem.fromJson(e));
      }

      final kycDocsRaw = loan['kyc_documents'];
      final kycDocsListRaw = kycDocsRaw is List<dynamic> ? kycDocsRaw : <dynamic>[];
      final kycDocuments = <LoanKycDocumentItem>[];
      for (final e in kycDocsListRaw) {
        if (e is Map<String, dynamic>) kycDocuments.add(LoanKycDocumentItem.fromJson(e));
      }

      final originalLoanRaw = loan['original_loan'];
      final originalLoan = originalLoanRaw is Map<String, dynamic> 
          ? OriginalLoanData.fromJson(originalLoanRaw) 
          : null;

      return LoanDetailData(
        loanId: parseInt(loan['loanid']),
        loanNo: (loan['loan_no'] ?? '#').toString(),
        amount: parseNum(loan['amount']),
        interestAmount: parseNum(loan['interest_amount'] ?? loan['interest']),
        totalAmount: parseNum(loan['total_amount']),
        period: loan['period']?.toString(),
        interestCycle: loan['interest_cycle']?.toString(),
        disbursedOn: loan['disbursed_on']?.toString(),
        lastRepaymentDate: loan['last_repayment_date']?.toString(),
        status: (loan['status'] ?? 'active').toString(),
        productName: loan['product_name']?.toString(),
        schedules: scheduleList,
        repayments: repList,
        totalRepaid: parseNum(loan['total_repaid']),
        totalDue: parseNum(loan['total_due']),
        progressPercent: parseNum(loan['progress_percent']).toDouble() / 100.0,
        productId: loan['product_id'] != null ? parseInt(loan['product_id']) : null,
        kycRequired: kycRequired,
        kycDocuments: kycDocuments,
        originalLoan: originalLoan,
      );
    } catch (_) {
      return null;
    }
  }
}

class ScheduleItem {
  final int id;
  final String? dueDate;
  final num totalDue;
  final num paidAmount;
  final num remaining;
  final String status; // paid, pending, upcoming

  ScheduleItem({
    required this.id,
    this.dueDate,
    required this.totalDue,
    required this.paidAmount,
    required this.remaining,
    required this.status,
  });

  static ScheduleItem fromJson(Map<String, dynamic> j) {
    int parseInt(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is String) return int.tryParse(v) ?? 0;
      if (v is num) return v.toInt();
      return 0;
    }
    num parseNum(dynamic v) {
      if (v == null) return 0;
      if (v is num) return v;
      if (v is String) return num.tryParse(v) ?? 0;
      return 0;
    }
    return ScheduleItem(
      id: parseInt(j['id']),
      dueDate: j['due_date']?.toString(),
      totalDue: parseNum(j['total_due']),
      paidAmount: parseNum(j['paid_amount']),
      remaining: parseNum(j['remaining']),
      status: (j['status'] ?? 'upcoming').toString(),
    );
  }
}

class RepaymentItem {
  final num amount;
  final String? paymentDate;
  final String? dueDate;

  RepaymentItem({required this.amount, this.paymentDate, this.dueDate});

  static RepaymentItem fromJson(Map<String, dynamic> j) {
    num parseNum(dynamic v) {
      if (v == null) return 0;
      if (v is num) return v;
      if (v is String) return num.tryParse(v) ?? 0;
      return 0;
    }
    return RepaymentItem(
      amount: parseNum(j['amount']),
      paymentDate: j['payment_date']?.toString(),
      dueDate: j['due_date']?.toString(),
    );
  }
}

/// Loan details screen - "Maelezo ya Mkopo" with tabs and repayment schedule. Loads from API when loanId+customerId set.
class LoanDetailsScreen extends StatefulWidget {
  final String loanNo;
  final String amount;
  final int? loanId;
  final int? customerId;

  const LoanDetailsScreen({
    super.key,
    this.loanNo = '#YW-9021',
    this.amount = '750,000',
    this.loanId,
    this.customerId,
  });

  @override
  State<LoanDetailsScreen> createState() => _LoanDetailsScreenState();
}

class _LoanDetailsScreenState extends State<LoanDetailsScreen> {
  int _tabIndex = 0;
  static const Color _surfaceDark = Color(0xFF16162A);
  LoanDetailData? _loanData;
  bool _loading = true;
  String? _error;
  bool _uploadingKyc = false;
  int? _selectedKycFileTypeId;

  @override
  void initState() {
    super.initState();
    if (widget.loanId != null && widget.customerId != null) {
      _fetchLoanDetail();
    } else {
      _loading = false;
    }
  }

  bool get _isAppliedLoan {
    final s = (_loanData?.status ?? '').toString().toLowerCase().trim();
    return s == 'applied';
  }

  Future<void> _pickAndUploadKycPdf() async {
    if (widget.loanId == null || widget.customerId == null) return;
    if (_loanData == null) return;
    if (_selectedKycFileTypeId == null) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Chagua aina ya kiambatisho (KYC).', style: GoogleFonts.manrope()),
          backgroundColor: Colors.red.shade700,
        ),
      );
      return;
    }

    try {
      final picked = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: const ['pdf'],
      );
      final path = picked?.files.single.path;
      if (path == null || path.isEmpty || !File(path).existsSync()) return;

      setState(() => _uploadingKyc = true);
      final url = Uri.parse(ApiConfig.customerUrl('upload-loan-document'));
      final request = http.MultipartRequest('POST', url);
      request.fields['customer_id'] = widget.customerId.toString();
      request.fields['loan_id'] = widget.loanId.toString();
      request.fields['file_type_id'] = _selectedKycFileTypeId.toString();
      request.files.add(await http.MultipartFile.fromPath('file', path));

      final streamed = await request.send();
      final res = await http.Response.fromStream(streamed);
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};

      if (!mounted) return;
      setState(() => _uploadingKyc = false);

      if (res.statusCode == 200 && (data['status'] == 200)) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Kiambatisho kimepakiwa.', style: GoogleFonts.manrope()),
            backgroundColor: AppColors.primary,
          ),
        );
        await _fetchLoanDetail(); // refresh list + statuses
      } else {
        final msg = data['message']?.toString() ?? 'Imeshindwa kupakia kiambatisho.';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(msg, style: GoogleFonts.manrope()),
            backgroundColor: Colors.red.shade700,
          ),
        );
      }
    } catch (e) {
      if (!mounted) return;
      setState(() => _uploadingKyc = false);
      // Show the real error message so it is easier to diagnose than the generic network message
      final msg = e.toString();
      // Also log to console for debugging
      // ignore: avoid_print
      print('KYC upload error: $msg');
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(msg, style: GoogleFonts.manrope()),
          backgroundColor: Colors.red.shade700,
        ),
      );
    }
  }

  Color _kycStatusColor(String status) {
    switch (status.toLowerCase()) {
      case 'accepted':
      case 'approved':
        return const Color(0xFF22C55E);
      case 'denied':
      case 'rejected':
        return const Color(0xFFEF4444);
      default:
        return Colors.white54;
    }
  }

  IconData _kycStatusIcon(String status) {
    switch (status.toLowerCase()) {
      case 'accepted':
      case 'approved':
        return Icons.check_circle_rounded;
      case 'denied':
      case 'rejected':
        return Icons.cancel_rounded;
      default:
        return Icons.schedule_rounded;
    }
  }

  String _kycStatusLabel(String status) {
    switch (status.toLowerCase()) {
      case 'accepted':
      case 'approved':
        return 'Imekubaliwa';
      case 'denied':
      case 'rejected':
        return 'Imekataliwa';
      default:
        return 'Inasubiri';
    }
  }

  Future<void> _openKycUrl(String? url) async {
    if (url == null || url.trim().isEmpty) return;
    try {
      final uri = Uri.parse(url);
      if (!await canLaunchUrl(uri)) return;
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      // silently ignore
    }
  }

  Future<void> _fetchLoanDetail() async {
    if (widget.loanId == null || widget.customerId == null) return;
    setState(() { _loading = true; _error = null; });
    try {
      final url = Uri.parse(ApiConfig.customerUrl('loan-detail'));
      final res = await http.post(
        url,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({
          'customer_id': widget.customerId,
          'loan_id': widget.loanId,
        }),
      );
      if (res.statusCode == 404) {
        setState(() {
          _error = 'Mkopo haupatikani au enzi ya seva haijasasishwa.';
          _loading = false;
        });
        return;
      }
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
        final loanData = LoanDetailData.fromJson(data);
        if (loanData == null) {
          setState(() {
            _error = 'Majibu ya seva si sahihi.';
            _loading = false;
          });
          return;
        }
        setState(() {
          _loanData = loanData;
          _loading = false;
          _error = null;
        });
      } else {
        setState(() {
          _error = data['message']?.toString() ?? 'Hitilafu ya kupakia';
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

  static String _fmt(num n) {
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

  static String _fmtDate(String? d) {
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
      backgroundColor: const Color(0xFF0A0A1A),
      appBar: AppBar(
        title: Text(
          'Maelezo ya Mkopo',
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
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Container(
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(
                  color: _surfaceDark,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(
                  children: [
                    _tab(0, 'Taarifa za Mkopo'),
                    _tab(1, 'Marejesho'),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),
            // Content
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
                  : _error != null
                      ? Center(
                          child: Padding(
                            padding: const EdgeInsets.all(24),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.error_outline_rounded, size: 48, color: Colors.white54),
                                const SizedBox(height: 12),
                                Text(
                                  _error!,
                                  textAlign: TextAlign.center,
                                  style: GoogleFonts.manrope(color: Colors.white70, fontSize: 14),
                                ),
                              ],
                            ),
                          ),
                        )
                      : SingleChildScrollView(
                          padding: const EdgeInsets.fromLTRB(16, 0, 16, 100),
                          child: _tabIndex == 0 ? _buildLoanInfoTab() : _buildRepaymentsTab(),
                        ),
            ),
            // Footer
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

  /// Format period for display: "30" + interest_cycle -> "30 mwezi", "30 wiki", "30 siku".
  String _formatPeriod(String? period, String? interestCycle) {
    if (period == null || period.isEmpty) return '—';
    final numStr = period.trim();
    if (numStr.isEmpty) return '—';
    final cycle = (interestCycle ?? 'monthly').toString().trim().toLowerCase();
    final unit = cycle.contains('daily') || cycle == 'siku'
        ? 'siku'
        : cycle.contains('weekly') || cycle == 'wiki'
            ? 'wiki'
            : 'mwezi';
    return '$numStr $unit';
  }

  String _statusLabel(String status) {
    switch (status.toLowerCase()) {
      case 'active':
      case 'approved':
        return 'Inayoendelea';
      case 'pending':
      case 'pending_approval':
        return 'Inasubiri';
      case 'completed':
      case 'closed':
        return 'Imekamilika';
      case 'rejected':
        return 'Imekataliwa';
      default:
        return status;
    }
  }

  Widget _buildLoanInfoTab() {
    final d = _loanData;
    final amountStr = d != null ? 'TZS ${_fmt(d.amount)}' : 'TZS ${widget.amount}';
    final interestStr = d != null ? 'TZS ${_fmt(d.interestAmount)}' : '—';
    final totalStr = d != null ? 'TZS ${_fmt(d.totalAmount)}' : '—';
    final dueDateStr = d != null ? _fmtDate(d.lastRepaymentDate ?? d.disbursedOn) : '—';
    final statusStr = d != null ? _statusLabel(d.status) : '—';
    final periodStr = _formatPeriod(d?.period, d?.interestCycle);
    final progress = d?.progressPercent ?? 0.0;
    final progressClamped = progress.clamp(0.0, 1.0);

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
          child: Stack(
            children: [
              Positioned(
                top: 0,
                right: 0,
                  child: Opacity(
                  opacity: 0.1,
                  child: Icon(Icons.account_balance_wallet_rounded, size: 64, color: AppColors.primary),
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Kiasi cha Mkopo',
                    style: GoogleFonts.manrope(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      letterSpacing: 0.5,
                      color: Colors.white54,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    amountStr,
                    style: GoogleFonts.manrope(
                      fontSize: 32,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                    ),
                  ),
                  const SizedBox(height: 16),
                  Container(
                    height: 1,
                    color: Colors.white.withOpacity(0.05),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Riba',
                              style: GoogleFonts.manrope(
                                fontSize: 11,
                                fontWeight: FontWeight.w500,
                                color: Colors.white54,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              interestStr,
                              style: GoogleFonts.manrope(
                                fontSize: 14,
                                fontWeight: FontWeight.w700,
                                color: Colors.white,
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
                              'Jumla ya Kulipa',
                              style: GoogleFonts.manrope(
                                fontSize: 11,
                                fontWeight: FontWeight.w500,
                                color: Colors.white54,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              totalStr,
                              style: GoogleFonts.manrope(
                                fontSize: 14,
                                fontWeight: FontWeight.w700,
                                color: AppColors.primary,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Container(
                    height: 1,
                    color: Colors.white.withOpacity(0.05),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Kilicholipwa',
                              style: GoogleFonts.manrope(
                                fontSize: 11,
                                fontWeight: FontWeight.w500,
                                color: Colors.white54,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              d != null ? 'TZS ${_fmt(d.totalRepaid)}' : '—',
                              style: GoogleFonts.manrope(
                                fontSize: 14,
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
                                fontSize: 11,
                                fontWeight: FontWeight.w500,
                                color: Colors.white54,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              d != null ? 'TZS ${_fmt(d.totalDue)}' : '—',
                              style: GoogleFonts.manrope(
                                fontSize: 14,
                                fontWeight: FontWeight.w700,
                                color: Colors.white,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        _detailRow(Icons.calendar_month_rounded, 'Tarehe ya Kurudisha', dueDateStr),
        const SizedBox(height: 12),
        _detailRow(
          Icons.info_outline_rounded,
          'Hali ya Mkopo',
          statusStr,
          valueColor: statusStr == 'Imekamilika' ? const Color(0xFF22C55E) : null,
        ),
        const SizedBox(height: 12),
        _detailRow(Icons.history_rounded, 'Muda wa Mkopo', periodStr),
        const SizedBox(height: 24),
        Container(
          height: 180,
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: _surfaceDark,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: Colors.white.withOpacity(0.05)),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                'Maendeleo ya Marejesho',
                style: GoogleFonts.manrope(
                  fontSize: 14,
                  fontWeight: FontWeight.w500,
                  color: Colors.white54,
                ),
              ),
              const SizedBox(height: 12),
              ClipRRect(
                borderRadius: BorderRadius.circular(999),
                child: LinearProgressIndicator(
                  value: progressClamped,
                  backgroundColor: Colors.white.withOpacity(0.1),
                  valueColor: AlwaysStoppedAnimation<Color>(AppColors.primary),
                  minHeight: 12,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                '${(progressClamped * 100).round()}% Imekamilika',
                style: GoogleFonts.manrope(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        _buildKycSection(),
        if (_loanData?.originalLoan != null) ...[
          const SizedBox(height: 16),
          _buildOriginalLoanSection(),
        ],
      ],
    );
  }

  Widget _buildOriginalLoanSection() {
    final original = _loanData!.originalLoan!;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: _surfaceDark,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withOpacity(0.05)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.history_rounded, size: 20, color: Colors.white54),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Mkopo wa Asili (Uliyorejesha)',
                  style: GoogleFonts.manrope(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: Colors.white,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.04),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.white.withOpacity(0.06)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Namba: ${original.loanNo}',
                          style: GoogleFonts.manrope(
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            color: Colors.white70,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'TZS ${_fmt(original.totalAmount)}',
                          style: GoogleFonts.manrope(
                            fontSize: 16,
                            fontWeight: FontWeight.w700,
                            color: Colors.white,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Kilichobaki: TZS ${_fmt(original.totalDue)}',
                          style: GoogleFonts.manrope(
                            fontSize: 11,
                            fontWeight: FontWeight.w500,
                            color: Colors.white54,
                          ),
                        ),
                      ],
                    ),
                    SizedBox(
                      height: 40,
                      child: ElevatedButton.icon(
                        onPressed: () {
                          Navigator.of(context).pushReplacement(
                            MaterialPageRoute(
                              builder: (_) => LoanDetailsScreen(
                                loanNo: original.loanNo,
                                amount: _fmt(original.totalAmount),
                                loanId: original.loanId,
                                customerId: widget.customerId,
                              ),
                            ),
                          );
                        },
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.primary,
                          foregroundColor: Colors.white,
                          elevation: 4,
                          shadowColor: AppColors.primary.withOpacity(0.3),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(10),
                          ),
                        ),
                        icon: const Icon(Icons.payments_rounded, size: 18),
                        label: Text(
                          'Lipa',
                          style: GoogleFonts.manrope(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildKycSection() {
    final required = _loanData?.kycRequired ?? [];
    final docs = _loanData?.kycDocuments ?? [];
    final canUpload = _isAppliedLoan && required.isNotEmpty;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: _surfaceDark,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withOpacity(0.05)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.attach_file_rounded, size: 20, color: AppColors.primary),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Viambatisho Muhimu (KYC)',
                  style: GoogleFonts.manrope(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: Colors.white,
                  ),
                ),
              ),
              if (canUpload)
                TextButton(
                  onPressed: _uploadingKyc ? null : () => _openKycUploadSheet(required),
                  child: _uploadingKyc
                      ? const SizedBox(
                          height: 16,
                          width: 16,
                          child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primary),
                        )
                      : Text('Pakia', style: GoogleFonts.manrope(color: AppColors.primary, fontWeight: FontWeight.w700)),
                ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            'PDF pekee inaruhusiwa.',
            style: GoogleFonts.manrope(fontSize: 11, color: Colors.white54),
          ),
          const SizedBox(height: 12),

          if (required.isEmpty)
            Text('Hakuna viambatisho vilivyowekwa kwa bidhaa hii.', style: GoogleFonts.manrope(color: Colors.white54))
          else ...[
            Text('Vinavyohitajika:', style: GoogleFonts.manrope(fontSize: 12, color: Colors.white70, fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: required
                  .map((r) => Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        decoration: BoxDecoration(
                          color: AppColors.primary.withOpacity(0.12),
                          borderRadius: BorderRadius.circular(999),
                          border: Border.all(color: AppColors.primary.withOpacity(0.25)),
                        ),
                        child: Text(r.name, style: GoogleFonts.manrope(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                      ))
                  .toList(),
            ),
          ],

          const SizedBox(height: 16),
          Text('Vilivyopakiwa:', style: GoogleFonts.manrope(fontSize: 12, color: Colors.white70, fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          if (docs.isEmpty)
            Text('Bado hakuna kiambatisho kilichopakiwa.', style: GoogleFonts.manrope(color: Colors.white54))
          else
            Column(
              children: docs.map((d) {
                final c = _kycStatusColor(d.status);
                final icon = _kycStatusIcon(d.status);
                final label = _kycStatusLabel(d.status);
                return InkWell(
                  onTap: d.url != null ? () => _openKycUrl(d.url) : null,
                  borderRadius: BorderRadius.circular(12),
                  child: Container(
                    margin: const EdgeInsets.only(bottom: 10),
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(0.04),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Colors.white.withOpacity(0.06)),
                    ),
                    child: Row(
                      children: [
                        Icon(icon, color: c, size: 22),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(d.fileType ?? 'Kiambatisho', style: GoogleFonts.manrope(color: Colors.white, fontWeight: FontWeight.w700)),
                              const SizedBox(height: 2),
                              Text(
                                d.createdAt != null ? _fmtDate(d.createdAt) : '—',
                                style: GoogleFonts.manrope(fontSize: 11, color: Colors.white54),
                              ),
                            ],
                          ),
                        ),
                        if (d.url != null)
                          Padding(
                            padding: const EdgeInsets.only(right: 8.0),
                            child: Icon(Icons.open_in_new_rounded, color: Colors.white70, size: 18),
                          ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                          decoration: BoxDecoration(
                            color: c.withOpacity(0.12),
                            borderRadius: BorderRadius.circular(999),
                            border: Border.all(color: c.withOpacity(0.35)),
                          ),
                          child: Text(label, style: GoogleFonts.manrope(color: c, fontSize: 11, fontWeight: FontWeight.w800)),
                        ),
                      ],
                    ),
                  ),
                );
              }).toList(),
            ),
        ],
      ),
    );
  }

  void _openKycUploadSheet(List<KycRequiredItem> required) {
    if (required.isEmpty) return;
    if (_selectedKycFileTypeId == null) {
      _selectedKycFileTypeId = required.first.id;
    }

    showDialog<void>(
      context: context,
      barrierColor: Colors.black.withOpacity(0.6),
      builder: (ctx) {
        return Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 380),
            child: Material(
              color: const Color(0xFF0A0A1A),
              borderRadius: BorderRadius.circular(18),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            'Pakia Kiambatisho (PDF)',
                            style: GoogleFonts.manrope(
                              fontSize: 16,
                              fontWeight: FontWeight.w800,
                              color: Colors.white,
                            ),
                          ),
                        ),
                        IconButton(
                          onPressed: () => Navigator.of(ctx).pop(),
                          icon: const Icon(Icons.close_rounded, color: Colors.white54),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<int>(
                      value: _selectedKycFileTypeId,
                      dropdownColor: const Color(0xFF0A0A1A),
                      decoration: InputDecoration(
                        labelText: 'Chagua aina ya kiambatisho',
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
                          borderSide: const BorderSide(color: AppColors.primary, width: 2),
                        ),
                      ),
                      style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
                      items: required
                          .map((r) => DropdownMenuItem<int>(
                                value: r.id,
                                child: Text(r.name),
                              ))
                          .toList(),
                      onChanged: (v) => setState(() => _selectedKycFileTypeId = v),
                    ),
                    const SizedBox(height: 18),
                    SizedBox(
                      width: double.infinity,
                      height: 52,
                      child: ElevatedButton.icon(
                        onPressed: _uploadingKyc
                            ? null
                            : () async {
                                Navigator.of(ctx).pop();
                                await _pickAndUploadKycPdf();
                              },
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.primary,
                          foregroundColor: Colors.white,
                          elevation: 8,
                          shadowColor: AppColors.primary.withOpacity(0.3),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                        ),
                        icon: const Icon(Icons.upload_file_rounded),
                        label: Text(
                          'Chagua PDF & Pakia',
                          style: GoogleFonts.manrope(fontWeight: FontWeight.w800),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _detailRow(IconData icon, String label, String value, {Color? valueColor}) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: _surfaceDark,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withOpacity(0.05)),
      ),
      child: Row(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: AppColors.primary.withOpacity(0.2),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: AppColors.primary, size: 24),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: GoogleFonts.manrope(
                    fontSize: 12,
                    fontWeight: FontWeight.w500,
                    color: Colors.white54,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  value,
                  style: GoogleFonts.manrope(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: valueColor ?? Colors.white,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildRepaymentsTab() {
    final schedules = _loanData?.schedules ?? [];
    final items = schedules.asMap().entries.map((e) {
      final i = e.key + 1;
      final s = e.value;
      final isPaid = s.status == 'paid';
      final isPending = s.status == 'pending';
      final statusLabel = isPaid ? 'Imelipwa' : (isPending ? 'Inasubiri' : 'Bado');
      final phaseDate = _fmtDate(s.dueDate).replaceAll(',', '');
      return (
        amount: 'TZS ${_fmt(s.totalDue)}',
        phase: 'Awamu ya $i • $phaseDate',
        status: statusLabel,
        isPaid: isPaid,
        isPending: isPending,
      );
    }).toList();
    if (items.isEmpty) {
      items.add((
        amount: 'TZS ${_loanData != null ? _fmt(_loanData!.totalDue) : widget.amount}',
        phase: '—',
        status: 'Hakuna ratiba',
        isPaid: false,
        isPending: false,
      ));
    }
    final repaidStr = _loanData != null ? 'TZS ${_fmt(_loanData!.totalRepaid)}' : '—';
    final dueStr = _loanData != null ? 'TZS ${_fmt(_loanData!.totalDue)}' : '—';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.list_alt_rounded, size: 20, color: Colors.white),
            const SizedBox(width: 8),
            Text(
              'Ratiba ya Marejesho',
              style: GoogleFonts.manrope(
                fontSize: 16,
                fontWeight: FontWeight.w700,
                color: Colors.white,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          decoration: BoxDecoration(
            color: _surfaceDark,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: Colors.white.withOpacity(0.05)),
          ),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Kilicholipwa',
                      style: GoogleFonts.manrope(
                        fontSize: 11,
                        fontWeight: FontWeight.w500,
                        color: Colors.white54,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      repaidStr,
                      style: GoogleFonts.manrope(
                        fontSize: 14,
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
                        fontSize: 11,
                        fontWeight: FontWeight.w500,
                        color: Colors.white54,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      dueStr,
                      style: GoogleFonts.manrope(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: Colors.white,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        ...items.map((item) => Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: item.isPending ? _surfaceDark : (item.isPaid ? _surfaceDark : _surfaceDark.withOpacity(0.5)),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: item.isPending ? AppColors.primary.withOpacity(0.2) : Colors.white.withOpacity(0.05),
              ),
              boxShadow: item.isPending ? [BoxShadow(color: AppColors.primary.withOpacity(0.1), blurRadius: 8)] : null,
            ),
            child: Row(
              children: [
                Container(
                  width: 32,
                  height: 32,
                  decoration: BoxDecoration(
                    color: item.isPaid
                        ? const Color(0xFF22C55E).withOpacity(0.2)
                        : item.isPending
                            ? AppColors.primary.withOpacity(0.2)
                            : Colors.white.withOpacity(0.08),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    item.isPaid ? Icons.check_rounded : (item.isPending ? Icons.schedule_rounded : Icons.lock_clock_rounded),
                    size: 18,
                    color: item.isPaid ? const Color(0xFF22C55E) : (item.isPending ? AppColors.primary : Colors.white54),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.amount,
                        style: GoogleFonts.manrope(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        item.phase,
                        style: GoogleFonts.manrope(
                          fontSize: 10,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 0.3,
                          color: Colors.white54,
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: item.isPaid
                        ? const Color(0xFF22C55E).withOpacity(0.1)
                        : item.isPending
                            ? AppColors.primary.withOpacity(0.2)
                            : Colors.white.withOpacity(0.05),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    item.status,
                    style: GoogleFonts.manrope(
                      fontSize: 10,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.3,
                      color: item.isPaid
                          ? const Color(0xFF22C55E)
                          : item.isPending
                              ? AppColors.primary
                              : Colors.white54,
                    ),
                  ),
                ),
              ],
            ),
          ),
        )),
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
              onPressed: () {
                Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => RepaymentScreen(
                      loanId: widget.loanId,
                      customerId: widget.customerId,
                      totalDue: _loanData?.totalDue,
                    ),
                  ),
                );
              },
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
                  const Icon(Icons.payments_rounded, size: 22),
                  const SizedBox(width: 8),
                  Text(
                    'Lipa Sasa',
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
}
