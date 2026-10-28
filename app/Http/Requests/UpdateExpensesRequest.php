<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Services\CostCalculationService;
use App\Services\CostPlanService;
use App\Support\StudyCalendar;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — checks for the expenses form
//  Location: app/Http/Requests/UpdateExpensesRequest.php
//  Four kinds of expense (fixed monthly, one time, % of sales, cost per unit) in the categories of the study, with their
//  months (inside the study), products, payment terms (customised payments add up to 100 %) and product sharing.
// ══════════════════════════════════════════════════════════════════

class UpdateExpensesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()->can('update', $project);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'rows'                               => ['nullable', 'array', 'max:500'],
            'rows.*.id'                          => ['nullable', 'integer'],
            'rows.*.relation_name'               => ['required', Rule::in(CostCalculationService::EXPENSE_KINDS)],
            'rows.*.category_id'                 => ['required', Rule::in(CostCalculationService::categoriesFor($project))],
            'rows.*.name'                        => ['required', 'string', 'max:255'],
            'rows.*.amount'                      => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'rows.*.start'                       => ['required', 'date_format:Y-m'],
            'rows.*.end'                         => ['nullable', 'date_format:Y-m'],
            'rows.*.increase_rate'               => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'rows.*.monthly_percentage'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.monthly_cost_of_unit'        => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'rows.*.amortization_months'         => ['nullable', 'integer', 'min:1', 'max:600'],
            'rows.*.products'                    => ['nullable', 'array'],
            'rows.*.products.*'                  => ['integer'],
            'rows.*.payment_terms'               => ['required', Rule::in(\App\Calculations\ExpenseCalculator::TERMS)],
            'rows.*.custom'                      => ['nullable', 'array', 'max:'.CostPlanService::CUSTOM_SLOTS],
            'rows.*.custom.*.due_days'           => ['nullable', Rule::in(CostPlanService::EXPENSE_DUE_DAYS)],
            'rows.*.custom.*.rate'               => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.is_as_revenue_percentages'   => ['nullable', 'boolean'],
            'rows.*.allocations'                 => ['nullable', 'array'],
            'rows.*.allocations.*'               => ['nullable', 'numeric', 'min:0', 'max:100'],
            'after'                              => ['nullable', Rule::in(['stay', 'next', 'list'])],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return trans('costs.fields');
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Project $project */
            $project = $this->route('project');
            $dates = $project->extended_study_dates ?? [];
            $studyEnd = $project->end_date ? StudyCalendar::indexOf($dates, $project->end_date->format('Y-m-d')) : null;
            $own = $project->expenses()->pluck('id')->all();
            $productIds = $project->products()->pluck('id')->all();

            foreach ((array) $this->input('rows', []) as $i => $row) {
                $kind = $row['relation_name'] ?? '';

                if (! empty($row['id']) && ! in_array((int) $row['id'], $own, true)) {
                    $validator->errors()->add("rows.$i.id", __('costs.errors.unknownRow'));
                }

                $needed = ['fixed_monthly_repeating_amount' => 'amount', 'one_time_expense' => 'amount', 'expense_as_percentage' => 'monthly_percentage', 'cost_per_unit' => 'monthly_cost_of_unit'][$kind] ?? null;
                if ($needed !== null && ($row[$needed] ?? '') === '') {
                    $validator->errors()->add("rows.$i.$needed", __('validation.required', ['attribute' => trans('costs.fields')["rows.*.$needed"] ?? $needed]));
                }

                $start = ! empty($row['start']) ? StudyCalendar::indexOf($dates, $row['start']) : null;
                if (! empty($row['start']) && ($start === null || ($studyEnd !== null && $start > $studyEnd))) {
                    $validator->errors()->add("rows.$i.start", __('costs.errors.monthOutside'));
                }

                if ($kind !== 'one_time_expense') {
                    $end = ! empty($row['end']) ? StudyCalendar::indexOf($dates, $row['end']) : null;
                    if (empty($row['end'])) {
                        $validator->errors()->add("rows.$i.end", __('validation.required', ['attribute' => (trans('costs.fields')['rows.*.end'] ?? 'end')]));
                    } elseif ($end === null || ($studyEnd !== null && $end > $studyEnd)) {
                        $validator->errors()->add("rows.$i.end", __('costs.errors.monthOutside'));
                    } elseif ($start !== null && $end < $start) {
                        $validator->errors()->add("rows.$i.end", __('costs.errors.endBeforeStart'));
                    }
                }

                if (in_array($kind, ['expense_as_percentage', 'cost_per_unit'], true)) {
                    $chosen = array_map('intval', (array) ($row['products'] ?? []));
                    if ($chosen === [] || array_diff($chosen, $productIds) !== []) {
                        $validator->errors()->add("rows.$i.products", __('costs.errors.products'));
                    }
                }

                if (($row['payment_terms'] ?? '') === 'customize') {
                    $total = 0.0;
                    foreach ((array) ($row['custom'] ?? []) as $line) {
                        $total += (float) ($line['rate'] ?? 0);
                    }
                    if (abs($total - 100) > 0.01) {
                        $validator->errors()->add("rows.$i.custom", __('costs.errors.paymentTotal'));
                    }
                }

                $allocated = CostCalculationService::EXPENSE_CATEGORIES[$row['category_id'] ?? ''] ?? false;
                $isShared = in_array($kind, ['fixed_monthly_repeating_amount', 'one_time_expense'], true);
                if ($allocated && $isShared && $productIds !== [] && ! (bool) ($row['is_as_revenue_percentages'] ?? true)) {
                    $total = 0.0;
                    foreach ($productIds as $id) {
                        $total += (float) ($row['allocations'][$id] ?? 0);
                    }
                    if (abs($total - 100) > 0.01) {
                        $validator->errors()->add("rows.$i.allocations", __('costs.errors.allocationTotal'));
                    }
                }
            }
        }];
    }
}
