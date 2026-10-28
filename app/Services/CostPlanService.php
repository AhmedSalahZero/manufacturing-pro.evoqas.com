<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Manpower;
use App\Models\Project;
use App\Support\StudyCalendar;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — CostPlanService (the staff and expenses forms)
//  Location: app/Services/CostPlanService.php
//
//  Saves what is typed on the staff form and the expenses form, then recalculates the whole study
//  (SalesCalculationService, which ends with CostCalculationService). Rows sent with an id are updated, rows without an id
//  are created and rows that are no longer sent are deleted. Months are typed as "YYYY-MM" and saved as a position in
//  the study (0 = the study's first month), so moving the study start moves everything with it.
// ══════════════════════════════════════════════════════════════════

class CostPlanService
{
    /** Days after which a part of an expense can be paid (0 = the same month). */
    public const EXPENSE_DUE_DAYS = [0, 15, 30, 45, 60, 90, 120, 150, 180, 210, 240, 270, 300, 330, 360];

    public const CUSTOM_SLOTS = 5;

    public function __construct(private readonly SalesCalculationService $calculations)
    {
    }

    /** @param array<string,mixed> $data already validated (see UpdateManpowerRequest) */
    public function saveManpower(Project $project, array $data): void
    {
        DB::transaction(function () use ($project, $data) {
            $productIds = $project->products()->pluck('id')->all();
            $allocations = [];
            $byRevenue = [];

            foreach (CostCalculationService::MANPOWER_TYPES as $type => $info) {
                if ($info['column'] === null) {
                    continue;
                }
                $byRevenue[$type] = (bool) ($data['by_revenue'][$type] ?? true);
                foreach ($productIds as $id) {
                    if (isset($data['allocations'][$type][$id])) {
                        $allocations[$type][$id] = (float) $data['allocations'][$type][$id];
                    }
                }
            }

            $project->update([
                'salaries_annual_increase'           => $data['salaries_annual_increase'] ?? 0,
                'salaries_tax_rate'                  => $data['salaries_tax_rate'] ?? 0,
                'social_insurance_rate'              => $data['social_insurance_rate'] ?? 0,
                'manpower_allocations'               => $allocations,
                'manpower_is_as_revenue_percentages' => $byRevenue,
            ]);

            $kept = [];
            foreach ($data['rows'] ?? [] as $row) {
                $values = [
                    'type'           => $row['type'],
                    'position'       => $row['position'],
                    'avg_salary'     => (string) ($row['avg_salary'] ?? 0),
                    'existing_count' => (string) ($project->new_company ? 0 : ($row['existing_count'] ?? 0)),
                    'hirings'        => $this->hirings($row['hirings'] ?? []),
                ];

                $model = ! empty($row['id']) ? Manpower::where('project_id', $project->id)->find($row['id']) : null;
                if ($model) {
                    $model->update($values);
                } else {
                    $model = $project->manpowers()->create($values);
                }
                $kept[] = $model->id;
            }

            Manpower::where('project_id', $project->id)->whereNotIn('id', $kept)->delete();
        });

        $this->calculations->recalculate($project->refresh());
    }

    /** @param array<string,mixed> $data already validated (see UpdateExpensesRequest) */
    public function saveExpenses(Project $project, array $data): void
    {
        DB::transaction(function () use ($project, $data) {
            $dates = $project->extended_study_dates ?? [];
            $productIds = $project->products()->pluck('id')->all();
            $kept = [];

            foreach ($data['rows'] ?? [] as $row) {
                $kind = $row['relation_name'];
                $start = StudyCalendar::indexOf($dates, $row['start']) ?? 0;
                $oneTime = $kind === 'one_time_expense';
                $end = $oneTime ? $start : (StudyCalendar::indexOf($dates, $row['end']) ?? $start);
                $selected = array_values(array_intersect($productIds, array_map('intval', $row['products'] ?? [])));
                $byRevenue = (bool) ($row['is_as_revenue_percentages'] ?? true);
                $custom = ($row['payment_terms'] ?? 'cash') === 'customize' ? $this->customPolicy($row['custom'] ?? []) : [];

                $values = [
                    'relation_name'             => $kind,
                    'expense_type'              => $kind,
                    'category_id'               => $row['category_id'],
                    'name'                      => $row['name'],
                    'start_date'                => $start,
                    'end_date'                  => $end,
                    'amount'                    => in_array($kind, ['fixed_monthly_repeating_amount', 'one_time_expense'], true) ? ($row['amount'] ?? 0) : 0,
                    'increase_rate'             => $kind === 'fixed_monthly_repeating_amount' ? (string) ($row['increase_rate'] ?? 0) : '0',
                    'increase_interval'         => 'annually',
                    'monthly_percentage'        => $kind === 'expense_as_percentage' ? (string) ($row['monthly_percentage'] ?? 0) : '0',
                    'monthly_cost_of_unit'      => $kind === 'cost_per_unit' ? (string) ($row['monthly_cost_of_unit'] ?? 0) : '0',
                    'amortization_months'       => $oneTime ? (int) ($row['amortization_months'] ?? 12) : 12,
                    'products'                  => in_array($kind, ['expense_as_percentage', 'cost_per_unit'], true) ? $selected : $productIds,
                    'payment_terms'             => $row['payment_terms'] ?? 'cash',
                    'custom_collection_policy'  => $custom,
                    'is_as_revenue_percentages' => $byRevenue ? 1 : 0,
                    'product_allocations'       => $byRevenue ? [] : $this->percentages($row['allocations'] ?? [], $productIds),
                ];

                $model = ! empty($row['id']) ? Expense::where('project_id', $project->id)->find($row['id']) : null;

                if ($model) {
                    $model->update($values);
                } else {
                    $model = $project->expenses()->create($values + ['collection_statements' => []]);
                }
                $kept[] = $model->id;
            }

            Expense::where('project_id', $project->id)->whereNotIn('id', $kept)->delete();
        });

        $this->calculations->recalculate($project->refresh());
    }

    /** "due days => %" with the lines of the same due days added together, as the old application did. */
    public function customPolicy(array $lines): array
    {
        $policy = [];

        foreach ($lines as $line) {
            if (($line['rate'] ?? '') === '' || ! isset($line['due_days']) || $line['due_days'] === '') {
                continue;
            }
            $days = (int) $line['due_days'];
            $policy[$days] = ($policy[$days] ?? 0) + (float) $line['rate'];
        }

        ksort($policy);

        return $policy;
    }

    /** @return array<int,float> month index => people */
    private function hirings(array $hirings): array
    {
        $result = [];

        foreach ($hirings as $month => $count) {
            if ($count !== null && $count !== '' && (float) $count != 0.0) {
                $result[(int) $month] = (float) $count;
            }
        }

        return $result;
    }

    /** @return array<int,float> */
    private function percentages(array $typed, array $productIds): array
    {
        $result = [];
        foreach ($productIds as $id) {
            if (isset($typed[$id])) {
                $result[$id] = (float) $typed[$id];
            }
        }

        return $result;
    }
}
