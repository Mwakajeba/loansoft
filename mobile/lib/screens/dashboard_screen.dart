import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import '../core/api_config.dart';
import '../core/app_colors.dart';
import '../core/user_photo.dart';
import '../widgets/app_bottom_nav.dart';
import 'group_members_screen.dart';
import 'loan_list_screen.dart';
import 'loan_application_screen.dart';
import 'miamala_screen.dart';
import 'profile_screen.dart';

class _AnnouncementItem {
  final int id;
  final String title;
  final String? description;
  final String? imageUrl;

  _AnnouncementItem({
    required this.id,
    required this.title,
    this.description,
    this.imageUrl,
  });

  factory _AnnouncementItem.fromJson(Map<String, dynamic> j) {
    return _AnnouncementItem(
      id: j['id'] is int ? j['id'] as int : int.tryParse(j['id']?.toString() ?? '0') ?? 0,
      title: j['title']?.toString() ?? '',
      description: j['description']?.toString(),
      imageUrl: j['image_url']?.toString(),
    );
  }
}

/// YAWOTE dashboard / home screen after login.
class DashboardScreen extends StatefulWidget {
  final int? userId;
  final String name;
  final String? phone;
  /// User photo URL (from login or profile upload). Dashboard shows this or avatar.
  final String? photo;
  /// Group ID (from login) to attach to loan applications (when customer is in a group).
  final int? groupId;
  /// Total outstanding loan balance from API (shown in top card).
  final double? totalLoanBalance;
  /// Days until next due (positive) or days overdue (negative). Null if no upcoming payment.
  final int? nextDueDays;
  /// Amount for the next/overdue payment (for display when overdue).
  final double? nextDueAmount;
  /// Group name (from login) for Kikundi screen.
  final String? groupName;
  /// Loans with repayments from login (for Miamala ya hivi karibuni).
  final List<dynamic>? loans;

  const DashboardScreen({
    super.key,
    this.userId,
    this.name = 'Guest',
    this.phone,
    this.photo,
    this.groupId,
    this.totalLoanBalance,
    this.nextDueDays,
    this.nextDueAmount,
    this.groupName,
    this.loans,
  });

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

/// First letter of each word caps, rest lowercase.
String _titleCaseName(String name) {
  if (name.isEmpty) return name;
  return name.split(' ').map((w) {
    if (w.isEmpty) return w;
    return w[0].toUpperCase() + w.substring(1).toLowerCase();
  }).join(' ');
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

class _DashboardScreenState extends State<DashboardScreen> {
  List<_AnnouncementItem> _announcements = [];
  bool _loadingAnnouncements = false;

  List<_AnnouncementItem> get announcements => _announcements;

  @override
  void initState() {
    super.initState();
    _loadAnnouncements();
  }

  Future<void> _loadAnnouncements() async {
    if (widget.userId == null) return;
    setState(() {
      _loadingAnnouncements = true;
    });
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}/api/customer/announcements?customer_id=${widget.userId}');
      final res = await http.get(uri, headers: {'Accept': 'application/json'});
      if (res.statusCode != 200) {
        setState(() {
          _announcements = [];
          _loadingAnnouncements = false;
        });
        return;
      }
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
      if (data['status'] == 200 && data['announcements'] is List) {
        final list = data['announcements'] as List<dynamic>;
        setState(() {
          _announcements = list
              .whereType<Map<String, dynamic>>()
              .map(_AnnouncementItem.fromJson)
              .toList();
          _loadingAnnouncements = false;
        });
      } else {
        setState(() {
          _announcements = [];
          _loadingAnnouncements = false;
        });
      }
    } catch (_) {
      setState(() {
        _announcements = [];
        _loadingAnnouncements = false;
      });
    }
  }
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.backgroundDark,
      body: SafeArea(
        child: Column(
          children: [
            // Header
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 24, 24, 16),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Karibu,',
                        style: GoogleFonts.manrope(
                          fontSize: 14,
                          fontWeight: FontWeight.w500,
                          color: Colors.white54,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        _titleCaseName(widget.name),
                        style: GoogleFonts.manrope(
                          fontSize: 17,
                          fontWeight: FontWeight.w800,
                          letterSpacing: -0.5,
                          color: Colors.white,
                        ),
                      ),
                    ],
                  ),
                  Stack(
                    clipBehavior: Clip.none,
                    children: [
                      Container(
                        width: 48,
                        height: 48,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: AppColors.primary.withOpacity(0.3), width: 2),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withOpacity(0.2),
                              blurRadius: 8,
                              offset: const Offset(0, 2),
                            ),
                          ],
                        ),
                        child: ClipOval(
                          child: _buildUserAvatar(),
                        ),
                      ),
                      Positioned(
                        bottom: 0,
                        right: 0,
                        child: Container(
                          width: 14,
                          height: 14,
                          decoration: BoxDecoration(
                            color: Colors.green,
                            shape: BoxShape.circle,
                            border: Border.all(color: AppColors.backgroundDark, width: 2),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            // Scrollable content
            Expanded(
              child: ScrollConfiguration(
                behavior: ScrollConfiguration.of(context).copyWith(scrollbars: false),
                child: ListView(
                  padding: const EdgeInsets.symmetric(horizontal: 24),
                  children: [
                    // Loan limit card
                    _buildLoanLimitCard(),
                    const SizedBox(height: 32),
                    // Quick actions
                    _buildQuickActions(),
                    const SizedBox(height: 32),
                    // Payment cards
                    _buildPaymentCards(),
                    const SizedBox(height: 32),
                    // Recent activity
                    _buildRecentActivity(),
                    const SizedBox(height: 24),
                    // Matangazo sliding cards (only when announcements exist)
                    if (announcements.length > 0) _buildMatangazoSlider(),
                    const SizedBox(height: 100),
                  ],
                ),
              ),
            ),
            // Bottom nav (includes one home indicator line)
            _buildBottomNav(),
          ],
        ),
      ),
    );
  }

  Widget _buildLoanLimitCard() {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.primary, AppColors.primary.withOpacity(0.8)],
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
        children: [
          Positioned(top: -40, right: -40, child: _blurCircle(80, Colors.white24)),
          Positioned(bottom: -40, left: -40, child: _blurCircle(64, Colors.black26)),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'MIKOPO INAYODAIWA',
                    style: GoogleFonts.manrope(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      letterSpacing: 1,
                      color: Colors.white70,
                    ),
                  ),
                  Icon(Icons.info_outline_rounded, color: Colors.white54, size: 20),
                ],
              ),
              const SizedBox(height: 8),
              Row(
                crossAxisAlignment: CrossAxisAlignment.baseline,
                textBaseline: TextBaseline.alphabetic,
                children: [
                  Text(
                    'TSh',
                    style: GoogleFonts.manrope(
                      fontSize: 18,
                      fontWeight: FontWeight.w700,
                      color: Colors.white70,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    _formatAmount(widget.totalLoanBalance ?? 0),
                    style: GoogleFonts.manrope(
                      fontSize: 36,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              _buildDueSubtext(),
            ],
          ),
        ],
      ),
    );
  }

  /// Swahili text for due amount and days (replaces "Tayari Kuchukuliwa").
  /// When overdue with amount, only days text here; amount shown on second line in _buildDueSubtext.
  String _dueSubtext() {
    final days = widget.nextDueDays;
    if (days != null) {
      if (days > 0) return 'Inalipwa ndani ya siku $days';
      if (days < 0) return 'Imechelewa siku ${-days}';
      return 'Inalipwa leo';
    }
    final hasDue = (widget.totalLoanBalance ?? 0) > 0;
    if (hasDue) return 'Kiasi kinachodaiwa';
    return 'Hakuna kiasi kinachodaiwa';
  }

  Widget _buildDueSubtext() {
    final text = _dueSubtext();
    final isOverdue = widget.nextDueDays != null && widget.nextDueDays! < 0;
    final showAmountLine = isOverdue && widget.nextDueAmount != null && widget.nextDueAmount! > 0;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.1),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: Colors.white.withOpacity(0.1)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(
              color: isOverdue ? const Color(0xFFFFCC00) : const Color(0xFF4ADE80),
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 8),
          showAmountLine
              ? Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      text,
                      style: GoogleFonts.manrope(
                        fontSize: 12,
                        fontWeight: FontWeight.w500,
                        color: Colors.white,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      'Kiasi kilichocheleweshwa: TSh ${_formatAmount(widget.nextDueAmount!)}',
                      style: GoogleFonts.manrope(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: Colors.white70,
                      ),
                    ),
                  ],
                )
              : Text(
                  text,
                  style: GoogleFonts.manrope(
                    fontSize: 12,
                    fontWeight: FontWeight.w500,
                    color: Colors.white,
                  ),
                ),
        ],
      ),
    );
  }

  Widget _blurCircle(double size, Color color) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        boxShadow: [
          BoxShadow(color: color, blurRadius: size * 0.5, spreadRadius: size * 0.2),
        ],
      ),
    );
  }

  void _onQuickActionTap(int index) {
    if (index == 0) {
      if (widget.userId == null) return;
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => LoanListScreen(customerId: widget.userId),
        ),
      );
    } else if (index == 1) {
      if (widget.userId == null) return;
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => GroupMembersScreen(
            customerId: widget.userId!,
            groupName: widget.groupName,
            groupId: widget.groupId,
          ),
        ),
      );
    } else if (index == 2) {
      if (widget.userId == null) return;
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => MiamalaScreen(customerId: widget.userId),
        ),
      );
    }
  }

  Widget _buildQuickActions() {
    final actions = [
      (icon: Icons.account_balance_wallet_rounded, label: 'Mikopo', color: AppColors.primary),
      (icon: Icons.groups_rounded, label: 'Kikundi', color: AppColors.primary),
      (icon: Icons.receipt_long_rounded, label: 'Miamala', color: AppColors.primary),
    ];
    return Row(
      children: actions.asMap().entries.map((entry) {
        final i = entry.key;
        final a = entry.value;
        return Expanded(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 6),
            child: Material(
              color: a.color.withOpacity(0.1),
              borderRadius: BorderRadius.circular(14),
              child: InkWell(
                onTap: () => _onQuickActionTap(i),
                borderRadius: BorderRadius.circular(14),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 20),
                  child: Column(
                    children: [
                      Container(
                        width: 48,
                        height: 48,
                        decoration: BoxDecoration(
                          color: a.color,
                          shape: BoxShape.circle,
                          boxShadow: [
                            BoxShadow(
                              color: a.color.withOpacity(0.3),
                              blurRadius: 12,
                              offset: const Offset(0, 4),
                            ),
                          ],
                        ),
                        child: Icon(a.icon, color: Colors.white, size: 24),
                      ),
                      const SizedBox(height: 12),
                      Text(
                        a.label,
                        style: GoogleFonts.manrope(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                        ),
                        textAlign: TextAlign.center,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
      }).toList(),
    );
  }

  Widget _buildPaymentCards() {
    final cards = [
      (icon: Icons.add_circle_outline_rounded, label: 'Weka Pesa', color: AppColors.primary),
      (icon: Icons.remove_circle_outline_rounded, label: 'Toa Pesa', color: AppColors.primary),
      (icon: Icons.receipt_rounded, label: 'Lipa Bili', color: AppColors.primary),
    ];
    return Row(
      children: cards.asMap().entries.map((entry) {
        final card = entry.value;
        return Expanded(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 6),
            child: Material(
              color: card.color.withOpacity(0.1),
              borderRadius: BorderRadius.circular(14),
              child: InkWell(
                onTap: () {
                  // TODO: Navigate to respective screens
                },
                borderRadius: BorderRadius.circular(14),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 20),
                  child: Column(
                    children: [
                      Container(
                        width: 48,
                        height: 48,
                        decoration: BoxDecoration(
                          color: card.color,
                          shape: BoxShape.circle,
                          boxShadow: [
                            BoxShadow(
                              color: card.color.withOpacity(0.3),
                              blurRadius: 12,
                              offset: const Offset(0, 4),
                            ),
                          ],
                        ),
                        child: Icon(card.icon, color: Colors.white, size: 24),
                      ),
                      const SizedBox(height: 12),
                      Text(
                        card.label,
                        style: GoogleFonts.manrope(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                        ),
                        textAlign: TextAlign.center,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
      }).toList(),
    );
  }

  /// Build up to 5 recent activities from loans (repayments + disbursements), sorted by date desc.
  List<Map<String, dynamic>> _recentActivitiesFromLoans() {
    final activities = <Map<String, dynamic>>[];
    final loans = widget.loans ?? [];
    for (final loan in loans) {
      if (loan is! Map<String, dynamic>) continue;
      final loanNo = loan['loan_no']?.toString() ?? '#';
      final disbursedOn = loan['disbursed_on']?.toString();
      if (disbursedOn != null && disbursedOn.isNotEmpty) {
        final amount = _parseNum(loan['total_amount'] ?? loan['amount']);
        activities.add({
          'sortKey': disbursedOn,
          'title': 'Mkopo Mpya',
          'subtitle': loanNo,
          'dateStr': _formatActivityDate(disbursedOn),
          'amountStr': '-TSh ${_formatAmount(amount)}',
          'isPositive': false,
          'color': AppColors.primary,
          'icon': Icons.arrow_upward_rounded,
        });
      }
      final repayments = loan['repayments'];
      if (repayments is List) {
        for (final r in repayments) {
          if (r is! Map<String, dynamic>) continue;
          final date = r['date']?.toString();
          if (date == null || date.isEmpty) continue;
          final amount = _parseNum(r['amount']);
          activities.add({
            'sortKey': date,
            'title': 'Malipo',
            'subtitle': loanNo,
            'dateStr': _formatActivityDate(date),
            'amountStr': '+TSh ${_formatAmount(amount)}',
            'isPositive': true,
            'color': const Color(0xFF10B981),
            'icon': Icons.arrow_downward_rounded,
          });
        }
      }
    }
    activities.sort((a, b) => (b['sortKey'] as String).compareTo(a['sortKey'] as String));
    return activities.take(5).toList();
  }

  double _parseNum(dynamic v) {
    if (v == null) return 0;
    if (v is num) return v.toDouble();
    if (v is String) return double.tryParse(v) ?? 0;
    return 0;
  }

  String _formatActivityDate(String dateStr) {
    try {
      final s = dateStr.split(' ').first;
      final parts = s.split('-');
      if (parts.length >= 3) {
        final y = parts[0];
        final m = int.tryParse(parts[1]) ?? 0;
        final d = parts[2];
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ago', 'Sep', 'Okt', 'Nov', 'Des'];
        final monthName = m >= 1 && m <= 12 ? months[m - 1] : m.toString();
        final now = DateTime.now();
        final dt = DateTime(int.tryParse(y) ?? 0, m, int.tryParse(d) ?? 0);
        if (dt.year == now.year && dt.month == now.month && dt.day == now.day) {
          return 'Leo';
        }
        return '$d $monthName $y';
      }
    } catch (_) {}
    return dateStr;
  }

  Widget _buildRecentActivity() {
    final items = _recentActivitiesFromLoans();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Miamala ya hivi karibuni',
          style: GoogleFonts.manrope(
            fontSize: 18,
            fontWeight: FontWeight.w700,
            color: Colors.white,
          ),
        ),
        const SizedBox(height: 12),
        if (items.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 24),
            child: Center(
              child: Text(
                'Hakuna miamala ya hivi karibuni.',
                style: GoogleFonts.manrope(
                  fontSize: 14,
                  color: Colors.white54,
                ),
              ),
            ),
          )
        else
          ...items.map((item) => Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white.withOpacity(0.05),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: Colors.white.withOpacity(0.08)),
              ),
              child: Row(
                children: [
                  Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(
                      color: (item['color'] as Color).withOpacity(0.2),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Icon(item['icon'] as IconData, color: item['color'] as Color, size: 22),
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          item['title'] as String,
                          style: GoogleFonts.manrope(
                            fontSize: 14,
                            fontWeight: FontWeight.w700,
                            color: Colors.white,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '${item['subtitle']} · ${item['dateStr']}',
                          style: GoogleFonts.manrope(
                            fontSize: 10,
                            fontWeight: FontWeight.w500,
                            letterSpacing: 0.5,
                            color: Colors.white54,
                          ),
                        ),
                      ],
                    ),
                  ),
                  Text(
                    item['amountStr'] as String,
                    style: GoogleFonts.manrope(
                      fontSize: 14,
                      fontWeight: FontWeight.w800,
                      color: (item['isPositive'] as bool) ? const Color(0xFF10B981) : Colors.white70,
                    ),
                  ),
                ],
              ),
            ),
          )),
      ],
    );
  }

  Widget _buildMatangazoSlider() {
    if (announcements.length == 0) {
      return const SizedBox.shrink();
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Text(
            'Matangazo',
            style: GoogleFonts.manrope(
              fontSize: 18,
              fontWeight: FontWeight.w700,
              color: Colors.white,
            ),
          ),
        ),
        SizedBox(
          height: 120,
          child: PageView.builder(
            itemCount: announcements.length,
            itemBuilder: (context, index) {
              final card = announcements[index];
              return Padding(
                padding: const EdgeInsets.only(right: 16),
                child: Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [
                        AppColors.primary,
                        AppColors.primary.withOpacity(0.8),
                      ],
                    ),
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: [
                      BoxShadow(
                        color: AppColors.primary.withOpacity(0.3),
                        blurRadius: 12,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        card.title,
                        style: GoogleFonts.manrope(
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                          color: Colors.white,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Expanded(
                        child: Text(
                          card.description ?? '',
                          style: GoogleFonts.manrope(
                            fontSize: 12,
                            fontWeight: FontWeight.w500,
                            height: 1.35,
                            color: Colors.white.withOpacity(0.95),
                          ),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _buildUserAvatar() {
    final raw = UserPhotoHolder.currentPhotoUrl ?? widget.photo;
    if (raw == null || raw.isEmpty) {
      return const Icon(Icons.person, color: Colors.white54, size: 28);
    }
    final photoUrl = raw.startsWith('http') ? raw : '${ApiConfig.baseUrl}/${raw.startsWith('/') ? raw.substring(1) : raw}';
    return Image.network(
      photoUrl,
      fit: BoxFit.cover,
      errorBuilder: (_, __, ___) => const Icon(Icons.person, color: Colors.white54, size: 28),
    );
  }

  Widget _buildBottomNav() {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        AppBottomNav(
          current: AppNavIndex.mwanzo,
          userId: widget.userId,
          name: widget.name,
          phone: widget.phone,
          onPlusPressed: () {
            Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => LoanApplicationScreen(
                  customerId: widget.userId,
                  groupId: widget.groupId,
                ),
              ),
            );
          },
        ),
        Center(
          child: Container(
            width: 128,
            height: 4,
            margin: const EdgeInsets.only(bottom: 8),
            decoration: BoxDecoration(
              color: Colors.white24,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
        ),
      ],
    );
  }
}
