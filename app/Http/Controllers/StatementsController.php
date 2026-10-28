<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\SalesCalculationService;
use App\Services\StatementsService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — StatementsController (financial statements of a study)
//  Location: app/Http/Controllers/StatementsController.php
//
//  show   income statement, cash flow and balance sheet   /projects/{project}/statements   (read-only: everything is calculated)
//  The numbers are built by App\Services\StatementsService from what the other screens saved.
// ══════════════════════════════════════════════════════════════════

class StatementsController extends Controller
{
    public function __construct(
        private readonly StatementsService $statements,
        private readonly SalesCalculationService $calculations,
    ) {
    }

    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);

        $timeline = $this->calculations->timeline($project);
        $props = [
            'project' => ['id' => $project->id, 'name' => $project->name, 'newCompany' => (bool) $project->new_company],
            'ready'   => false,
        ];

        if ($timeline !== null && $project->products()->exists()) {
            $props['ready'] = true;
            $props['statements'] = $this->statements->present($this->statements->build($project, $timeline), $timeline);
            $props['taxRate'] = (float) $project->tax_rate;
        }

        return Inertia::render('Statements/Show', $props);
    }
}
