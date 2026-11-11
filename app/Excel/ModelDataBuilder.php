<?php

namespace App\Excel;

use App\Models\CashAndBankOpeningBalance;
use App\Models\EquityOpeningBalance;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetOpeningBalance;
use App\Models\LoanSchedulePayment;
use App\Models\LongTermLoanOpeningBalance;
use App\Models\ExportMarket;
use App\Models\FxRate;
use App\Models\Manpower;
use App\Models\OtherCreditsOpeningBalance;
use App\Models\OtherDebtorsOpeningBalance;
use App\Models\OtherLongTermLiabilitiesOpeningBalance;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\SalesChannel;
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

        $channels = SalesChannel::where('project_id', $id)->pluck('name', 'id')->all();
        $markets = ExportMarket::where('project_id', $id)->get(['id', 'name', 'currency'])->keyBy('id');
        $products = [];
        foreach (Product::where('project_id', $id)->orderBy('id')->get() as $product) {
            $row = $product->toArray();
            $row['seasonality'] = $product->seasonality()->first()?->toArray();
            $row['export_seasonality'] = $product->exportSeasonality()->first()?->toArray();
            $row['segments'] = self::segments($product, $channels, $markets);
            $row['recipe'] = $product->rawMaterials()->get()->map(fn ($m) => ['raw_material_id' => $m->id, 'percentages' => $m->pivot->percentages])->all();
            $products[] = $row;
        }

        return [
            'project'        => self::projectRow($project),
            'source_note'    => self::sourceNote($project),
            'products'       => $products,
            'fx_rates'       => $project->has_export_sales ? FxRate::where('project_id', $id)->orderBy('id')->get(['currency', 'rates'])->map(fn ($r) => ['currency' => $r->currency, 'rates' => (array) $r->rates])->all() : [],
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
     * What a product shares over the channels and markets, in the order of the screen (channels first), with the names the sheets show.
     *
     * @param  array<int,string>  $channels  id => name
     * @return list<array<string,mixed>>
     */
    private static function segments(Product $product, array $channels, $markets): array
    {
        $rows = [];

        foreach (ProductSegment::where('product_id', $product->id)->orderBy('kind')->orderBy('id')->get() as $segment) {
            $market = $segment->export_market_id ? $markets->get($segment->export_market_id) : null;
            $rows[] = [
                'kind'                    => $segment->kind,
                'name'                    => $segment->kind === 'local' ? (string) ($channels[$segment->sales_channel_id] ?? '') : (string) ($market?->name ?? ''),
                'currency'                => $market?->currency,
                'allocation_percentages'  => (array) $segment->allocation_percentages,
                'price_differences'       => (array) $segment->price_differences,
                'first_foreign_price'     => (float) $segment->first_foreign_price,
                'foreign_growth_rates'    => (array) $segment->foreign_growth_rates,
                'uses_own_terms'          => (bool) $segment->uses_own_terms,
                'collection_policy_value' => (array) $segment->collection_policy_value,
            ];
        }

        return $rows;
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
