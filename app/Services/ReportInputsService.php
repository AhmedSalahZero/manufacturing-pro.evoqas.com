<?php

namespace App\Services;

use App\Calculations\OpeningBalanceCalculator;
use App\Calculations\Timeline;
use App\Models\Project;
use App\Support\StudyCalendar;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ReportInputsService (the building blocks of a study, ready to print)
//  Location: app/Services/ReportInputsService.php
//
//  build()  the pages a person filled in (products, raw materials, manpower plan, expenses, fixed assets, opening balances),
//           as read-only tables for the printable report. Nothing is calculated here that the screens do not already show:
//           the figures come from what was typed and from what the calculation saved.
//
//  Shape:   a list of sections: ['id' => 'products', 'blocks' => [block, ...]]; a block is
//             ['type' => 'table', 'title' => cell|null, 'headers' => [cell], 'rows' => [[cell]], 'total' => bool]  or
//             ['type' => 'text',  'title' => cell|null, 'text' => string].
//           A cell is a plain string or number, or ['k' => 'lang.key', 'p' => [params]] (translated by the page),
//           ['pct' => 12.5] (a percentage) or ['n' => 1234.5, 'd' => 1] (a number with a fixed count of decimals).
//  Empty sections are left out; opening balances appear for an existing company only.
// ══════════════════════════════════════════════════════════════════

class ReportInputsService
{
    public function __construct(
        private readonly ProductPlanService $plans,
        private readonly CostCalculationService $costs,
        private readonly OpeningBalanceService $balances,
    ) {
    }

    /** @return list<array{id:string,blocks:list<array<string,mixed>>}> */
    public function build(Project $project, ?Timeline $timeline): array
    {
        $project->load('products');
        $dates = $project->extended_study_dates ?? [];

        $sections = [
            'products'    => $this->products($project, $dates, $timeline),
            'rawMaterials' => $this->rawMaterials($project),
            'manpower'    => $this->manpower($project, $timeline),
            'expenses'    => $this->expenses($project, $dates, $timeline),
            'assets'      => $this->assets($project, $dates, $timeline),
            'opening'     => $project->new_company ? [] : $this->opening($project),
        ];

        $out = [];
        foreach ($sections as $id => $blocks) {
            if ($blocks !== []) {
                $out[] = ['id' => $id, 'blocks' => $blocks];
            }
        }

        return $out;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function table(mixed $title, array $headers, array $rows, bool $total = false): array
    {
        return ['type' => 'table', 'title' => $title, 'headers' => $headers, 'rows' => $rows, 'total' => $total];
    }

    private function text(mixed $title, string $text): array
    {
        return ['type' => 'text', 'title' => $title, 'text' => $text];
    }

    private function k(string $key, array $params = [], string $suffix = ''): array
    {
        $cell = ['k' => $key];
        if ($params !== []) {
            $cell['p'] = $params;
        }
        if ($suffix !== '') {
            $cell['sfx'] = $suffix;   // plain text shown after the translated label (a number, a name)
        }

        return $cell;
    }

    private function pct(mixed $value): array
    {
        return ['pct' => (float) $value];
    }

    /** @param  list<int|string>  $years */
    private function yearHeaders(array $years, int $startYear): array
    {
        return array_map(fn ($y) => (string) ($startYear + (int) $y), $years);
    }

    /** One value per year from a stored array keyed by year index. */
    private function byYear(array $stored, array $years, callable $format): array
    {
        return array_map(fn ($y) => isset($stored[$y]) && $stored[$y] !== '' ? $format($stored[$y]) : '', $years);
    }

    /** "50% after 30 days + 50% after 60 days" without words, so it reads the same in both languages. */
    private function shares(array $custom): string
    {
        $parts = [];
        foreach ($custom as $days => $rate) {
            $parts[] = ((float) $rate).'% / '.(int) $days.'d';
        }

        return $parts === [] ? '' : implode(' + ', $parts);
    }

    /** The calendar years of the study (the extra year the calculation adds after the study is left out). */
    private function studyYears(Project $project, ?Timeline $timeline): array
    {
        if (! $timeline) {
            return [];
        }

        $last = (int) ($project->end_date ?? $project->start_date)->format('Y');
        $years = [];
        foreach ($timeline->dates as $date) {
            $y = (int) substr($date, 0, 4);
            if ($y <= $last) {
                $years[$y] = true;
            }
        }

        return array_keys($years);
    }

    // ── products ────────────────────────────────────────────────────────────

    private function products(Project $project, array $dates, ?Timeline $timeline): array
    {
        $startYear = (int) $project->start_date->format('Y');
        $periods = $project->duration > 1 ? 2 : 1;
        $blocks = [];

        foreach ($project->products()->with('seasonality')->orderBy('id')->get() as $product) {
            $years = $this->plans->viewYears($project, $product);
            $name = $product->name.($product->measurement_unit ? ' ('.$product->measurement_unit.')' : '');
            $num = fn ($v) => (float) $v;
            $pctf = fn ($v) => $this->pct($v);
            $heads = array_merge([''], $this->yearHeaders($years, $startYear));

            $blocks[] = $this->table(
                ['text' => $name],
                [$this->k('report.f.sellingStart'), $this->k('products.info.vat'), $this->k('products.info.withhold')],
                [[StudyCalendar::monthAt($dates, $product->selling_start_date) ?? '', $this->pct($product->vat_rate), $this->pct($product->withhold_tax_rate)]],
            );

            $stored = fn (string $field) => (array) $product->{$field};
            $rows = [
                array_merge([$this->k('products.sales.capacity')], $this->byYear($stored('max_capacity'), $years, $num)),
                array_merge([$this->k('products.sales.targetPercent')], $this->byYear($stored('target_percentages'), $years, $pctf)),
                array_merge([$this->k('products.sales.targetQuantity')], $this->byYear($stored('target_quantities'), $years, $num)),
                array_merge([$this->k('products.sales.localPercent')], $this->byYear($stored('local_target_quantity_percentages'), $years, $pctf)),
                array_merge([$this->k('products.sales.localQuantity')], $this->byYear($stored('local_target_quantities'), $years, $num)),
                array_merge([$this->k('products.sales.localGrowth')], $this->byYear($stored('local_growth_rates'), $years, $pctf)),
                array_merge([$this->k('products.sales.localPrice')], $this->byYear($stored('local_price_per_unit'), $years, fn ($v) => ['n' => (float) $v, 'd' => 2])),
                array_merge([$this->k('products.sales.exportGrowth')], $this->byYear($stored('export_growth_rates'), $years, $pctf)),
                array_merge([$this->k('products.sales.exportPrice')], $this->byYear($stored('export_price_per_unit'), $years, fn ($v) => ['n' => (float) $v, 'd' => 2])),
            ];
            $blocks[] = $this->table($this->k('products.sales.title'), $heads, $rows);

            $season = $product->seasonality;
            $type = $season?->type ?? 'flat';
            if ($type === 'flat') {
                $blocks[] = $this->table($this->k('products.season.title'), [$this->k('products.season.title')], [[$this->k('products.season.flat')]]);
            } else {
                $values = array_values(array_map('floatval', (array) $season->percentages));
                $headers = [$this->k('products.season.'.$type)];
                foreach ($values as $i => $_) {
                    $headers[] = $type === 'quarterly' ? $this->k('products.season.quarter', ['n' => $i + 1]) : (string) ($i + 1);
                }
                $blocks[] = $this->table($this->k('products.season.title'), $headers, [array_merge([''], array_map($pctf, $values))]);
            }

            $inventoryHead = [$this->k('products.inventory.coverage')];
            $inventoryRow = [$this->k('terms.days', ['n' => (int) ($product->fg_inventory_coverage_days ?? 30)])];
            if (! $project->new_company) {
                $inventoryHead = array_merge($inventoryHead, [$this->k('products.inventory.quantity'), $this->k('products.inventory.value')]);
                $inventoryRow = array_merge($inventoryRow, [$num($product->fg_inventory_quantity), $num($product->fg_inventory_value)]);
                foreach (ProductPlanService::FG_BREAKDOWN_TYPES as $part) {
                    $inventoryHead[] = $this->k('products.inventory.parts.'.$part);
                    $inventoryRow[] = $this->pct(($product->fg_beginning_inventory_breakdowns[$part]['percentage'] ?? null) ?? ($part === 'raw_material_value' ? 100 : 0));
                }
            }
            $blocks[] = $this->table($this->k('products.inventory.title'), $inventoryHead, [$inventoryRow]);

            $policy = (array) $product->collection_policy_value;
            $policyRows = [];
            foreach (['local', 'export'] as $channel) {
                for ($i = 0; $i < $periods; $i++) {
                    $p = ((array) ($policy[$channel] ?? []))[$i] ?? [];
                    $policyRows[] = [
                        $this->k('products.collection.'.$channel),
                        $this->k($i === 0 ? 'products.collection.firstYear' : 'products.collection.laterYears'),
                        $this->pct($p['cash_payment'] ?? 100),
                        $this->pct($p['rate'][0] ?? 0), $this->k('terms.days', ['n' => (int) ($p['due_in_days'][0] ?? 30)]),
                        $this->pct($p['rate'][1] ?? 0), $this->k('terms.days', ['n' => (int) ($p['due_in_days'][1] ?? 60)]),
                    ];
                }
            }
            $blocks[] = $this->table($this->k('products.collection.title'), [
                '', '', $this->k('terms.collect.cash'), $this->k('terms.collect.later', [], ' 1'), $this->k('terms.collect.after'), $this->k('terms.collect.later', [], ' 2'), $this->k('terms.collect.after'),
            ], $policyRows);

            $recipe = [];
            foreach ($product->rawMaterials()->get() as $material) {
                $percentages = (array) json_decode((string) $material->pivot->percentages, true);
                $recipe[] = array_merge([$material->name], $this->byYear($percentages, $years, $pctf));
            }
            if ($recipe !== []) {
                $blocks[] = $this->table($this->k('products.recipe.title'), array_merge([$this->k('products.recipe.material')], $this->yearHeaders($years, $startYear)), $recipe);
            }

            $results = $this->productResults($product, $years, $startYear, $timeline);
            if ($results !== []) {
                $blocks[] = $this->table($this->k('products.results.title'), [
                    $this->k('products.results.year'), $this->k('products.results.salesValue'), $this->k('products.results.localSales'), $this->k('products.results.exportSales'),
                    $this->k('products.results.salesQuantity'), $this->k('products.results.madeQuantity'), $this->k('products.results.closingStock'),
                ], $results);
            }

            if ($product->comment_for_sales) {
                $blocks[] = $this->text($this->k('products.comment.title'), (string) $product->comment_for_sales);
            }
        }

        return $blocks;
    }

    /** @return list<list<mixed>> the saved calculation of a product, added up per year */
    private function productResults($product, array $years, int $startYear, ?Timeline $timeline): array
    {
        $values = (array) $product->monthly_sales_target_values;
        if ($timeline === null || $values === []) {
            return [];
        }

        $quantities = (array) $product->monthly_sales_target_quantities;
        $made = (array) ($product->product_inventory_qt_statement['manufacturing_quantity'] ?? []);
        $closing = (array) ($product->product_inventory_qt_statement['end_balance'] ?? []);
        $local = (array) ($product->local_collection_statement['monthly']['expense'] ?? []);
        $export = (array) ($product->export_collection_statement['monthly']['expense'] ?? []);
        $rows = [];

        foreach ($years as $year) {
            $months = array_filter(array_keys($values), fn ($m) => $timeline->yearIndexOf((int) $m) === $year);
            $sum = fn (array $series) => array_sum(array_map(fn ($m) => (float) ($series[$m] ?? 0), $months));
            $rows[] = [
                (string) ($startYear + $year), $sum($values), $sum($local), $sum($export), $sum($quantities), $sum($made),
                $months === [] ? 0 : (float) ($closing[max($months)] ?? 0),
            ];
        }

        return $rows;
    }

    // ── raw materials ───────────────────────────────────────────────────────

    private function rawMaterials(Project $project): array
    {
        $materials = $project->rawMaterials()->orderBy('id')->get();
        if ($materials->isEmpty()) {
            return [];
        }

        $periods = $project->duration > 1 ? 2 : 1;
        $rows = [];
        foreach ($materials as $m) {
            $stored = (array) $m->collection_policy_value;
            for ($i = 0; $i < $periods; $i++) {
                $p = $stored[$i] ?? [];
                $rows[] = [
                    $i === 0 ? $m->name.($m->measurement_unit ? ' ('.$m->measurement_unit.')' : '') : '',
                    $i === 0 ? $this->pct($m->vat_rate) : '',
                    $i === 0 ? $this->pct($m->withhold_tax_rate) : '',
                    $i === 0 ? $this->k('terms.days', ['n' => (int) $m->rm_inventory_coverage_days]) : '',
                    $this->k($i === 0 ? 'rawPayments.firstYear' : 'rawPayments.laterYears'),
                    $this->pct($p['cash_payment'] ?? 100),
                    $this->pct($p['rate'][0] ?? 0), $this->k('terms.days', ['n' => (int) ($p['due_in_days'][0] ?? 30)]),
                    $this->pct($p['rate'][1] ?? 0), $this->k('terms.days', ['n' => (int) ($p['due_in_days'][1] ?? 60)]),
                ];
            }
        }

        $blocks = [$this->table($this->k('rawPayments.title'), [
            $this->k('products.recipe.material'), $this->k('rawPayments.vat'), $this->k('rawPayments.withhold'), $this->k('rawPayments.coverage'), '',
            $this->k('terms.pay.cash'), $this->k('terms.pay.later', [], ' 1'), $this->k('terms.pay.after'), $this->k('terms.pay.later', [], ' 2'), $this->k('terms.pay.after'),
        ], $rows)];

        if ($project->comment_for_raw_materials) {
            $blocks[] = $this->text($this->k('products.comment.title'), (string) $project->comment_for_raw_materials);
        }

        return $blocks;
    }

    // ── manpower plan ───────────────────────────────────────────────────────

    private function manpower(Project $project, ?Timeline $timeline): array
    {
        $rows = $project->manpowers()->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $blocks = [$this->table($this->k('manpower.settings.title'), [
            $this->k('manpower.settings.increase'), $this->k('manpower.settings.tax'), $this->k('manpower.settings.insurance'),
        ], [[$this->pct($project->salaries_annual_increase), $this->pct($project->salaries_tax_rate), $this->pct($project->social_insurance_rate)]])];

        $monthName = fn ($index) => $timeline && isset($timeline->dates[$index]) ? substr($timeline->dates[$index], 0, 7) : (string) $index;
        $positions = [];
        foreach ($rows as $r) {
            $hirings = array_filter((array) $r->hirings, fn ($v) => (float) $v > 0);
            ksort($hirings);
            $when = implode('; ', array_map(fn ($m, $v) => $monthName($m).' × '.(float) $v, array_keys($hirings), $hirings));
            $positions[] = [
                $this->k('manpower.types.'.$r->type), $r->position, (float) $r->avg_salary,
                $project->new_company ? '' : (float) $r->existing_count,
                (float) array_sum(array_map('floatval', $hirings)), $when,
            ];
        }
        $blocks[] = $this->table($this->k('manpower.title'), [
            $this->k('manpower.results.type'), $this->k('manpower.position'), $this->k('manpower.salary'), $this->k('manpower.existing'),
            $this->k('report.f.hired'), $this->k('manpower.hiringsButton'),
        ], $positions);

        $products = $project->products;
        if ($products->isNotEmpty()) {
            $shares = [];
            foreach (CostCalculationService::MANPOWER_TYPES as $type => $info) {
                if ($info['column'] === null) {
                    continue;
                }
                $percent = $this->costs->manpowerPercentages($project, $type);
                $byRevenue = (bool) (($project->manpower_is_as_revenue_percentages ?? [])[$type] ?? true);
                $row = [$this->k('manpower.types.'.$type), $this->k($byRevenue ? 'assets.byRevenue' : 'assets.custom')];
                foreach ($products as $p) {
                    $row[] = $byRevenue ? '' : $this->pct($percent[$p->id] ?? 0);
                }
                $shares[] = $row;
            }
            $blocks[] = $this->table($this->k('manpower.share.title'), array_merge(['', ''], $products->pluck('name')->all()), $shares);
        }

        $years = $this->studyYears($project, $timeline);
        if ($timeline && $years !== []) {
            $perType = [];
            foreach ($rows as $r) {
                foreach (array_keys($timeline->operationMonths) as $month) {
                    $y = (int) substr($timeline->dates[$month], 0, 4);
                    $perType[$r->type][$y] = ($perType[$r->type][$y] ?? 0) + (float) (((array) $r->salary_expenses)[$month] ?? 0);
                }
            }
            $table = [];
            $totals = array_fill_keys($years, 0.0);
            foreach ($perType as $type => $byYear) {
                $line = [$this->k('manpower.types.'.$type)];
                foreach ($years as $y) {
                    $line[] = (float) ($byYear[$y] ?? 0);
                    $totals[$y] += (float) ($byYear[$y] ?? 0);
                }
                $table[] = $line;
            }
            $table[] = array_merge([$this->k('manpower.results.total')], array_values($totals));
            $blocks[] = $this->table($this->k('manpower.results.expense'), array_merge([$this->k('manpower.results.type')], array_map('strval', $years)), $table, true);
        }

        return $blocks;
    }

    // ── expenses ────────────────────────────────────────────────────────────

    private function expenses(Project $project, array $dates, ?Timeline $timeline): array
    {
        $rows = $project->expenses()->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $names = $project->products->pluck('name', 'id')->all();
        $table = [];
        foreach ($rows as $e) {
            $amount = match ($e->relation_name) {
                'expense_as_percentage' => $this->pct($e->monthly_percentage),
                'cost_per_unit'         => (float) $e->monthly_cost_of_unit,
                default                 => (float) $e->amount,
            };
            $terms = (string) ($e->payment_terms ?: 'cash');
            $selected = array_values(array_filter(array_map(fn ($id) => $names[(int) $id] ?? null, (array) $e->products)));
            $table[] = [
                $this->k('expenses.categories.'.$e->category_id), $e->name, $this->k('expenses.kinds.'.$e->relation_name), $amount,
                StudyCalendar::monthAt($dates, $e->start_date) ?? '', StudyCalendar::monthAt($dates, $e->end_date) ?? '',
                $this->pct($e->increase_rate),
                $e->relation_name === 'one_time_expense' ? (int) ($e->amortization_months ?: 12) : '',
                $terms === 'customize' ? $this->shares((array) $e->custom_collection_policy) : $this->k('expenses.terms.'.$terms),
                $selected === [] ? '' : implode(', ', $selected),
            ];
        }

        $blocks = [$this->table($this->k('expenses.title'), [
            $this->k('expenses.category'), $this->k('expenses.name'), $this->k('report.f.kind'), $this->k('report.f.amount'),
            $this->k('expenses.from'), $this->k('expenses.to'), $this->k('expenses.increase'), $this->k('expenses.amortization'),
            $this->k('expenses.paymentTerms'), $this->k('expenses.productsTitle'),
        ], $table)];

        $years = $this->studyYears($project, $timeline);
        if ($timeline && $years !== []) {
            $table = [];
            $totals = array_fill_keys($years, 0.0);
            foreach ($rows as $e) {
                $perYear = [];
                foreach ((array) ($e->collection_statements['monthly']['expense'] ?? []) as $month => $value) {
                    if (isset($timeline->dates[$month])) {
                        $y = (int) substr($timeline->dates[$month], 0, 4);
                        $perYear[$y] = ($perYear[$y] ?? 0) + (float) $value;
                    }
                }
                $line = [$e->name];
                foreach ($years as $y) {
                    $line[] = (float) ($perYear[$y] ?? 0);
                    $totals[$y] += (float) ($perYear[$y] ?? 0);
                }
                $table[] = $line;
            }
            $table[] = array_merge([$this->k('manpower.results.total')], array_values($totals));
            $blocks[] = $this->table($this->k('expenses.results.title'), array_merge([$this->k('expenses.results.expense')], array_map('strval', $years)), $table, true);
        }

        return $blocks;
    }

    // ── fixed assets ────────────────────────────────────────────────────────

    private function assets(Project $project, array $dates, ?Timeline $timeline): array
    {
        $rows = $project->fixedAssets()->with('loanSchedulePayments')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $main = [];
        $funding = [];
        $instalments = [];
        $shares = [];
        $products = $project->products;

        foreach ($rows as $a) {
            $terms = (string) ($a->payment_terms ?: 'cash');
            $custom = [];
            foreach ((array) $a->custom_collection_policy as $days => $rate) {
                $custom[] = ((float) $rate).'% / '.(int) $days.'d';
            }
            $summary = $this->assetSummary($a, $dates);
            $equity = (float) $a->equity_funding_rate;

            $main[] = [
                $a->name, (int) $a->counts, (float) $a->amount, $summary['cost'],
                (int) $a->depreciation_duration > 0 ? $this->k('assets.years', ['n' => (int) $a->depreciation_duration]) : $this->k('assets.noDepreciation'),
                StudyCalendar::monthAt($dates, $a->start_date) ?? '', StudyCalendar::monthAt($dates, $a->end_date) ?? '',
                $this->pct($a->admin_depreciation_percentage), $this->pct($a->replacement_cost_rate),
                (float) $a->replacement_cost_rate > 0 ? $this->k('assets.replacementYears', ['n' => (int) ($a->replacement_cost_interval ?: 1)]) : '',
                $terms === 'customize' ? implode(' + ', $custom) : $this->k('assets.terms.'.$terms),
            ];

            $funding[] = [
                $a->name, $this->pct($equity), $this->pct(max(0, 100 - $equity)),
                $equity < 100 ? $this->pct($a->interest_rate) : '', $equity < 100 ? (int) $a->grace_period : '', $equity < 100 ? (int) ($a->tenor ?: 60) : '',
                $equity < 100 ? $this->k('assets.intervals.'.$this->loanInterval((string) $a->installment_interval)) : '',
                $summary['loan'], $summary['capitalized'], $summary['transferred'] ?? '', $summary['value'],
            ];

            if ($terms === 'installment') {
                $instalments[] = [
                    $a->name, $this->pct($a->reservation_rate), $this->pct($a->contractual_rate), (int) $a->after_months,
                    (int) $a->installment_grace_period, (int) ($a->installment_count ?: 1), $this->k('assets.intervals.'.($a->payment_installment_interval ?: 'monthly')),
                ];
            }

            if ($products->isNotEmpty()) {
                $byRevenue = (bool) $a->is_as_revenue_percentages;
                $typed = (array) $a->product_allocations;
                $line = [$a->name, $this->k($byRevenue ? 'assets.byRevenue' : 'assets.custom')];
                foreach ($products as $p) {
                    $line[] = $byRevenue ? '' : $this->pct($typed[$p->id] ?? 0);
                }
                $shares[] = $line;
            }
        }

        $blocks = [$this->table($this->k('assets.title'), [
            $this->k('assets.name'), $this->k('assets.counts'), $this->k('assets.amount'), $this->k('assets.total'), $this->k('assets.depreciation'),
            $this->k('assets.from'), $this->k('assets.to'), $this->k('assets.adminPct'), $this->k('assets.replacementRate'), $this->k('assets.replacementInterval'),
            $this->k('assets.paymentTerms'),
        ], $main)];

        if ($instalments !== []) {
            $blocks[] = $this->table($this->k('assets.terms.installment'), [
                $this->k('assets.name'), $this->k('assets.reservation'), $this->k('assets.contractual'), $this->k('assets.afterMonths'),
                $this->k('assets.installmentGrace'), $this->k('assets.installmentCount'), $this->k('assets.installmentInterval'),
            ], $instalments);
        }

        $blocks[] = $this->table($this->k('assets.funding'), [
            $this->k('assets.name'), $this->k('assets.equity'), $this->k('assets.debt'), $this->k('assets.interest'), $this->k('assets.graceMonths'),
            $this->k('assets.tenor'), $this->k('assets.loanInterval'), $this->k('assets.found.loan'), $this->k('assets.found.capitalized'),
            $this->k('assets.found.transferred'), $this->k('assets.found.value'),
        ], $funding);

        if ($shares !== []) {
            $blocks[] = $this->table($this->k('assets.allocation'), array_merge([$this->k('assets.name'), ''], $products->pluck('name')->all()), $shares);
        }

        $years = $this->studyYears($project, $timeline);
        if ($timeline && $years !== []) {
            $sum = [];
            $lastMonth = [];
            foreach ($timeline->dates as $index => $date) {
                $lastMonth[(int) substr($date, 0, 4)] = $index;
            }
            foreach ($rows as $a) {
                $dep = (array) (($a->depreciation_statement ?? [])['total_monthly_depreciation'] ?? []);
                $net = (array) (($a->depreciation_statement ?? [])['end_balance'] ?? []);
                $paid = (array) $a->ffe_payment;
                $loan = $a->loanSchedulePayments->first();
                $balance = $loan ? (array) json_decode((string) $loan->endBalance, true) : [];

                foreach ($timeline->dates as $month => $date) {
                    $y = (int) substr($date, 0, 4);
                    $sum['depreciation'][$y] = ($sum['depreciation'][$y] ?? 0) + (float) ($dep[$month] ?? 0);
                    $sum['payments'][$y] = ($sum['payments'][$y] ?? 0) + (float) ($paid[$month] ?? 0);
                    if ($month === $lastMonth[$y]) {
                        $sum['net'][$y] = ($sum['net'][$y] ?? 0) + (float) ($net[$month] ?? 0);
                        $sum['loan'][$y] = ($sum['loan'][$y] ?? 0) + (float) ($balance[$month] ?? 0);
                    }
                }
            }
            $table = [];
            foreach (['depreciation', 'payments', 'net', 'loan'] as $line) {
                $table[] = array_merge([$this->k('assets.results.'.$line)], array_map(fn ($y) => (float) ($sum[$line][$y] ?? 0), $years));
            }
            $blocks[] = $this->table($this->k('assets.results.title'), array_merge([''], array_map('strval', $years)), $table);
        }

        return $blocks;
    }

    private function loanInterval(string $interval): string
    {
        return match ($interval) {
            'quarterly', 'quartly'           => 'quarterly',
            'semi-annually', 'semi annually' => 'semi-annually',
            default                          => 'monthly',
        };
    }

    /** @return array{cost:float,loan:float,capitalized:float,transferred:?string,value:float} */
    private function assetSummary($asset, array $dates): array
    {
        $loan = (array) $asset->ffe_loan_withdrawal_end_balance;
        $capitalised = (array) (($asset->capitalization_statement ?? [])['capitalized_interest'] ?? []);
        $transferred = (array) (($asset->capitalization_statement ?? [])['transferred_date_and_vales'] ?? []);

        return [
            'cost'        => (float) $asset->counts * (float) $asset->amount,
            'loan'        => $loan === [] ? 0.0 : (float) end($loan),
            'capitalized' => (float) array_sum($capitalised),
            'transferred' => $transferred === [] ? null : StudyCalendar::monthAt($dates, (int) array_key_last($transferred)),
            'value'       => $transferred === [] ? 0.0 : (float) end($transferred),
        ];
    }

    // ── opening balances (an existing company) ──────────────────────────────

    private function opening(Project $project): array
    {
        $project->load(['fixedAssetOpeningBalances', 'cashAndBankOpeningBalances', 'otherDebtorsOpeningBalances', 'supplierPayableOpeningBalances',
            'otherCreditsOpeningBalances', 'vatAndCreditWithholdTaxOpeningBalances', 'longTermLoanOpeningBalances',
            'otherLongTermLiabilitiesOpeningBalances', 'equityOpeningBalances']);

        $totals = $this->balances->totals($project);
        $empty = collect($totals)->filter(fn ($v) => abs((float) $v) > 0)->isEmpty() && $project->fixedAssetOpeningBalances->isEmpty();
        if ($empty) {
            return [];
        }

        $blocks = [];
        $assets = $project->fixedAssetOpeningBalances->sortBy('id')->values();
        if ($assets->isNotEmpty()) {
            $blocks[] = $this->table($this->k('opening.fixed.title'), [
                $this->k('opening.fixed.name'), $this->k('opening.fixed.gross'), $this->k('opening.fixed.accumulated'), $this->k('opening.fixed.net'),
                $this->k('opening.fixed.monthly'), $this->k('opening.fixed.monthsLeft'), $this->k('opening.fixed.adminPct'),
            ], $assets->map(fn ($a) => [
                $a->name, (float) $a->gross_amount, (float) $a->accumulated_depreciation, (float) $a->gross_amount - (float) $a->accumulated_depreciation,
                (float) $a->monthly_depreciation, (int) $a->monthly_counts, $this->pct($a->admin_depreciation_percentage),
            ])->all());
        }

        $named = fn ($rows) => $rows->sortBy('id')->values()->map(fn ($r) => [$r->name ?: '—', (float) $r->amount])->all();
        $debtors = $named($project->otherDebtorsOpeningBalances);
        $creditors = $named($project->otherCreditsOpeningBalances);
        $loans = $project->longTermLoanOpeningBalances->sortBy('id')->values()->map(fn ($r, $i) => [$this->k('opening.loans.amount', [], ' '.($i + 1)), (float) $r->amount])->all();
        $longTerm = $project->otherLongTermLiabilitiesOpeningBalances->sortBy('id')->values()->map(fn ($r, $i) => [$this->k('opening.otherLong.title', [], ' '.($i + 1)), (float) $r->amount])->all();

        $head = [$this->k('opening.name'), $this->k('opening.amount')];
        $balance = OpeningBalanceCalculator::balance($totals);
        $blocks[] = $this->table($this->k('opening.sections.assets'), $head, array_merge(
            [
                [$this->k('opening.fixed.title'), (float) $totals['net_fixed_assets']],
                [$this->k('opening.cash.cash'), (float) $totals['cash']],
                [$this->k('opening.cash.receivables'), (float) $totals['receivables']],
                [$this->k('opening.cash.inventory'), (float) $totals['inventory']],
            ],
            array_map(fn ($r) => [$this->k('opening.debtors.title', [], ': '.$r[0]), $r[1]], $debtors),
            [[$this->k('opening.tiles.assets'), (float) $balance['assets']]],
        ), true);

        $blocks[] = $this->table($this->k('opening.sections.liabilities'), $head, array_merge(
            [
                [$this->k('opening.suppliers.amount'), (float) $totals['suppliers']],
            ],
            array_map(fn ($r) => [$this->k('opening.creditors.title', [], ': '.$r[0]), $r[1]], $creditors),
            [
                [$this->k('opening.taxes.vat'), (float) $totals['vat']],
                [$this->k('opening.taxes.withhold'), (float) $totals['withhold']],
                [$this->k('opening.taxes.corporate'), (float) $totals['corporate_tax']],
            ],
            $loans,
            $longTerm,
        ));

        $blocks[] = $this->table($this->k('opening.equity.title'), $head, [
            [$this->k('opening.equity.paidUp'), (float) $totals['paid_up']],
            [$this->k('opening.equity.reserve'), (float) $totals['legal_reserve']],
            [$this->k('opening.equity.retained'), (float) $totals['retained_earnings']],
            [$this->k('opening.equity.total'), (float) ($totals['paid_up'] + $totals['legal_reserve'] + $totals['retained_earnings'])],
        ], true);

        $blocks[] = $this->table(null, [$this->k('opening.tiles.assets'), $this->k('opening.tiles.liabilities'), $this->k('opening.tiles.difference')], [[
            (float) $balance['assets'], (float) $balance['liabilities_and_equity'], (float) $balance['difference'],
        ]]);

        return $blocks;
    }
}
