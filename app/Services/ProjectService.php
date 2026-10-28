<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterial;
use App\Models\Seasonality;
use App\Models\User;
use App\Support\StudyCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ProjectService (create, save, copy a study)
//  Location: app/Services/ProjectService.php
//
//  Everything that changes a study as a whole happens here, inside one database transaction:
//  either all of it is saved or none of it.
// ══════════════════════════════════════════════════════════════════

class ProjectService
{
    /** The nine "opening balance" tables. A new company has none, so saving a new-company study clears them. */
    public const OPENING_BALANCE_TABLES = [
        'fixed_asset_opening_balances',
        'cash_and_bank_opening_balances',
        'other_debtors_opening_balances',
        'supplier_payable_opening_balances',
        'other_credits_opening_balances',
        'other_long_term_liabilities_opening_balances',
        'equity_opening_balances',
        'vat_and_credit_withhold_tax_opening_balances',
        'long_term_loan_opening_balances',
    ];

    /** Tables copied row by row with a new project_id, and the ids inside them that must point to the copies. */
    private const COPY_PLAN = [
        'manpowers'                            => [],
        'expenses'                             => [],
        'fixed_asset_opening_balances'         => [],
        'cash_and_bank_opening_balances'       => [],
        'other_debtors_opening_balances'       => [],
        'supplier_payable_opening_balances'    => [],
        'other_credits_opening_balances'       => [],
        'other_long_term_liabilities_opening_balances' => [],
        'equity_opening_balances'              => [],
        'vat_and_credit_withhold_tax_opening_balances' => [],
        'long_term_loan_opening_balances'      => [],
        'product_raw_material'                 => ['product_id' => 'products', 'raw_material_id' => 'raw_materials'],
        'product_expense_allocations'          => ['product_id' => 'products'],
        'loan_schedule_payments'               => ['fixed_asset_id' => 'fixed_assets'],
        'sensitivity_loan_schedule_payments'   => ['fixed_asset_id' => 'fixed_assets'],
        'sensitivities'                        => [],
        'balance_sheets'                       => [],
        'income_statements'                    => [],
        'cash_in_out_statements'               => [],
    ];

    /** A new, empty study with just a name. The person fills in the rest on the study form. */
    public function create(User $user, string $name): Project
    {
        return Project::create([
            'user_id'     => $user->id,
            'company_id'  => $user->company_id,
            'name'        => $name,
            'slug'        => $this->slug($name),
            'new_company' => true,
        ]);
    }

    /**
     * Saves the whole study form: the study itself, its months, its products and its raw materials.
     *
     * @param  array<string, mixed>  $data  already validated (see UpdateProjectRequest)
     */
    public function save(Project $project, array $data): Project
    {
        $saved = DB::transaction(function () use ($project, $data) {
            $calendar = StudyCalendar::build($data['start_date'], (int) $data['duration'], $data['operation_start_date']);
            $perpetual = (int) $data['duration'] >= 3;
            $returns = (int) $data['duration'] >= 2;

            $project->fill([
                'name'                     => $data['name'],
                'slug'                     => $this->slug($data['name']),
                'is_completed'             => true,
                'new_company'              => (bool) $data['new_company'],
                'main_functional_currency' => $data['main_functional_currency'],
                'intro_paragraph'          => $data['intro_paragraph'] ?? null,
                'business_sector_id'       => $data['business_sector_id'] ?? null,
                'tax_rate'                 => $data['tax_rate'],
                'return_rate'              => $returns ? ($data['return_rate'] ?? null) : null,
                'perpetual_growth_rate'    => $perpetual ? ($data['perpetual_growth_rate'] ?? null) : null,
                'duration'                 => (int) $data['duration'],
                'start_date'               => $data['start_date'],
                // Saved as a position in the study months (0 = first month): move the study start and it moves with it.
                'operation_start_date'     => StudyCalendar::indexOf($calendar['extended_study_dates'], $data['operation_start_date']) ?? 0,
                'end_date'                 => $calendar['end_date'],
                'extended_end_date'        => $calendar['extended_end_date'],
                'study_dates'              => $calendar['study_dates'],
                'extended_study_dates'     => $calendar['extended_study_dates'],
                'operation_dates'          => $calendar['operation_dates'],
            ])->save();

            if ($project->new_company) {
                foreach (self::OPENING_BALANCE_TABLES as $table) {
                    DB::table($table)->where('project_id', $project->id)->delete();
                }
            }

            $this->syncProducts($project, $data['products'], $calendar['extended_study_dates']);
            $this->syncRawMaterials($project, $data['rawMaterials']);

            return $project->refresh();
        });

        // Dates, selling starts and tax rates feed every monthly figure, so the sales results are worked out again.
        app(SalesCalculationService::class)->recalculate($saved);

        return $saved;
    }

    /**
     * A full copy of a study: its settings, products, raw materials, staff, expenses, assets, opening balances
     * and saved results. The copy belongs to the person who made it ($owner), in the same company.
     */
    public function copy(Project $project, string $name, User $owner): Project
    {
        return DB::transaction(function () use ($project, $name, $owner) {
            $copy = $project->replicate(['deleted_at']);
            $copy->user_id = $owner->id;
            $copy->company_id = $project->company_id;
            $copy->name = $name;
            $copy->slug = $this->slug($name);
            $copy->save();

            $maps = [];
            foreach (['products', 'raw_materials', 'fixed_assets'] as $table) {
                $maps[$table] = $this->copyRows($table, $project->id, $copy->id, [], [], $table === 'fixed_assets'
                    ? fn (array $row) => $this->remapKeyedColumns($row, ['product_allocations', 'monthly_product_allocations'], $maps['products'])
                    : null);
            }

            foreach (self::COPY_PLAN as $table => $links) {
                $this->copyRows($table, $project->id, $copy->id, $maps, $links, function (array $row) use ($table, $maps) {
                    return match ($table) {
                        'expenses'                     => $this->remapExpenseProducts($row, $maps['products']),
                        'fixed_asset_opening_balances' => $this->remapKeyedColumns($row, ['product_allocations', 'monthly_product_allocations'], $maps['products']),
                        default                        => $row,
                    };
                });
            }

            // The monthly sales pattern of each product follows the product to its copy.
            $this->copyRows('seasonality', $project->id, $copy->id, $maps, [], function (array $row) use ($maps) {
                if ($row['model_name'] === 'Product') {
                    if (! isset($maps['products'][$row['model_id']])) {
                        return null;
                    }
                    $row['model_id'] = $maps['products'][$row['model_id']];
                }

                return $row;
            });

            // Shares typed per product (staff and expenses) are filed under product ids: point them at the copied products.
            $copy->update([
                'manpower_allocations'          => $this->remapKeys($copy->manpower_allocations, $maps['products'], 1),
                'monthly_manpower_allocations'  => $this->remapKeys($copy->monthly_manpower_allocations, $maps['products'], 1),
            ]);

            return $copy;
        });
    }

    /**
     * Re-files a list that is keyed by product id under the ids of the copied products.
     * $depth 0: the keys are product ids; 1: the product ids are one level down (type => product id => value).
     */
    private function remapKeys(?array $data, array $productMap, int $depth = 0): ?array
    {
        if ($data === null) {
            return null;
        }

        $result = [];
        foreach ($data as $key => $value) {
            if ($depth > 0) {
                $result[$key] = is_array($value) ? $this->remapKeys($value, $productMap, $depth - 1) : $value;
            } elseif (isset($productMap[(int) $key])) {
                $result[$productMap[(int) $key]] = $value;
            }
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $rows */
    private function syncProducts(Project $project, array $rows, array $extendedStudyDates): void
    {
        $kept = [];

        foreach ($rows as $row) {
            $values = [
                'name'               => $row['name'],
                'measurement_unit'   => $row['measurement_unit'],
                'selling_start_date' => StudyCalendar::indexOf($extendedStudyDates, $row['selling_start_date']) ?? 0,
                'vat_rate'           => $row['vat_rate'] ?? 0,
                'withhold_tax_rate'  => $row['withhold_tax_rate'] ?? 0,
            ];

            $product = ! empty($row['id']) ? $project->products()->whereKey($row['id'])->first() : null;
            $product ? $product->update($values) : ($product = $project->products()->create($values));
            $kept[] = $product->id;
        }

        $removed = $project->products()->whereNotIn('id', $kept)->pluck('id')->all();

        if ($removed === []) {
            return;
        }

        // What belongs to a removed product goes with it (its recipe lines and allocations are removed by the database).
        Seasonality::where('model_name', 'Product')->whereIn('model_id', $removed)->delete();
        Product::whereIn('id', $removed)->delete();

        foreach (Expense::where('project_id', $project->id)->whereNotNull('products')->get() as $expense) {
            $ids = array_values(array_filter((array) $expense->products, fn ($id) => ! in_array((int) $id, $removed, true)));
            if ($ids !== (array) $expense->products) {
                $expense->update(['products' => $ids]);
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function syncRawMaterials(Project $project, array $rows): void
    {
        $kept = [];

        foreach ($rows as $row) {
            $values = [
                'name'                       => $row['name'],
                'rm_inventory_coverage_days' => (int) $row['rm_inventory_coverage_days'],
                'measurement_unit'           => $row['measurement_unit'],
                'vat_rate'                   => $row['vat_rate'] ?? 0,
                'withhold_tax_rate'          => $row['withhold_tax_rate'] ?? 0,
            ];

            $material = ! empty($row['id']) ? $project->rawMaterials()->whereKey($row['id'])->first() : null;
            $material ? $material->update($values) : ($material = $project->rawMaterials()->create($values));
            $kept[] = $material->id;
        }

        RawMaterial::where('project_id', $project->id)->whereNotIn('id', $kept)->delete();
    }

    /**
     * Copies every row of a table that belongs to a project into the new project.
     *
     * @param  array<string, array<int, int>>  $maps  old id → new id, per table already copied
     * @param  array<string, string>           $links column → table it points to (the id is replaced by the copy's id)
     * @return array<int, int> old id → new id
     */
    private function copyRows(string $table, int $fromProject, int $toProject, array $maps = [], array $links = [], ?callable $adjust = null): array
    {
        $ids = [];

        foreach (DB::table($table)->where('project_id', $fromProject)->orderBy('id')->get() as $row) {
            $values = (array) $row;
            $oldId = $values['id'];
            unset($values['id']);
            $values['project_id'] = $toProject;

            foreach ($links as $column => $target) {
                if ($values[$column] === null) {
                    continue;
                }
                if (! isset($maps[$target][$values[$column]])) {
                    continue 2;   // points to something that is not in this study: nothing sensible to copy
                }
                $values[$column] = $maps[$target][$values[$column]];
            }

            if ($adjust && ($values = $adjust($values)) === null) {
                continue;
            }

            $ids[$oldId] = DB::table($table)->insertGetId($values);
        }

        return $ids;
    }

    /** An expense can be tied to products (a JSON list of product ids): point it at the copied products. */
    private function remapExpenseProducts(array $row, array $productMap): array
    {
        $list = is_string($row['products'] ?? null) ? json_decode($row['products'], true) : null;

        if (is_array($list)) {
            $row['products'] = json_encode(array_values(array_map(fn ($id) => $productMap[(int) $id] ?? (int) $id, $list)));
        }

        return $this->remapKeyedColumns($row, ['product_allocations', 'monthly_product_allocations', 'product_revenues'], $productMap);
    }

    /** JSON columns keyed by product id (typed shares): re-file them under the ids of the copied products. */
    private function remapKeyedColumns(array $row, array $columns, array $productMap): array
    {
        foreach ($columns as $column) {
            $keyed = is_string($row[$column] ?? null) ? json_decode($row[$column], true) : null;
            if (is_array($keyed)) {
                $row[$column] = json_encode($this->remapKeys($keyed, $productMap));
            }
        }

        return $row;
    }

    private function slug(string $name): string
    {
        return Str::slug($name) ?: 'study';
    }
}
