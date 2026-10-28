<?php

namespace App\Http\Controllers;

use App\Http\Requests\CopyProjectRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\BusinessSector;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use App\Support\StudyCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ProjectController (the studies)
//  Location: app/Http/Controllers/ProjectController.php
//
//  index    the list of my studies + "new study"        /projects
//  store    create a study from its name                POST /projects
//  edit     the study form                              /projects/{project}/edit
//  update   save the study form                         PUT /projects/{project}
//  copy     a full copy under a new name                POST /projects/{project}/copy
//  destroy  delete (kept in the database, can be restored)
//
//  Who may do what is decided by App\Policies\ProjectPolicy: the owner, the company administrator and
//  the colleagues the study is shared with (see ProjectShareController).
//  The work itself is done by App\Services\ProjectService.
// ══════════════════════════════════════════════════════════════════

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects)
    {
    }

    public function index(Request $request): Response
    {
        $me = $request->user();

        $query = Project::query()->where('company_id', $me->company_id)->with(['user:id,name', 'shares'])->withCount('products');

        // A company administrator sees every study of the company; everyone else sees their own and the ones shared with them.
        if (! $me->isCompanyAdmin()) {
            $query->where(fn ($q) => $q->where('user_id', $me->id)->orWhereHas('shares', fn ($s) => $s->where('user_id', $me->id)));
        }

        $studies = $query->latest('id')->get()->map(function (Project $p) use ($me) {
            $access = $p->accessFor($me);
            $canShare = in_array($access, ['owner', 'admin'], true);

            return [
                'id'          => $p->id,
                'name'        => $p->name,
                'completed'   => $p->is_completed,
                'new_company' => $p->new_company,
                'duration'    => $p->duration,
                'start'       => $p->start_date?->format('Y-m'),
                'end'         => $p->end_date?->format('Y-m'),
                'updated_at'  => $p->updated_at?->format('Y-m-d'),
                'owner'       => $p->user?->name,
                'access'      => $access,
                'can_share'   => $canShare,
                'can_delete'  => $canShare,
                'can_excel'   => $p->products_count > 0 && $p->start_date !== null && (int) $p->duration > 0,
                'shared_with' => $p->shares->count(),
                'shares'      => $canShare ? $p->shares->map(fn ($s) => ['user_id' => $s->user_id, 'can_edit' => $s->can_edit])->values() : [],
            ];
        });

        $colleagues = User::query()
            ->where('company_id', $me->company_id)->where('is_active', true)->whereKeyNot($me->id)
            ->orderBy('name')->get(['id', 'name', 'email', 'job_title']);

        return Inertia::render('Projects/Index', ['studies' => $studies, 'colleagues' => $colleagues]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = $this->projects->create($request->user(), $request->validated('name'));

        return redirect()->route('projects.edit', $project)->with('success', __('projects.created'));
    }

    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $dates = $project->extended_study_dates ?? [];
        $study = config('zavero.study');

        return Inertia::render('Projects/Edit', [
            'project' => [
                'id'                       => $project->id,
                'name'                     => $project->name,
                'completed'                => $project->is_completed,
                'owner'                    => $project->user?->name,
                'new_company'              => $project->new_company,
                'main_functional_currency' => $project->main_functional_currency ?: $study['default_currency'],
                'intro_paragraph'          => $project->intro_paragraph,
                'business_sector_id'       => $project->business_sector_id,
                'start_date'               => $project->start_date?->format('Y-m'),
                'duration'                 => $project->duration,
                'operation_start_date'     => StudyCalendar::monthAt($dates, $project->operation_start_date),
                'tax_rate'                 => $project->tax_rate,
                'return_rate'              => $project->return_rate,
                'perpetual_growth_rate'    => $project->perpetual_growth_rate,
            ],
            'products' => $project->products()->orderBy('id')->get()->map(fn ($p) => [
                'id'                 => $p->id,
                'name'               => $p->name,
                'measurement_unit'   => $p->measurement_unit,
                'selling_start_date' => StudyCalendar::monthAt($dates, $p->selling_start_date),
                'vat_rate'           => $p->vat_rate,
                'withhold_tax_rate'  => $p->withhold_tax_rate,
            ])->values(),
            'rawMaterials' => $project->rawMaterials()->orderBy('id')->get()->map(fn ($m) => [
                'id'                         => $m->id,
                'name'                       => $m->name,
                'rm_inventory_coverage_days' => $m->rm_inventory_coverage_days,
                'measurement_unit'           => $m->measurement_unit,
                'vat_rate'                   => $m->vat_rate,
                'withhold_tax_rate'          => $m->withhold_tax_rate,
            ])->values(),
            'readOnly' => ! $request->user()->can('update', $project),
            'sectors' => BusinessSector::orderBy('id')->get(['id', 'name_en', 'name_ar']),
            'options' => [
                'currencies'       => $study['currencies'],
                'units'            => $study['measurement_units'],
                'coverageDays'     => $study['rm_coverage_days'],
                'minYears'         => $study['min_years'],
                'maxYears'         => $study['max_years'],
            ],
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $this->projects->save($project, $request->validated());

        if ($request->input('after') === 'list') {
            return redirect()->route('projects.index')->with('success', __('projects.saved'));
        }

        return redirect()->route('projects.edit', $project)->with('success', __('projects.saved'));
    }

    public function copy(CopyProjectRequest $request, Project $project): RedirectResponse
    {
        $copy = $this->projects->copy($project, $request->validated('name'), $request->user());

        return redirect()->route('projects.index')->with('success', __('projects.copied', ['name' => $copy->name]));
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        return redirect()->route('projects.index')->with('success', __('projects.deleted', ['name' => $project->name]));
    }
}
