<?php

namespace App\Http\Controllers;

use App\Excel\ModelBuilder;
use App\Excel\ModelDataBuilder;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ExcelModelController (the "Excel Model" download of a study)
//  Location: app/Http/Controllers/ExcelModelController.php
//
//  download   GET /projects/{project}/excel-model
//  Builds a live Excel workbook of the study: one sheet per page of the application, from the inputs to the financial statements
//  and the dashboard, all linked by formulas. The person can change any yellow input cell and everything recalculates. The sheets
//  are protected without a password (only the input cells are open). Sheet text follows the language of the application
//  (English, or Arabic with right-to-left sheets). Anyone who may view the study may download it.
// ══════════════════════════════════════════════════════════════════

class ExcelModelController extends Controller
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function download(Project $project): Response|RedirectResponse
    {
        Gate::authorize('view', $project);

        if (! $project->start_date || ! $project->duration || ! $project->products()->exists()) {
            return redirect()->route('projects.index')->with('error', __('projects.excelNotReady'));
        }

        $lang = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $ctx = ModelBuilder::build(ModelDataBuilder::fromProject($project), $lang);
        $bytes = $ctx->wb->toString();

        $slug = Str::slug((string) $project->name);
        $file = 'ZAVERO-Model-'.($slug !== '' ? $slug : 'study-'.$project->id).'-'.$lang.'.xlsx';

        return response($bytes, 200, [
            'Content-Type'        => self::MIME,
            'Content-Disposition' => 'attachment; filename="'.$file.'"; filename*=UTF-8\'\''.rawurlencode($file),
            'Content-Length'      => (string) strlen($bytes),
            'Cache-Control'       => 'private, no-store',
        ]);
    }
}
