<?php

namespace App\Http\Requests;

use App\Calculations\LoanCalculator;
use App\Models\Project;
use App\Services\FixedAssetService;
use App\Support\StudyCalendar;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — checks for the fixed assets form
//  Location: app/Http/Requests/UpdateFixedAssetsRequest.php
//  Each asset: kind (land, construction, machine, other), name, count, price, depreciation, the months it is bought (a construction has an
//  end month and a spending curve, a machine a delivery and a ready month), payment terms (cash, customised payments that
//  add up to 100 %, instalments, or for a machine milestones that add up to 100 %), funding (equity % and a bank loan with interest, grace period, tenor), replacement
//  cost and how the depreciation is shared between the products.
// ══════════════════════════════════════════════════════════════════

class UpdateFixedAssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return Gate::forUser($this->user())->allows('update', $project);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'rows'                                 => ['nullable', 'array', 'max:300'],
            'rows.*.id'                            => ['nullable', 'integer'],
            'rows.*.name'                          => ['required', 'string', 'max:255'],
            'rows.*.asset_type'                    => ['nullable', Rule::in(FixedAssetService::TYPES)],
            'rows.*.execution_curve'               => ['nullable', Rule::in(FixedAssetService::CURVES)],
            'rows.*.counts'                        => ['required', 'integer', 'min:0', 'max:1000000'],
            'rows.*.amount'                        => ['required', 'numeric', 'min:0', 'max:100000000000'],
            'rows.*.depreciation_duration'         => ['required', 'integer', 'min:0', 'max:25'],
            'rows.*.start'                         => ['required', 'date_format:Y-m'],
            'rows.*.delivery'                      => ['nullable', 'date_format:Y-m'],
            'rows.*.end'                           => ['nullable', 'date_format:Y-m'],
            'rows.*.admin_depreciation_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.replacement_cost_rate'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.replacement_cost_interval'     => ['nullable', 'integer', 'min:1', 'max:5'],
            'rows.*.payment_terms'                 => ['required', Rule::in(FixedAssetService::PAYMENT_TERMS)],
            'rows.*.custom'                        => ['nullable', 'array', 'max:'.FixedAssetService::CUSTOM_SLOTS],
            'rows.*.custom.*.due_days'             => ['nullable', Rule::in(FixedAssetService::DUE_DAYS)],
            'rows.*.custom.*.rate'                 => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.custom.*.from_total'           => ['nullable', 'boolean'],
            'rows.*.milestones'                    => ['nullable', 'array'],
            'rows.*.milestones.purchase.rate'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.milestones.purchase.days'      => ['nullable', Rule::in(FixedAssetService::DUE_DAYS)],
            'rows.*.milestones.delivery.rate'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.milestones.delivery.days'      => ['nullable', Rule::in(FixedAssetService::DUE_DAYS)],
            'rows.*.milestones.ready.rate'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.milestones.ready.days'         => ['nullable', Rule::in(FixedAssetService::DUE_DAYS)],
            'rows.*.reservation_rate'              => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.contractual_rate'              => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.after_months'                  => ['nullable', 'integer', 'min:0', 'max:120'],
            'rows.*.installment_grace_period'      => ['nullable', 'integer', 'min:0', 'max:120'],
            'rows.*.installment_count'             => ['nullable', 'integer', 'min:1', 'max:120'],
            'rows.*.payment_installment_interval'  => ['nullable', Rule::in(FixedAssetService::PAYMENT_INTERVALS)],
            'rows.*.equity_funding_rate'           => ['required', 'numeric', 'min:0', 'max:100'],
            'rows.*.interest_rate'                 => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.grace_period'                  => ['nullable', 'integer', 'min:0', 'max:600'],
            'rows.*.tenor'                         => ['nullable', 'integer', 'min:1', 'max:600'],
            'rows.*.installment_interval'          => ['nullable', Rule::in(FixedAssetService::LOAN_INTERVALS)],
            'rows.*.is_as_revenue_percentages'     => ['nullable', 'boolean'],
            'rows.*.allocations'                   => ['nullable', 'array'],
            'rows.*.allocations.*'                 => ['nullable', 'numeric', 'min:0', 'max:100'],
            'after'                                => ['nullable', Rule::in(['stay', 'next', 'list'])],
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
            $dates = $project->extended_study_dates ?? [];
            $studyEnd = $project->end_date ? StudyCalendar::indexOf($dates, $project->end_date->format('Y-m-d')) : null;
            $own = $project->fixedAssets()->pluck('id')->all();
            $productIds = $project->products()->pluck('id')->all();

            foreach ((array) $this->input('rows', []) as $i => $row) {
                if (! empty($row['id']) && ! in_array((int) $row['id'], $own, true)) {
                    $validator->errors()->add("rows.$i.id", __('costs.errors.unknownRow'));
                }

                $type = in_array($row['asset_type'] ?? '', FixedAssetService::TYPES, true) ? $row['asset_type'] : 'construction';
                // Land and other assets are bought in one month, so they have no end month.
                $oneMonth = in_array($type, ['land', 'other'], true);

                $start = ! empty($row['start']) ? StudyCalendar::indexOf($dates, $row['start']) : null;
                $end = ! $oneMonth && ! empty($row['end']) ? StudyCalendar::indexOf($dates, $row['end']) : null;
                $delivery = $type === 'machine' && ! empty($row['delivery']) ? StudyCalendar::indexOf($dates, $row['delivery']) : null;

                if (! empty($row['start']) && ($start === null || ($studyEnd !== null && $start > $studyEnd))) {
                    $validator->errors()->add("rows.$i.start", __('costs.errors.monthOutside'));
                }
                if (! $oneMonth) {
                    if (empty($row['end'])) {
                        $validator->errors()->add("rows.$i.end", __('costs.errors.endRequired'));
                    } elseif ($end === null || ($studyEnd !== null && $end > $studyEnd)) {
                        $validator->errors()->add("rows.$i.end", __('costs.errors.monthOutside'));
                    } elseif ($start !== null && $end < $start) {
                        $validator->errors()->add("rows.$i.end", __('costs.errors.endBeforeStart'));
                    }
                }
                // A machine is bought, then delivered, then ready (installed and tested), in that order.
                if ($type === 'machine') {
                    if (empty($row['delivery'])) {
                        $validator->errors()->add("rows.$i.delivery", __('costs.errors.deliveryRequired'));
                    } elseif ($delivery === null || ($studyEnd !== null && $delivery > $studyEnd)) {
                        $validator->errors()->add("rows.$i.delivery", __('costs.errors.monthOutside'));
                    } elseif (($start !== null && $delivery < $start) || ($end !== null && $delivery > $end)) {
                        $validator->errors()->add("rows.$i.delivery", __('costs.errors.datesOrder'));
                    }
                }

                $terms = $row['payment_terms'] ?? '';

                if ($terms === 'milestone') {
                    if ($type !== 'machine') {
                        $validator->errors()->add("rows.$i.payment_terms", __('costs.errors.milestoneNotMachine'));
                    } else {
                        $total = 0.0;
                        foreach (FixedAssetService::MILESTONES as $key) {
                            $total += (float) ($row['milestones'][$key]['rate'] ?? 0);
                        }
                        if (abs($total - 100) > 0.01) {
                            $validator->errors()->add("rows.$i.milestones", __('costs.errors.milestoneTotal'));
                        }
                    }
                }

                if ($terms === 'customize') {
                    $total = 0.0;
                    $days = [];
                    foreach ((array) ($row['custom'] ?? []) as $line) {
                        if (($line['rate'] ?? '') === '' || ($line['due_days'] ?? '') === '') {
                            continue;
                        }
                        $total += (float) $line['rate'];
                        $days[] = (int) $line['due_days'];
                    }
                    if (abs($total - 100) > 0.01) {
                        $validator->errors()->add("rows.$i.custom", __('costs.errors.paymentTotal'));
                    } elseif (count($days) !== count(array_unique($days))) {
                        $validator->errors()->add("rows.$i.custom", __('costs.errors.duplicateDays'));
                    }
                }

                if ($terms === 'installment' && (float) ($row['reservation_rate'] ?? 0) + (float) ($row['contractual_rate'] ?? 0) > 100) {
                    $validator->errors()->add("rows.$i.reservation_rate", __('costs.errors.installmentTotal'));
                }

                // The bank loan: the first instalment (after the grace period) must fall inside the tenor.
                if ((float) ($row['equity_funding_rate'] ?? 100) < 100) {
                    $step = LoanCalculator::intervalMonths((string) ($row['installment_interval'] ?? 'monthly'));
                    $tenor = (int) ($row['tenor'] ?? 0);
                    $grace = (int) ($row['grace_period'] ?? 0);

                    if ($tenor < 1 || intdiv($grace + $step, $step) > intdiv($tenor, $step)) {
                        $validator->errors()->add("rows.$i.grace_period", __('costs.errors.graceTooLong'));
                    }
                }

                $manufacturing = 100 - (float) ($row['admin_depreciation_percentage'] ?? 0);
                if ($manufacturing > 0 && $type !== 'land' && (int) ($row['depreciation_duration'] ?? 0) > 0 && $productIds !== [] && ! (bool) ($row['is_as_revenue_percentages'] ?? true)) {
                    $total = 0.0;
                    foreach ($productIds as $id) {
                        $total += (float) ($row['allocations'][$id] ?? 0);
                    }
                    if (abs($total - 100) > 0.01) {
                        $validator->errors()->add("rows.$i.allocations", __('costs.errors.allocationTotal'));
                    }
                }
            }
        }];
    }
}
