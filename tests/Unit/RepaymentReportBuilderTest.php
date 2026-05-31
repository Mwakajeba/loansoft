<?php

namespace Tests\Unit;

use App\Support\Loans\RepaymentReportBuilder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class RepaymentReportBuilderTest extends TestCase
{
    public function test_fee_receipts_support_legacy_loan_references(): void
    {
        $loan = (object) [
            'id' => 7,
            'loanNo' => 'SF-7',
            'customer' => (object) ['name' => 'Jane Doe'],
            'product' => (object) ['name' => 'Biashara'],
            'branch' => (object) ['name' => 'HQ'],
            'group' => (object) ['name' => 'Alpha'],
            'loanOfficer' => (object) ['name' => 'Officer One'],
            'balance' => 1250,
            'report_receipt_fee_ids' => [1, 5],
            'report_receipt_chart_account_ids' => [16, 17],
            'report_penalty_chart_account_ids' => [22],
            'report_principal_account_id' => 10,
            'report_interest_account_ids' => [11, 12],
        ];

        $receipt = (object) [
            'reference' => 'LOAN-7',
            'date' => '2026-05-12',
            'amount' => 140,
            'bankAccount' => (object) [
                'chartAccount' => (object) ['account_name' => 'NBC Collection'],
            ],
            'receiptItems' => collect([
                (object) ['fee_id' => null, 'chart_account_id' => 10, 'amount' => 70],
                (object) ['fee_id' => null, 'chart_account_id' => 11, 'amount' => 20],
                (object) ['fee_id' => null, 'chart_account_id' => 16, 'amount' => 25],
                (object) ['fee_id' => null, 'chart_account_id' => 17, 'amount' => 15],
                (object) ['fee_id' => null, 'chart_account_id' => 22, 'amount' => 10],
            ]),
        ];

        $rows = RepaymentReportBuilder::makeFeeReceiptRows(
            collect([$receipt]),
            collect([$loan])->keyBy('id')
        );

        $this->assertCount(1, $rows);
        $this->assertSame('fee_receipt', $rows[0]->entry_type);
        $this->assertSame('2026-05-12', $rows[0]->payment_date);
        $this->assertSame('NBC Collection', $rows[0]->payment_method);
        $this->assertSame('SF-7', $rows[0]->loan_no);
        $this->assertSame(40.0, $rows[0]->fee_amount);
        $this->assertSame(140.0, $rows[0]->amount_paid);
        $this->assertSame(70.0, $rows[0]->principal);
        $this->assertSame(20.0, $rows[0]->interest);
        $this->assertSame(10.0, $rows[0]->penalty_amount);
    }

    public function test_monthly_groups_include_empty_months_and_grand_totals(): void
    {
        $rows = new Collection([
            (object) [
                'entry_type' => 'repayment',
                'payment_date' => '2026-04-10',
                'month_key' => '2026-04',
                'amount_paid' => 120.0,
                'principal' => 80.0,
                'interest' => 20.0,
                'fee_amount' => 10.0,
                'penalty_amount' => 10.0,
            ],
            (object) [
                'entry_type' => 'fee_receipt',
                'payment_date' => '2026-06-05',
                'month_key' => '2026-06',
                'amount_paid' => 35.0,
                'principal' => 0.0,
                'interest' => 0.0,
                'fee_amount' => 35.0,
                'penalty_amount' => 0.0,
            ],
        ]);

        $monthlyGroups = RepaymentReportBuilder::monthlyGroups($rows, '2026-04-01', '2026-06-30');
        $summary = RepaymentReportBuilder::summarize($rows);

        $this->assertCount(3, $monthlyGroups);
        $this->assertSame('April 2026', $monthlyGroups[0]->month_label);
        $this->assertSame(120.0, $monthlyGroups[0]->summary['total_paid']);
        $this->assertSame(0, $monthlyGroups[1]->summary['transaction_count']);
        $this->assertSame('June 2026', $monthlyGroups[2]->month_label);
        $this->assertSame(35.0, $monthlyGroups[2]->summary['total_fees']);
        $this->assertSame(155.0, $summary['total_paid']);
        $this->assertSame(45.0, $summary['total_fees']);
        $this->assertSame(10.0, $summary['total_penalty']);
    }

    public function test_unmapped_receipt_items_are_still_included_in_amount_paid(): void
    {
        $loan = (object) [
            'id' => 7,
            'loanNo' => 'SF-7',
            'customer' => (object) ['name' => 'Jane Doe'],
            'product' => (object) ['name' => 'Biashara'],
            'branch' => (object) ['name' => 'HQ'],
            'group' => (object) ['name' => 'Alpha'],
            'loanOfficer' => (object) ['name' => 'Officer One'],
            'balance' => 1250,
            'report_receipt_fee_ids' => [1],
            'report_receipt_chart_account_ids' => [16],
            'report_penalty_chart_account_ids' => [],
            'report_principal_account_id' => null,
            'report_interest_account_ids' => [],
        ];

        $receipt = (object) [
            'reference' => 'LOAN-7',
            'date' => '2026-05-12',
            'amount' => 100,
            'bankAccount' => (object) [
                'chartAccount' => (object) ['account_name' => 'NBC Collection'],
            ],
            'receiptItems' => collect([
                (object) ['fee_id' => null, 'chart_account_id' => 16, 'amount' => 25],
                (object) ['fee_id' => null, 'chart_account_id' => 999, 'amount' => 75],
            ]),
        ];

        $rows = RepaymentReportBuilder::makeFeeReceiptRows(
            collect([$receipt]),
            collect([$loan])->keyBy('id')
        );

        $this->assertCount(1, $rows);
        $this->assertSame(100.0, $rows[0]->amount_paid);
        $this->assertSame(100.0, $rows[0]->fee_amount);
    }

    public function test_receipt_with_repayments_uses_repayment_component_breakdown(): void
    {
        $loan = (object) [
            'id' => 9,
            'loanNo' => 'SF-9',
            'customer' => (object) ['name' => 'John Doe'],
            'product' => (object) ['name' => 'Biashara'],
            'branch' => (object) ['name' => 'HQ'],
            'group' => (object) ['name' => 'Beta'],
            'loanOfficer' => (object) ['name' => 'Officer Two'],
            'balance' => 1000,
            'report_receipt_fee_ids' => [1],
            'report_receipt_chart_account_ids' => [16],
            'report_penalty_chart_account_ids' => [22],
            'report_principal_account_id' => 10,
            'report_interest_account_ids' => [11],
        ];

        $receipt = (object) [
            'reference' => 'LOAN-9',
            'date' => '2026-05-20',
            'amount' => 150,
            'bankAccount' => (object) [
                'chartAccount' => (object) ['account_name' => 'CRDB Collection'],
            ],
            'repayments' => collect([
                (object) ['principal' => 100, 'interest' => 30, 'fee_amount' => 10, 'penalt_amount' => 10],
            ]),
            'receiptItems' => collect([
                (object) ['fee_id' => null, 'chart_account_id' => 16, 'amount' => 150],
            ]),
        ];

        $rows = RepaymentReportBuilder::makeFeeReceiptRows(
            collect([$receipt]),
            collect([$loan])->keyBy('id')
        );

        $this->assertCount(1, $rows);
        $this->assertSame(150.0, $rows[0]->amount_paid);
        $this->assertSame(100.0, $rows[0]->principal);
        $this->assertSame(30.0, $rows[0]->interest);
        $this->assertSame(10.0, $rows[0]->fee_amount);
        $this->assertSame(10.0, $rows[0]->penalty_amount);
    }

    public function test_receipt_breakdown_ignores_repayments_from_other_loans(): void
    {
        $loan = (object) [
            'id' => 9,
            'loanNo' => 'SF-9',
            'customer' => (object) ['name' => 'John Doe'],
            'product' => (object) ['name' => 'Biashara'],
            'branch' => (object) ['name' => 'HQ'],
            'group' => (object) ['name' => 'Beta'],
            'loanOfficer' => (object) ['name' => 'Officer Two'],
            'balance' => 1000,
            'report_receipt_fee_ids' => [1],
            'report_receipt_chart_account_ids' => [16],
            'report_penalty_chart_account_ids' => [22],
            'report_principal_account_id' => 10,
            'report_interest_account_ids' => [11],
        ];

        $receipt = (object) [
            'reference' => 'LOAN-9',
            'date' => '2026-05-20',
            'amount' => 150,
            'bankAccount' => (object) [
                'chartAccount' => (object) ['account_name' => 'CRDB Collection'],
            ],
            'repayments' => collect([
                (object) ['loan_id' => 9, 'principal' => 100, 'interest' => 30, 'fee_amount' => 10, 'penalt_amount' => 10],
                (object) ['loan_id' => 44, 'principal' => 500, 'interest' => 50, 'fee_amount' => 5, 'penalt_amount' => 2],
            ]),
            'receiptItems' => collect([
                (object) ['fee_id' => null, 'chart_account_id' => 16, 'amount' => 150],
            ]),
        ];

        $rows = RepaymentReportBuilder::makeFeeReceiptRows(
            collect([$receipt]),
            collect([$loan])->keyBy('id')
        );

        $this->assertCount(1, $rows);
        $this->assertSame(100.0, $rows[0]->principal);
        $this->assertSame(30.0, $rows[0]->interest);
        $this->assertSame(10.0, $rows[0]->fee_amount);
        $this->assertSame(10.0, $rows[0]->penalty_amount);
    }
}
