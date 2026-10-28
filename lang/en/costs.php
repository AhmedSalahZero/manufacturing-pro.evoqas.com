<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Staff and expenses messages (English)
//  Location: lang/en/costs.php
//  Keep key-for-key in sync with lang/ar/costs.php (a test checks it).
// ══════════════════════════════════════════════════════════════════

return [
    'manpowerSaved' => 'The manpower plan was saved and the study was recalculated.',
    'expensesSaved' => 'The expenses were saved and the study was recalculated.',
    'assetsSaved'   => 'The fixed assets were saved and the study was recalculated.',

    'errors' => [
        'levyTotal'       => 'Income tax and social insurance together must be less than 100%.',
        'unknownRow'      => 'This line does not belong to the study. Please reload the page.',
        'hiringMonth'     => 'Hirings can only be entered for the months of operation.',
        'allocationTotal' => 'The product shares must add up to 100%.',
        'monthOutside'    => 'This month is outside the study.',
        'endBeforeStart'  => 'The end month cannot be before the start month.',
        'products'        => 'Choose at least one product of this study.',
        'paymentTotal'    => 'The payments must add up to 100%.',
        'duplicateDays'   => 'Each payment day can be used once.',
        'installmentTotal' => 'Reservation and contractual payment together cannot exceed 100%.',
        'graceTooLong'    => 'The grace period is too long for the tenor: the first instalment must fall inside it.',
    ],

    // Names used inside automatic messages ("The :attribute must be a number").
    'fields' => [
        'salaries_annual_increase'    => 'yearly salary increase',
        'salaries_tax_rate'           => 'income tax on salaries',
        'social_insurance_rate'       => 'social insurance',
        'rows'                        => 'lines',
        'rows.*.type'                 => 'staff type',
        'rows.*.position'             => 'position',
        'rows.*.avg_salary'           => 'net monthly salary',
        'rows.*.existing_count'       => 'existing count',
        'rows.*.hirings'              => 'hirings',
        'rows.*.hirings.*'            => 'hirings',
        'allocations.*.*'             => 'product share',
        'rows.*.relation_name'        => 'kind of expense',
        'rows.*.category_id'          => 'category',
        'rows.*.name'                 => 'expense name',
        'rows.*.amount'               => 'amount',
        'rows.*.start'                => 'start month',
        'rows.*.end'                  => 'end month',
        'rows.*.increase_rate'        => 'yearly increase',
        'rows.*.monthly_percentage'   => 'percentage of sales',
        'rows.*.monthly_cost_of_unit' => 'cost per unit',
        'rows.*.amortization_months'  => 'number of months',
        'rows.*.products'             => 'products',
        'rows.*.payment_terms'        => 'payment terms',
        'rows.*.custom.*.due_days'    => 'payment days',
        'rows.*.custom.*.rate'        => 'payment %',
        'rows.*.allocations.*'        => 'product share',
        'rows.*.counts' => 'count',
        'rows.*.depreciation_duration' => 'depreciation years',
        'rows.*.admin_depreciation_percentage' => 'administration depreciation %',
        'rows.*.replacement_cost_rate' => 'replacement cost %',
        'rows.*.replacement_cost_interval' => 'replacement interval',
        'rows.*.custom.*.from_total' => 'payment basis',
        'rows.*.reservation_rate' => 'reservation %',
        'rows.*.contractual_rate' => 'contractual %',
        'rows.*.after_months' => 'after months',
        'rows.*.installment_grace_period' => 'instalment grace period',
        'rows.*.installment_count' => 'instalment count',
        'rows.*.payment_installment_interval' => 'instalment interval',
        'rows.*.equity_funding_rate' => 'equity funding %',
        'rows.*.interest_rate' => 'interest %',
        'rows.*.grace_period' => 'grace period',
        'rows.*.tenor' => 'tenor',
        'rows.*.installment_interval' => 'loan instalment interval',
    ],
];
