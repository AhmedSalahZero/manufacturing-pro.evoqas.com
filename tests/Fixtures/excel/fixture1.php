<?php
use App\Calculations\ProductSalesCalculator;
// A new company: 2 products, 2 raw materials, staff, 5 expenses, 2 fixed assets (one with a loan), 3 years from 2026-01.
$yrs = fn (array $v) => $v;   // year index => value
$pol = fn ($cash, $r1, $d1, $r2, $d2) => ['cash_payment' => $cash, 'rate' => [$r1, $r2], 'due_in_days' => [$d1, $d2]];
$study = [
    'project' => [
        'id' => 1, 'name' => 'Fixture one', 'new_company' => true, 'start_date' => '2026-01-01', 'duration' => 3, 'operation_start_date' => 6,
        'tax_rate' => 22.5, 'perpetual_growth_rate' => 3, 'return_rate' => 18,
        'salaries_annual_increase' => 10, 'salaries_tax_rate' => 8, 'social_insurance_rate' => 12,
        'manpower_allocations' => ['direct_labor' => [1 => 60, 2 => 40], 'manufacturing_overheads' => [1 => 50, 2 => 50]],
        'manpower_is_as_revenue_percentages' => ['direct_labor' => false, 'manufacturing_overheads' => true],
    ],
    'products' => [
        [
            'id' => 1, 'name' => 'Juice', 'selling_start_date' => 6, 'seasonality' => ['type' => 'quarterly', 'percentages' => [20, 30, 35, 15]],
            'max_capacity' => [0 => 100000, 1 => 200000, 2 => 250000], 'target_percentages' => [0 => 70, 1 => 75, 2 => 76],
            'local_target_quantity_percentages' => [0 => 85.7143, 1 => 80, 2 => 78.9474],
            'local_price_per_unit' => [0 => 25, 1 => 27, 2 => 29],
            'export_price_per_unit' => [0 => 20, 1 => 21, 2 => 22],
            'fg_inventory_quantity' => 0, 'fg_inventory_coverage_days' => 45, 'fg_inventory_value' => 0, 'fg_beginning_inventory_breakdowns' => [],
            'vat_rate' => 14, 'withhold_tax_rate' => 1,
            'collection_policy_value' => [
                'local' => [$pol(20, 50, 30, 30, 75), $pol(10, 60, 45, 30, 90)],
                'export' => [$pol(50, 50, 60, 0, null), $pol(100, 0, null, 0, null)],
            ],
            'recipe' => [['raw_material_id' => 1, 'percentages' => [0 => 30, 1 => 31, 2 => 31]], ['raw_material_id' => 2, 'percentages' => [0 => 10, 1 => 10, 2 => 11]]],
        ],
        [
            'id' => 2, 'name' => 'Jam', 'selling_start_date' => 9, 'seasonality' => ['type' => 'monthly', 'percentages' => [5, 5, 6, 7, 8, 9, 10, 11, 12, 10, 9, 8]],
            'max_capacity' => [0 => 40000, 1 => 100000, 2 => 120000], 'target_percentages' => [0 => 50, 1 => 70, 2 => 75],
            'local_target_quantity_percentages' => [0 => 100, 1 => 100, 2 => 100],
            'local_price_per_unit' => [0 => 40, 1 => 42, 2 => 44],
            'export_price_per_unit' => [0 => 0, 1 => 0, 2 => 0],
            'fg_inventory_quantity' => 0, 'fg_inventory_coverage_days' => 20, 'fg_inventory_value' => 0, 'fg_beginning_inventory_breakdowns' => [],
            'vat_rate' => 14, 'withhold_tax_rate' => 0,
            'collection_policy_value' => [
                'local' => [$pol(100, 0, null, 0, null), $pol(100, 0, null, 0, null)],
                'export' => [$pol(100, 0, null, 0, null), $pol(100, 0, null, 0, null)],
            ],
            'recipe' => [['raw_material_id' => 1, 'percentages' => [0 => 20, 1 => 20, 2 => 20]]],
        ],
    ],
    'raw_materials' => [
        ['id' => 1, 'name' => 'Fruit', 'beginning_inventory_value' => 0, 'rm_inventory_coverage_days' => 40, 'vat_rate' => 14, 'withhold_tax_rate' => 1,
         'collection_policy_value' => [$pol(30, 40, 30, 30, 60), $pol(20, 40, 45, 40, 90)]],
        ['id' => 2, 'name' => 'Packaging', 'beginning_inventory_value' => 0, 'rm_inventory_coverage_days' => 25, 'vat_rate' => 14, 'withhold_tax_rate' => 0,
         'collection_policy_value' => [$pol(100, 0, null, 0, null), $pol(100, 0, null, 0, null)]],
    ],
    'manpowers' => [
        ['id' => 1, 'name' => 'Operators', 'type' => 'direct_labor', 'existing_count' => 0, 'avg_salary' => 6000, 'hirings' => [3 => 4, 6 => 6, 18 => 4]],
        ['id' => 2, 'name' => 'Supervisors', 'type' => 'manufacturing_overheads', 'existing_count' => 0, 'avg_salary' => 12000, 'hirings' => [3 => 1, 6 => 1]],
        ['id' => 3, 'name' => 'Sales reps', 'type' => 'sales', 'existing_count' => 0, 'avg_salary' => 9000, 'hirings' => [6 => 2, 12 => 2]],
        ['id' => 4, 'name' => 'Managers', 'type' => 'general', 'existing_count' => 0, 'avg_salary' => 25000, 'hirings' => [0 => 1, 6 => 1]],
    ],
    'expenses' => [
        ['id' => 1, 'name' => 'Rent', 'category_id' => 'general-expenses', 'relation_name' => 'fixed_monthly_repeating_amount', 'start_date' => 0, 'end_date' => 36, 'amount' => 30000,
         'increase_interval' => 'annually', 'increase_rate' => 8, 'is_deductible' => false, 'vat_rate' => 0, 'withhold_tax_rate' => 0, 'payment_terms' => 'quarterly', 'custom_collection_policy' => [],
         'is_as_revenue_percentages' => true, 'products' => [], 'product_allocations' => []],
        ['id' => 2, 'name' => 'Factory utilities', 'category_id' => 'manufacturing-expenses', 'relation_name' => 'fixed_monthly_repeating_amount', 'start_date' => 6, 'end_date' => 36, 'amount' => 18000,
         'increase_interval' => 'semi-annually', 'increase_rate' => 5, 'is_deductible' => false, 'vat_rate' => 0, 'withhold_tax_rate' => 0, 'payment_terms' => 'customize', 'custom_collection_policy' => [0 => 40, 30 => 60],
         'is_as_revenue_percentages' => false, 'products' => [], 'product_allocations' => [1 => 70, 2 => 30]],
        ['id' => 3, 'name' => 'Brand launch', 'category_id' => 'start-up-expenses', 'relation_name' => 'one_time_expense', 'start_date' => 2, 'end_date' => 2, 'amount' => 120000, 'amortization_months' => 12,
         'is_deductible' => false, 'vat_rate' => 0, 'withhold_tax_rate' => 0, 'payment_terms' => 'customize', 'custom_collection_policy' => [0 => 50, 30 => 25, 60 => 25],
         'is_as_revenue_percentages' => true, 'products' => [], 'product_allocations' => []],
        ['id' => 4, 'name' => 'Sales commission', 'category_id' => 'sales', 'relation_name' => 'expense_as_percentage', 'start_date' => 6, 'end_date' => 36, 'monthly_percentage' => 2.5,
         'is_deductible' => false, 'vat_rate' => 0, 'withhold_tax_rate' => 0, 'payment_terms' => 'cash', 'custom_collection_policy' => [],
         'is_as_revenue_percentages' => true, 'products' => [1, 2], 'product_allocations' => []],
        ['id' => 5, 'name' => 'Freight', 'category_id' => 'other-operation-expenses', 'relation_name' => 'cost_per_unit', 'start_date' => 6, 'end_date' => 36, 'monthly_cost_of_unit' => 0.8,
         'is_deductible' => false, 'vat_rate' => 0, 'withhold_tax_rate' => 0, 'payment_terms' => 'annually', 'custom_collection_policy' => [],
         'is_as_revenue_percentages' => true, 'products' => [1], 'product_allocations' => []],
    ],
    'fixed_assets' => [
        ['id' => 1, 'name' => 'Office fit-out', 'counts' => 1, 'amount' => 400000, 'start_date' => 2, 'end_date' => 4, 'depreciation_duration' => 5,
         'admin_depreciation_percentage' => 100, 'manufacturing_depreciation_percentage' => 0, 'replacement_cost_rate' => 0, 'replacement_cost_interval' => 5,
         'payment_terms' => 'cash', 'custom_collection_policy' => [], 'from_total_or_executions' => [], 'reservation_rate' => 0, 'contractual_rate' => 0, 'after_months' => 0,
         'installment_grace_period' => 0, 'installment_count' => 1, 'payment_installment_interval' => 'monthly',
         'equity_funding_rate' => 100, 'interest_rate' => 0, 'tenor' => 0, 'installment_interval' => 'monthly', 'grace_period' => 0,
         'is_as_revenue_percentages' => true, 'product_allocations' => []],
        ['id' => 2, 'name' => 'Production line', 'counts' => 2, 'amount' => 2500000, 'start_date' => 0, 'end_date' => 5, 'depreciation_years' => 0, 'depreciation_duration' => 10,
         'admin_depreciation_percentage' => 10, 'manufacturing_depreciation_percentage' => 90, 'replacement_cost_rate' => 15, 'replacement_cost_interval' => 2,
         'payment_terms' => 'customize', 'custom_collection_policy' => [0 => 30, 60 => 20, 90 => 50], 'from_total_or_executions' => [0 => true, 60 => false, 90 => true],
         'reservation_rate' => 0, 'contractual_rate' => 0, 'after_months' => 0, 'installment_grace_period' => 0, 'installment_count' => 1, 'payment_installment_interval' => 'monthly',
         'equity_funding_rate' => 40, 'interest_rate' => 16, 'tenor' => 36, 'installment_interval' => 'quarterly', 'grace_period' => 6,
         'is_as_revenue_percentages' => false, 'product_allocations' => [1 => 55, 2 => 45]],
    ],
    'opening' => [],
];

$ext = function (array $v) { $v[3] = $v[2]; return $v; };
foreach ($study['products'] as &$pr) {
    foreach (['max_capacity', 'target_percentages', 'local_target_quantity_percentages', 'local_price_per_unit', 'export_price_per_unit'] as $f) $pr[$f] = $ext($pr[$f]);
    foreach ($pr['recipe'] as &$line) $line['percentages'] = $ext($line['percentages']);
    unset($line);
    $q = ProductSalesCalculator::yearlyQuantities($pr['max_capacity'], $pr['target_percentages'], $pr['local_target_quantity_percentages']);
    $pr['local_target_quantities'] = $q['local']; $pr['export_target_quantities'] = $q['export'];
}
unset($pr);
return $study;
