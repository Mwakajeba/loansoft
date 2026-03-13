import 'dart:convert';
import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import '../core/api_config.dart';
import '../core/app_colors.dart';
import '../core/user_photo.dart';
import '../widgets/app_bottom_nav.dart';
import 'group_members_screen.dart';
import 'loan_application_screen.dart';
import 'login_screen.dart';

/// Wasifu (Profile) screen - profile header, account security, general & help. Loads real data from profile API when [customerId] is set.
class ProfileScreen extends StatefulWidget {
  final int? customerId;
  final String? name;
  final String? phone;

  const ProfileScreen({
    super.key,
    this.customerId,
    this.name,
    this.phone,
  });

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  static const Color _vividRed = Color(0xFFFF3B30); // logout button

  bool _biometricsEnabled = true;
  bool _loading = true;
  String? _error;
  String? _name;
  String? _phone;
  String? _photo;
  String? _customerNo;
  String? _branch;
  String? _groupName;
  int? _groupId;
  String? _sex;

  @override
  void initState() {
    super.initState();
    _name = widget.name;
    _phone = widget.phone;
    if (widget.customerId != null) {
      _fetchProfile();
    } else {
      _loading = false;
    }
  }

  Future<void> _fetchProfile() async {
    if (widget.customerId == null) return;
    setState(() { _loading = true; _error = null; });
    try {
      final url = Uri.parse(ApiConfig.customerUrl('profile'));
      final res = await http.post(
        url,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'customer_id': widget.customerId}),
      );
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
        if (res.statusCode == 200 && (data['status'] == 200)) {
        final c = data['customer'] as Map<String, dynamic>? ?? {};
        final photoUrl = c['photo']?.toString();
        if (photoUrl != null && photoUrl.isNotEmpty) {
          UserPhotoHolder.currentPhotoUrl = photoUrl;
        }
        setState(() {
          _name = _name ?? c['name']?.toString();
          _phone = _phone ?? c['phone1']?.toString() ?? c['phone2']?.toString();
          _photo = photoUrl;
          _customerNo = c['customerNo']?.toString();
          _branch = c['branch']?.toString();
          _groupName = c['group_name']?.toString();
          _groupId = c['group_id'] != null ? (c['group_id'] is int ? c['group_id'] as int : int.tryParse(c['group_id'].toString())) : null;
          _sex = c['sex']?.toString();
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

  String _jinsia(String? sex) {
    if (sex == null || sex.isEmpty) return '—';
    final s = sex.toUpperCase();
    if (s == 'M' || s == 'MALE') return 'Mwanaume';
    if (s == 'F' || s == 'FEMALE') return 'Mwanamke';
    return sex;
  }

  bool _uploadingPhoto = false;

  void _openChangePasswordDialog() {
    if (widget.customerId == null) return;
    showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => _ChangePasswordDialog(
        customerId: widget.customerId!,
        onSuccess: _logoutAfterPasswordChange,
        onCancel: () => Navigator.of(ctx).pop(),
      ),
    );
  }

  void _logoutAfterPasswordChange() {
    UserPhotoHolder.currentPhotoUrl = null;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (route) => false,
    );
  }

  void _openKuhusuAppDialog() {
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.backgroundDark,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(
          'Kuhusu App',
          style: GoogleFonts.manrope(fontWeight: FontWeight.w700, color: Colors.white),
        ),
        content: SingleChildScrollView(
          child: Text(
            'Programu hii ni kwa wateja wa mikopo. Unaweza:\n\n'
            '• Kuona mikopo yako (Mikopo) na hali ya kila mkopo\n'
            '• Kuona wanachama wa kikundi chako (Kikundi)\n'
            '• Kuona miamala yako – malipo ya mikopo, ada na faini (Miamala)\n'
            '• Kusoma na kuchapisha maombi ya mkopo\n'
            '• Kuwasilisha malalamiko (Malalamiko)\n'
            '• Kubadilisha wasifu wako na picha\n'
            '• Kubadilisha neno la siri\n\n'
            'Tumia Msaada kwa kuwasiliana na ofisi kwa simu au barua pepe.',
            style: GoogleFonts.manrope(fontSize: 15, height: 1.5, color: Colors.white70),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: Text('Sawa', style: GoogleFonts.manrope(color: AppColors.primary)),
          ),
        ],
      ),
    );
  }

  void _openMsaadaDialog() {
    showDialog<void>(
      context: context,
      builder: (ctx) => _MsaadaDialog(onClose: () => Navigator.of(ctx).pop()),
    );
  }

  Future<void> _pickAndUploadPhoto() async {
    if (widget.customerId == null) return;
    try {
      final picker = ImagePicker();
      final XFile? picked = await picker.pickImage(
        source: ImageSource.gallery,
        maxWidth: 1024,
        maxHeight: 1024,
        imageQuality: 85,
      );
      if (picked == null || !mounted) return;
      setState(() => _uploadingPhoto = true);
      final url = Uri.parse(ApiConfig.customerUrl('update-photo'));
      final request = http.MultipartRequest('POST', url);
      request.fields['customer_id'] = widget.customerId.toString();
      request.files.add(await http.MultipartFile.fromPath('photo', picked.path));
      final streamed = await request.send();
      final res = await http.Response.fromStream(streamed);
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
      if (mounted) {
        setState(() => _uploadingPhoto = false);
        if (res.statusCode == 200 && (data['status'] == 200)) {
          final newUrl = data['photo_url']?.toString();
          if (newUrl != null && newUrl.isNotEmpty) {
            UserPhotoHolder.currentPhotoUrl = newUrl;
            setState(() => _photo = newUrl);
          }
        }
      }
    } catch (e) {
      if (mounted) setState(() => _uploadingPhoto = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.backgroundDark,
      appBar: AppBar(
        title: Text(
          'Wasifu',
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
        child: Stack(
          children: [
            // Scrollable content
            CustomScrollView(
              slivers: [
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(24, 32, 24, 200),
                  sliver: SliverList(
                    delegate: SliverChildListDelegate([
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
                              textAlign: TextAlign.center,
                              style: GoogleFonts.manrope(color: Colors.white70, fontSize: 14),
                            ),
                          ),
                        )
                      else ...[
                      // Profile header
                      _buildProfileHeader(),
                      const SizedBox(height: 40),
                      // Taarifa Binafsi
                      _buildSectionTitle('Taarifa Binafsi'),
                      const SizedBox(height: 12),
                      _buildSettingsCard(
                        children: [
                          _buildInfoRow(Icons.badge_outlined, 'Namba ya Usajili', _customerNo ?? '—'),
                          _buildDivider(),
                          _buildInfoRow(Icons.wc_rounded, 'Jinsia', _jinsia(_sex)),
                          _buildDivider(),
                          _buildInfoRow(Icons.account_balance_rounded, 'Tawi', _branch ?? '—'),
                          _buildDivider(),
                          _buildInfoRow(Icons.group_rounded, 'Kundi', _groupName ?? '—'),
                          _buildDivider(),
                          _buildSettingRow(
                            icon: Icons.people_rounded,
                            label: 'Wana Kikundi',
                            trailing: const Icon(Icons.chevron_right_rounded, color: Colors.white54, size: 24),
                            onTap: widget.customerId != null
                                ? () {
                                    Navigator.of(context).push(
                                      MaterialPageRoute(
                                        builder: (_) => GroupMembersScreen(
                                          customerId: widget.customerId!,
                                          groupName: _groupName,
                                          groupId: _groupId,
                                        ),
                                      ),
                                    );
                                  }
                                : null,
                          ),
                        ],
                      ),
                      const SizedBox(height: 32),
                      // Usalama wa Akaunti
                      _buildSectionTitle('Usalama wa Akaunti'),
                      const SizedBox(height: 12),
                      _buildSettingsCard(
                        children: [
                          _buildSettingRow(
                            icon: Icons.lock_reset_rounded,
                            label: 'Badilisha Neno la Siri',
                            trailing: const Icon(Icons.chevron_right_rounded, color: Colors.white54, size: 24),
                            onTap: widget.customerId != null ? _openChangePasswordDialog : null,
                          ),
                          _buildDivider(),
                          _buildSettingRow(
                            icon: Icons.fingerprint_rounded,
                            label: 'Uthibitishaji wa Biometria',
                            trailing: Switch(
                              value: _biometricsEnabled,
                              onChanged: (v) => setState(() => _biometricsEnabled = v),
                              activeColor: AppColors.primary,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 32),
                      // Jumla na Msaada
                      _buildSectionTitle('Jumla na Msaada'),
                      const SizedBox(height: 12),
                      _buildSettingsCard(
                        children: [
                          _buildSettingRow(
                            icon: Icons.language_rounded,
                            label: 'Lugha',
                            trailing: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(
                                  'Kiswahili',
                                  style: GoogleFonts.manrope(fontSize: 14, color: Colors.white54),
                                ),
                                const SizedBox(width: 8),
                                const Icon(Icons.chevron_right_rounded, color: Colors.white54, size: 24),
                              ],
                            ),
                          ),
                          _buildDivider(),
                          _buildSettingRow(
                            icon: Icons.help_outline_rounded,
                            label: 'Msaada',
                            trailing: const Icon(Icons.chevron_right_rounded, color: Colors.white54, size: 24),
                            onTap: _openMsaadaDialog,
                          ),
                          _buildDivider(),
                          _buildSettingRow(
                            icon: Icons.info_outline_rounded,
                            label: 'Kuhusu App',
                            trailing: const Icon(Icons.chevron_right_rounded, color: Colors.white54, size: 24),
                            onTap: _openKuhusuAppDialog,
                          ),
                        ],
                      ),
                      const SizedBox(height: 48),
                      // Footer logo/version
                      Center(
                        child: Column(
                          children: [
                            Text(
                              'YAWOTE',
                              style: GoogleFonts.manrope(
                                fontSize: 20,
                                fontWeight: FontWeight.w800,
                                letterSpacing: -0.5,
                                color: AppColors.primary.withValues(alpha: 0.3),
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Toleo 2.0.0',
                              style: GoogleFonts.manrope(
                                fontSize: 12,
                                fontWeight: FontWeight.w500,
                                color: Colors.white38,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                    ]),
                  ),
                ),
              ],
            ),
            // Ondoka above bottom bar; bottom bar at very bottom
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    padding: const EdgeInsets.fromLTRB(24, 24, 24, 32),
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.bottomCenter,
                        end: Alignment.topCenter,
                        colors: [
                          AppColors.backgroundDark,
                          AppColors.backgroundDark.withValues(alpha: 0.95),
                          AppColors.backgroundDark.withValues(alpha: 0.0),
                        ],
                      ),
                    ),
                    child: Material(
                      color: _vividRed,
                      borderRadius: BorderRadius.circular(14),
                      elevation: 8,
                      shadowColor: _vividRed.withValues(alpha: 0.3),
                      child: InkWell(
                        onTap: () {
                          UserPhotoHolder.currentPhotoUrl = null;
                          Navigator.of(context).pushAndRemoveUntil(
                            MaterialPageRoute(builder: (_) => const LoginScreen()),
                            (route) => false,
                          );
                        },
                        borderRadius: BorderRadius.circular(14),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(vertical: 16),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.logout_rounded, color: Colors.white, size: 22),
                              const SizedBox(width: 8),
                              Text(
                                'Ondoka',
                                style: GoogleFonts.manrope(
                                  fontSize: 16,
                                  fontWeight: FontWeight.w700,
                                  color: Colors.white,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                  AppBottomNav(
                    current: AppNavIndex.akaunti,
                    userId: widget.customerId,
                    name: _name,
                    phone: _phone,
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
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildProfileHeader() {
    final name = _name?.trim().isNotEmpty == true ? _name! : '—';
    final phone = _phone?.trim().isNotEmpty == true ? _phone! : '—';
    final photoUrl = _photo?.trim().isNotEmpty == true ? _photo! : null;
    return Column(
      children: [
        Stack(
          clipBehavior: Clip.none,
          children: [
            Container(
              width: 128,
              height: 128,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(color: AppColors.primary, width: 4),
                color: AppColors.backgroundDark,
              ),
              child: ClipOval(
                child: photoUrl != null
                    ? Image.network(
                        photoUrl.startsWith('http') ? photoUrl : '${ApiConfig.baseUrl}/$photoUrl',
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => const Icon(Icons.person, color: Colors.white54, size: 56),
                      )
                    : const Icon(Icons.person, color: Colors.white54, size: 56),
              ),
            ),
            Positioned(
              bottom: 0,
              right: 0,
              child: Material(
                color: AppColors.primary,
                shape: const CircleBorder(),
                elevation: 8,
                child: InkWell(
                  onTap: _uploadingPhoto ? null : _pickAndUploadPhoto,
                  customBorder: const CircleBorder(),
                  child: Padding(
                    padding: const EdgeInsets.all(10),
                    child: _uploadingPhoto
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Icon(Icons.edit_rounded, color: Colors.white, size: 18),
                  ),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 16),
        Text(
          name,
          style: GoogleFonts.manrope(
            fontSize: 24,
            fontWeight: FontWeight.w700,
            letterSpacing: -0.5,
            color: Colors.white,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          phone,
          style: GoogleFonts.manrope(
            fontSize: 15,
            fontWeight: FontWeight.w500,
            color: AppColors.primary.withValues(alpha: 0.85),
          ),
        ),
        const SizedBox(height: 16),
        OutlinedButton(
          onPressed: (_uploadingPhoto || widget.customerId == null) ? null : _pickAndUploadPhoto,
          style: OutlinedButton.styleFrom(
            foregroundColor: AppColors.primary,
            side: BorderSide(color: AppColors.primary.withValues(alpha: 0.3)),
            backgroundColor: AppColors.primary.withValues(alpha: 0.1),
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
          ),
          child: _uploadingPhoto
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primary),
                )
              : Text(
                  'Badilisha Picha',
                  style: GoogleFonts.manrope(fontSize: 14, fontWeight: FontWeight.w600),
                ),
        ),
      ],
    );
  }

  Widget _buildSectionTitle(String title) {
    return Padding(
      padding: const EdgeInsets.only(left: 8),
      child: Text(
        title.toUpperCase(),
        style: GoogleFonts.manrope(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          letterSpacing: 1.2,
          color: Colors.white54,
        ),
      ),
    );
  }

  Widget _buildSettingsCard({required List<Widget> children}) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.primary.withValues(alpha: 0.06),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.12)),
      ),
      child: Column(children: children),
    );
  }

  Widget _buildSettingRow({
    required IconData icon,
    required String label,
    required Widget trailing,
    VoidCallback? onTap,
  }) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: AppColors.primary.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(icon, color: AppColors.primary, size: 22),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Text(
                  label,
                  style: GoogleFonts.manrope(
                    fontSize: 15,
                    fontWeight: FontWeight.w500,
                    color: Colors.white,
                  ),
                ),
              ),
              trailing,
            ],
          ),
        ),
      ),
    );
  }

  /// Label + value row for Taarifa Binafsi (no tap).
  Widget _buildInfoRow(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.2),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: AppColors.primary, size: 22),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Text(
              label,
              style: GoogleFonts.manrope(
                fontSize: 15,
                fontWeight: FontWeight.w500,
                color: Colors.white,
              ),
            ),
          ),
          Text(
            value,
            style: GoogleFonts.manrope(
              fontSize: 14,
              fontWeight: FontWeight.w500,
              color: Colors.white54,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildDivider() {
    return Divider(
      height: 1,
      indent: 72,
      endIndent: 16,
      color: AppColors.primary.withValues(alpha: 0.12),
    );
  }
}

/// Dialog form: old password, new password, confirm new password. On success calls [onSuccess] (caller should pop and logout).
class _ChangePasswordDialog extends StatefulWidget {
  final int customerId;
  final VoidCallback onSuccess;
  final VoidCallback onCancel;

  const _ChangePasswordDialog({
    required this.customerId,
    required this.onSuccess,
    required this.onCancel,
  });

  @override
  State<_ChangePasswordDialog> createState() => _ChangePasswordDialogState();
}

class _ChangePasswordDialogState extends State<_ChangePasswordDialog> {
  final _formKey = GlobalKey<FormState>();
  final _oldController = TextEditingController();
  final _newController = TextEditingController();
  final _confirmController = TextEditingController();
  bool _obscureOld = true;
  bool _obscureNew = true;
  bool _obscureConfirm = true;
  bool _loading = false;
  String? _error;

  @override
  void dispose() {
    _oldController.dispose();
    _newController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _error = null);
    final newPass = _newController.text.trim();
    final confirm = _confirmController.text.trim();
    if (newPass.length < 6) {
      setState(() => _error = 'Neno la siri jipya lazima liwe na herufi 6 au zaidi.');
      return;
    }
    if (newPass != confirm) {
      setState(() => _error = 'Neno la siri jipya na uthibitishaji si sawa.');
      return;
    }
    setState(() => _loading = true);
    try {
      final url = Uri.parse(ApiConfig.customerUrl('change-password'));
      final res = await http.post(
        url,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({
          'customer_id': widget.customerId,
          'old_password': _oldController.text,
          'new_password': newPass,
        }),
      );
      final data = jsonDecode(res.body) as Map<String, dynamic>? ?? {};
      final status = (data['status'] is int) ? data['status'] as int : res.statusCode;
      if (status == 200) {
        if (mounted) widget.onSuccess();
        return;
      }
      setState(() {
        _loading = false;
        _error = data['message']?.toString() ?? 'Hitilafu. Jaribu tena.';
      });
    } catch (e) {
      setState(() {
        _loading = false;
        _error = ApiConfig.networkErrorMessage(e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: AppColors.backgroundDark,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Text(
        'Badilisha Neno la Siri',
        style: GoogleFonts.manrope(fontWeight: FontWeight.w700, color: Colors.white),
      ),
      content: SingleChildScrollView(
        child: Form(
          key: _formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'Ingiza neno la siri la zamani na jipya.',
                style: GoogleFonts.manrope(fontSize: 14, color: Colors.white70),
              ),
              const SizedBox(height: 20),
              TextFormField(
                controller: _oldController,
                obscureText: _obscureOld,
                style: GoogleFonts.manrope(color: Colors.white, fontSize: 16),
                decoration: InputDecoration(
                  labelText: 'Neno la siri la zamani',
                  labelStyle: GoogleFonts.manrope(color: Colors.white54),
                  filled: true,
                  fillColor: Colors.white.withValues(alpha: 0.08),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white24),
                  ),
                  suffixIcon: IconButton(
                    icon: Icon(
                      _obscureOld ? Icons.visibility_off_rounded : Icons.visibility_rounded,
                      color: Colors.white54,
                    ),
                    onPressed: () => setState(() => _obscureOld = !_obscureOld),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _newController,
                obscureText: _obscureNew,
                style: GoogleFonts.manrope(color: Colors.white, fontSize: 16),
                decoration: InputDecoration(
                  labelText: 'Neno la siri jipya',
                  labelStyle: GoogleFonts.manrope(color: Colors.white54),
                  filled: true,
                  fillColor: Colors.white.withValues(alpha: 0.08),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white24),
                  ),
                  suffixIcon: IconButton(
                    icon: Icon(
                      _obscureNew ? Icons.visibility_off_rounded : Icons.visibility_rounded,
                      color: Colors.white54,
                    ),
                    onPressed: () => setState(() => _obscureNew = !_obscureNew),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _confirmController,
                obscureText: _obscureConfirm,
                style: GoogleFonts.manrope(color: Colors.white, fontSize: 16),
                decoration: InputDecoration(
                  labelText: 'Thibitisha neno la siri jipya',
                  labelStyle: GoogleFonts.manrope(color: Colors.white54),
                  filled: true,
                  fillColor: Colors.white.withValues(alpha: 0.08),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: Colors.white24),
                  ),
                  suffixIcon: IconButton(
                    icon: Icon(
                      _obscureConfirm ? Icons.visibility_off_rounded : Icons.visibility_rounded,
                      color: Colors.white54,
                    ),
                    onPressed: () => setState(() => _obscureConfirm = !_obscureConfirm),
                  ),
                ),
              ),
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(
                  _error!,
                  style: GoogleFonts.manrope(fontSize: 13, color: AppColors.brandRed),
                ),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _loading ? null : widget.onCancel,
          child: Text(
            'Ghairi',
            style: GoogleFonts.manrope(color: Colors.white54),
          ),
        ),
        FilledButton(
          onPressed: _loading ? null : _submit,
          style: FilledButton.styleFrom(backgroundColor: AppColors.primary),
          child: _loading
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                )
              : Text(
                  'Badilisha',
                  style: GoogleFonts.manrope(fontWeight: FontWeight.w600, color: Colors.white),
                ),
        ),
      ],
    );
  }
}

/// Msaada dialog: fetches company phone and email from API and displays for communication.
class _MsaadaDialog extends StatefulWidget {
  final VoidCallback onClose;

  const _MsaadaDialog({required this.onClose});

  @override
  State<_MsaadaDialog> createState() => _MsaadaDialogState();
}

class _MsaadaDialogState extends State<_MsaadaDialog> {
  bool _loading = true;
  String? _error;
  String? _name;
  String? _phone;
  String? _email;

  @override
  void initState() {
    super.initState();
    _fetchContact();
  }

  Future<void> _fetchContact() async {
    try {
      final url = Uri.parse(ApiConfig.customerUrl('company-contact'));
      final res = await http.get(
        url,
        headers: {'Accept': 'application/json'},
      );
      final body = res.body.trim().isEmpty ? '{}' : res.body;
      final data = jsonDecode(body) as Map<String, dynamic>? ?? {};
      if (mounted) {
        setState(() {
          _loading = false;
          if ((data['status'] is int) && data['status'] == 200) {
            final c = data['company'] as Map<String, dynamic>? ?? {};
            _name = c['name']?.toString();
            _phone = c['phone']?.toString();
            _email = c['email']?.toString();
            _error = null;
          } else {
            _error = data['message']?.toString() ?? 'Imeshindwa kupakia maelezo.';
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

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: AppColors.backgroundDark,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Text(
        'Msaada',
        style: GoogleFonts.manrope(fontWeight: FontWeight.w700, color: Colors.white),
      ),
      content: _loading
          ? const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Center(child: CircularProgressIndicator(color: AppColors.primary)),
            )
          : _error != null
              ? Text(
                  _error!,
                  style: GoogleFonts.manrope(fontSize: 14, color: Colors.white70),
                )
              : SingleChildScrollView(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Wasiliana na ofisi kwa mawasiliano yafuatayo:',
                        style: GoogleFonts.manrope(fontSize: 14, color: Colors.white70),
                      ),
                      if (_name != null && _name!.trim().isNotEmpty) ...[
                        const SizedBox(height: 12),
                        Text(
                          _name!,
                          style: GoogleFonts.manrope(
                            fontSize: 16,
                            fontWeight: FontWeight.w600,
                            color: Colors.white,
                          ),
                        ),
                      ],
                      if (_phone != null && _phone!.trim().isNotEmpty) ...[
                        const SizedBox(height: 12),
                        Row(
                          children: [
                            Icon(Icons.phone_rounded, size: 20, color: AppColors.primary),
                            const SizedBox(width: 10),
                            SelectableText(
                              _phone!,
                              style: GoogleFonts.manrope(fontSize: 15, color: Colors.white),
                            ),
                          ],
                        ),
                      ],
                      if (_email != null && _email!.trim().isNotEmpty) ...[
                        const SizedBox(height: 10),
                        Row(
                          children: [
                            Icon(Icons.email_rounded, size: 20, color: AppColors.primary),
                            const SizedBox(width: 10),
                            Expanded(
                              child: SelectableText(
                                _email!,
                                style: GoogleFonts.manrope(fontSize: 15, color: Colors.white),
                              ),
                            ),
                          ],
                        ),
                      ],
                      if ((_phone == null || _phone!.trim().isEmpty) &&
                          (_email == null || _email!.trim().isEmpty) &&
                          _error == null)
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text(
                            'Hakuna mawasiliano yaliyowekwa kwa sasa.',
                            style: GoogleFonts.manrope(fontSize: 14, color: Colors.white54),
                          ),
                        ),
                    ],
                  ),
                ),
      actions: [
        TextButton(
          onPressed: widget.onClose,
          child: Text('Funga', style: GoogleFonts.manrope(color: AppColors.primary)),
        ),
      ],
    );
  }
}
