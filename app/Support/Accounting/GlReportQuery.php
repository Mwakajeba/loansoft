<?php

namespace App\Support\Accounting;

use App\Models\BankAccount;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared GL query helpers for Cash Book, General Ledger, Trial Balance, etc.
 */
class GlReportQuery
{
  public static function applyBranchFilter(Builder $query, User $user, $branchId, string $glTable = 'gl_transactions'): Builder
  {
    $assignedBranchIds = $user->branches()->pluck('branches.id')->toArray();

    if ($branchId === 'all') {
      if (!empty($assignedBranchIds)) {
        $query->whereIn("{$glTable}.branch_id", $assignedBranchIds);
      }
    } elseif ($branchId) {
      $query->where("{$glTable}.branch_id", $branchId);
    } elseif (!empty($assignedBranchIds)) {
      $query->whereIn("{$glTable}.branch_id", $assignedBranchIds);
    }

    return $query;
  }

  public static function applyDateRange(Builder $query, string $startDate, string $endDate, string $glTable = 'gl_transactions'): Builder
  {
    $start = Carbon::parse($startDate)->startOfDay();
    $end = Carbon::parse($endDate)->endOfDay();

    return $query->whereBetween("{$glTable}.date", [$start, $end]);
  }

  public static function applyOpeningBefore(Builder $query, string $startDate, string $glTable = 'gl_transactions'): Builder
  {
    return $query->where("{$glTable}.date", '<', Carbon::parse($startDate)->startOfDay());
  }

  /**
   * Cash-basis: include all lines of a voucher when any line touches a bank/cash chart account.
   */
  public static function applyCashBasisFilter(Builder $query, string $glTable = 'gl_transactions'): Builder
  {
    $bankChartIds = DB::table('bank_accounts')->whereNotNull('chart_account_id')->pluck('chart_account_id');

    return $query->whereExists(function (Builder $sub) use ($glTable, $bankChartIds) {
      $sub->selectRaw('1')
        ->from('gl_transactions as gl2')
        ->whereColumn('gl2.transaction_id', "{$glTable}.transaction_id")
        ->whereColumn('gl2.transaction_type', "{$glTable}.transaction_type")
        ->whereIn('gl2.chart_account_id', $bankChartIds);
    });
  }

  /**
   * @return int[]
   */
  public static function bankChartAccountIds(User $user, int $companyId, $bankAccountId = 'all', $branchId = 'all'): array
  {
    $query = BankAccount::query()
      ->forUserBranches($user)
      ->whereHas('chartAccount.accountClassGroup', fn ($q) => $q->where('company_id', $companyId))
      ->whereNotNull('chart_account_id');

    if ($bankAccountId && $bankAccountId !== 'all') {
      $query->where('bank_accounts.id', $bankAccountId);
    }

    if ($branchId && $branchId !== 'all') {
      $query->where(function ($q) use ($branchId) {
        $q->where('bank_accounts.branch_id', $branchId)
          ->orWhere('bank_accounts.is_all_branches', true);
      });
    }

    return $query->pluck('chart_account_id')->unique()->filter()->values()->all();
  }

  public static function signedMovement(string $nature, float $amount, ?string $accountClassName = null): float
  {
    $delta = $nature === 'debit' ? $amount : -$amount;

    return $delta;
  }

  public static function netBalance(float $totalDebit, float $totalCredit, ?string $accountClassName = null): float
  {
    return $totalDebit - $totalCredit;
  }

  public static function resolveReference(?string $transactionType, $transactionId): string
  {
    if (!$transactionType || !$transactionId) {
      return '';
    }

    $type = strtolower(trim($transactionType));

    if (in_array($type, ['receipt', 'receipt_reversal'], true)) {
      $receipt = Receipt::withTrashed()->find($transactionId);

      return (string) ($receipt?->reference_number ?: $receipt?->reference ?: '');
    }

    if ($type === 'payment') {
      $payment = Payment::find($transactionId);

      return (string) ($payment?->reference_number ?: $payment?->reference ?: '');
    }

    if (str_contains($type, 'journal') || $type === 'journal') {
      $journal = Journal::find($transactionId);

      return (string) ($journal?->reference ?: '');
    }

    return '';
  }

  public static function formatVoucherNo(?string $transactionType, $transactionId): string
  {
    if (!$transactionType || $transactionId === null || $transactionId === '') {
      return '';
    }

    return trim($transactionType) . '-' . $transactionId;
  }

  /**
   * @param  Collection<int, object>|array<int, object>  $transactions
   * @param  Collection<int|string, object>  $openingBalances  keyed by chart_account_id
   * @return array<int, object>
   */
  public static function attachRunningBalances($transactions, $openingBalances, bool $groupByAccount = true): array
  {
    $openingBalances = $openingBalances instanceof Collection ? $openingBalances : collect($openingBalances);
    $running = [];
    $processed = [];

    foreach ($transactions as $transaction) {
      $accountId = $transaction->chart_account_id;

      if (!isset($running[$accountId])) {
        $opening = $openingBalances->get($accountId);
        $running[$accountId] = $opening
          ? self::netBalance((float) $opening->total_debit, (float) $opening->total_credit)
          : 0.0;
      }

      $running[$accountId] += self::signedMovement($transaction->nature, (float) $transaction->amount);
      $transaction->running_balance = round($running[$accountId], 2);
      $transaction->reference_no = self::resolveReference($transaction->transaction_type ?? null, $transaction->transaction_id ?? null);
      $transaction->voucher_no = self::formatVoucherNo($transaction->transaction_type ?? null, $transaction->transaction_id ?? null);
      $processed[] = $transaction;
    }

    return $processed;
  }

  public static function normalizeBranchId(User $user, $branchId, Collection $branches): mixed
  {
    if ($branchId === 'all' && $branches->count() <= 1) {
      return optional($branches->first())->id;
    }

    if ($branchId === null || $branchId === '') {
      return $branches->count() > 1 ? 'all' : optional($branches->first())->id;
    }

    return $branchId;
  }
}
