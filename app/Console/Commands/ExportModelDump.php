<?php

namespace App\Console\Commands;

use App\Excel\ModelDataBuilder;
use App\Models\BalanceSheet;
use App\Models\CashInOutStatement;
use App\Models\IncomeStatement;
use App\Models\Project;
use App\Services\DashboardService;
use App\Services\SalesCalculationService;
use App\Services\StatementsService;
use Illuminate\Console\Command;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — zavero:model-dump (everything a study holds, as one JSON file)
//  Location: app/Console/Commands/ExportModelDump.php
//
//  php artisan zavero:model-dump "Nile Valley Foods - Expansion (Demo)"      (a study's name or its number)
//  Writes  storage/app/model-dump-<number>.json  with what was typed (the inputs) and what the application worked out
//  from it (the saved monthly figures, the statements and the dashboard). It is the reference the Excel model is checked
//  against: the workbook is recalculated outside the application and every figure must match these within 0.01.
//  Nothing is changed in the study.
// ══════════════════════════════════════════════════════════════════

class ExportModelDump extends Command
{
    protected $signature = 'zavero:model-dump {study : the name or the number of the study}';

    protected $description = 'Writes everything a study holds (inputs and calculated figures) to one JSON file';

    public function handle(SalesCalculationService $calculations, StatementsService $statements, DashboardService $dashboards): int
    {
        $key = (string) $this->argument('study');
        $project = ctype_digit($key) ? Project::find((int) $key) : Project::where('name', $key)->first();

        if (! $project) {
            $this->error('No study found for "'.$key.'".');

            return self::FAILURE;
        }

        $id = $project->id;
        $all = fn (string $model) => $model::where('project_id', $id)->orderBy('id')->get()->toArray();

        $dump = ModelDataBuilder::fromProject($project) + [
            'saved_statements'         => [
                'income'  => $all(IncomeStatement::class),
                'cash'    => $all(CashInOutStatement::class),
                'balance' => $all(BalanceSheet::class),
            ],
        ];

        $timeline = $calculations->timeline($project);

        if ($timeline) {
            $built = $dashboards->build($project, $timeline);
            $dump['built'] = $built['built'] ?? null;
            $dump['statements_present'] = $statements->present($built['built'], $timeline);
            $dump['dashboard_present'] = $dashboards->present($built, $project, $timeline);
            $dump['timeline'] = ['dates' => $timeline->dates, 'operation_months' => $timeline->operationMonths];
        }

        $path = storage_path('app/model-dump-'.$id.'.json');
        file_put_contents($path, json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        $this->info('Written: '.$path.' ('.number_format(filesize($path) / 1024, 0).' KB)');

        return self::SUCCESS;
    }
}
