<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\SalesCalculationService;
use App\Services\SensitivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — SensitivityController ("what if …?" side by side with the study)
//  Location: app/Http/Controllers/SensitivityController.php
//
//  show    the three settings per product and, when any is set, the dashboard of the study next to the dashboard with them
//          GET /projects/{project}/sensitivity
//  update  saves the settings (they never change the study itself)   PUT /projects/{project}/sensitivity
//  How the scenario is worked out: App\Services\SensitivityService (the whole calculation again, rolled back).
// ══════════════════════════════════════════════════════════════════

class SensitivityController extends Controller
{
    public function __construct(
        private readonly SensitivityService $sensitivity,
        private readonly SalesCalculationService $calculations,
    ) {
    }

    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);

        $rows = $this->sensitivity->rows($project);
        $props = [
            'project'  => DashboardController::projectProps($project),
            'ready'    => false,
            'canEdit'  => Gate::allows('update', $project),
            'rows'     => $rows,
            'compared' => false,
        ];

        if ($this->calculations->timeline($project) !== null && $rows !== []) {
            $props['ready'] = true;

            if ($this->sensitivity->hasChanges($rows)) {
                $props['compared'] = true;
                $props += $this->sensitivity->compare($project, $rows);
            }
        }

        return Inertia::render('Sensitivity/Show', $props);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $slots = collect($this->sensitivity->rows($project));

        $data = $request->validate([
            'rows'          => ['required', 'array', 'max:'.count(SensitivityService::SLOTS)],
            'rows.*.id'     => ['required', 'integer', Rule::in($slots->pluck('id')->all())],
            'rows.*.target' => ['required', 'numeric', 'between:-100,1000'],
            'rows.*.rm'     => ['required', 'numeric', 'between:-100,1000'],
            'rows.*.days'   => ['required', 'integer', 'between:-365,365'],
        ]);

        $rows = collect($data['rows'])->map(fn ($row) => [
            'slot'   => $slots->firstWhere('id', (int) $row['id'])['slot'],
            'target' => round((float) $row['target'], 2),
            'rm'     => round((float) $row['rm'], 2),
            'days'   => (int) $row['days'],
        ])->all();

        $this->sensitivity->save($project, $request->user(), $rows);

        return redirect()->route('projects.sensitivity', $project)->with('success', __('sharing.sensitivity_saved'));
    }
}
