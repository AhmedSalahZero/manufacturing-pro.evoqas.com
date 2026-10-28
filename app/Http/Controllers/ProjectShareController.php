<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectSharesRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ProjectShareController (share a study with colleagues)
//  Location: app/Http/Controllers/ProjectShareController.php
//  PUT /projects/{project}/shares   { shares: [ { user_id, can_edit } ] }
//  The list sent is the whole truth: colleagues not in it lose their access.
//  Owner or company administrator only (see ProjectPolicy::share).
// ══════════════════════════════════════════════════════════════════

class ProjectShareController extends Controller
{
    public function update(UpdateProjectSharesRequest $request, Project $project): RedirectResponse
    {
        $rows = collect($request->validated('shares'));

        DB::transaction(function () use ($project, $rows) {
            $project->shares()->whereNotIn('user_id', $rows->pluck('user_id'))->delete();

            foreach ($rows as $row) {
                $project->shares()->updateOrCreate(['user_id' => $row['user_id']], ['can_edit' => (bool) $row['can_edit']]);
            }
        });

        return back()->with('success', __('projects.share.saved'));
    }
}
