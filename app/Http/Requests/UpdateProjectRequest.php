<?php

namespace App\Http\Requests;

use App\Support\StudyCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — UpdateProjectRequest (the checks on the study form)
//  Location: app/Http/Requests/UpdateProjectRequest.php
//
//  Months arrive from the screen as "2026-03" and are turned into "2026-03-01" before checking.
//  The rules follow the previous ZAVERO:
//    · duration 1 to 7 years;
//    · a NEW company may start operating any month from the study start; an EXISTING company starts together with the study;
//    · operation and selling start must be before the study end date (December of the last year);
//    · every product starts selling on or after the operation start;
//    · at least one product and one raw material, each with a name.
//  Differences, on purpose: the return rate is required from 2 years and the perpetual growth rate from 3 years
//  (the previous screen locked the growth rate below 3 years yet required it from 2), and both are checked
//  against the duration being saved, not the one saved last time.
// ══════════════════════════════════════════════════════════════════

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'start_date'           => $this->firstOfMonth($this->input('start_date')),
            'operation_start_date' => $this->firstOfMonth($this->input('operation_start_date')),
            'new_company'          => filter_var($this->input('new_company'), FILTER_VALIDATE_BOOLEAN),
            'name'                 => trim((string) $this->input('name')),
        ];

        foreach (['products', 'rawMaterials'] as $list) {
            $rows = $this->input($list);
            if (is_array($rows)) {
                $merge[$list] = array_map(function ($row) {
                    if (is_array($row) && array_key_exists('selling_start_date', $row)) {
                        $row['selling_start_date'] = $this->firstOfMonth($row['selling_start_date']);
                    }

                    return $row;
                }, array_values($rows));
            }
        }

        $duration = $this->input('duration');
        if ($merge['start_date'] && is_numeric($duration) && (int) $duration >= StudyCalendar::MIN_YEARS && (int) $duration <= StudyCalendar::MAX_YEARS) {
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $merge['start_date']);
            if ($start) {
                $merge['end_date'] = StudyCalendar::endYear($start, (int) $duration).'-12-01';
            }
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $study = config('zavero.study');
        $duration = (int) $this->input('duration');
        $project = $this->route('project');

        return [
            'name'                     => ['required', 'string', 'max:255'],
            'new_company'              => ['required', 'boolean'],
            'main_functional_currency' => ['required', Rule::in($study['currencies'])],
            'intro_paragraph'          => ['nullable', 'string', 'max:100000'],
            'business_sector_id'       => ['nullable', 'integer', 'exists:business_sectors,id'],

            'start_date'               => ['required', 'date_format:Y-m-d'],
            'duration'                 => ['required', 'integer', 'between:'.$study['min_years'].','.$study['max_years']],
            'operation_start_date'     => [
                'required', 'date_format:Y-m-d',
                $this->input('new_company') ? 'after_or_equal:start_date' : 'date_equals:start_date',
                'before:end_date',
            ],

            'tax_rate'                 => ['required', 'numeric', 'min:0', 'max:100'],
            'return_rate'              => [$duration >= 2 ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:100'],
            'perpetual_growth_rate'    => [$duration >= 3 ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:10'],

            'products'                         => ['required', 'array', 'min:1'],
            'products.*.id'                    => ['nullable', 'integer', Rule::exists('products', 'id')->where('project_id', $project->id)],
            'products.*.name'                  => ['required', 'string', 'max:255'],
            'products.*.measurement_unit'      => ['required', Rule::in($study['measurement_units'])],
            'products.*.selling_start_date'    => ['required', 'date_format:Y-m-d', 'after_or_equal:operation_start_date', 'before:end_date'],
            'products.*.vat_rate'              => ['nullable', 'numeric', 'min:0', 'max:100'],
            'products.*.withhold_tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],

            'rawMaterials'                           => ['required', 'array', 'min:1'],
            'rawMaterials.*.id'                      => ['nullable', 'integer', Rule::exists('raw_materials', 'id')->where('project_id', $project->id)],
            'rawMaterials.*.name'                    => ['required', 'string', 'max:255'],
            'rawMaterials.*.rm_inventory_coverage_days' => ['required', 'integer', Rule::in($study['rm_coverage_days'])],
            'rawMaterials.*.measurement_unit'        => ['required', Rule::in($study['measurement_units'])],
            'rawMaterials.*.vat_rate'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rawMaterials.*.withhold_tax_rate'       => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return trans('projects.validation');
    }

    public function attributes(): array
    {
        return trans('projects.fields');
    }

    /** "2026-03" or "2026-03-17" → "2026-03-01"; anything else is left for the checks to refuse. */
    private function firstOfMonth(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})(-\d{2})?$/', $value, $m)) {
            return $m[1].'-'.$m[2].'-01';
        }

        return $value;
    }
}
