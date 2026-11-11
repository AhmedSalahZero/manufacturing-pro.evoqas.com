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
        'exportOff'         => 'مبيعات التصدير موقوفة في هذه الدراسة، لذلك يجب أن تكون نسبة البيع المحلي 100%. فعّل التصدير في إعدادات الدراسة أولاً.',
        'noChannel'         => 'اختر قناة بيع واحدة على الأقل للكمية المحلية.',
        'noMarket'          => 'اختر سوق تصدير واحداً على الأقل لكمية التصدير.',
        'channelTotal'      => 'يجب أن يكون مجموع نسب قنوات البيع لسنة :year يساوي 100% (الآن :total%).',
        'marketTotal'       => 'يجب أن يكون مجموع نسب أسواق التصدير لسنة :year يساوي 100% (الآن :total%).',
        'noFx'              => 'السوق ":market" يحتاج إلى سعر صرف أكبر من صفر للعملة :currency في كل سنة يصدّر فيها. أدخل الأسعار في إعدادات الدراسة.',
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
        'export_seasonality.type'                   => 'النمط الشهري للتصدير',
        'export_seasonality.quarterly.*'            => 'نسبة ربع التصدير',
        'export_seasonality.monthly.*'              => 'نسبة شهر التصدير',
        'segments.local.*.sales_channel_id'         => 'قناة البيع',
        'segments.local.*.allocation_percentages.*' => 'نسبة القناة %',
        'segments.local.*.price_differences.*'      => 'فرق السعر %',
        'segments.export.*.export_market_id'        => 'سوق التصدير',
        'segments.export.*.allocation_percentages.*' => 'نسبة السوق %',
        'segments.export.*.first_foreign_price'     => 'سعر السنة الأولى',
        'segments.export.*.foreign_growth_rates.*'  => 'نمو السعر %',
        'segments.*.*.collection_policy_value.*.cash_payment'  => 'النقد %',
        'segments.*.*.collection_policy_value.*.rate.*'        => 'نسبة التحصيل %',
        'segments.*.*.collection_policy_value.*.due_in_days.*' => 'أيام الاستحقاق',
    ],
];
