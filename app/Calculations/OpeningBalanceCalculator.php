<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Opening balance calculator
//  Location: app/Calculations/OpeningBalanceCalculator.php
//
//  Pure calculation, ported from the old application (HasCollectionOrPaymentStatement::calculateSettlementStatement,
//  Project::calculateFixedAssetOpeningBalances and the saving hooks of the opening balance models).
//  An existing company starts the study with balances already on its books. Each balance that is paid or collected
//  over time is given month by month ("settlements"); the statement then shows what is left every month:
//      beginning balance + additions = total due;   total due - settlement = end balance.
//  Months are positions in the study's month list (0 = the first month).
// ══════════════════════════════════════════════════════════════════

final class OpeningBalanceCalculator
{
    /**
     * The monthly statement of one opening balance (customers, debtors, suppliers, creditors, loan, long-term liabilities).
     * For the quarterly, semi-annual and annual views the settlements are added up at the last month of each calendar
     * period, as the old application did; the balance still runs month by month.
     *
     * @param  list<int>             $months       month indexes the statement covers (0 .. the study's last month)
     * @param  array<int,float|int>  $settlements  month index => amount collected or paid
     * @return array<string,array<string,array<int,float|int>>>  interval => column => month index => value
     */
    public static function settlementStatement(array $months, array $settlements, float $opening, Timeline $timeline): array
    {
        $result = [];

        foreach (IntervalSums::INTERVALS as $interval) {
            $settled = $interval === 'monthly' ? $settlements : IntervalSums::sum($settlements, $interval, $timeline);
            $balance = $opening;

            foreach ($months as $month) {
                $payment = $settled[$month] ?? 0;
                $end = $balance - $payment;

                $result[$interval]['beginning_balance'][$month] = $balance;
                $result[$interval]['addition'][$month] = 0;
                $result[$interval]['total_due'][$month] = $balance;
                $result[$interval]['payment'][$month] = $payment;
                $result[$interval]['end_balance'][$month] = $end;

                $balance = $end;
            }
        }

        return $result;
    }

    /**
     * What an owned fixed asset does over the study: monthly depreciation for the months it has left (counted from the
     * operation start), split into the administration and manufacturing parts, and its net value every month.
     *
     * @param  array{gross:float,accumulated:float,monthly:float,months:int,admin_pct:float,manufacturing_pct:float}  $asset
     * @param  list<int>  $months  month indexes 0 .. the study's last month
     * @return array{admin:array<int,float>, manufacturing:array<int,float>, accumulated:array<int,float>, statement:array<string,array<string,array<int,float>>>}
     */
    public static function fixedAsset(array $asset, Timeline $timeline, array $months): array
    {
        $operation = array_keys($timeline->operationMonths);
        $count = max(0, (int) $asset['months']);
        $monthly = (float) $asset['monthly'];

        $depreciation = [];
        foreach (array_slice($operation, 0, $count) as $month) {
            $depreciation[$month] = $monthly;
        }

        $admin = [];
        $manufacturing = [];
        foreach ($depreciation as $month => $value) {
            $admin[$month] = $value * ((float) $asset['admin_pct'] / 100);
            $manufacturing[$month] = $value * ((float) $asset['manufacturing_pct'] / 100);
        }

        $accumulated = [];
        $running = (float) $asset['accumulated'];
        foreach ($months as $month) {
            $running += ($admin[$month] ?? 0) + ($manufacturing[$month] ?? 0);
            $accumulated[$month] = $running;
        }

        $statement = ['monthly' => ['beginning_balance' => [], 'addition' => [], 'total_due' => [], 'payment' => [], 'end_balance' => []]];
        foreach ($months as $month) {
            $statement['monthly']['beginning_balance'][$month] = (float) $asset['gross'];
            $statement['monthly']['addition'][$month] = 0;
            $statement['monthly']['total_due'][$month] = (float) $asset['gross'];
            $statement['monthly']['payment'][$month] = $accumulated[$month];
            $statement['monthly']['end_balance'][$month] = (float) $asset['gross'] - $accumulated[$month];
        }

        return ['admin' => $admin, 'manufacturing' => $manufacturing, 'accumulated' => $accumulated, 'statement' => $statement];
    }

    /**
     * A balance that stays the same every operation month up to the study's end (paid-up capital, legal reserve).
     *
     * @param  list<int>  $months
     * @return array<int,float>
     */
    public static function repeated(float $value, array $months): array
    {
        return array_fill_keys($months, $value);
    }

    /**
     * Do the opening balances balance? Assets must equal liabilities plus equity.
     *
     * @param  array<string,float>  $t  the totals by name (see OpeningBalanceService::totals)
     * @return array{assets:float, liabilities_and_equity:float, difference:float, balanced:bool}
     */
    public static function balance(array $t): array
    {
        $assets = $t['net_fixed_assets'] + $t['cash'] + $t['receivables'] + $t['inventory'] + $t['other_debtors'];
        $other = $t['suppliers'] + $t['other_creditors'] + $t['vat'] + $t['withhold'] + $t['corporate_tax']
            + $t['loans'] + $t['other_long_term'] + $t['paid_up'] + $t['legal_reserve'] + $t['retained_earnings'];

        return [
            'assets'                 => $assets,
            'liabilities_and_equity' => $other,
            'difference'             => $other - $assets,
            'balanced'               => abs($other - $assets) < 0.01,
        ];
    }
}
