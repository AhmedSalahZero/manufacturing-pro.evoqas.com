<?php

namespace App\Excel;

use App\Models\CashAndBankOpeningBalance;
use App\Models\EquityOpeningBalance;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetOpeningBalance;
use App\Models\LoanSchedulePayment;
use App\Models\LongTermLoanOpeningBalance;
use App\Models\Manpower;
use App\Models\OtherCreditsOpeningBalance;
use App\Models\OtherDebtorsOpeningBalance;
use App\Models\OtherLongTermLiabilitiesOpeningBalance;
use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterial;
use App\Models\SupplierPayableOpeningBalance;
use App\Models\VatAndCreditWithholdTaxOpeningBalance;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ModelDataBuilder (what a study holds, in the shape the Excel model is built from)
//  Location: app/Excel/ModelDataBuilder.php
//
//  fromProject() reads the INPUTS of a study (what was typed on the forms) into one array: project, products (with their
//  seasonality and recipe), raw materials, manpower, expenses, fixed assets, loan schedules and the opening balances.
//  It is what ModelBuilder works from and also the inputs part of the "zavero:model-dump" file. Nothing is changed.
// ══════════════════════════════════════════════════════════════════

final class ModelDataBuilder
{
    /** @return array<string,mixed> */
    public static function fromProject(Project $project): array
    {
        $id = $project->id;
        $all = fn (string $model) => $model::where('project_id', $id)->orderBy('id')->get()->toArray();

        $products = [];
        foreach (Product::where('project_id', $id)->orderBy('id')->get() as $product) {
            $row = $product->toArray();
            $row['seasonality'] = $product->seasonality()->first()?->toArray();
            $row['recipe'] = $product->rawMaterials()->get()->map(fn ($m) => ['raw_material_id' => $m->id, 'percentages' => $m->pivot->percentages])->all();
            $products[] = $row;
        }

        return [
            'project'        => self::projectRow($project),
            'source_note'    => self::sourceNote($project),
            'products'       => $products,
            'raw_materials'  => $all(RawMaterial::class),
            'manpowers'      => $all(Manpower::class),
            'expenses'       => $all(Expense::class),
            'fixed_assets'   => $all(FixedAsset::class),
            'loan_schedules' => $all(LoanSchedulePayment::class),
            'opening'        => [
                'fixed_assets'    => $all(FixedAssetOpeningBalance::class),
                'cash'            => $all(CashAndBankOpeningBalance::class),
                'other_debtors'   => $all(OtherDebtorsOpeningBalance::class),
                'suppliers'       => $all(SupplierPayableOpeningBalance::class),
                'other_creditors' => $all(OtherCreditsOpeningBalance::class),
                'taxes'           => $all(VatAndCreditWithholdTaxOpeningBalance::class),
                'loans'           => $all(LongTermLoanOpeningBalance::class),
                'other_long_term' => $all(OtherLongTermLiabilitiesOpeningBalance::class),
                'equity'          => $all(EquityOpeningBalance::class),
            ],
        ];
    }

    /**
     * The study as an array, with its dates as plain "Y-m-d" days of the application's time zone. (toArray() writes a date as
     * UTC, so a Cairo midnight would come out as 22:00 of the day before and the workbook would start a month early.)
     *
     * @return array<string,mixed>
     */
    public static function projectRow(Project $project): array
    {
        $row = $project->toArray();

        foreach (['start_date', 'end_date', 'extended_end_date', 'selling_start_date', 'product_first_selling_date', 'product_second_selling_date',
            'product_third_selling_date', 'product_fourth_selling_date', 'product_fifth_selling_date'] as $field) {
            $value = $project->{$field};
            $row[$field] = $value ? $value->format('Y-m-d') : null;
        }

        return $row;
    }

    /** One line saying where the start of the study came from, printed small on the Cover (it shows at once if a file was made from stale or shifted dates). */
    public static function sourceNote(Project $project): string
    {
        $raw = (string) $project->getRawOriginal('start_date');
        $dates = $project->getRawOriginal('study_dates');
        $first = is_string($dates) ? (json_decode($dates, true)[0] ?? '') : (is_array($dates) ? ($dates[0] ?? '') : '');

        return 'start in database: '.$raw.' | first study month: '.$first.' | time zone: '.date_default_timezone_get();
    }
}
