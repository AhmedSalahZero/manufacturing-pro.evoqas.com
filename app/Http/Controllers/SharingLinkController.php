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
//  ZAVERO Manufacturing Pro — SharingLinkController (the public links of a study: view links and fill links)
//  Location: app/Http/Controllers/SharingLinkController.php
//
//  index    the links of the study with the number of visits     GET    /projects/{project}/links
//  store    a new link (type view or fill, name, optional expiry date; the secret code is random)   POST   /projects/{project}/links
//  toggle   pause a link / resume it                               PUT    /projects/{project}/links/{link}/toggle
//  expiry   set or clear the last day the link works               PUT    /projects/{project}/links/{link}/expiry
//  destroy  delete a link (its visits go too)                      DELETE /projects/{project}/links/{link}
//  Owner or company administrator only (ProjectPolicy::share). What a visitor sees: PublicStudyController and routes/web.php.
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
            'links'   => $links->map(fn (SharingLink $link) => self::present($link, $visits->get($link->link_code, collect())))->values(),
        ]);
    }

    /**
     * One link for the screens (also used by the list of studies).
     *
     * @param  \Illuminate\Support\Collection<int,SharingLinkVisitor>  $visits
     * @return array<string,mixed>
     */
    public static function present(SharingLink $link, $visits): array
    {
        return [
            'id'         => $link->id,
            'name'       => $link->name,
            'type'       => $link->type,
            'url'        => route($link->isFill() ? 'fill.show' : 'shared.show', $link->link_code),
            'closed'     => (bool) $link->closed,
            'status'     => $link->status(),
            'expires_at' => $link->expires_at?->format('Y-m-d'),
            'visits'     => $visits->count(),
            'last_visit' => $visits->max('created_at')?->format('Y-m-d H:i'),
            'created'    => $link->created_at?->format('Y-m-d'),
        ];
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('share', $project);

        $data = $request->validate([
            'type'       => ['nullable', 'in:'.SharingLink::VIEW.','.SharingLink::FILL],
            'name'       => [($request->input('type') === SharingLink::FILL ? 'nullable' : 'required'), 'string', 'max:120'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        $type = $data['type'] ?? SharingLink::VIEW;

        SharingLink::create([
            'project_id' => $project->id,
            'user_id'    => $request->user()->id,
            'name'       => $data['name'] ?? __('sharing.template_name'),
            'link_code'  => Str::random(40),
            'type'       => $type,
            'closed'     => false,
            'expires_at' => $data['expires_at'] ?? null,
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

    public function expiry(Request $request, Project $project, SharingLink $link): RedirectResponse
    {
        Gate::authorize('share', $project);
        abort_unless($link->project_id === $project->id, 404);

        $data = $request->validate(['expires_at' => ['nullable', 'date_format:Y-m-d']]);
        $link->update(['expires_at' => $data['expires_at'] ?? null]);

        return back()->with('success', __('sharing.link_expiry_saved'));
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
