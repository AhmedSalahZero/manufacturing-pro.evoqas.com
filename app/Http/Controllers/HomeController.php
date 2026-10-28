<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SharingLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — HomeController
//  Location: app/Http/Controllers/HomeController.php
//
//  The first screen after signing in. The super admin goes straight to the list of companies;
//  everyone else sees the welcome page: four numbers about their studies, the studies they worked on last
//  and quick actions (start a study, open the last one). A company administrator sees every study of the company,
//  anyone else the ones they own or that are shared with them (the same rule as the list of studies).
// ══════════════════════════════════════════════════════════════════

class HomeController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user()->isSuperAdmin()) {
            return redirect()->route('admin.companies.index');
        }

        $me = $request->user();
        $query = Project::query()->where('company_id', $me->company_id);

        if (! $me->isCompanyAdmin()) {
            $query->where(fn ($q) => $q->where('user_id', $me->id)->orWhereHas('shares', fn ($s) => $s->where('user_id', $me->id)));
        }

        $visibleIds = (clone $query)->pluck('id');

        $recent = (clone $query)->with('shares')->orderByDesc('updated_at')->orderByDesc('id')->limit(5)->get()
            ->map(fn (Project $p) => [
                'id'         => $p->id,
                'name'       => $p->name,
                'completed'  => (bool) $p->is_completed,
                'duration'   => $p->duration,
                'access'     => $p->accessFor($me),
                'updated_at' => $p->updated_at?->format('Y-m-d'),
            ])->values();

        return Inertia::render('Home', [
            'stats' => [
                'studies'      => $visibleIds->count(),
                'shared'       => (clone $query)->where('user_id', '!=', $me->id)->count(),
                'edited_week'  => (clone $query)->where('updated_at', '>=', now()->subDays(7))->count(),
                'open_links'   => SharingLink::whereIn('project_id', $visibleIds)->where('closed', false)->count(),
            ],
            'recent' => $recent,
        ]);
    }
}
