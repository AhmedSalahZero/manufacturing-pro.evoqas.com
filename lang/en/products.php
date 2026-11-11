<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Product and raw material payment messages (English)
//  Location: lang/en/products.php
//  Keep key-for-key in sync with lang/ar/products.php (a test checks it).
// ══════════════════════════════════════════════════════════════════

return [
    'saved'         => 'The product was saved and the study was recalculated.',
    'paymentsSaved' => 'The payment terms were saved and the study was recalculated.',

    'errors' => [
        'seasonalityTotal'  => 'The monthly pattern must add up to 100%.',
        'collectionTotal'   => 'The collection terms (cash + later payments) must add up to 100%.',
        'paymentTotal'      => 'The payment terms (cash + later payments) must add up to 100%.',
        'breakdownTotal'    => 'The opening inventory split must add up to 100%.',
        'duplicateMaterial' => 'A raw material can be added to a product only once.',
        'exportOff'         => 'Export sales are switched off for this study, so the local share must be 100%. Switch export on in the study settings first.',
        'noChannel'         => 'Choose at least one sales channel for the local quantity.',
        'noMarket'          => 'Choose at least one export market for the export quantity.',
        'channelTotal'      => 'The sales channel shares of :year must add up to 100% (now :total%).',
        'marketTotal'       => 'The export market shares of :year must add up to 100% (now :total%).',
        'noFx'              => 'The market ":market" needs an exchange rate above zero for :currency in every year it exports. Enter the rates in the study settings.',
    ],

    // Names used inside automatic messages ("The :attribute must be a number").
    'fields' => [
        'max_capacity.*'                            => 'maximum capacity',
        'target_percentages.*'                      => 'target selling quantity %',
        'local_target_quantity_percentages.*'       => 'local selling quantity %',
        'local_growth_rates.*'                      => 'local price growth %',
        'local_price_per_unit.*'                    => 'local price per unit',
        'export_growth_rates.*'                     => 'export price growth %',
        'export_price_per_unit.*'                   => 'export price per unit',
        'seasonality.type'                          => 'monthly pattern',
        'seasonality.quarterly.*'                   => 'quarter percentage',
        'seasonality.monthly.*'                     => 'month percentage',
        'fg_inventory_quantity'                     => 'opening inventory quantity',
        'fg_inventory_value'                        => 'opening inventory value',
        'fg_inventory_coverage_days'                => 'inventory coverage days',
        'fg_beginning_inventory_breakdowns.*.percentage' => 'opening inventory split %',
        'comment_for_sales'                         => 'comment',
        'comment_for_raw_materials'                 => 'comment',
        'collection_policy_value.*.*.cash_payment'  => 'cash %',
        'collection_policy_value.*.*.rate.*'        => 'collection %',
        'collection_policy_value.*.*.due_in_days.*' => 'due days',
        'rawMaterials.*.raw_material_id'            => 'raw material',
        'rawMaterials.*.percentages.*'              => 'raw material % of sales',
        'rawMaterials.*.collection_policy_value.*.cash_payment'  => 'cash %',
        'rawMaterials.*.collection_policy_value.*.rate.*'        => 'payment %',
        'rawMaterials.*.collection_policy_value.*.due_in_days.*' => 'due days',
        'export_seasonality.type'                   => 'export monthly pattern',
        'export_seasonality.quarterly.*'            => 'export quarter percentage',
        'export_seasonality.monthly.*'              => 'export month percentage',
        'segments.local.*.sales_channel_id'         => 'sales channel',
        'segments.local.*.allocation_percentages.*' => 'channel share %',
        'segments.local.*.price_differences.*'      => 'price difference %',
        'segments.export.*.export_market_id'        => 'export market',
        'segments.export.*.allocation_percentages.*' => 'market share %',
        'segments.export.*.first_foreign_price'     => 'first-year price',
        'segments.export.*.foreign_growth_rates.*'  => 'price growth %',
        'segments.*.*.collection_policy_value.*.cash_payment'  => 'cash %',
        'segments.*.*.collection_policy_value.*.rate.*'        => 'collection %',
        'segments.*.*.collection_policy_value.*.due_in_days.*' => 'due days',
    ],
];
