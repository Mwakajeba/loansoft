import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;

import '../core/api_config.dart';
import '../core/app_colors.dart';
import '../widgets/app_bottom_nav.dart';
import 'loan_application_screen.dart';

/// One complaint from API (customer-complains).
class ComplaintItem {
  final int id;
  final String categoryName;
  final String description;
  final String status;
  final String? response;
  final String? respondedBy;
  final String? respondedAt;
  final String createdAt;

  ComplaintItem({
    required this.id,
    required this.categoryName,
    required this.description,
    required this.status,
    this.response,
    this.respondedBy,
    this.respondedAt,
    required this.createdAt,
  });

  static ComplaintItem fromJson(Map<String, dynamic> j) {
    return ComplaintItem(
      id: (j['id'] is int) ? j['id'] as int : int.tryParse(j['id']?.toString() ?? '0') ?? 0,
      categoryName: j['category_name']?.toString() ?? '—',
      description: j['description']?.toString() ?? '',
      status: j['status']?.toString() ?? 'pending',
      response: j['response']?.toString(),
      respondedBy: j['responded_by']?.toString(),
      respondedAt: j['responded_at']?.toString(),
      createdAt: j['created_at']?.toString() ?? '',
    );
  }
}

/// One category from API (complain-categories).
class ComplainCategoryItem {
  final int id;
  final String name;
  final String? description;
  final int priority;

  ComplainCategoryItem({
    required this.id,
    required this.name,
    this.description,
    this.priority = 0,
  });

  static ComplainCategoryItem fromJson(Map<String, dynamic> j) {
    return ComplainCategoryItem(
      id: (j['id'] is int) ? j['id'] as int : int.tryParse(j['id']?.toString() ?? '0') ?? 0,
      name: j['name']?.toString() ?? '—',
      description: j['description']?.toString(),
      priority: (j['priority'] is int) ? j['priority'] as int : int.tryParse(j['priority']?.toString() ?? '0') ?? 0,
    );
  }
}

/// Malalamiko (Complaints) — list and submit via API.
class ComplaintsScreen extends StatefulWidget {
  final int? customerId;

  const ComplaintsScreen({super.key, this.customerId});

  @override
  State<ComplaintsScreen> createState() => _ComplaintsScreenState();
}

class _ComplaintsScreenState extends State<ComplaintsScreen> {
  static const Color _surfaceDark = Color(0xFF16162A);

  List<ComplaintItem> _complaints = [];
  List<ComplainCategoryItem> _categories = [];
  bool _loading = true;
  String? _error;
  int _tabIndex = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (widget.customerId == null) {
      setState(() { _loading = false; _error = 'Hakuna kitambulisho cha mteja.'; });
      return;
    }
    setState(() { _loading = true; _error = null; });
    await Future.wait([_fetchCategories(), _fetchComplaints()]);
  }

  Future<void> _fetchCategories() async {
    try {
      final url = Uri.parse(ApiConfig.customerUrl('complain-categories'));
      final res = await http.get(url, headers: {'Accept': 'application/json'});
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
      if (res.statusCode == 200 && (data['status'] == 200)) {
        final list = (data['categories'] as List<dynamic>?) ?? [];
        if (mounted) {
          setState(() {
            _categories = list.map((e) => ComplainCategoryItem.fromJson(e as Map<String, dynamic>)).toList();
          });
        }
      }
    } catch (_) {}
  }

  Future<void> _fetchComplaints() async {
    try {
      final url = Uri.parse(ApiConfig.customerUrl('customer-complains'));
      final res = await http.post(
        url,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'customer_id': widget.customerId}),
      );
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
      if (mounted) {
        setState(() { _loading = false; });
        if (res.statusCode == 200 && (data['status'] == 200)) {
          final list = (data['complains'] as List<dynamic>?) ?? [];
          setState(() {
            _complaints = list.map((e) => ComplaintItem.fromJson(e as Map<String, dynamic>)).toList();
            _error = null;
          });
        } else {
          setState(() => _error = data['message']?.toString() ?? 'Hitilafu ya kupakia malalamiko.');
        }
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

  void _onComplaintSubmitted() {
    _load();
    setState(() => _tabIndex = 0); // switch to Malalamiko list tab
  }

  String _formatDate(String? s) {
    if (s == null || s.isEmpty) return '—';
    try {
      final parts = s.split(' ');
      if (parts.isNotEmpty) {
        final d = parts[0].split('-');
        if (d.length >= 3) return '${d[2]}/${d[1]}/${d[0]}';
      }
    } catch (_) {}
    return s;
  }

  String _statusLabel(String status) {
    switch (status.toLowerCase()) {
      case 'pending':
        return 'Inasubiri';
      case 'resolved':
      case 'closed':
        return 'Imekwisha';
      default:
        return status;
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
          'Malalamiko',
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
                          onPressed: _load,
                          icon: Icon(Icons.refresh_rounded, color: AppColors.primary, size: 20),
                          label: Text('Jaribu tena', style: TextStyle(color: AppColors.primary)),
                        ),
                      ],
                    ),
                  ),
                )
              : Column(
                  children: [
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
                            _tab(0, 'Malalamiko'),
                            _tab(1, 'Toa Malalamiko'),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                    Expanded(
                      child: _tabIndex == 0
                          ? RefreshIndicator(
                              onRefresh: _load,
                              color: AppColors.primary,
                              child: ListView(
                                padding: const EdgeInsets.fromLTRB(24, 0, 24, 120),
                                children: [
                                  if (_complaints.isEmpty)
                                    Padding(
                                      padding: const EdgeInsets.only(top: 48),
                                      child: Center(
                                        child: Text(
                                          'Hakuna malalamiko bado.\nNenda kwenye kichupo "Toa Malalamiko" kuongeza.',
                                          textAlign: TextAlign.center,
                                          style: GoogleFonts.manrope(fontSize: 14, color: Colors.white54),
                                        ),
                                      ),
                                    )
                                  else
                                    ..._complaints.map((c) => _buildComplaintCard(c)),
                                ],
                              ),
                            )
                          : _BuildToaMalalamikoTab(
                              categories: _categories,
                              customerId: widget.customerId,
                              onSubmitted: _onComplaintSubmitted,
                            ),
                    ),
                  ],
                ),
      bottomNavigationBar: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          AppBottomNav(
            current: AppNavIndex.malalamiko,
            userId: widget.customerId,
            onPlusPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => LoanApplicationScreen(customerId: widget.customerId),
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

  Widget _buildComplaintCard(ComplaintItem c) {
    final isResolved = c.status.toLowerCase() == 'resolved' || c.status.toLowerCase() == 'closed';
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.05),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withOpacity(0.08)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
decoration: BoxDecoration(
                color: (isResolved ? const Color(0xFF10B981) : AppColors.primary).withOpacity(0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  _statusLabel(c.status),
                  style: GoogleFonts.manrope(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: isResolved ? const Color(0xFF10B981) : AppColors.primary,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                c.categoryName,
                style: GoogleFonts.manrope(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Colors.white70,
                ),
              ),
              const Spacer(),
              Text(
                _formatDate(c.createdAt),
                style: GoogleFonts.manrope(fontSize: 10, color: Colors.white54),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            c.description,
            style: GoogleFonts.manrope(
              fontSize: 14,
              fontWeight: FontWeight.w500,
              color: Colors.white,
              height: 1.35,
            ),
          ),
          if (c.response != null && c.response!.isNotEmpty) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: AppColors.primary.withOpacity(0.1),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Jibu',
                    style: GoogleFonts.manrope(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: AppColors.primary,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    c.response!,
                    style: GoogleFonts.manrope(
                      fontSize: 13,
                      color: Colors.white70,
                      height: 1.35,
                    ),
                  ),
                  if (c.respondedBy != null && c.respondedBy!.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      '— ${c.respondedBy}',
                      style: GoogleFonts.manrope(fontSize: 11, color: Colors.white54, fontStyle: FontStyle.italic),
                    ),
                  ],
                  if (c.respondedAt != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      _formatDate(c.respondedAt),
                      style: GoogleFonts.manrope(fontSize: 10, color: Colors.white54),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Tab content: Toa Malalamiko — Aina ya malalamiko + Maelezo form.
class _BuildToaMalalamikoTab extends StatefulWidget {
  final List<ComplainCategoryItem> categories;
  final int? customerId;
  final VoidCallback onSubmitted;

  const _BuildToaMalalamikoTab({
    required this.categories,
    required this.customerId,
    required this.onSubmitted,
  });

  @override
  State<_BuildToaMalalamikoTab> createState() => _BuildToaMalalamikoTabState();
}

class _BuildToaMalalamikoTabState extends State<_BuildToaMalalamikoTab> {

  final _formKey = GlobalKey<FormState>();
  final _descriptionController = TextEditingController();
  int? _selectedCategoryId;
  bool _submitting = false;
  String? _message;

  @override
  void dispose() {
    _descriptionController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (widget.customerId == null) {
      setState(() => _message = 'Hakuna kitambulisho cha mteja.');
      return;
    }
    if (_selectedCategoryId == null) {
      setState(() => _message = 'Chagua aina ya malalamiko.');
      return;
    }
    if (!(_formKey.currentState?.validate() ?? false)) return;
    final desc = _descriptionController.text.trim();
    if (desc.length < 10) {
      setState(() => _message = 'Maelezo yanahitaji herufi angalau 10.');
      return;
    }
    setState(() { _submitting = true; _message = null; });
    try {
      final url = Uri.parse(ApiConfig.customerUrl('submit-complain'));
      final res = await http.post(
        url,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({
          'customer_id': widget.customerId,
          'complain_category_id': _selectedCategoryId,
          'description': desc,
        }),
      );
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
      if (!mounted) return;
      setState(() => _submitting = false);
      if (res.statusCode == 200 && (data['status'] == 200)) {
        _descriptionController.clear();
        setState(() => _selectedCategoryId = null);
        widget.onSubmitted();
      } else {
        setState(() => _message = data['message']?.toString() ?? data['errors']?.toString() ?? 'Imeshindwa kutuma.');
      }
    } catch (e) {
      if (mounted) setState(() {
        _submitting = false;
        _message = ApiConfig.networkErrorMessage(e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (widget.customerId == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Hakuna kitambulisho cha mteja. Ingia tena.',
            textAlign: TextAlign.center,
            style: GoogleFonts.manrope(fontSize: 14, color: Colors.white54),
          ),
        ),
      );
    }
    if (widget.categories.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Hakuna aina za malalamiko zilizowekwa. Wasiliana na meneja.',
            textAlign: TextAlign.center,
            style: GoogleFonts.manrope(fontSize: 14, color: Colors.white54),
          ),
        ),
      );
    }
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 120),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Chagua aina ya malalamiko na uandike maelezo.',
              style: GoogleFonts.manrope(fontSize: 14, color: Colors.white70),
            ),
            const SizedBox(height: 20),
            DropdownButtonFormField<int>(
              value: _selectedCategoryId,
              decoration: InputDecoration(
                labelText: 'Aina ya malalamiko',
                labelStyle: GoogleFonts.manrope(color: Colors.white54),
                filled: true,
                fillColor: Colors.white.withOpacity(0.06),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                ),
              ),
              dropdownColor: AppColors.backgroundDark,
              style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
              items: widget.categories
                  .map((cat) => DropdownMenuItem<int>(
                        value: cat.id,
                        child: Text(cat.name),
                      ))
                  .toList(),
              onChanged: (v) => setState(() => _selectedCategoryId = v),
            ),
            const SizedBox(height: 20),
            TextFormField(
              controller: _descriptionController,
              maxLines: 5,
              decoration: InputDecoration(
                labelText: 'Maelezo au Malalamiko (angalau herufi 10)',
                labelStyle: GoogleFonts.manrope(color: Colors.white54),
                hintText: 'Andika malalamiko yako hapa...',
                hintStyle: GoogleFonts.manrope(color: Colors.white38),
                filled: true,
                fillColor: Colors.white.withOpacity(0.06),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
                ),
              ),
              style: GoogleFonts.manrope(color: Colors.white, fontSize: 14),
              validator: (v) {
                if (v == null || v.trim().length < 10) return 'Andika angalau herufi 10.';
                return null;
              },
            ),
            if (_message != null) ...[
              const SizedBox(height: 12),
              Text(
                _message!,
                style: GoogleFonts.manrope(fontSize: 13, color: Colors.red.shade300),
              ),
            ],
            const SizedBox(height: 28),
            SizedBox(
              height: 52,
              child: ElevatedButton(
                onPressed: _submitting ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.primary,
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                ),
                child: _submitting
                    ? const SizedBox(
                        height: 24,
                        width: 24,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : Text(
                        'Tuma Malalamiko',
                        style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 16),
                      ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
