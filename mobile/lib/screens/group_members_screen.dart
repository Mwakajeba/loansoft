import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import '../core/api_config.dart';
import '../core/app_colors.dart';
import 'member_loans_screen.dart';

/// One group member from API (group_members + customers).
class GroupMemberItem {
  final int id;
  final String name;
  final String? phone1;
  final String? phone2;
  final String? customerNo;

  GroupMemberItem({
    required this.id,
    required this.name,
    this.phone1,
    this.phone2,
    this.customerNo,
  });

  static GroupMemberItem fromJson(Map<String, dynamic> j) {
    int parseId(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is String) return int.tryParse(v) ?? 0;
      if (v is num) return v.toInt();
      return 0;
    }
    return GroupMemberItem(
      id: parseId(j['id']),
      name: (j['name'] ?? '').toString(),
      phone1: j['phone1']?.toString(),
      phone2: j['phone2']?.toString(),
      customerNo: j['customerNo']?.toString(),
    );
  }
}

/// Lists all members of the customer's group (Wana Kikundi) from group_members API.
/// Tapping a member opens their loans screen.
class GroupMembersScreen extends StatefulWidget {
  /// Required to fetch members for this customer's group.
  final int customerId;
  /// Optional group name for the app bar.
  final String? groupName;
  /// Optional group ID to check if it's group 1.
  final int? groupId;

  const GroupMembersScreen({
    super.key,
    required this.customerId,
    this.groupName,
    this.groupId,
  });

  @override
  State<GroupMembersScreen> createState() => _GroupMembersScreenState();
}

class _GroupMembersScreenState extends State<GroupMembersScreen> {
  List<GroupMemberItem> _members = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    // Skip API call if group_id == 1 (individual loans - too many members)
    if (widget.groupId == 1) {
      setState(() {
        _loading = false;
        _members = [];
      });
    } else {
      _fetchMembers();
    }
  }

  Future<void> _fetchMembers() async {
    setState(() { _loading = true; _error = null; });
    try {
      final url = Uri.parse(ApiConfig.customerUrl('group-members'));
      final res = await http.post(
        url,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'customer_id': widget.customerId}),
      );
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
      if (res.statusCode == 200 && (data['status'] == 200)) {
        final list = (data['members'] as List<dynamic>?) ?? [];
        setState(() {
          _members = list.map((e) => GroupMemberItem.fromJson(e as Map<String, dynamic>)).toList();
          _loading = false;
          _error = null;
        });
      } else {
        setState(() {
          _error = data['message'] as String? ?? 'Hitilafu ya kupakia';
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: AppColors.backgroundDark,
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
        title: Text(
          (widget.groupName != null && widget.groupName!.trim().isNotEmpty)
              ? widget.groupName!.trim()
              : 'Wana Kikundi',
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
              : _members.isEmpty
                  ? Center(
                      child: Text(
                        widget.groupId == 1
                            ? 'Hakuna Mwanachama Yeyote Zaid yako'
                            : 'Hakuna wanachama wa kikundi',
                        style: GoogleFonts.manrope(color: Colors.white54, fontSize: 15),
                      ),
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
                      itemCount: _members.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 8),
                      itemBuilder: (context, index) {
                        final member = _members[index];
                        final name = member.name.trim().isEmpty ? '—' : member.name;
                        return Material(
                          color: Colors.transparent,
                          child: InkWell(
                            onTap: () {
                              Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (_) => MemberLoansScreen(
                                    memberId: member.id,
                                    memberName: name,
                                  ),
                                ),
                              );
                            },
                            borderRadius: BorderRadius.circular(12),
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                              decoration: BoxDecoration(
                                color: AppColors.primary.withValues(alpha: 0.06),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: AppColors.primary.withValues(alpha: 0.12)),
                              ),
                              child: Row(
                                children: [
                                  CircleAvatar(
                                    radius: 22,
                                    backgroundColor: AppColors.primary.withValues(alpha: 0.2),
                                    child: Text(
                                      name.isNotEmpty && name != '—' ? name[0].toUpperCase() : '?',
                                      style: GoogleFonts.manrope(
                                        fontSize: 18,
                                        fontWeight: FontWeight.w700,
                                        color: AppColors.primary,
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: 14),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          name,
                                          style: GoogleFonts.manrope(
                                            fontSize: 15,
                                            fontWeight: FontWeight.w600,
                                            color: Colors.white,
                                          ),
                                        ),
                                        if (member.customerNo != null && member.customerNo!.isNotEmpty) ...[
                                          const SizedBox(height: 2),
                                          Text(
                                            member.customerNo!,
                                            style: GoogleFonts.manrope(
                                              fontSize: 12,
                                              fontWeight: FontWeight.w500,
                                              color: Colors.white54,
                                            ),
                                          ),
                                        ],
                                      ],
                                    ),
                                  ),
                                  Icon(Icons.chevron_right_rounded, color: Colors.white54, size: 24),
                                ],
                              ),
                            ),
                          ),
                        );
                      },
                    ),
    );
  }
}
