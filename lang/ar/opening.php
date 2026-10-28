<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — رسائل الأرصدة الافتتاحية (العربية)
//  Location: lang/ar/opening.php
//  Keep key-for-key in sync with lang/en/opening.php (a test checks it).
// ══════════════════════════════════════════════════════════════════

return [
    'saved'      => 'تم حفظ الأرصدة الافتتاحية وإعادة حساب الدراسة.',
    'unbalanced' => 'لم يتم الحفظ: يجب أن يساوي إجمالي الأصول إجمالي الالتزامات وحقوق الملكية. الفرق بينهما :amount.',

    'errors' => [
        'monthOutside'        => 'يجب أن تكون هذه الأشهر داخل مدة الدراسة.',
        'tooMuch'             => 'مجموع الأشهر أكبر من الرصيد.',
        'depreciationTooHigh' => 'لا يمكن أن يزيد مجمع الإهلاك عن الإجمالي.',
    ],

    'fields' => [
        'fixed_assets.*.name'                          => 'اسم الأصل',
        'fixed_assets.*.gross_amount'                  => 'القيمة الإجمالية',
        'fixed_assets.*.accumulated_depreciation'      => 'مجمع الإهلاك',
        'fixed_assets.*.monthly_depreciation'          => 'الإهلاك الشهري',
        'fixed_assets.*.monthly_counts'                => 'الأشهر المتبقية',
        'fixed_assets.*.admin_depreciation_percentage' => 'إهلاك الإدارة',
        'fixed_assets.*.allocations.*'                 => 'حصة المنتج',
        'cash.cash_and_bank_amount'                    => 'النقدية والبنوك',
        'cash.customer_receivable_amount'              => 'مديونية العملاء',
        'cash.settlements.*'                           => 'التحصيل',
        'other_debtors.*.name'                         => 'الاسم',
        'other_debtors.*.amount'                       => 'المبلغ',
        'other_debtors.*.settlements.*'                => 'التسوية',
        'suppliers.amount'                             => 'مستحقات الموردين',
        'suppliers.settlements.*'                      => 'السداد',
        'other_creditors.*.name'                       => 'الاسم',
        'other_creditors.*.amount'                     => 'المبلغ',
        'other_creditors.*.settlements.*'              => 'السداد',
        'taxes.vat_amount'                             => 'ضريبة القيمة المضافة المستحقة',
        'taxes.credit_withhold_taxes'                  => 'ضريبة الخصم الدائنة',
        'taxes.corporate_taxes_payable'                => 'ضريبة الشركات المستحقة',
        'loans.*.amount'                               => 'مبلغ القرض',
        'loans.*.installments.*'                       => 'القسط',
        'loans.*.interests.*'                          => 'الفائدة',
        'other_long_term.*.amount'                     => 'المبلغ',
        'other_long_term.*.settlements.*'              => 'السداد',
        'equity.paid_up_capital_amount'                => 'رأس المال المدفوع',
        'equity.legal_reserve'                         => 'الاحتياطي القانوني',
        'equity.retained_earnings'                     => 'الأرباح المحتجزة',
    ],
];
