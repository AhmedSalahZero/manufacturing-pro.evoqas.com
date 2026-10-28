<?php

namespace App\Http\Requests;

use App\Calculations\CollectionPolicy;
use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — checks for the raw material payment terms form
//  Location: app/Http/Requests/UpdateRawMaterialPaymentsRequest.php
//  Every raw material has a payment policy for the first operation year and one for the following years
//  (cash % + up to two later payments); each must add up to 100 %.
// ══════════════════════════════════════════════════════════════════

class UpdateRawMaterialPaymentsRequest extends FormRequest
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
        /** @var Project $project */
        $project = $this->route('project');
        $periods = $project->duration > 1 ? 2 : 1;

        $rules = [
            'comment_for_raw_materials' => ['nullable', 'string', 'max:20000'],
            'rawMaterials'              => ['required', 'array', 'min:1'],
            'after'                     => ['nullable', Rule::in(['stay', 'next', 'list'])],
        ];

        foreach ($project->rawMaterials()->pluck('id') as $id) {
            for ($i = 0; $i < $periods; $i++) {
                $base = "rawMaterials.$id.collection_policy_value.$i";
                $rules["$base.cash_payment"] = ['nullable', 'numeric', 'min:0', 'max:100'];
                $rules["$base.rate"] = ['nullable', 'array', 'max:2'];
                $rules["$base.rate.*"] = ['nullable', 'numeric', 'min:0', 'max:100'];
                $rules["$base.due_in_days"] = ['nullable', 'array', 'max:2'];
                $rules["$base.due_in_days.*"] = ['nullable', Rule::in(CollectionPolicy::DUE_DAYS)];
            }
        }

        return $rules;
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return trans('products.fields');
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Project $project */
            $project = $this->route('project');
            $periods = $project->duration > 1 ? 2 : 1;

            foreach ($project->rawMaterials()->pluck('id') as $id) {
                for ($i = 0; $i < $periods; $i++) {
                    $period = $this->input("rawMaterials.$id.collection_policy_value.$i", []);
                    $sum = (float) ($period['cash_payment'] ?? 0) + array_sum(array_map('floatval', (array) ($period['rate'] ?? [])));
                    if (abs($sum - 100) > 0.01) {
                        $validator->errors()->add("rawMaterials.$id.collection_policy_value.$i", __('products.errors.paymentTotal'));
                    }
                }
            }
        }];
    }
}
