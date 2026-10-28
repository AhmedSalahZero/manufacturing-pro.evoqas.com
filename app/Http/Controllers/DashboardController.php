<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DashboardService;
use App\Services\SalesCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — DashboardController (the Dashboard of a study)
//  Location: app/Http/Controllers/DashboardController.php
//
//  show      charts, KPIs (IRR, NPV, payback), required investment, DCF, liquidity and activity ratios   GET /projects/{project}/dashboard
//  comments  the two notes written under the charts                                                       PUT /projects/{project}/dashboard/comments
//  The numbers are worked out by App\Services\DashboardService from what the other screens saved (read-only).
// ══════════════════════════════════════════════════════════════════

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboards,
        private readonly SalesCalculationService $calculations,
    ) {
    }

    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);

        $timeline = $this->calculations->timeline($project);
        $props = [
            'project'  => $this->projectProps($project),
            'ready'    => false,
            'canEdit'  => $this->canEdit($project),
            'comments' => ['one' => $project->dashboard_comment_1, 'two' => $project->dashboard_comment_2],
        ];

        if ($timeline !== null && $project->products()->exists()) {
            $props['ready'] = true;
            $props['dashboard'] = $this->dashboards->present($this->dashboards->build($project, $timeline), $project, $timeline);
        }

        return Inertia::render('Dashboard/Show', $props);
    }

    public function comments(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'one' => ['nullable', 'string', 'max:5000'],
            'two' => ['nullable', 'string', 'max:5000'],
        ]);

        $project->update(['dashboard_comment_1' => $data['one'] ?? null, 'dashboard_comment_2' => $data['two'] ?? null]);

        return back()->with('success', __('sharing.comments_saved'));
    }

    /** @return array<string,mixed> */
    public static function projectProps(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'currency' => $project->main_functional_currency];
    }

    private function canEdit(Project $project): bool
    {
        return Gate::allows('update', $project);
    }
}
