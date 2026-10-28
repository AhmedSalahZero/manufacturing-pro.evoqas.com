<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SharingLink;
use App\Models\SharingLinkVisitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — SharingLinkController (public read-only links of a study)
//  Location: app/Http/Controllers/SharingLinkController.php
//
//  index    the links of the study with the number of visits     GET    /projects/{project}/links
//  store    a new link (name only; the secret code is random)     POST   /projects/{project}/links
//  toggle   switch a link off / on                                 PUT    /projects/{project}/links/{link}/toggle
//  destroy  delete a link (its visits go too)                      DELETE /projects/{project}/links/{link}
//  Owner or company administrator only (ProjectPolicy::share). The page a visitor sees: PublicStudyController.
// ══════════════════════════════════════════════════════════════════

class SharingLinkController extends Controller
{
    public function index(Project $project): Response
    {
        Gate::authorize('share', $project);

        $links = SharingLink::where('project_id', $project->id)->orderByDesc('id')->get();
        $visits = SharingLinkVisitor::whereIn('link_code', $links->pluck('link_code'))->get()->groupBy('link_code');

        return Inertia::render('Sharing/Index', [
            'project' => DashboardController::projectProps($project),
            'links'   => $links->map(fn (SharingLink $link) => [
                'id'         => $link->id,
                'name'       => $link->name,
                'url'        => route('shared.show', $link->link_code),
                'closed'     => (bool) $link->closed,
                'visits'     => $visits->get($link->link_code, collect())->count(),
                'last_visit' => $visits->get($link->link_code, collect())->max('created_at')?->format('Y-m-d H:i'),
                'created'    => $link->created_at?->format('Y-m-d'),
            ])->values(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('share', $project);

        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        SharingLink::create([
            'project_id' => $project->id,
            'user_id'    => $request->user()->id,
            'name'       => $data['name'],
            'link_code'  => Str::random(40),
            'closed'     => false,
        ]);

        return back()->with('success', __('sharing.link_created'));
    }

    public function toggle(Project $project, SharingLink $link): RedirectResponse
    {
        Gate::authorize('share', $project);
        abort_unless($link->project_id === $project->id, 404);

        $link->update(['closed' => ! $link->closed]);

        return back()->with('success', $link->closed ? __('sharing.link_closed') : __('sharing.link_opened'));
    }

    public function destroy(Project $project, SharingLink $link): RedirectResponse
    {
        Gate::authorize('share', $project);
        abort_unless($link->project_id === $project->id, 404);

        SharingLinkVisitor::where('link_code', $link->link_code)->delete();
        $link->delete();

        return back()->with('success', __('sharing.link_deleted'));
    }
}
