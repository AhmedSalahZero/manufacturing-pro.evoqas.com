<?php

namespace App\Services;

use App\Calculations\DashboardCalculator as D;
use App\Calculations\FinancialStatementsCalculator;
use App\Calculations\FinancialStatementsCalculator as F;
use App\Calculations\Timeline;
use App\Models\FixedAsset;
use App\Models\ProductSegment;
use App\Models\Project;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — DashboardService (the Dashboard of a study)
//  Location: app/Services/DashboardService.php
//
//  Takes the statements built by StatementsService::build() and works out the dashboard with DashboardCalculator:
//  revenue and profit charts, costs as % of revenue, required investment, DCF (free cash flow, WACC, NPV, IRR),
//  payback, liquidity and activity ratios. Nothing is saved: it is worked out each time from what the other screens saved
//  (or, for the sensitivity screen, from a recalculation that is rolled back).
//  present() gives lists in year order (one value per year) ready for the screen.
// ══════════════════════════════════════════════════════════════════

class DashboardService
{
    public function __construct(private readonly StatementsService $statements)
    {
    }

    /** @return array<string,mixed> */
    public function build(Project $project, Timeline $timeline): array
    {
        $built = $this->statements->build($project, $timeline);
        $years = $built['years'];
        $studyMonths = (int) $project->duration * 12;

        $assets = FixedAsset::where('project_id', $project->id)->orderBy('id')->get();
        $costOfDebt = D::costOfDebt($assets->map(fn ($a) => [
            'amount'        => (float) $a->counts * (float) $a->amount,
            'equity_rate'   => (float) $a->equity_funding_rate,
            'interest_rate' => (float) $a->interest_rate,
        ])->all());

        $summary = $built['summary'];
        $lines = $built['lines'];
        $income = $built['income'];

        // A study of one year is shown month by month; longer studies year by year.
        $monthly = (int) $project->duration === 1;
        $periods = $monthly ? array_combine($built['months'], array_map(fn ($m) => [$m], $built['months'])) : $years;

        $results = D::results([
            'years'                      => $years,
            'periods'                    => $periods,
            'revenue'                    => $income['revenue'],
            'gross_profit'               => $income['gross_profit'],
            'ebitda'                     => $income['ebitda'],
            'ebit'                       => $income['ebit'],
            'net_profit'                 => $income['net_profit'],
            'cogs_lines'                 => $built['cogs_lines'],
            'expense_lines'              => $built['expense_lines'],
            'depreciation'               => $income['depreciation'],
            'net_change_working_capital' => $built['working_capital']['net'],
            'capex'                      => $summary['fixed_asset_payments'],
            'tax_rate'                   => (float) $project->tax_rate,
            'perpetual_growth_rate'      => (float) $project->perpetual_growth_rate,
            'return_rate'                => (float) $project->return_rate,
            'cost_of_debt'               => $costOfDebt,
            'equity_funding'             => $built['funding']['equity'],
            'debt_funding'               => $built['funding']['debt'],
        ]);

        $months = $built['months'];
        $inventory = [];
        foreach ($months as $m) {
            $inventory[$m] = ($lines['rm_inventory'][$m] ?? 0) + ($lines['fg_inventory'][$m] ?? 0);
        }
        $ratios = D::ratios([
            'years'               => $years,
            'revenue'             => $income['revenue'],
            'gross_profit'        => $income['gross_profit'],
            'current_assets'      => $built['balance']['total_current_assets'],
            'current_liabilities' => $built['balance']['total_current_liabilities'],
            'cash'                => $lines['cash'],
            'receivables'         => $lines['customer_receivables'],
            'inventory'           => $inventory,
            'payables'            => $lines['supplier_payables'],
        ]);

        $workingCapital = array_sum($summary['working_capital_injection']);
        $equity = array_sum($summary['equity_injection']) + $workingCapital;
        $payback = D::payback($summary['customer_collection'], $summary['total_cash_out'], $equity, (float) $project->perpetual_growth_rate / 100, $studyMonths);

        // Cash in and out and the running total of the two, per period.
        $cashIn = FinancialStatementsCalculator::byYear($built['cash']['cash_in'], $periods, 'sum');
        $cashOut = FinancialStatementsCalculator::byYear($built['cash']['cash_out'], $periods, 'sum');
        $accumulated = [];
        $running = 0.0;
        foreach ($cashIn as $key => $in) {
            $running += $in - ($cashOut[$key] ?? 0);
            $accumulated[$key] = $running;
        }

        // An existing company brings cash and bank and loans from before the study: they are not part of the free cash flow,
        // so they are added to / taken from the value of the discounted cash flow to reach the equity value (see present()).
        $opening = ['applies' => ! $project->new_company, 'cash' => 0.0, 'loans' => 0.0];
        if ($opening['applies']) {
            $opening['cash'] = (float) $project->cashAndBankOpeningBalances()->sum('cash_and_bank_amount');
            $opening['loans'] = (float) $project->longTermLoanOpeningBalances()->sum('amount');
        }

        return [
            'monthly'    => $monthly,
            'opening'    => $opening,
            'periods'    => $periods,
            'cash'       => ['in' => $cashIn, 'out' => $cashOut, 'accumulated' => $accumulated],
            'built'      => $built,
            'results'    => $results,
            'ratios'     => $ratios,
            'payback'    => $payback,
            'investment' => [
                'fixed_assets'    => (float) $assets->sum(fn ($a) => (float) $a->counts * (float) $a->amount),
                'working_capital' => (float) $workingCapital,
                'equity'          => (float) $equity,
                'loans'           => (float) array_sum($summary['loan_withdrawal']),
            ],
        ];
    }

    /**
     * Everything the dashboard screen shows, as lists in year order.
     *
     * @param  array<string,mixed>  $dashboard  the result of build()
     * @return array<string,mixed>
     */
    public function present(array $dashboard, Project $project, Timeline $timeline): array
    {
        $years = $dashboard['built']['years'];
        $keys = array_keys($years);
        $periods = $dashboard['periods'];
        $periodKeys = array_keys($periods);
        $list = fn (array $series) => array_map(fn ($y) => round((float) ($series[$y] ?? 0), 4), $keys);
        $byPeriod = fn (array $series) => array_map(fn ($p) => round((float) ($series[$p] ?? 0), 4), $periodKeys);
        $r = $dashboard['results'];
        $dcf = $r['dcf'];

        $profit = [];
        foreach ($r['profit'] as $name => $row) {
            $profit[$name] = ['value' => $byPeriod($row['value']), 'percent' => $byPeriod($row['percent'])];
        }
        $costs = [];
        foreach ($r['costs'] as $name => $row) {
            $costs[$name] = ['value' => $byPeriod($row['value']), 'percent' => $byPeriod($row['percent'])];
        }

        $payback = $dashboard['payback'];
        if ($payback['month'] !== null) {
            $payback['date'] = $project->start_date->copy()->addMonths($payback['month'])->format('Y-m');
        } else {
            $payback['date'] = null;
        }

        return [
            'years'      => array_map(fn ($y, $ms) => ['index' => $y, 'year' => (int) substr($timeline->dates[$ms[0]], 0, 4)], $keys, $years),
            'monthly'    => $dashboard['monthly'],
            'periods'    => array_map(fn ($p, $ms) => $dashboard['monthly'] ? substr($timeline->dates[$ms[0]], 0, 7) : (string) (int) substr($timeline->dates[$ms[0]], 0, 4), $periodKeys, $periods),
            'sales_breakdown' => $this->salesBreakdown($project, $periods),
            'revenue'    => ['value' => $byPeriod($r['revenue']), 'growth' => $byPeriod($r['growth']), 'accumulated' => $byPeriod($r['accumulated'])],
            'cash'       => ['in' => $byPeriod($dashboard['cash']['in']), 'out' => $byPeriod($dashboard['cash']['out']), 'accumulated' => $byPeriod($dashboard['cash']['accumulated'])],
            'profit'     => $profit,
            'costs'      => $costs,
            'investment' => $dashboard['investment'],
            'dcf'        => [
                'free_cash_flow'  => $list($dcf['free_cash_flow']),
                'ebit'            => $list($dcf['ebit']),
                'taxes'           => $list($dcf['taxes']),
                'depreciation'    => $list($dcf['depreciation']),
                'working_capital' => $list($dcf['working_capital']),
                'capex'           => $list($dcf['capex']),
                'wacc'            => array_map(fn ($v) => round($v * 100, 4), $list($dcf['wacc'])),
                'terminal_value'  => $list($dcf['terminal_value']),
                'with_terminal'   => $list($dcf['with_terminal']),
                'discount_factor' => $list($dcf['discount_factor']),
                'discounted'      => $list($dcf['discounted']),
                'terminal_pv'     => round($dcf['terminal_pv'], 2),
                'terminal_ok'     => $dcf['terminal_ok'],
            ],
            // Equity value (existing company only) = NPV (the sum of the discounted free cash flow, i.e. the enterprise value)
            // + cash and bank at the opening − loans outstanding at the opening. The free cash flow itself is not changed.
            'valuation'  => [
                'applies'      => $dashboard['opening']['applies'],
                'cash'         => round($dashboard['opening']['cash'], 2),
                'loans'        => round($dashboard['opening']['loans'], 2),
                'equity_value' => round($dcf['npv'] + $dashboard['opening']['cash'] - $dashboard['opening']['loans'], 2),
            ],
            'ratios'     => array_map($list, $dashboard['ratios']),
            'kpi'        => [
                'npv'        => round($dcf['npv'], 2),
                'irr'        => $dcf['irr'] === null ? null : round($dcf['irr'], 2),
                'payback'    => $payback,
                'investment' => round($dashboard['investment']['fixed_assets'] + $dashboard['investment']['working_capital'], 2),
            ],
        ];
    }

    /**
     * Sales of every channel (local) and every market (export) of the study, per period, added up over the products
     * (two products sharing a channel name are shown as one line). Empty lists when no product has channels or markets.
     *
     * @param  array<int|string,list<int>>  $periods  period => the month numbers it holds
     * @return array{local:list<array{name:string,values:list<float>}>,export:list<array{name:string,values:list<float>}>}
     */
    private function salesBreakdown(Project $project, array $periods): array
    {
        $groups = ['local' => [], 'export' => []];
        $segments = ProductSegment::whereIn('product_id', $project->products()->pluck('id'))->with(['salesChannel', 'exportMarket'])->orderBy('id')->get();

        foreach ($segments as $segment) {
            $values = (array) $segment->monthly_values;
            if ($values === []) {
                continue;
            }
            $name = (string) ($segment->kind === 'local' ? $segment->salesChannel?->name : $segment->exportMarket?->name);
            foreach (array_values($periods) as $i => $months) {
                $groups[$segment->kind][$name][$i] = ($groups[$segment->kind][$name][$i] ?? 0) + array_sum(array_map(fn ($m) => (float) ($values[$m] ?? 0), $months));
            }
        }

        $out = [];
        foreach ($groups as $kind => $rows) {
            $out[$kind] = [];
            foreach ($rows as $name => $perPeriod) {
                $out[$kind][] = ['name' => (string) $name, 'values' => array_map(fn ($i) => round((float) ($perPeriod[$i] ?? 0), 4), array_keys(array_values($periods)))];
            }
        }

        return $out;
    }
}
