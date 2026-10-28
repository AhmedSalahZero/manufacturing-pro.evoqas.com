<?php

namespace App\Http\Controllers;

use App\Models\SharingLink;
use App\Models\SharingLinkVisitor;
use App\Services\DashboardService;
use App\Services\SalesCalculationService;
use App\Services\StatementsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — PublicStudyController (what a visitor of a sharing link sees)
//  Location: app/Http/Controllers/PublicStudyController.php
//
//  show   GET /shared/{code}   no sign-in. The dashboard and the three financial statements of the study, read-only.
//         An unknown code is a 404; a link that was switched off shows a "closed" page (410). Each visit is logged
//         (the address of the visitor, once an hour per address) so the owner can see who looked.
//  Nothing else of the study (inputs, people, other studies, the company's users) is ever sent to the visitor.
// ══════════════════════════════════════════════════════════════════

class PublicStudyController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboards,
        private readonly StatementsService $statements,
        private readonly SalesCalculationService $calculations,
    ) {
    }

    public function show(Request $request, string $code): Response
    {
        $link = SharingLink::with('project')->where('link_code', $code)->first();   // lazy loading is off: ask for the study together with the link
        abort_if($link === null || $link->project === null, 404);

        if ($link->closed) {
            return Inertia::render('Shared/Closed')->toResponse($request)->setStatusCode(410);
        }

        $this->logVisit($request, $code);

        $project = $link->project;
        $timeline = $this->calculations->timeline($project);
        $props = [
            'project'  => ['name' => $project->name, 'currency' => $project->main_functional_currency, 'intro' => $project->intro_paragraph],
            'link'     => ['name' => $link->name],
            'ready'    => false,
            'comments' => ['one' => $project->dashboard_comment_1, 'two' => $project->dashboard_comment_2],
        ];

        if ($timeline !== null && $project->products()->exists()) {
            $built = $this->dashboards->build($project, $timeline);
            $props['ready'] = true;
            $props['dashboard'] = $this->dashboards->present($built, $project, $timeline);
            $props['statements'] = $this->statements->present($built['built'], $timeline);
        }

        return Inertia::render('Shared/Show', $props)->toResponse($request);
    }

    private function logVisit(Request $request, string $code): void
    {
        $ip = (string) $request->ip();

        $recent = SharingLinkVisitor::where('link_code', $code)->where('ip', $ip)->where('created_at', '>=', now()->subHour())->exists();
        if (! $recent) {
            SharingLinkVisitor::create(['link_code' => $code, 'ip' => $ip]);
        }
    }
}
