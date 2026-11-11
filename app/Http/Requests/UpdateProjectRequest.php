<?php

namespace App\Http\Requests;

use App\Models\ExportMarket;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\SalesChannel;
use App\Support\StudyCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
//  Sales channels and export (product sales rebuild, R1 and R7): the export toggle, the foreign currencies with a rate for
//  every study year, the sales channels and the export markets. These four keys are optional: a form that does not send
//  them leaves what is saved untouched.
//  Differences, on purpose: the return rate is required from 2 years and the perpetual growth rate from 3 years
//  (the previous screen locked the growth rate below 3 years yet required it from 2), and both are checked
//  against the duration being saved, not the one saved last time.
// ══════════════════════════════════════════════════════════════════

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('update', $this->route('project'));
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'start_date'           => $this->firstOfMonth($this->input('start_date')),
            'operation_start_date' => $this->firstOfMonth($this->input('operation_start_date')),
            'new_company'          => filter_var($this->input('new_company'), FILTER_VALIDATE_BOOLEAN),
            'name'                 => trim((string) $this->input('name')),
        ];

        if ($this->has('has_export_sales')) {
            $merge['has_export_sales'] = filter_var($this->input('has_export_sales'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($this->has('clear_export')) {
            $merge['clear_export'] = filter_var($this->input('clear_export'), FILTER_VALIDATE_BOOLEAN);
        }

        foreach (['sales_channels', 'export_markets'] as $list) {
            $rows = $this->input($list);
            if (is_array($rows)) {
                $merge[$list] = array_map(fn ($row) => is_array($row) && isset($row['name']) ? ['name' => trim((string) $row['name'])] + $row : $row, array_values($rows));
            }
        }
        if (is_array($this->input('fx_rates'))) {
            $merge['fx_rates'] = array_values($this->input('fx_rates'));
        }

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

        // The three rates are the owner's assumptions: a customer who fills in the study through a "fill" link keeps what is saved.
        $link = $this->attributes->get('public_link');
        if ($link && $link->isFill() && ($project = $this->route('project'))) {
            $merge['tax_rate'] = $project->tax_rate;
            $merge['return_rate'] = $project->return_rate;
            $merge['perpetual_growth_rate'] = $project->perpetual_growth_rate;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $study = config('zavero.study');
        $duration = (int) $this->input('duration');
        $project = $this->route('project');

        return $this->salesRules($study, $duration, $project) + [
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

    /** Whether this save has export sales: what is sent, or else what is saved. */
    private function exports(): bool
    {
        return $this->has('has_export_sales') ? (bool) $this->input('has_export_sales') : (bool) $this->route('project')->has_export_sales;
    }

    /** @return array<string,mixed> the rules of the sales-channels-and-export card (R1, R7) */
    private function salesRules(array $study, int $duration, $project): array
    {
        $exports = $this->exports();
        $years = $this->input('start_date') && $duration >= StudyCalendar::MIN_YEARS && $duration <= StudyCalendar::MAX_YEARS && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->input('start_date'))
            ? StudyCalendar::yearCount($this->input('start_date'), $duration)
            : 0;

        $rules = [
            'has_export_sales'               => ['sometimes', 'boolean'],
            'clear_export'                   => ['sometimes', 'boolean'],

            'sales_channels'                 => ['sometimes', 'array', 'max:'.$study['max_channels']],
            'sales_channels.*.id'            => ['nullable', 'integer', Rule::exists('sales_channels', 'id')->where('project_id', $project->id)],
            'sales_channels.*.name'          => ['required', 'string', 'max:100', 'distinct:ignore_case'],

            'fx_rates'                       => ['sometimes', 'array', 'max:'.$study['max_foreign_currencies']],
            'fx_rates.*.currency'            => ['required', 'string', Rule::in($study['currencies']), 'not_in:'.$this->input('main_functional_currency'), 'distinct'],
            'fx_rates.*.rates'               => ['required_with:fx_rates.*.currency', 'array'],

            'export_markets'                 => ['sometimes', 'array', 'max:'.$study['max_markets']],
            'export_markets.*.id'            => ['nullable', 'integer', Rule::exists('export_markets', 'id')->where('project_id', $project->id)],
            'export_markets.*.name'          => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'export_markets.*.currency'      => ['required', 'string', Rule::in($study['currencies'])],
        ];

        // R1.3 / R7: with export sales every foreign currency needs a rate above zero for every study year.
        for ($year = 0; $year < $years; $year++) {
            $rules["fx_rates.*.rates.{$year}"] = $exports ? ['required', 'numeric', 'gt:0', 'max:1000000000'] : ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /** The checks that need the saved data: the markets' currencies, the export toggle and what is still in use. */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->salesChecks($validator)];
    }

    private function salesChecks(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;   // the basic mistakes are shown first
        }

        $project = $this->route('project');
        $exports = $this->exports();
        $currencies = collect($this->input('fx_rates', $this->has('fx_rates') ? [] : $project->fxRates->map(fn ($r) => ['currency' => $r->currency])->all()))->pluck('currency')->all();

        if ($exports) {
            if ($currencies === []) {
                $validator->errors()->add('fx_rates', trans('projects.sales.needCurrency'));
            }

            // R7: every market is paid in a currency the study has chosen.
            $markets = $this->has('export_markets') ? $this->input('export_markets') : $project->exportMarkets->map(fn ($m) => ['currency' => $m->currency])->all();
            foreach (array_values($markets) as $i => $market) {
                if (! in_array($market['currency'] ?? null, $currencies, true)) {
                    $validator->errors()->add("export_markets.{$i}.currency", trans('projects.sales.marketCurrency'));
                }
            }
        } elseif ($this->has('has_export_sales') && ! $this->boolean('clear_export') && $this->productsWithExport($project) !== []) {
            // R1.6: switching export off while products still sell abroad needs the user's confirmation.
            $validator->errors()->add('has_export_sales', trans('projects.sales.exportInUse', ['products' => implode(', ', $this->productsWithExport($project))]));
        }

        // D8 / R7: a channel or market that a product uses cannot be deleted.
        $this->checkNotInUse($validator, $project, 'sales_channels', SalesChannel::class, 'sales_channel_id', 'channelInUse');
        $this->checkNotInUse($validator, $project, 'export_markets', ExportMarket::class, 'export_market_id', 'marketInUse');
    }

    private function checkNotInUse(Validator $validator, $project, string $field, string $model, string $column, string $message): void
    {
        if (! $this->has($field)) {
            return;
        }

        $kept = collect($this->input($field))->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        foreach ($model::where('project_id', $project->id)->whereNotIn('id', $kept)->get() as $row) {
            $names = ProductSegment::where($column, $row->id)->pluck('product_id')->unique()->pipe(
                fn ($ids) => Product::whereIn('id', $ids)->orderBy('name')->pluck('name')->all()
            );

            if ($names !== []) {
                $validator->errors()->add($field, trans("projects.sales.{$message}", ['name' => $row->name, 'products' => implode(', ', $names)]));
            }
        }
    }

    /** @return list<string> names of the study's products that have export quantity */
    private function productsWithExport($project): array
    {
        return $project->products()->orderBy('name')->get()
            ->filter(fn (Product $p) => collect((array) $p->export_target_quantities)->contains(fn ($q) => (float) $q > 0))
            ->pluck('name')->all();
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
