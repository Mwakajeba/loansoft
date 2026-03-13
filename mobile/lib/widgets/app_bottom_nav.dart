import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/app_colors.dart';
import '../screens/loan_list_screen.dart';
import '../screens/profile_screen.dart';
import '../screens/complaints_screen.dart';

/// Index for the four tabs (plus button is center, no index).
/// 0 = Mwanzo, 1 = Mikopo, 3 = Malalamiko, 4 = Akaunti
enum AppNavIndex { mwanzo, mikopo, malalamiko, akaunti }

/// Shared bottom bar: Mwanzo | Mikopo | [+] | Malalamiko | Akaunti
class AppBottomNav extends StatelessWidget {
  final AppNavIndex current;
  final int? userId;
  final String? name;
  final String? phone;
  final VoidCallback? onPlusPressed;

  const AppBottomNav({
    super.key,
    required this.current,
    this.userId,
    this.name,
    this.phone,
    this.onPlusPressed,
  });

  @override
  Widget build(BuildContext context) {
    const labels = ['Mwanzo', 'Mikopo', '', 'Malalamiko', 'Akaunti'];
    const icons = [
      Icons.home_rounded,
      Icons.credit_card_rounded,
      Icons.add,
      Icons.report_problem_rounded,
      Icons.person_rounded,
    ];
    return Container(
      height: 88,
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.backgroundDark.withOpacity(0.9),
        border: Border(top: BorderSide(color: Colors.white.withOpacity(0.08))),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          for (int i = 0; i < 5; i++) ...[
            if (i == 2)
              Transform.translate(
                offset: const Offset(0, -24),
                child: Material(
                  color: AppColors.primary,
                  shape: const CircleBorder(),
                  elevation: 12,
                  shadowColor: AppColors.primary.withOpacity(0.4),
                  child: InkWell(
                    onTap: onPlusPressed ?? () {},
                    customBorder: const CircleBorder(),
                    child: const SizedBox(
                      width: 56,
                      height: 56,
                      child: Icon(Icons.add, color: Colors.white, size: 32),
                    ),
                  ),
                ),
              )
            else
              _NavItem(
                icon: icons[i],
                label: labels[i],
                selected: _indexForTab(i) == current,
                onTap: () => _onTabTap(context, i),
              ),
          ],
        ],
      ),
    );
  }

  AppNavIndex _indexForTab(int i) {
    if (i == 0) return AppNavIndex.mwanzo;
    if (i == 1) return AppNavIndex.mikopo;
    if (i == 3) return AppNavIndex.malalamiko;
    if (i == 4) return AppNavIndex.akaunti;
    return AppNavIndex.mwanzo;
  }

  void _onTabTap(BuildContext context, int i) {
    if (i == 0) {
      Navigator.of(context).popUntil((route) => route.isFirst);
      return;
    }
    if (i == 1) {
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => LoanListScreen(customerId: userId),
        ),
      );
      return;
    }
    if (i == 3) {
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => ComplaintsScreen(customerId: userId),
        ),
      );
      return;
    }
    if (i == 4) {
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => ProfileScreen(
            customerId: userId,
            name: name,
            phone: phone,
          ),
        ),
      );
    }
  }
}

class _NavItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;

  const _NavItem({
    required this.icon,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            icon,
            size: 26,
            color: selected ? AppColors.primary : Colors.white38,
          ),
          const SizedBox(height: 4),
          Text(
            label,
            style: GoogleFonts.manrope(
              fontSize: 10,
              fontWeight: FontWeight.w700,
              color: selected ? AppColors.primary : Colors.white38,
            ),
          ),
        ],
      ),
    );
  }
}
