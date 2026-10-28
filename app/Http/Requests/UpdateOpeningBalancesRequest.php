<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\StudyCalendar;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — checks for the opening balances form (existing companies only)
//  Location: app/Http/Requests/UpdateOpeningBalancesRequest.php
//  Amounts are never negative (except retained earnings, which can be a loss). A balance that is collected or paid over
//  time is given month by month: the months must be inside the study and together cannot exceed the balance.
//  Whether assets equal liabilities plus equity is NOT checked here: like the old application the figures are saved and
//  the screen then says by how much they differ (so a study can be filled in step by step).
// ══════════════════════════════════════════════════════════════════

class UpdateOpeningBalancesRequest extends FormRequest
{
    /** Sections with a month-by-month settlement and the key of the amount they settle. */
    private const SETTLED = [
        'other_debtors'   => ['amount', 'settlements'],
        'other_creditors' => ['amount', 'settlements'],
        'other_long_term' => ['amount', 'settlements'],
        'loans'           => ['amount', 'installments'],
    ];

    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()->can('update', $project);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:100000000000'];

        return [
            'fixed_assets'                                  => ['nullable', 'array', 'max:300'],
            'fixed_assets.*.id'                             => ['nullable', 'integer'],
            'fixed_assets.*.name'                           => ['required', 'string', 'max:255'],
            'fixed_assets.*.gross_amount'                   => $money,
            'fixed_assets.*.accumulated_depreciation'       => $money,
            'fixed_assets.*.monthly_depreciation'           => $money,
            'fixed_assets.*.monthly_counts'                 => ['nullable', 'integer', 'min:0', 'max:600'],
            'fixed_assets.*.admin_depreciation_percentage'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_assets.*.is_as_revenue_percentages'      => ['nullable', 'boolean'],
            'fixed_assets.*.allocations'                    => ['nullable', 'array'],
            'fixed_assets.*.allocations.*'                  => ['nullable', 'numeric', 'min:0', 'max:100'],

            'cash'                                          => ['nullable', 'array'],
            'cash.cash_and_bank_amount'                     => $money,
            'cash.customer_receivable_amount'               => $money,
            'cash.settlements'                              => ['nullable', 'array'],
            'cash.settlements.*'                            => ['nullable', 'numeric', 'min:0'],

            'other_debtors'                                 => ['nullable', 'array', 'max:100'],
            'other_debtors.*.id'                            => ['nullable', 'integer'],
            'other_debtors.*.name'                          => ['required', 'string', 'max:255'],
            'other_debtors.*.amount'                        => $money,
            'other_debtors.*.settlements'                   => ['nullable', 'array'],
            'other_debtors.*.settlements.*'                 => ['nullable', 'numeric', 'min:0'],

            'suppliers'                                     => ['nullable', 'array'],
            'suppliers.amount'                              => $money,
            'suppliers.settlements'                         => ['nullable', 'array'],
            'suppliers.settlements.*'                       => ['nullable', 'numeric', 'min:0'],

            'other_creditors'                               => ['nullable', 'array', 'max:100'],
            'other_creditors.*.id'                          => ['nullable', 'integer'],
            'other_creditors.*.name'                        => ['required', 'string', 'max:255'],
            'other_creditors.*.amount'                      => $money,
            'other_creditors.*.settlements'                 => ['nullable', 'array'],
            'other_creditors.*.settlements.*'               => ['nullable', 'numeric', 'min:0'],

            'taxes'                                         => ['nullable', 'array'],
            'taxes.vat_amount'                              => $money,
            'taxes.credit_withhold_taxes'                   => $money,
            'taxes.corporate_taxes_payable'                 => $money,

            'loans'                                         => ['nullable', 'array', 'max:100'],
            'loans.*.id'                                    => ['nullable', 'integer'],
            'loans.*.amount'                                => $money,
            'loans.*.installments'                          => ['nullable', 'array'],
            'loans.*.installments.*'                        => ['nullable', 'numeric', 'min:0'],
            'loans.*.interests'                             => ['nullable', 'array'],
            'loans.*.interests.*'                           => ['nullable', 'numeric', 'min:0'],

            'other_long_term'                               => ['nullable', 'array', 'max:100'],
            'other_long_term.*.id'                          => ['nullable', 'integer'],
            'other_long_term.*.amount'                      => $money,
            'other_long_term.*.settlements'                 => ['nullable', 'array'],
            'other_long_term.*.settlements.*'               => ['nullable', 'numeric', 'min:0'],

            'equity'                                        => ['nullable', 'array'],
            'equity.paid_up_capital_amount'                 => $money,
            'equity.legal_reserve'                          => $money,
            'equity.retained_earnings'                      => ['nullable', 'numeric', 'min:-100000000000', 'max:100000000000'],

            'after'                                         => ['nullable', Rule::in(['stay', 'next', 'list'])],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return trans('opening.fields');
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Project $project */
            $project = $this->route('project');
            $dates = $project->extended_study_dates ?? [];
            $studyEnd = $project->end_date ? StudyCalendar::indexOf($dates, $project->end_date->format('Y-m-d')) : null;
            $productIds = $project->products()->pluck('id')->all();

            $owned = [
                'fixed_assets'    => $project->fixedAssetOpeningBalances()->pluck('id')->all(),
                'other_debtors'   => $project->otherDebtorsOpeningBalances()->pluck('id')->all(),
                'other_creditors' => $project->otherCreditsOpeningBalances()->pluck('id')->all(),
                'loans'           => $project->longTermLoanOpeningBalances()->pluck('id')->all(),
                'other_long_term' => $project->otherLongTermLiabilitiesOpeningBalances()->pluck('id')->all(),
            ];

            foreach ($owned as $section => $ids) {
                foreach ((array) $this->input($section, []) as $i => $row) {
                    if (! empty($row['id']) && ! in_array((int) $row['id'], $ids, true)) {
                        $validator->errors()->add("$section.$i.id", __('costs.errors.unknownRow'));
                    }
                }
            }

            // Month-by-month amounts: months inside the study, and not more than the balance itself.
            $check = function (string $path, array $months, $amount) use ($validator, $studyEnd) {
                $total = 0.0;
                foreach ($months as $month => $value) {
                    if (! is_numeric($month) || (int) $month < 0 || ($studyEnd !== null && (int) $month > $studyEnd)) {
                        $validator->errors()->add($path, __('opening.errors.monthOutside'));

                        return;
                    }
                    $total += (float) $value;
                }
                if ($total > (float) $amount + 0.01) {
                    $validator->errors()->add($path, __('opening.errors.tooMuch'));
                }
            };

            foreach (self::SETTLED as $section => [$amountKey, $monthsKey]) {
                foreach ((array) $this->input($section, []) as $i => $row) {
                    $check("$section.$i.$monthsKey", (array) ($row[$monthsKey] ?? []), $row[$amountKey] ?? 0);
                }
            }
            $check('cash.settlements', (array) $this->input('cash.settlements', []), $this->input('cash.customer_receivable_amount', 0));
            $check('suppliers.settlements', (array) $this->input('suppliers.settlements', []), $this->input('suppliers.amount', 0));

            foreach ((array) $this->input('loans', []) as $i => $row) {
                foreach ((array) ($row['interests'] ?? []) as $month => $value) {
                    if (! is_numeric($month) || (int) $month < 0 || ($studyEnd !== null && (int) $month > $studyEnd)) {
                        $validator->errors()->add("loans.$i.interests", __('opening.errors.monthOutside'));
                        break;
                    }
                }
            }

            // Typed product shares of an owned asset's depreciation must add up to 100 %.
            foreach ((array) $this->input('fixed_assets', []) as $i => $row) {
                $manufacturing = 100 - (float) ($row['admin_depreciation_percentage'] ?? 0);
                $typed = ! (bool) ($row['is_as_revenue_percentages'] ?? true);

                if ($typed && $manufacturing > 0 && (float) ($row['monthly_depreciation'] ?? 0) > 0 && $productIds !== []) {
                    $total = 0.0;
                    foreach ($productIds as $id) {
                        $total += (float) (($row['allocations'] ?? [])[$id] ?? 0);
                    }
                    if (abs($total - 100) > 0.01) {
                        $validator->errors()->add("fixed_assets.$i.allocations", __('costs.errors.allocationTotal'));
                    }
                }

                if ((float) ($row['accumulated_depreciation'] ?? 0) > (float) ($row['gross_amount'] ?? 0) + 0.01) {
                    $validator->errors()->add("fixed_assets.$i.accumulated_depreciation", __('opening.errors.depreciationTooHigh'));
                }
            }
        }];
    }
}
