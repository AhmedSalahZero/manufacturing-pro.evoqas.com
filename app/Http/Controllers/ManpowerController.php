<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateManpowerRequest;
use App\Models\Project;
use App\Services\CostCalculationService;
use App\Services\CostPlanService;
use App\Services\SalesCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ManpowerController (staff of a study)
//  Location: app/Http/Controllers/ManpowerController.php
//
//  edit    the staff form      /projects/{project}/manpower
//  update  save it (and recalculate the study)   PUT /projects/{project}/manpower
//  The saving is done by App\Services\CostPlanService, the numbers by App\Services\CostCalculationService.
// ══════════════════════════════════════════════════════════════════

class ManpowerController extends Controller
{
    public function __construct(
        private readonly CostPlanService $plans,
        private readonly SalesCalculationService $calculations,
        private readonly CostCalculationService $costs,
    ) {
    }

    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->load('products');
        $timeline = $this->calculations->timeline($project);
        $operation = $timeline ? $timeline->operationMonths : [];
        $types = array_keys(CostCalculationService::MANPOWER_TYPES);

        $months = [];
        foreach ($operation as $index => $date) {
            $months[] = ['index' => $index, 'year' => (int) substr($date, 0, 4), 'month' => (int) substr($date, 5, 2)];
        }

        $rows = $project->manpowers()->orderBy('id')->get();

        $allocations = [];
        $byRevenue = [];
        foreach (CostCalculationService::MANPOWER_TYPES as $type => $info) {
            if ($info['column'] === null) {
                continue;
            }
            $allocations[$type] = $this->costs->manpowerPercentages($project, $type);
            $byRevenue[$type] = (bool) (($project->manpower_is_as_revenue_percentages ?? [])[$type] ?? true);
        }

        return Inertia::render('Manpower/Edit', [
            'project' => [
                'id'          => $project->id,
                'name'        => $project->name,
                'newCompany'  => (bool) $project->new_company,
                'endYear'     => (int) ($project->end_date ?? $project->start_date)->format('Y'),
                'increase'    => (float) $project->salaries_annual_increase,
                'taxRate'     => (float) $project->salaries_tax_rate,
                'insurance'   => (float) $project->social_insurance_rate,
            ],
            'types'       => $types,
            'allocated'   => array_keys($allocations),
            'months'      => $months,
            'rows'        => $rows->map(fn ($r) => [
                'id'             => $r->id,
                'type'           => $r->type,
                'position'       => $r->position,
                'avg_salary'     => (float) $r->avg_salary,
                'existing_count' => (float) $r->existing_count,
                'hirings'        => (object) collect((array) $r->hirings)->map(fn ($v) => (float) $v)->all(),
            ])->values(),
            'products'    => $project->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'allocations' => (object) $allocations,
            'byRevenue'   => (object) $byRevenue,
            'results'     => $this->results($rows, $timeline),
            'readOnly'    => ! $request->user()->can('update', $project),
        ]);
    }

    public function update(UpdateManpowerRequest $request, Project $project): RedirectResponse
    {
        $this->plans->saveManpower($project, $request->validated());

        return match ($request->input('after')) {
            'list'  => redirect()->route('projects.index')->with('success', __('costs.manpowerSaved')),
            'next'  => redirect()->route('projects.expenses.edit', $project)->with('success', __('costs.manpowerSaved')),
            default => redirect()->route('projects.manpower.edit', $project)->with('success', __('costs.manpowerSaved')),
        };
    }

    /**
     * Per position and calendar year: headcount at the end of the year, salaries paid, employer cost.
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
            $counts = (array) $row->accumulated_manpower_counts;
            $payments = (array) $row->salary_payments;
            $expenses = (array) $row->salary_expenses;

            foreach (array_keys($timeline->operationMonths) as $month) {
                $year = (int) substr($timeline->dates[$month], 0, 4);
                $years[$year] = true;
                $result[$row->id][$year]['headcount'] = (float) ($counts[$month] ?? 0);
                $result[$row->id][$year]['payments'] = ($result[$row->id][$year]['payments'] ?? 0) + (float) ($payments[$month] ?? 0);
                $result[$row->id][$year]['expense'] = ($result[$row->id][$year]['expense'] ?? 0) + (float) ($expenses[$month] ?? 0);
            }
        }

        ksort($years);

        return ['years' => array_keys($years), 'rows' => (object) $result];
    }
}
