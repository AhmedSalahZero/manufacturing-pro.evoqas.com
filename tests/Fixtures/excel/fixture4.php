<?php
// An existing company with opening balances (based on fixture 3).
$d = require __DIR__.'/fixture3.php';
$d['project']['new_company'] = false;
$d['project']['operation_start_date'] = 0;
$d['project']['name'] = 'Fixture four';
foreach ($d['products'] as $i => &$pr) {
    $pr['selling_start_date'] = 0;
}
unset($pr);
$d['products'][0]['fg_inventory_quantity'] = 3000;
$d['products'][0]['fg_inventory_value'] = 70000;
$d['products'][0]['fg_beginning_inventory_breakdowns'] = ['raw_material_value' => ['value' => 30000], 'direct_labor_value' => ['value' => 15000], 'manufacturing_overheads_value' => ['value' => 25000]];
$d['products'][1]['fg_inventory_quantity'] = 1000;
$d['products'][1]['fg_inventory_value'] = 23000;
$d['products'][1]['fg_beginning_inventory_breakdowns'] = ['raw_material_value' => ['value' => 10000], 'direct_labor_value' => ['value' => 5000], 'manufacturing_overheads_value' => ['value' => 8000]];
$d['raw_materials'][0]['beginning_inventory_value'] = 40000;
$d['raw_materials'][1]['beginning_inventory_value'] = 20000;
foreach ([0 => 10, 1 => 2, 2 => 3, 3 => 2] as $i => $n) $d['manpowers'][$i]['existing_count'] = $n;
$d['expenses'][2]['category_id'] = 'general-expenses';
$inst = []; $int = [];
for ($m = 0; $m < 12; $m++) { $inst[$m] = 50000; $int[$m] = 6000 - $m * 400; }
$d['opening'] = [
    'cash' => [['id' => 1, 'cash_and_bank_amount' => 500000, 'customer_receivable_amount' => 300000, 'payload' => [0 => 100000, 1 => 100000, 2 => 100000]]],
    'other_debtors' => [['id' => 1, 'name' => 'Staff loans', 'amount' => 60000, 'payload' => [1 => 30000, 4 => 30000]]],
    'suppliers' => [['id' => 1, 'amount' => 200000, 'payload' => [0 => 100000, 2 => 100000]]],
    'other_creditors' => [['id' => 1, 'name' => 'Accrued', 'amount' => 80000, 'payload' => [3 => 80000]]],
    'taxes' => [['id' => 1, 'vat_amount' => 25000, 'credit_withhold_taxes' => 10000, 'corporate_taxes_payable' => 40000]],
    'loans' => [['id' => 1, 'amount' => 600000, 'installments' => $inst, 'interests' => $int]],
    'other_long_term' => [['id' => 1, 'amount' => 150000, 'payload' => [11 => 150000]]],
    'equity' => [['id' => 1, 'paid_up_capital_amount' => 1000000, 'legal_reserve' => 100000, 'retained_earnings' => 708000]],
    'fixed_assets' => [
        ['id' => 1, 'name' => 'Old line', 'gross_amount' => 2000000, 'accumulated_depreciation' => 800000, 'monthly_depreciation' => 16667, 'monthly_counts' => 60, 'admin_depreciation_percentage' => 20,
         'manufacturing_depreciation_percentage' => 80, 'is_as_revenue_percentages' => false, 'product_allocations' => [1 => 60, 2 => 40]],
        ['id' => 2, 'name' => 'Old building', 'gross_amount' => 1000000, 'accumulated_depreciation' => 300000, 'monthly_depreciation' => 4000, 'monthly_counts' => 40, 'admin_depreciation_percentage' => 50,
         'manufacturing_depreciation_percentage' => 50, 'is_as_revenue_percentages' => true, 'product_allocations' => []],
    ],
];
return $d;
