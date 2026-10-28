<?php

namespace App\Excel;

use App\Excel\Sheets\CostingSheet;
use App\Excel\Sheets\CoverSheet;
use App\Excel\Sheets\DashboardSheet;
use App\Excel\Sheets\ExpenseSheet;
use App\Excel\Sheets\FixedAssetSheet;
use App\Excel\Sheets\ManpowerSheet;
use App\Excel\Sheets\OpeningSheet;
use App\Excel\Sheets\RawMaterialSheet;
use App\Excel\Sheets\ShareSheet;
use App\Excel\Sheets\StatementsSheet;
use App\Excel\Sheets\SalesSheet;
use App\Excel\Sheets\StudySheet;

// Builds the whole workbook of a study from its saved rows (see ModelData).
final class ModelBuilder
{
    /** Printed on the Cover, so it is clear which version of the generator made a file. */
    public const BUILD = '3 (dates by study_dates)';

    public static function build(array $dump, string $lang = 'en'): Ctx
    {
        $x = new Ctx(new ModelData($dump), new Labels($lang));

        StudySheet::study($x);
        StudySheet::calendar($x);
        foreach ($x->m->products as $i => $_) {
            SalesSheet::build($x, $i);
        }

        RawMaterialSheet::build($x);
        ShareSheet::build($x);
        ManpowerSheet::build($x);
        ExpenseSheet::build($x);
        FixedAssetSheet::build($x);
        OpeningSheet::build($x);
        CostingSheet::build($x);
        StatementsSheet::build($x);
        DashboardSheet::build($x);
        CoverSheet::build($x);

        // the tabs: order and colour (yellow = inputs, grey = working, blue = statements, green = results)
        $x->wb->order(array_map(fn ($k) => $x->sheets[$k], CoverSheet::order($x)));
        $inputs = ['study', 'rm', 'mp', 'exp', 'fa', 'ob'];
        foreach ($x->sheets as $key => $sheet) {
            if (in_array($key, $inputs, true) || preg_match('/^s\d+$/', $key)) {
                $sheet->tab('FFE3C968');
            } elseif (in_array($key, ['is', 'tax', 'cf', 'bs'], true)) {
                $sheet->tab('FF2158D6');
            } elseif ($key === 'dash') {
                $sheet->tab('FF2E9E5B');
            } elseif ($key !== 'cover') {
                $sheet->tab('FF9AA5B8');
            }
        }

        return $x;
    }
}
