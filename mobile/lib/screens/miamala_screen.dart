import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;

import '../core/api_config.dart';
import '../core/app_colors.dart';

/// One transaction from API (customer/transactions).
class TransactionItem {
  final int id;
  final String? date;
  final String description;
  final double amount;
  final String? referenceType;
  final String? referenceNumber;

  TransactionItem({
    required this.id,
    this.date,
    required this.description,
    required this.amount,
    this.referenceType,
    this.referenceNumber,
  });

  static TransactionItem fromJson(Map<String, dynamic> j) {
    int parseId(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is String) return int.tryParse(v) ?? 0;
      return 0;
    }
    double parseAmount(dynamic v) {
      if (v == null) return 0;
      if (v is num) return v.toDouble();
      if (v is String) return double.tryParse(v) ?? 0;
      return 0;
    }
    return TransactionItem(
      id: parseId(j['id']),
      date: j['date']?.toString(),
      description: (j['description'] ?? 'Malipo').toString(),
      amount: parseAmount(j['amount']),
      referenceType: j['reference_type']?.toString(),
      referenceNumber: j['reference_number']?.toString(),
    );
  }
}

/// Miamala – list of transactions (receipts) for the logged-in customer: loan repayments, fees, penalty.
class MiamalaScreen extends StatefulWidget {
  final int? customerId;

  const MiamalaScreen({super.key, this.customerId});

  @override
  State<MiamalaScreen> createState() => _MiamalaScreenState();
}

class _MiamalaScreenState extends State<MiamalaScreen> {
  List<TransactionItem> _transactions = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (widget.customerId != null) {
      _fetchTransactions();
    } else {
      _loading = false;
      _error = 'Tafadhali ingia kwanza';
    }
  }

  Future<void> _fetchTransactions() async {
    if (widget.customerId == null) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final url = Uri.parse(ApiConfig.customerUrl('transactions'));
      final res = await http.post(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: jsonEncode({'customer_id': widget.customerId}),
      );
      final String body = res.body;
      if (body.trim().isEmpty) {
        setState(() {
          _error = 'Seva haikujibu';
          _loading = false;
        });
        return;
      }
      final Map<String, dynamic> data = jsonDecode(body);
      final int status = (data['status'] is int) ? data['status'] as int : 200;
      if (status != 200) {
        setState(() {
          _error = data['message']?.toString() ?? 'Hitilafu';
          _loading = false;
        });
        return;
      }
      final List<dynamic> list = data['transactions'] is List ? data['transactions'] as List : [];
      final List<TransactionItem> items = list
          .whereType<Map<String, dynamic>>()
          .map((j) => TransactionItem.fromJson(j))
          .toList();
      setState(() {
        _transactions = items;
        _loading = false;
        _error = null;
      });
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
        title: Text(
          'Miamala',
          style: GoogleFonts.manrope(fontWeight: FontWeight.w700, color: Colors.white),
        ),
        backgroundColor: AppColors.backgroundDark,
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          _error!,
                          style: GoogleFonts.manrope(fontSize: 16, color: Colors.white70),
                          textAlign: TextAlign.center,
                        ),
                        const SizedBox(height: 16),
                        TextButton(
                          onPressed: _fetchTransactions,
                          child: const Text('Jaribu tena'),
                        ),
                      ],
                    ),
                  ),
                )
              : _transactions.isEmpty
                  ? Center(
                      child: Text(
                        'Hakuna miamala bado.',
                        style: GoogleFonts.manrope(fontSize: 16, color: Colors.white70),
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _fetchTransactions,
                      color: AppColors.primary,
                      child: ListView.builder(
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                        itemCount: _transactions.length,
                        itemBuilder: (context, index) {
                          final t = _transactions[index];
                          return Card(
                            color: const Color(0xFF1A2233),
                            margin: const EdgeInsets.only(bottom: 10),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            child: ListTile(
                              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                              title: Text(
                                t.description,
                                style: GoogleFonts.manrope(
                                  fontWeight: FontWeight.w600,
                                  color: Colors.white,
                                  fontSize: 15,
                                ),
                              ),
                              subtitle: t.date != null
                                  ? Padding(
                                      padding: const EdgeInsets.only(top: 4),
                                      child: Text(
                                        t.date!,
                                        style: GoogleFonts.manrope(
                                          fontSize: 13,
                                          color: Colors.white60,
                                        ),
                                      ),
                                    )
                                  : null,
                              trailing: Text(
                                'TZS ${t.amount.toStringAsFixed(2)}',
                                style: GoogleFonts.manrope(
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.primary,
                                  fontSize: 15,
                                ),
                              ),
                            ),
                          );
                        },
                      ),
                    ),
    );
  }
}
