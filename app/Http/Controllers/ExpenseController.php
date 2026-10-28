<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateExpensesRequest;
use App\Models\Project;
use App\Services\CostCalculationService;
use App\Services\CostPlanService;
use App\Services\SalesCalculationService;
use App\Support\StudyCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ExpenseController (expense lines of a study)
//  Location: app/Http/Controllers/ExpenseController.php
//
//  edit    the expenses form   /projects/{project}/expenses
//  update  save it (and recalculate the study)   PUT /projects/{project}/expenses
//  The saving is done by App\Services\CostPlanService, the numbers by App\Services\CostCalculationService.
// ══════════════════════════════════════════════════════════════════

class ExpenseController extends Controller
{
    public function __construct(
        private readonly CostPlanService $plans,
        private readonly SalesCalculationService $calculations,
    ) {
    }

    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->load('products');
        $dates = $project->extended_study_dates ?? [];
        $slots = CostPlanService::CUSTOM_SLOTS;   // the most payment lines one expense can have

        $rows = $project->expenses()->orderBy('id')->get();

        return Inertia::render('Expenses/Edit', [
            'project' => [
                'id'         => $project->id,
                'name'       => $project->name,
                'newCompany' => (bool) $project->new_company,
                'startYear'  => (int) $project->start_date->format('Y'),
                'endYear'    => (int) ($project->end_date ?? $project->start_date)->format('Y'),
            ],
            'categories' => collect(CostCalculationService::categoriesFor($project))
                ->map(fn ($c) => ['id' => $c, 'allocated' => CostCalculationService::EXPENSE_CATEGORIES[$c]])->values(),
            'kinds'      => CostCalculationService::EXPENSE_KINDS,
            'products'   => $project->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'rows'       => $rows->map(function ($e) use ($dates, $slots, $project) {
                $custom = [];
                foreach ((array) $e->custom_collection_policy as $days => $rate) {
                    $custom[] = ['due_days' => (int) $days, 'rate' => (float) $rate];
                }
                if ($custom === []) {
                    $custom[] = ['due_days' => '', 'rate' => ''];   // the pop-up shows lines one by one; start with one empty line
                }

                $typed = (array) $e->product_allocations;
                $allocations = [];
                foreach ($project->products as $p) {
                    $allocations[$p->id] = (float) ($typed[$p->id] ?? 0);
                }

                return [
                    'id'                        => $e->id,
                    'relation_name'             => $e->relation_name,
                    'category_id'               => $e->category_id,
                    'name'                      => $e->name,
                    'amount'                    => (float) $e->amount,
                    'start'                     => StudyCalendar::monthAt($dates, $e->start_date) ?? '',
                    'end'                       => StudyCalendar::monthAt($dates, $e->end_date) ?? '',
                    'increase_rate'             => (float) $e->increase_rate,
                    'monthly_percentage'        => (float) $e->monthly_percentage,
                    'monthly_cost_of_unit'      => (float) $e->monthly_cost_of_unit,
                    'amortization_months'       => (int) ($e->amortization_months ?: 12),
                    'products'                  => array_map('intval', (array) $e->products),
                    'payment_terms'             => $e->payment_terms ?: 'cash',
                    'custom'                    => $custom,
                    'is_as_revenue_percentages' => (bool) $e->is_as_revenue_percentages,
                    'allocations'               => (object) $allocations,
                ];
            })->values(),
            'results'    => $this->results($rows, $this->calculations->timeline($project)),
            'options'    => ['dueDays' => CostPlanService::EXPENSE_DUE_DAYS, 'slots' => $slots],
            'readOnly'   => ! $request->user()->can('update', $project),
        ]);
    }

    public function update(UpdateExpensesRequest $request, Project $project): RedirectResponse
    {
        $this->plans->saveExpenses($project, $request->validated());

        return match ($request->input('after')) {
            'next'  => redirect()->route('projects.assets.edit', $project)->with('success', __('costs.expensesSaved')),
            'list'  => redirect()->route('projects.index')->with('success', __('costs.expensesSaved')),
            default => redirect()->route('projects.expenses.edit', $project)->with('success', __('costs.expensesSaved')),
        };
    }

    /**
     * Per expense and calendar year: the expense (before VAT) and what is paid.
     *
     * @return array<string,mixed>
     */
    private function results($rows, $timeline): array
    {
        if (! $timeline) {
            return ['years' => [], 'rows' => (object) []];
        }

        $years = [];
        $result = [];

        foreach ($rows as $row) {
            $expense = (array) (($row->collection_statements['monthly']['expense'] ?? []));
            $payments = (array) $row->payment_amounts;

            foreach ($expense as $month => $value) {
                if (isset($timeline->dates[$month])) {
                    $year = (int) substr($timeline->dates[$month], 0, 4);
                    $years[$year] = true;
                    $result[$row->id][$year]['expense'] = ($result[$row->id][$year]['expense'] ?? 0) + (float) $value;
                }
            }
            foreach ($payments as $month => $value) {
                if (isset($timeline->dates[$month])) {
                    $year = (int) substr($timeline->dates[$month], 0, 4);
                    $years[$year] = true;
                    $result[$row->id][$year]['payments'] = ($result[$row->id][$year]['payments'] ?? 0) + (float) $value;
                }
            }
        }

        ksort($years);

        return ['years' => array_keys($years), 'rows' => (object) $result];
    }
}
