<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Fixed asset calculator
//  Location: app/Calculations/FixedAssetCalculator.php
//
//  Pure calculation, ported from the old application (Project::recalculateFixedAsset, FixedAsset, FfeExecutionAndPayment,
//  ContractPaymentService, InstallmentWithGraceMethod, ProjectsUnderProgress, FixedAssetsPayableEndBalance).
//
//  What happens to one asset:
//    1. Execution  The cost (count × amount) is spent in equal parts over the months from the start to the end month.
//    2. Payments   The supplier is paid in cash, on customised terms (a % of the total or of each month's execution, due
//                  in days), or in instalments (reservation, contractual payment, then equal instalments).
//    3. Funding    The owners' equity pays first; once it is used up, the bank loan pays (see LoanCalculator).
//    4. Capitalisation  While the asset is being built it sits in "projects under progress", with the loan interest that
//                  accrued meanwhile added to its cost. It is transferred to fixed assets in the month after the last of
//                  the execution or of the month before operation starts.
//    5. Depreciation  Straight line over the depreciation years, from the month after the transfer (or from the operation
//                  start when that is later). A replacement cost (a % of the gross value) comes back every N years and is
//                  depreciated over the same number of years from the month after it.
//    6. Split      The depreciation is divided into manufacturing (shared between the products) and administration.
//    7. Payable    What is owed to the supplier month by month.
//  Month indexes are positions in the study's month list (0 = the first month).
// ══════════════════════════════════════════════════════════════════

final class FixedAssetCalculator
{
    public const TERMS = ['cash', 'customize', 'installment'];

    public const INSTALLMENT_INTERVALS = ['monthly' => 1, 'quarterly' => 3, 'semi-annually' => 6, 'annually' => 12];

    /**
     * The whole calculation for one asset.
     *
     * @param  array<string,mixed>  $a  count, amount, start, end, depreciation_years, admin_pct, manufacturing_pct,
     *                                   replacement_rate, replacement_years, terms, policy (days => %), from_total (days => bool),
     *                                   reservation, contractual, after_months, installment_grace, installment_count,
     *                                   installment_interval, equity_rate, interest_rate, tenor, loan_interval, loan_grace
     * @return array<string,mixed>
     */
    public static function calculate(array $a, Timeline $timeline, int $operationStart, int $studyEnd): array
    {
        $indexes = array_keys($timeline->dates);
        $maxIndex = max($indexes);
        $firstDate = $timeline->dates[$indexes[0]];

        $total = (float) $a['count'] * (float) $a['amount'];
        $start = (int) $a['start'];
        $end = (int) $a['end'];

        $execution = self::execution($total, $start, $end);
        $payments = self::payments($a, $total, $execution, $maxIndex);
        $funding = self::funding($payments, $total, (float) $a['equity_rate']);

        // Loan: drawdown, then repayment.
        $drawdown = ['interest' => [], 'end_balance' => [], 'withdrawal' => [], 'start_index' => null, 'amount' => 0.0];
        $schedule = [];
        $loanStartIndex = null;

        if ((float) $a['equity_rate'] < 100 && $funding['withdrawal'] !== []) {
            $drawdown = LoanCalculator::drawdown($funding['withdrawal'], (float) $a['interest_rate'], $firstDate);
            $loanStartIndex = $drawdown['start_index'];

            if ($loanStartIndex !== null) {
                $schedule = LoanCalculator::schedule(
                    $loanStartIndex,
                    LoanCalculator::addMonths($firstDate, $loanStartIndex),
                    $drawdown['amount'],
                    (float) $a['interest_rate'],
                    (int) $a['tenor'],
                    (string) $a['loan_interval'],
                    (int) $a['loan_grace'],
                );
            }
        }

        $loanInterest = $schedule['interestAmount'] ?? [];
        $underProgress = self::underProgress($start, $end, $execution, $loanInterest, $drawdown['interest'], $operationStart, $indexes);
        $transferIndex = $underProgress === [] ? null : array_key_last($underProgress['end_balance']);

        $depreciation = [];
        $transferredAmount = 0.0;
        if ($underProgress !== []) {
            $transferredAmount = (float) (end($underProgress['transferred_date_and_vales']) ?: 0);
            $depreciation = self::depreciation(
                (int) array_key_last($underProgress['transferred_date_and_vales']),
                $transferredAmount,
                (int) $a['depreciation_years'] * 12,
                (float) $a['replacement_rate'],
                (int) $a['replacement_years'] * 12,
                $operationStart,
                $end,
                $studyEnd,
                $indexes,
            );
        }

        $monthlyDepreciation = $depreciation['total_monthly_depreciation'] ?? [];
        $manufacturing = [];
        $administration = [];
        foreach ($monthlyDepreciation as $month => $value) {
            $manufacturing[$month] = (float) $a['manufacturing_pct'] / 100 * $value;
            $administration[$month] = (float) $a['admin_pct'] / 100 * $value;
        }

        return [
            'total_cost'                       => $total,
            'execution'                        => $execution,
            'payments'                         => $payments,
            'equity_payment'                   => $funding['equity'],
            'loan_withdrawal'                  => $funding['withdrawal'],
            'loan_withdrawal_interest'         => $drawdown['interest'],
            'loan_withdrawal_end_balance'      => $drawdown['end_balance'],
            'loan_start_index'                 => $loanStartIndex,
            'loan_amount'                      => $drawdown['amount'],
            'schedule'                         => $schedule,
            'schedule_end_balance'             => $schedule === [] ? [] : LoanCalculator::fillMonths($schedule['endBalance'], $indexes),
            'under_progress'                   => $underProgress,
            'transfer_index'                   => $transferIndex,
            'transferred_amount'               => $transferredAmount,
            'income_statement_interest'        => $transferIndex === null ? [] : array_filter($drawdown['interest'], fn ($i) => $i >= $transferIndex, ARRAY_FILTER_USE_KEY),
            'depreciation'                     => $depreciation,
            'manufacturing_depreciation'       => $manufacturing,
            'administration_depreciation'      => $administration,
            'payable'                          => self::payable($execution, $payments, $maxIndex),
        ];
    }

    // ── 1. Execution ───────────────────────────────────────────────

    /** @return array<int,float> month index => amount spent */
    public static function execution(float $total, int $start, int $end): array
    {
        $duration = $end === $start ? 0 : $end - $start + 1;

        if ($duration === 0) {
            return [$start => $total];
        }

        $months = [];
        for ($i = 0; $i < $duration; $i++) {
            $months[$start + $i] = $total / $duration;
        }

        return $months;
    }

    // ── 2. Payments ────────────────────────────────────────────────

    /**
     * What is paid to the supplier, by month. Money due after the last month of the study is not counted.
     *
     * @param  array<string,mixed>   $a
     * @param  array<int,float|int>  $execution
     * @return array<int,float>
     */
    public static function payments(array $a, float $total, array $execution, int $maxIndex): array
    {
        $terms = (string) ($a['terms'] ?? 'cash');

        if ($terms === 'installment') {
            $paid = self::installments(
                (int) $a['start'], $total,
                (float) ($a['reservation'] ?? 0), (float) ($a['contractual'] ?? 0), (int) ($a['after_months'] ?? 0),
                (int) ($a['installment_grace'] ?? 0), (int) ($a['installment_count'] ?? 1), (string) ($a['installment_interval'] ?? 'monthly'),
            );
        } else {
            $policy = $terms === 'cash' ? [0 => 100.0] : (array) ($a['policy'] ?? []);
            $fromTotal = $terms === 'cash' ? [] : (array) ($a['from_total'] ?? []);
            $paid = self::contractPayments($execution, $total, $policy, $fromTotal, $terms === 'cash');
        }

        $paid = array_filter($paid, fn ($month) => $month <= $maxIndex, ARRAY_FILTER_USE_KEY);
        ksort($paid);

        return $paid;
    }

    /**
     * Customised payments. Every line is "due days => %" and is taken either from the total cost (paid once, counted from
     * the first execution month) or from each month's execution. A line whose due days are under 30 lands in the same month;
     * otherwise months = round(days / 30).
     *
     * @param  array<int,float|int>   $execution
     * @param  array<int,float|int>   $policy      due days => %
     * @param  array<int,bool|int>    $fromTotal   due days => true when the % is of the total cost
     * @return array<int,float>
     */
    public static function contractPayments(array $execution, float $total, array $policy, array $fromTotal, bool $allFromExecution = false): array
    {
        $paid = [];
        $first = array_key_first($execution);

        if ($allFromExecution) {
            $fromExecution = array_keys($policy);
        } else {
            $fromExecution = [];
            foreach ($fromTotal as $days => $isTotal) {
                if ($isTotal) {
                    $month = $first + CollectionPolicy::monthsLater((int) $days);
                    $paid[$month] = $total * (($policy[$days] ?? 0) / 100) + ($paid[$month] ?? 0);
                } else {
                    $fromExecution[] = $days;
                }
            }
        }

        foreach ($execution as $month => $amount) {
            foreach ($fromExecution as $days) {
                $target = $month + CollectionPolicy::monthsLater((int) $days);
                $paid[$target] = $amount * (($policy[$days] ?? 0) / 100) + ($paid[$target] ?? 0);
            }
        }

        ksort($paid);

        return $paid;
    }

    /**
     * Instalment payments: a reservation at the start, a contractual payment some months later (the same month when 0),
     * and the rest in equal instalments of the chosen interval after the grace period.
     *
     * @return array<int,float>
     */
    public static function installments(int $start, float $amount, float $reservation, float $contractual, int $afterMonths, int $grace, int $count, string $interval): array
    {
        $step = self::INSTALLMENT_INTERVALS[$interval] ?? 0;
        $count = max(1, $count);
        $secondIndex = $start + $afterMonths;
        $balance = (100 - $reservation - $contractual) / 100 * $amount;
        $each = $balance / $count;

        if ($afterMonths === 0) {
            $dates = [$start => $reservation / 100 * $amount + $contractual / 100 * $amount];
        } else {
            $dates = [$start => $reservation / 100 * $amount, $secondIndex => $contractual / 100 * $amount];
        }

        if ($step < 1) {
            return $dates;
        }

        $remaining = $balance;
        for ($i = 0; $i < $count; $i += $step) {
            $month = $i + $secondIndex + $step + $grace;
            $dates[$month] = $each * $step;
            $remaining -= $dates[$month];
            if ($remaining < 0) {
                $dates[$month] += $remaining;
            }
        }

        return $dates;
    }

    // ── 3. Funding ─────────────────────────────────────────────────

    /**
     * Equity pays first, then the loan.
     *
     * @param  array<int,float>  $payments
     * @return array{equity:array<int,float>, withdrawal:array<int,float>}
     */
    public static function funding(array $payments, float $total, float $equityRate): array
    {
        $equityAmount = $total * ($equityRate / 100);

        // What the equity pays each month: all of the payment while the equity lasts, then what is left of it.
        $equity = [];
        $remaining = $equityAmount;
        foreach ($payments as $month => $payment) {
            $equity[$month] = $remaining >= $payment ? $payment : $remaining;
            $remaining -= $equity[$month];
        }

        // What the loan pays: each payment after the equity ran out (the month it runs out, only the part it could not cover).
        $withdrawal = [];
        $balance = $equityAmount;
        $previousBalance = null;
        $drawing = false;
        foreach ($payments as $month => $payment) {
            $balance -= $payment;
            if ($balance < 0) {
                $withdrawal[$month] = $drawing ? ($balance - $previousBalance) * -1 : $balance * -1;
                $drawing = true;
            }
            $previousBalance = $balance;
        }

        if (array_sum($withdrawal) > -1 && array_sum($withdrawal) < 1) {
            return ['equity' => $equity, 'withdrawal' => []];
        }

        // Every month from the first to the last draw is listed, with 0 where nothing is drawn.
        $first = array_key_first($withdrawal);
        $last = array_key_last($withdrawal);
        for ($month = $first; $month <= $last; $month++) {
            $withdrawal[$month] ??= 0.0;
        }
        ksort($withdrawal);

        return ['equity' => $equity, 'withdrawal' => $withdrawal];
    }

    // ── 4. Capitalisation (projects under progress) ────────────────

    /**
     * The asset while it is being built: spending plus the loan interest that accrued up to the transfer.
     *
     * @param  array<int,float>  $execution
     * @param  array<int,float>  $loanInterest        interest of the repayment schedule
     * @param  array<int,float>  $drawdownInterest    interest during the drawdown
     * @param  list<int>         $indexes             every month of the study list
     * @return array<string,array<int,float|int>>     empty when there is nothing to build
     */
    public static function underProgress(int $start, int $end, array $execution, array $loanInterest, array $drawdownInterest, int $operationStart, array $indexes): array
    {
        if ($execution === []) {
            return [];
        }

        $transferFactor = $end === $start ? 0 : 1;
        $finalExecution = array_key_last($execution);

        // The interest counts up to the month before operation starts, but never before the last execution month.
        $beforeOperation = $operationStart === 0 ? 0 : $operationStart - 1;
        $beforeOperation = max(0, $beforeOperation);
        $beforeOperation = $operationStart >= $end ? $end : $beforeOperation;
        $finalCapitalised = $beforeOperation >= $finalExecution ? $beforeOperation : $finalExecution;
        $transferMonth = $finalCapitalised + $transferFactor;

        $capitalised = [];
        foreach (array_unique(array_merge(array_keys($drawdownInterest), array_keys($loanInterest))) as $month) {
            $capitalised[$month] = $month <= $finalCapitalised ? ($drawdownInterest[$month] ?? 0) + ($loanInterest[$month] ?? 0) : 0;
        }

        $result = [];
        $beginning = 0.0;
        foreach ($indexes as $month) {
            $result['beginning_balance'][$month] = $beginning;
            $added = $execution[$month] ?? 0;
            $result['additions'][$month] = $added;
            $interest = $capitalised[$month] ?? 0;
            $result['capitalized_interest'][$month] = $interest;
            $total = $beginning + $added + $interest;
            $result['total'][$month] = $total;
            $beginning = $total;

            if ($month === $transferMonth) {
                $result['transferred_date_and_vales'][$month] = $total;
                $result['end_balance'][$month] = 0.0;
                break;
            }

            $result['transferred_date_and_vales'][$month] = 0;
            $result['end_balance'][$month] = $total;
        }

        return $result;
    }

    // ── 5. Depreciation ────────────────────────────────────────────

    /**
     * The asset's gross value, replacements and straight-line depreciation.
     *
     * @param  int    $transferMonth        the month the asset is transferred to fixed assets
     * @param  int    $durationMonths       depreciation period in months (0 = not depreciated)
     * @param  int    $replacementMonths    months between replacements
     * @param  int    $operationStart       first month of operation
     * @param  int    $assetEnd             last month of the execution
     * @param  int    $studyEnd             last month of the study
     * @param  list<int>  $indexes          every month of the study list
     * @return array<string,array<int,float|int>>
     */
    public static function depreciation(int $transferMonth, float $transferred, int $durationMonths, float $replacementRate, int $replacementMonths, int $operationStart, int $assetEnd, int $studyEnd, array $indexes): array
    {
        $operationStart = max($operationStart, $assetEnd);
        $replacementRate /= 100;

        // The months a replacement falls in: operation start + N, + 2N, … until one passes the study end.
        $replacementMonthsList = [];
        $cursor = $operationStart;
        foreach ($indexes as $month) {
            if ($cursor > $studyEnd) {
                break;
            }
            $cursor += $replacementMonths;
            $replacementMonthsList[] = $cursor;
        }

        $result = ['beginning_balance' => [], 'additions' => [], 'initial_total_gross' => [], 'replacement_cost' => [], 'final_total_gross' => [], 'end_balance' => []];
        $monthly = [];
        $beginning = 0.0;

        foreach ($indexes as $month) {
            $added = $month === $transferMonth ? $transferred : 0.0;
            $initial = $beginning + $added;
            $isReplacement = in_array($month, $replacementMonthsList, true);
            $replacement = $isReplacement ? $initial * $replacementRate : 0.0;
            $final = $initial + $replacement;

            // Depreciation of what came in this month: from the month after, for the whole depreciation period.
            if ($durationMonths > 0 && ($added + $replacement) != 0.0) {
                $perMonth = ($added + $replacement) / $durationMonths;
                if ($transferMonth < $operationStart) {
                    $from = $operationStart;
                    $to = $from + $durationMonths - 1;
                } else {
                    $from = $month + 1;
                    $to = $from + $durationMonths - 1;
                }
                if ($isReplacement && $transferMonth <= $operationStart) {
                    $to = $month + $durationMonths;
                }
                foreach ($indexes as $j) {
                    if ($j > $month && $j >= $from && $j <= $to) {
                        $monthly[$j] = ($monthly[$j] ?? 0) + $perMonth;
                    }
                }
            }

            $result['beginning_balance'][$month] = $beginning;
            $result['additions'][$month] = $added;
            $result['initial_total_gross'][$month] = $initial;
            $result['replacement_cost'][$month] = $replacement;
            $result['final_total_gross'][$month] = $final;
            $beginning = $final;
        }

        $accumulated = [];
        $running = 0.0;
        foreach ($indexes as $month) {
            $running += $monthly[$month] ?? 0;
            $accumulated[$month] = $running;
            $result['end_balance'][$month] = $result['final_total_gross'][$month] - $running;
        }

        $result['total_monthly_depreciation'] = $monthly;
        $result['accumulated_depreciation'] = $accumulated;

        return $result;
    }

    // ── 7. Payable ─────────────────────────────────────────────────

    /**
     * What is still owed to the supplier at the end of each month: everything executed so far minus everything paid.
     * (The old application only counted purchases from the first payment month on.)
     *
     * @param  array<int,float|int>  $execution
     * @param  array<int,float|int>  $payments
     * @return array<int,float>
     */
    public static function payable(array $execution, array $payments, int $maxIndex): array
    {
        if ($execution === []) {
            return [];
        }

        $first = min(array_merge(array_keys($execution), array_keys($payments)));
        $balance = 0.0;
        $result = [];

        for ($month = $first; $month <= $maxIndex; $month++) {
            $balance += ($execution[$month] ?? 0) - ($payments[$month] ?? 0);
            $balance = abs($balance) < 0.000001 ? 0.0 : $balance;
            $result[$month] = $balance;
        }

        return $result;
    }
}
