<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Services\CostCalculationService;
use App\Services\SalesCalculationService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — checks for the staff form
//  Location: app/Http/Requests/UpdateManpowerRequest.php
//  Salary settings, the positions (type, name, salary, existing count, people hired per month) and how the direct labour
//  and manufacturing overheads staff cost is shared between the products (by sales, or typed % adding up to 100).
// ══════════════════════════════════════════════════════════════════

class UpdateManpowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()->can('update', $project);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'salaries_annual_increase' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'salaries_tax_rate'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'social_insurance_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows'                     => ['nullable', 'array', 'max:300'],
            'rows.*.id'                => ['nullable', 'integer'],
            'rows.*.type'              => ['required', Rule::in(array_keys(CostCalculationService::MANPOWER_TYPES))],
            'rows.*.position'          => ['required', 'string', 'max:255'],
            'rows.*.avg_salary'        => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'rows.*.existing_count'    => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'rows.*.hirings'           => ['nullable', 'array'],
            'rows.*.hirings.*'         => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'allocations'              => ['nullable', 'array'],
            'allocations.*'            => ['nullable', 'array'],
            'allocations.*.*'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'by_revenue'               => ['nullable', 'array'],
            'by_revenue.*'             => ['boolean'],
            'after'                    => ['nullable', Rule::in(['stay', 'next', 'list'])],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return trans('costs.fields');
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Project $project */
            $project = $this->route('project');

            if ((float) $this->input('salaries_tax_rate', 0) + (float) $this->input('social_insurance_rate', 0) >= 100) {
                $validator->errors()->add('salaries_tax_rate', __('costs.errors.levyTotal'));
            }

            $own = $project->manpowers()->pluck('id')->all();
            $timeline = app(SalesCalculationService::class)->timeline($project);
            $months = $timeline ? array_keys($timeline->operationMonths) : [];

            foreach ((array) $this->input('rows', []) as $i => $row) {
                if (! empty($row['id']) && ! in_array((int) $row['id'], $own, true)) {
                    $validator->errors()->add("rows.$i.id", __('costs.errors.unknownRow'));
                }
                foreach (array_keys((array) ($row['hirings'] ?? [])) as $month) {
                    if (! in_array((int) $month, $months, true)) {
                        $validator->errors()->add("rows.$i.hirings", __('costs.errors.hiringMonth'));
                        break;
                    }
                }
            }

            $usedTypes = collect((array) $this->input('rows', []))->pluck('type')->unique()->all();
            $productIds = $project->products()->pluck('id')->all();

            foreach (CostCalculationService::MANPOWER_TYPES as $type => $info) {
                if ($info['column'] === null || ! in_array($type, $usedTypes, true) || $productIds === []) {
                    continue;
                }
                if ((bool) $this->input("by_revenue.$type", true)) {
                    continue;
                }
                $total = 0.0;
                foreach ($productIds as $id) {
                    $total += (float) $this->input("allocations.$type.$id", 0);
                }
                if (abs($total - 100) > 0.01) {
                    $validator->errors()->add("allocations.$type", __('costs.errors.allocationTotal'));
                }
            }
        }];
    }
}
