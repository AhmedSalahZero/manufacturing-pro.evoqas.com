<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Project;
use App\Services\SegmentPricing;
use App\Services\ProductPlanService;
use App\Calculations\CollectionPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — checks for the product form
//  Location: app/Http/Requests/UpdateProductRequest.php
//  Who may save: anyone who can edit the study (see App\Policies\ProjectPolicy). The messages are in lang/*/products.php.
// ══════════════════════════════════════════════════════════════════

class UpdateProductRequest extends FormRequest
{
    private const TOLERANCE = 0.01;

    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return Gate::forUser($this->user())->allows('update', $project);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');
        $periods = $project->duration > 1 ? 2 : 1;

        $rules = [
            'max_capacity'                      => ['required', 'array'],
            'max_capacity.*'                    => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'target_percentages'                => ['required', 'array'],
            'target_percentages.*'              => ['nullable', 'numeric', 'min:0', 'max:100'],
            'local_target_quantity_percentages' => ['required', 'array'],
            'local_target_quantity_percentages.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'local_growth_rates'                => ['required', 'array'],
            'local_growth_rates.*'              => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'local_price_per_unit'              => ['required', 'array'],
            'local_price_per_unit.*'            => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'export_growth_rates'               => ['required', 'array'],
            'export_growth_rates.*'             => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'export_price_per_unit'             => ['required', 'array'],
            'export_price_per_unit.*'           => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],

            'seasonality'                       => ['required', 'array'],
            'seasonality.type'                  => ['required', Rule::in(['flat', 'quarterly', 'monthly'])],
            'seasonality.quarterly'             => ['nullable', 'array', 'size:4'],
            'seasonality.quarterly.*'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'seasonality.monthly'               => ['nullable', 'array', 'size:12'],
            'seasonality.monthly.*'             => ['nullable', 'numeric', 'min:0', 'max:100'],

            'fg_inventory_coverage_days'        => ['required', Rule::in(ProductPlanService::FG_COVERAGE_DAYS)],
            'comment_for_sales'                 => ['nullable', 'string', 'max:20000'],

            'collection_policy_value'           => ['required', 'array'],

            'rawMaterials'                      => ['nullable', 'array'],
            'rawMaterials.*.raw_material_id'    => ['nullable', 'integer', Rule::exists('raw_materials', 'id')->where('project_id', $project->id)],
            'rawMaterials.*.percentages'        => ['nullable', 'array'],
            'rawMaterials.*.percentages.*'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'after'                             => ['nullable', Rule::in(['stay', 'next', 'list'])],
        ];

        foreach (['local', 'export'] as $stream) {
            for ($i = 0; $i < $periods; $i++) {
                $base = "collection_policy_value.$stream.$i";
                $rules["$base.cash_payment"] = ['nullable', 'numeric', 'min:0', 'max:100'];
                $rules["$base.rate"] = ['nullable', 'array', 'max:2'];
                $rules["$base.rate.*"] = ['nullable', 'numeric', 'min:0', 'max:100'];
                $rules["$base.due_in_days"] = ['nullable', 'array', 'max:2'];
                $rules["$base.due_in_days.*"] = ['nullable', Rule::in(CollectionPolicy::DUE_DAYS)];
            }
        }

        // Sales channels, export markets and the export monthly pattern (product sales rebuild, R2 R3 R4 R5 R7). All optional.
        $rules += [
            'export_seasonality'                 => ['nullable', 'array'],
            'export_seasonality.type'            => ['required_with:export_seasonality', Rule::in(['flat', 'quarterly', 'monthly'])],
            'export_seasonality.quarterly'       => ['nullable', 'array', 'size:4'],
            'export_seasonality.quarterly.*'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'export_seasonality.monthly'         => ['nullable', 'array', 'size:12'],
            'export_seasonality.monthly.*'       => ['nullable', 'numeric', 'min:0', 'max:100'],

            'segments'                           => ['nullable', 'array'],
            'segments.local'                     => ['nullable', 'array', 'max:'.config('zavero.study.max_channels')],
            'segments.local.*.sales_channel_id'  => ['required', 'integer', 'distinct', Rule::exists('sales_channels', 'id')->where('project_id', $project->id)],
            'segments.local.*.allocation_percentages'   => ['nullable', 'array'],
            'segments.local.*.allocation_percentages.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'segments.local.*.price_differences'        => ['nullable', 'array'],
            'segments.local.*.price_differences.*'      => ['nullable', 'numeric', 'between:-99,1000'],
            'segments.local.*.uses_own_terms'           => ['nullable', 'boolean'],
            'segments.export'                    => ['nullable', 'array', 'max:'.config('zavero.study.max_markets')],
            'segments.export.*.export_market_id' => ['required', 'integer', 'distinct', Rule::exists('export_markets', 'id')->where('project_id', $project->id)],
            'segments.export.*.allocation_percentages'   => ['nullable', 'array'],
            'segments.export.*.allocation_percentages.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'segments.export.*.first_foreign_price'      => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'segments.export.*.foreign_growth_rates'     => ['nullable', 'array'],
            'segments.export.*.foreign_growth_rates.*'   => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'segments.export.*.uses_own_terms'           => ['nullable', 'boolean'],
        ];

        foreach (['local', 'export'] as $stream) {
            for ($i = 0; $i < $periods; $i++) {
                $base = "segments.$stream.*.collection_policy_value.$i";
                $rules["$base.cash_payment"] = ['nullable', 'numeric', 'min:0', 'max:100'];
                $rules["$base.rate"] = ['nullable', 'array', 'max:2'];
                $rules["$base.rate.*"] = ['nullable', 'numeric', 'min:0', 'max:100'];
                $rules["$base.due_in_days"] = ['nullable', 'array', 'max:2'];
                $rules["$base.due_in_days.*"] = ['nullable', Rule::in(CollectionPolicy::DUE_DAYS)];
            }
        }

        if (! $project->new_company) {
            $rules['fg_inventory_quantity'] = ['nullable', 'numeric', 'min:0', 'max:1000000000000'];
            $rules['fg_inventory_value'] = ['nullable', 'numeric', 'min:0', 'max:1000000000000'];
            $rules['fg_beginning_inventory_breakdowns'] = ['nullable', 'array'];
            $rules['fg_beginning_inventory_breakdowns.*.percentage'] = ['nullable', 'numeric', 'min:0', 'max:100'];
        }

        return $rules;
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return trans('products.fields');
    }

    /** The rules that look at several fields together. */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Project $project */
            $project = $this->route('project');
            $data = $this->all();

            $type = $data['seasonality']['type'] ?? 'flat';
            if (in_array($type, ['quarterly', 'monthly'], true)) {
                $sum = array_sum(array_map('floatval', (array) ($data['seasonality'][$type] ?? [])));
                if (abs($sum - 100) > self::TOLERANCE) {
                    $validator->errors()->add("seasonality.$type", __('products.errors.seasonalityTotal'));
                }
            }

            $periods = $project->duration > 1 ? 2 : 1;
            foreach (['local', 'export'] as $stream) {
                for ($i = 0; $i < $periods; $i++) {
                    $period = $data['collection_policy_value'][$stream][$i] ?? [];
                    $sum = (float) ($period['cash_payment'] ?? 0) + array_sum(array_map('floatval', (array) ($period['rate'] ?? [])));
                    if (abs($sum - 100) > self::TOLERANCE) {
                        $validator->errors()->add("collection_policy_value.$stream.$i", __('products.errors.collectionTotal'));
                    }
                }
            }

            if (! $project->new_company && (float) ($data['fg_inventory_value'] ?? 0) > 0) {
                $sum = 0;
                foreach (ProductPlanService::FG_BREAKDOWN_TYPES as $kind) {
                    $sum += (float) ($data['fg_beginning_inventory_breakdowns'][$kind]['percentage'] ?? 0);
                }
                if (abs($sum - 100) > self::TOLERANCE) {
                    $validator->errors()->add('fg_beginning_inventory_breakdowns', __('products.errors.breakdownTotal'));
                }
            }

            $exportType = $data['export_seasonality']['type'] ?? 'flat';
            if (in_array($exportType, ['quarterly', 'monthly'], true)) {
                $sum = array_sum(array_map('floatval', (array) ($data['export_seasonality'][$exportType] ?? [])));
                if (abs($sum - 100) > self::TOLERANCE) {
                    $validator->errors()->add("export_seasonality.$exportType", __('products.errors.seasonalityTotal'));
                }
            }

            $this->checkSales($validator, $project, $data);

            $ids = array_filter(array_column((array) ($data['rawMaterials'] ?? []), 'raw_material_id'));
            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add('rawMaterials', __('products.errors.duplicateMaterial'));
            }
        }];
    }

    /**
     * R7: the checks on the sales channels and export markets of a product.
     * They apply once the study has defined a channel or a market; a study with none keeps working as it always did (R8).
     *
     * @param  array<string,mixed>  $data
     */
    private function checkSales(Validator $validator, Project $project, array $data): void
    {
        if (! $project->usesSalesSegments()) {
            return;
        }

        // The form sends one value per year it shows: those are the years that are checked.
        $years = array_map('intval', array_keys((array) ($data['max_capacity'] ?? [])));
        $capacity = (array) ($data['max_capacity'] ?? []);
        $target = (array) ($data['target_percentages'] ?? []);
        $localShare = (array) ($data['local_target_quantity_percentages'] ?? []);

        // Group quantity of each year, as the server works it out (capacity x target %, split by the local share).
        $local = $export = [];
        foreach ($years as $year) {
            $quantity = (float) ($capacity[$year] ?? 0) * (float) ($target[$year] ?? 0) / 100;
            $local[$year] = $quantity * (float) ($localShare[$year] ?? 0) / 100;
            $export[$year] = $quantity - $local[$year];
        }
        $localYears = array_keys(array_filter($local, fn ($q) => $q > 0));
        $exportYears = array_keys(array_filter($export, fn ($q) => $q > 0.000001));

        // Export switched off: the export share must be 0.
        if (! $project->has_export_sales && $exportYears !== []) {
            $validator->errors()->add('local_target_quantity_percentages', __('products.errors.exportOff'));
        }
        if (! $project->has_export_sales && ! empty($data['segments']['export'])) {
            $validator->errors()->add('segments.export', __('products.errors.exportOff'));
        }

        if (! array_key_exists('segments', $data)) {
            return;   // the screen always sends the segments; a save without them keeps what is saved
        }

        $this->checkGroup($validator, $project, 'local', (array) ($data['segments']['local'] ?? []), $localYears, 'noChannel', 'channelTotal');

        if ($project->has_export_sales) {
            $this->checkGroup($validator, $project, 'export', (array) ($data['segments']['export'] ?? []), $exportYears, 'noMarket', 'marketTotal');
            $this->checkFx($validator, $project, (array) ($data['segments']['export'] ?? []), $exportYears);
        }

        // A segment with its own terms needs terms that add up to 100 (the same check as the product's default terms).
        $periods = $project->duration > 1 ? 2 : 1;
        foreach (['local', 'export'] as $stream) {
            foreach (array_values((array) ($data['segments'][$stream] ?? [])) as $n => $row) {
                if (empty($row['uses_own_terms'])) {
                    continue;
                }
                for ($i = 0; $i < $periods; $i++) {
                    $period = $row['collection_policy_value'][$i] ?? [];
                    $sum = (float) ($period['cash_payment'] ?? 0) + array_sum(array_map('floatval', (array) ($period['rate'] ?? [])));
                    if (abs($sum - 100) > self::TOLERANCE) {
                        $validator->errors()->add("segments.$stream.$n.collection_policy_value.$i", __('products.errors.collectionTotal'));
                    }
                }
            }
        }
    }

    /**
     * At least one channel (market) while there is local (export) quantity, and the shares of every year that sells add up to 100.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<int>  $sellingYears  the years that have quantity
     */
    private function checkGroup(Validator $validator, Project $project, string $kind, array $rows, array $sellingYears, string $missing, string $total): void
    {
        $rows = array_values($rows);

        if ($sellingYears === []) {
            return;
        }

        if ($rows === []) {
            $validator->errors()->add("segments.$kind", __("products.errors.$missing"));

            return;
        }

        foreach ($sellingYears as $year) {
            $sum = array_sum(array_map(fn ($row) => (float) ($row['allocation_percentages'][$year] ?? 0), $rows));
            if (abs($sum - 100) > self::TOLERANCE) {
                $validator->errors()->add("segments.$kind", __("products.errors.$total", ['year' => (int) $project->start_date->format('Y') + $year, 'total' => round($sum, 2)]));
            }
        }
    }

    /**
     * Every market's currency must be a currency of the study with a rate above zero for every year that exports.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<int>  $exportYears
     */
    private function checkFx(Validator $validator, Project $project, array $rows, array $exportYears): void
    {
        $fx = app(SegmentPricing::class)->fxRates($project);
        $markets = $project->exportMarkets()->get()->keyBy('id');

        foreach (array_values($rows) as $n => $row) {
            $market = $markets->get((int) ($row['export_market_id'] ?? 0));
            $rates = $fx[$market?->currency] ?? null;

            foreach ($exportYears as $year) {
                if ($rates === null || (float) ($rates[$year] ?? 0) <= 0) {
                    $validator->errors()->add("segments.export.$n.export_market_id", __('products.errors.noFx', ['market' => $market?->name, 'currency' => $market?->currency === 'euro' ? 'EUR' : strtoupper((string) $market?->currency)]));

                    break;
                }
            }
        }
    }
}
