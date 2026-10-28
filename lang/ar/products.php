<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — رسائل المنتجات وشروط سداد الخامات (عربي)
//  Location: lang/ar/products.php
//  Keep key-for-key in sync with lang/en/products.php (a test checks it).
// ══════════════════════════════════════════════════════════════════

return [
    'saved'         => 'تم حفظ المنتج وإعادة حساب الدراسة.',
    'paymentsSaved' => 'تم حفظ شروط السداد وإعادة حساب الدراسة.',

    'errors' => [
        'seasonalityTotal'  => 'يجب أن يكون مجموع النمط الشهري 100%.',
        'collectionTotal'   => 'يجب أن يكون مجموع شروط التحصيل (النقدي + الدفعات اللاحقة) 100%.',
        'paymentTotal'      => 'يجب أن يكون مجموع شروط السداد (النقدي + الدفعات اللاحقة) 100%.',
        'breakdownTotal'    => 'يجب أن يكون مجموع توزيع المخزون الافتتاحي 100%.',
        'duplicateMaterial' => 'لا يمكن إضافة الخامة نفسها إلى المنتج أكثر من مرة.',
    ],

    // Names used inside automatic messages.
    'fields' => [
        'max_capacity.*'                            => 'الطاقة القصوى',
        'target_percentages.*'                      => 'نسبة الكمية المستهدف بيعها',
        'local_target_quantity_percentages.*'       => 'نسبة المبيعات المحلية',
        'local_growth_rates.*'                      => 'نسبة نمو السعر المحلي',
        'local_price_per_unit.*'                    => 'سعر الوحدة محلياً',
        'export_growth_rates.*'                     => 'نسبة نمو سعر التصدير',
        'export_price_per_unit.*'                   => 'سعر الوحدة للتصدير',
        'seasonality.type'                          => 'النمط الشهري',
        'seasonality.quarterly.*'                   => 'نسبة الربع',
        'seasonality.monthly.*'                     => 'نسبة الشهر',
        'fg_inventory_quantity'                     => 'كمية المخزون الافتتاحي',
        'fg_inventory_value'                        => 'قيمة المخزون الافتتاحي',
        'fg_inventory_coverage_days'                => 'أيام تغطية المخزون',
        'fg_beginning_inventory_breakdowns.*.percentage' => 'نسبة توزيع المخزون الافتتاحي',
        'comment_for_sales'                         => 'التعليق',
        'comment_for_raw_materials'                 => 'التعليق',
        'collection_policy_value.*.*.cash_payment'  => 'النسبة النقدية',
        'collection_policy_value.*.*.rate.*'        => 'نسبة التحصيل',
        'collection_policy_value.*.*.due_in_days.*' => 'أيام الاستحقاق',
        'rawMaterials.*.raw_material_id'            => 'الخامة',
        'rawMaterials.*.percentages.*'              => 'نسبة الخامة من المبيعات',
        'rawMaterials.*.collection_policy_value.*.cash_payment'  => 'النسبة النقدية',
        'rawMaterials.*.collection_policy_value.*.rate.*'        => 'نسبة السداد',
        'rawMaterials.*.collection_policy_value.*.due_in_days.*' => 'أيام الاستحقاق',
    ],
];
