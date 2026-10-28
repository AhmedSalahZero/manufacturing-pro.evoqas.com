<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — رسائل العمالة والمصروفات (العربية)
//  Location: lang/ar/costs.php
//  Keep key-for-key in sync with lang/en/costs.php (a test checks it).
// ══════════════════════════════════════════════════════════════════

return [
    'manpowerSaved' => 'تم حفظ خطة العمالة وإعادة حساب الدراسة.',
    'expensesSaved' => 'تم حفظ المصروفات وإعادة حساب الدراسة.',
    'assetsSaved'   => 'تم حفظ الأصول الثابتة وإعادة حساب الدراسة.',

    'errors' => [
        'levyTotal'       => 'يجب أن يكون مجموع ضريبة الدخل والتأمينات الاجتماعية أقل من 100%.',
        'unknownRow'      => 'هذا البند لا يخص الدراسة. يرجى إعادة تحميل الصفحة.',
        'hiringMonth'     => 'يمكن إدخال التعيينات لأشهر التشغيل فقط.',
        'allocationTotal' => 'يجب أن يكون مجموع حصص المنتجات 100%.',
        'monthOutside'    => 'هذا الشهر خارج مدة الدراسة.',
        'endBeforeStart'  => 'لا يمكن أن يكون شهر النهاية قبل شهر البداية.',
        'products'        => 'اختر منتجًا واحدًا على الأقل من هذه الدراسة.',
        'paymentTotal'    => 'يجب أن يكون مجموع الدفعات 100%.',
        'duplicateDays'   => 'يمكن استخدام كل يوم استحقاق مرة واحدة فقط.',
        'installmentTotal' => 'لا يجوز أن يتجاوز مجموع الحجز والدفعة التعاقدية 100%.',
        'graceTooLong'    => 'فترة السماح أطول من اللازم بالنسبة لمدة القرض: يجب أن يقع القسط الأول داخلها.',
    ],

    'fields' => [
        'salaries_annual_increase'    => 'الزيادة السنوية للرواتب',
        'salaries_tax_rate'           => 'ضريبة الدخل على الرواتب',
        'social_insurance_rate'       => 'التأمينات الاجتماعية',
        'rows'                        => 'البنود',
        'rows.*.type'                 => 'نوع العمالة',
        'rows.*.position'             => 'الوظيفة',
        'rows.*.avg_salary'           => 'صافي الراتب الشهري',
        'rows.*.existing_count'       => 'العدد الحالي',
        'rows.*.hirings'              => 'التعيينات',
        'rows.*.hirings.*'            => 'التعيينات',
        'allocations.*.*'             => 'حصة المنتج',
        'rows.*.relation_name'        => 'نوع المصروف',
        'rows.*.category_id'          => 'الفئة',
        'rows.*.name'                 => 'اسم المصروف',
        'rows.*.amount'               => 'المبلغ',
        'rows.*.start'                => 'شهر البداية',
        'rows.*.end'                  => 'شهر النهاية',
        'rows.*.increase_rate'        => 'الزيادة السنوية',
        'rows.*.monthly_percentage'   => 'النسبة من المبيعات',
        'rows.*.monthly_cost_of_unit' => 'التكلفة لكل وحدة',
        'rows.*.amortization_months'  => 'عدد الأشهر',
        'rows.*.products'             => 'المنتجات',
        'rows.*.payment_terms'        => 'شروط السداد',
        'rows.*.custom.*.due_days'    => 'أيام السداد',
        'rows.*.custom.*.rate'        => 'نسبة السداد %',
        'rows.*.allocations.*'        => 'حصة المنتج',
        'rows.*.counts' => 'العدد',
        'rows.*.depreciation_duration' => 'سنوات الإهلاك',
        'rows.*.admin_depreciation_percentage' => 'نسبة الإهلاك الإداري',
        'rows.*.replacement_cost_rate' => 'نسبة تكلفة الإحلال',
        'rows.*.replacement_cost_interval' => 'فترة الإحلال',
        'rows.*.custom.*.from_total' => 'أساس الدفعة',
        'rows.*.reservation_rate' => 'نسبة الحجز',
        'rows.*.contractual_rate' => 'نسبة الدفعة التعاقدية',
        'rows.*.after_months' => 'بعد عدد الأشهر',
        'rows.*.installment_grace_period' => 'فترة سماح الأقساط',
        'rows.*.installment_count' => 'عدد الأقساط',
        'rows.*.payment_installment_interval' => 'فترة الأقساط',
        'rows.*.equity_funding_rate' => 'نسبة التمويل الذاتي',
        'rows.*.interest_rate' => 'نسبة الفائدة',
        'rows.*.grace_period' => 'فترة السماح',
        'rows.*.tenor' => 'مدة القرض',
        'rows.*.installment_interval' => 'فترة أقساط القرض',
    ],
];
