<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Study (project) messages (English)
//  Location: lang/en/projects.php
//  Keep key-for-key in sync with lang/ar/projects.php (a test checks it).
// ══════════════════════════════════════════════════════════════════

return [
    'created' => 'The study was created. Fill in its details below.',
    'saved'   => 'The study was saved.',
    'copied'  => 'The copy ":name" was created.',
    'deleted' => 'The study ":name" was deleted.',
    'excelNotReady' => 'Add the study dates and at least one product before downloading the Excel model.',

    // Names used inside automatic messages ("The :attribute field is required").
    'fields' => [
        'name'                                      => 'study name',
        'newName'                                   => 'name of the copy',
        'main_functional_currency'                  => 'study currency',
        'start_date'                                => 'study start date',
        'duration'                                  => 'duration',
        'operation_start_date'                      => 'operation start date',
        'business_sector_id'                        => 'business sector',
        'tax_rate'                                  => 'corporate tax rate',
        'return_rate'                               => 'required investment return',
        'perpetual_growth_rate'                     => 'perpetual growth rate',
        'intro_paragraph'                           => 'intro paragraph',
        'products'                                  => 'products',
        'products.*.name'                           => 'product name',
        'products.*.measurement_unit'               => 'product measurement unit',
        'products.*.selling_start_date'             => 'selling start date',
        'products.*.vat_rate'                       => 'product VAT rate',
        'products.*.withhold_tax_rate'              => 'product withholding tax rate',
        'rawMaterials'                              => 'raw materials',
        'rawMaterials.*.name'                       => 'raw material name',
        'rawMaterials.*.rm_inventory_coverage_days' => 'inventory coverage days',
        'rawMaterials.*.measurement_unit'           => 'raw material measurement unit',
        'rawMaterials.*.vat_rate'                   => 'raw material VAT rate',
        'rawMaterials.*.withhold_tax_rate'          => 'raw material withholding tax rate',
    ],

    'validation' => [
        'duration.required'                        => 'Please select the duration.',
        'duration.between'                         => 'The duration must be between :min and :max years.',
        'start_date.required'                      => 'Please enter the study start date.',
        'operation_start_date.required'            => 'Please enter the operation start date.',
        'operation_start_date.date_equals'         => 'For an existing company the operation start date must equal the study start date.',
        'operation_start_date.after_or_equal'      => 'The operation start date must be on or after the study start date.',
        'operation_start_date.before'              => 'The operation start date must be before the study end date.',
        'tax_rate.required'                        => 'Please enter the corporate tax rate.',
        'return_rate.required'                     => 'Please enter the required investment return %.',
        'return_rate.gt'                           => 'The required investment return must be greater than zero.',
        'perpetual_growth_rate.required'           => 'Please enter the perpetual growth rate %.',
        'perpetual_growth_rate.gt'                 => 'The perpetual growth rate must be greater than zero.',
        'perpetual_growth_rate.max'                => 'The perpetual growth rate must not be more than :max%.',
        'products.required'                        => 'Add at least one product.',
        'products.min'                             => 'Add at least one product.',
        'products.*.name.required'                 => 'Please enter the product name.',
        'products.*.measurement_unit.required'     => 'Please select the product measurement unit.',
        'products.*.selling_start_date.required'   => 'Please enter the selling start date.',
        'products.*.selling_start_date.after_or_equal' => 'The selling start date must be on or after the operation start date.',
        'products.*.selling_start_date.before'     => 'The selling start date must be before the study end date.',
        'rawMaterials.required'                    => 'Add at least one raw material.',
        'rawMaterials.min'                         => 'Add at least one raw material.',
        'rawMaterials.*.name.required'             => 'Please enter the raw material name.',
        'rawMaterials.*.measurement_unit.required' => 'Please select the raw material measurement unit.',
        'rawMaterials.*.rm_inventory_coverage_days.required' => 'Please select the inventory coverage days.',
    ],

    'share' => [
        'person' => 'colleague',
        'saved'  => 'The sharing was saved.',
    ],
];
