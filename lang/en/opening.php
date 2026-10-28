<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Opening balances messages (English)
//  Location: lang/en/opening.php
//  Keep key-for-key in sync with lang/ar/opening.php (a test checks it).
// ══════════════════════════════════════════════════════════════════

return [
    'saved'      => 'The opening balances were saved and the study was recalculated.',
    'unbalanced' => 'Not saved: total assets must equal total liabilities plus equity. They differ by :amount.',

    'errors' => [
        'monthOutside'        => 'These months must be inside the study.',
        'tooMuch'             => 'The months add up to more than the balance.',
        'depreciationTooHigh' => 'Accumulated depreciation cannot be more than the gross amount.',
    ],

    // Names used inside automatic messages ("The :attribute must be a number").
    'fields' => [
        'fixed_assets.*.name'                          => 'asset name',
        'fixed_assets.*.gross_amount'                  => 'gross amount',
        'fixed_assets.*.accumulated_depreciation'      => 'accumulated depreciation',
        'fixed_assets.*.monthly_depreciation'          => 'monthly depreciation',
        'fixed_assets.*.monthly_counts'                => 'months left',
        'fixed_assets.*.admin_depreciation_percentage' => 'administration depreciation',
        'fixed_assets.*.allocations.*'                 => 'product share',
        'cash.cash_and_bank_amount'                    => 'cash and banks',
        'cash.customer_receivable_amount'              => 'customer receivables',
        'cash.settlements.*'                           => 'collection',
        'other_debtors.*.name'                         => 'name',
        'other_debtors.*.amount'                       => 'amount',
        'other_debtors.*.settlements.*'                => 'settlement',
        'suppliers.amount'                             => 'supplier payables',
        'suppliers.settlements.*'                      => 'payment',
        'other_creditors.*.name'                       => 'name',
        'other_creditors.*.amount'                     => 'amount',
        'other_creditors.*.settlements.*'              => 'payment',
        'taxes.vat_amount'                             => 'VAT payable',
        'taxes.credit_withhold_taxes'                  => 'credit withholding tax',
        'taxes.corporate_taxes_payable'                => 'corporate tax payable',
        'loans.*.amount'                               => 'loan amount',
        'loans.*.installments.*'                       => 'instalment',
        'loans.*.interests.*'                          => 'interest',
        'other_long_term.*.amount'                     => 'amount',
        'other_long_term.*.settlements.*'              => 'payment',
        'equity.paid_up_capital_amount'                => 'paid-up capital',
        'equity.legal_reserve'                         => 'legal reserve',
        'equity.retained_earnings'                     => 'retained earnings',
    ],
];
