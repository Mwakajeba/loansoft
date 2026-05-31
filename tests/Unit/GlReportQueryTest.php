<?php

namespace Tests\Unit;

use App\Support\Accounting\GlReportQuery;
use PHPUnit\Framework\TestCase;

class GlReportQueryTest extends TestCase
{
    public function test_signed_movement_debit_and_credit(): void
    {
        $this->assertEquals(100.0, GlReportQuery::signedMovement('debit', 100));
        $this->assertEquals(-50.0, GlReportQuery::signedMovement('credit', 50));
    }

    public function test_format_voucher_no(): void
    {
        $this->assertEquals('receipt-42', GlReportQuery::formatVoucherNo('receipt', 42));
    }
}
