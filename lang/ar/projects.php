<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — رسائل الدراسات (المشروعات) بالعربية
//  Location: lang/ar/projects.php
//  Keep key-for-key in sync with lang/en/projects.php (a test checks it).
// ══════════════════════════════════════════════════════════════════

return [
    'created' => 'تم إنشاء الدراسة. أكمل بياناتها في الأسفل.',
    'saved'   => 'تم حفظ الدراسة.',
    'copied'  => 'تم إنشاء النسخة ":name".',
    'excelNotReady' => 'أضف تواريخ الدراسة ومنتجاً واحداً على الأقل قبل تنزيل نموذج إكسل.',
    'deleted' => 'تم حذف الدراسة ":name".',

    'fields' => [
        'name'                                      => 'اسم الدراسة',
        'newName'                                   => 'اسم النسخة',
        'main_functional_currency'                  => 'عملة الدراسة',
        'start_date'                                => 'تاريخ بداية الدراسة',
        'duration'                                  => 'المدة',
        'operation_start_date'                      => 'تاريخ بداية التشغيل',
        'business_sector_id'                        => 'القطاع',
        'tax_rate'                                  => 'معدل ضريبة الشركات',
        'return_rate'                               => 'العائد المطلوب على الاستثمار',
        'perpetual_growth_rate'                     => 'معدل النمو الدائم',
        'intro_paragraph'                           => 'الفقرة التمهيدية',
        'products'                                  => 'المنتجات',
        'products.*.name'                           => 'اسم المنتج',
        'products.*.measurement_unit'               => 'وحدة قياس المنتج',
        'products.*.selling_start_date'             => 'تاريخ بداية البيع',
        'products.*.vat_rate'                       => 'نسبة ضريبة القيمة المضافة للمنتج',
        'products.*.withhold_tax_rate'              => 'نسبة الخصم من المنبع للمنتج',
        'rawMaterials'                              => 'المواد الخام',
        'rawMaterials.*.name'                       => 'اسم المادة الخام',
        'rawMaterials.*.rm_inventory_coverage_days' => 'أيام تغطية المخزون',
        'rawMaterials.*.measurement_unit'           => 'وحدة قياس المادة الخام',
        'rawMaterials.*.vat_rate'                   => 'نسبة ضريبة القيمة المضافة للمادة الخام',
        'rawMaterials.*.withhold_tax_rate'          => 'نسبة الخصم من المنبع للمادة الخام',
    ],

    'validation' => [
        'duration.required'                        => 'من فضلك اختر المدة.',
        'duration.between'                         => 'يجب أن تكون المدة بين :min و :max سنوات.',
        'start_date.required'                      => 'من فضلك أدخل تاريخ بداية الدراسة.',
        'operation_start_date.required'            => 'من فضلك أدخل تاريخ بداية التشغيل.',
        'operation_start_date.date_equals'         => 'في الشركة القائمة يجب أن يساوي تاريخ بداية التشغيل تاريخ بداية الدراسة.',
        'operation_start_date.after_or_equal'      => 'يجب أن يكون تاريخ بداية التشغيل في تاريخ بداية الدراسة أو بعده.',
        'operation_start_date.before'              => 'يجب أن يكون تاريخ بداية التشغيل قبل تاريخ نهاية الدراسة.',
        'tax_rate.required'                        => 'من فضلك أدخل معدل ضريبة الشركات.',
        'return_rate.required'                     => 'من فضلك أدخل العائد المطلوب على الاستثمار %.',
        'return_rate.gt'                           => 'يجب أن يكون العائد المطلوب على الاستثمار أكبر من صفر.',
        'perpetual_growth_rate.required'           => 'من فضلك أدخل معدل النمو الدائم %.',
        'perpetual_growth_rate.gt'                 => 'يجب أن يكون معدل النمو الدائم أكبر من صفر.',
        'perpetual_growth_rate.max'                => 'يجب ألا يزيد معدل النمو الدائم عن :max%.',
        'products.required'                        => 'أضف منتجًا واحدًا على الأقل.',
        'products.min'                             => 'أضف منتجًا واحدًا على الأقل.',
        'products.*.name.required'                 => 'من فضلك أدخل اسم المنتج.',
        'products.*.measurement_unit.required'     => 'من فضلك اختر وحدة قياس المنتج.',
        'products.*.selling_start_date.required'   => 'من فضلك أدخل تاريخ بداية البيع.',
        'products.*.selling_start_date.after_or_equal' => 'يجب أن يكون تاريخ بداية البيع في تاريخ بداية التشغيل أو بعده.',
        'products.*.selling_start_date.before'     => 'يجب أن يكون تاريخ بداية البيع قبل تاريخ نهاية الدراسة.',
        'rawMaterials.required'                    => 'أضف مادة خام واحدة على الأقل.',
        'rawMaterials.min'                         => 'أضف مادة خام واحدة على الأقل.',
        'rawMaterials.*.name.required'             => 'من فضلك أدخل اسم المادة الخام.',
        'rawMaterials.*.measurement_unit.required' => 'من فضلك اختر وحدة قياس المادة الخام.',
        'rawMaterials.*.rm_inventory_coverage_days.required' => 'من فضلك اختر أيام تغطية المخزون.',
    ],

    'share' => [
        'person' => 'الزميل',
        'saved'  => 'تم حفظ المشاركة.',
    ],
];
