<?php

namespace App\Http\Controllers;

use App\Calculations\CollectionPolicy;
use App\Http\Requests\UpdateRawMaterialPaymentsRequest;
use App\Models\Project;
use App\Services\ProductPlanService;
use App\Services\SalesCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — RawMaterialPaymentController (how raw materials are paid for)
//  Location: app/Http/Controllers/RawMaterialPaymentController.php
//
//  edit    the payment terms of every raw material      /projects/{project}/raw-materials
//  update  save them (and recalculate the study)        PUT /projects/{project}/raw-materials
//  The materials themselves (names, VAT, stock coverage) are on the study form.
// ══════════════════════════════════════════════════════════════════

class RawMaterialPaymentController extends Controller
{
    public function __construct(private readonly SalesCalculationService $calculations)
    {
    }

    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $periods = $project->duration > 1 ? 2 : 1;
        $lastProduct = $project->products()->orderByDesc('id')->value('id');

        return Inertia::render('RawMaterials/Payments', [
            'project' => [
                'id'      => $project->id,
                'name'    => $project->name,
                'periods' => $periods,
                'comment' => $project->comment_for_raw_materials,
            ],
            'materials' => $project->rawMaterials()->orderBy('id')->get()->map(function ($m) use ($periods) {
                $stored = (array) $m->collection_policy_value;
                $rows = [];
                for ($i = 0; $i < $periods; $i++) {
                    $period = $stored[$i] ?? [];
                    $rows[] = [
                        'cash_payment' => (float) ($period['cash_payment'] ?? 100),
                        'rate'         => [(float) ($period['rate'][0] ?? 0), (float) ($period['rate'][1] ?? 0)],
                        'due_in_days'  => [(int) ($period['due_in_days'][0] ?? 30), (int) ($period['due_in_days'][1] ?? 60)],
                    ];
                }

                return [
                    'id'                => $m->id,
                    'name'              => $m->name,
                    'measurement_unit'  => $m->measurement_unit,
                    'vat_rate'          => (float) $m->vat_rate,
                    'withhold_rate'     => (float) $m->withhold_tax_rate,
                    'coverage_days'     => (int) $m->rm_inventory_coverage_days,
                    'policy'            => $rows,
                ];
            })->values(),
            'lastProduct' => $lastProduct,
            'readOnly'    => ! $request->user()->can('update', $project),
            'options'     => ['dueDays' => CollectionPolicy::DUE_DAYS],
        ]);
    }

    public function update(UpdateRawMaterialPaymentsRequest $request, Project $project): RedirectResponse
    {
        $periods = $project->duration > 1 ? 2 : 1;
        $data = $request->validated();

        foreach ($project->rawMaterials as $material) {
            $input = $data['rawMaterials'][$material->id]['collection_policy_value'] ?? null;

            if ($input === null) {
                continue;
            }

            $policy = [];
            for ($i = 0; $i < $periods; $i++) {
                $policy[$i] = ProductPlanService::mergeDueDays($input[$i] ?? []);
            }

            $material->update(['collection_policy_value' => $policy, 'collection_policy_type' => 'customize', 'collection_policy_interval' => 'monthly']);
        }

        $project->update(['comment_for_raw_materials' => $data['comment_for_raw_materials'] ?? null]);
        $this->calculations->recalculate($project);

        return match ($request->input('after')) {
            'list'  => redirect()->route('projects.index')->with('success', __('products.paymentsSaved')),
            'next'  => redirect()->route('projects.manpower.edit', $project)->with('success', __('products.paymentsSaved')),
            default => redirect()->route('projects.raw-materials.edit', $project)->with('success', __('products.paymentsSaved')),
        };
    }
}
