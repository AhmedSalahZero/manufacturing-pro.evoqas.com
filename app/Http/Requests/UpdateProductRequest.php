<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Project;
use App\Services\ProductPlanService;
use App\Calculations\CollectionPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
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

        return $this->user()->can('update', $project);
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

            $ids = array_filter(array_column((array) ($data['rawMaterials'] ?? []), 'raw_material_id'));
            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add('rawMaterials', __('products.errors.duplicateMaterial'));
            }
        }];
    }
}
