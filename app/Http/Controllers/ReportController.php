<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DashboardService;
use App\Services\ReportInputsService;
use App\Services\SalesCalculationService;
use App\Services\StatementsService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ReportController (the printable report of a study)
//  Location: app/Http/Controllers/ReportController.php
//
//  show   GET /projects/{project}/report
//  One clean page with the whole study: the summary, every input page (products, raw materials, manpower plan, expenses,
//  fixed assets and, for an existing company, opening balances), the financial statements, then the results (KPIs and charts). The "Print / Save as PDF" button
//  opens the browser's print window (English or Arabic, right-to-left included): the page has its own print layout.
// ══════════════════════════════════════════════════════════════════

class ReportController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboards,
        private readonly StatementsService $statements,
        private readonly SalesCalculationService $calculations,
        private readonly ReportInputsService $inputs,
    ) {
    }

    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);

        $timeline = $this->calculations->timeline($project);
        $props = [
            'project'  => DashboardController::projectProps($project) + [
                'intro'    => $project->intro_paragraph,
                'start'    => $project->start_date?->format('Y-m'),
                'duration' => $project->duration,
                'tax'      => (float) $project->tax_rate,
                'return'   => (float) $project->return_rate,
                'growth'   => (float) $project->perpetual_growth_rate,
            ],
            'ready'    => false,
            'comments' => ['one' => $project->dashboard_comment_1, 'two' => $project->dashboard_comment_2],
            'date'     => now()->format('Y-m-d'),
        ];

        if ($timeline !== null && $project->products()->exists()) {
            $built = $this->dashboards->build($project, $timeline);
            $props['ready'] = true;
            $props['dashboard'] = $this->dashboards->present($built, $project, $timeline);
            $props['statements'] = $this->statements->present($built['built'], $timeline);
            $props['inputs'] = $this->inputs->build($project, $timeline);
        }

        return Inertia::render('Report/Show', $props);
    }
}
