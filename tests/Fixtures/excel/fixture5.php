<?php
// Fixture 5: the study of fixture 1 with ten fixed assets that cover every kind (land, construction flat and on an S-curve, machines, other)
// and every way of paying (cash, customised, instalments, milestones), with and without a bank loan.
$d = require __DIR__.'/fixture1.php';
$base = ['counts' => 1, 'depreciation_duration' => 5, 'admin_depreciation_percentage' => 20, 'manufacturing_depreciation_percentage' => 80,
  'replacement_cost_rate' => 0, 'replacement_cost_interval' => 3, 'payment_terms' => 'cash', 'custom_collection_policy' => [], 'from_total_or_executions' => [],
  'reservation_rate' => 0, 'contractual_rate' => 0, 'after_months' => 0, 'installment_grace_period' => 0, 'installment_count' => 1, 'payment_installment_interval' => 'monthly',
  'equity_funding_rate' => 100, 'interest_rate' => 0, 'tenor' => 60, 'installment_interval' => 'monthly', 'grace_period' => 0, 'is_as_revenue_percentages' => true, 'product_allocations' => [],
  'asset_type' => 'construction', 'execution_curve' => 'flat', 'delivery_date' => null, 'milestone_payments' => null];
$ms = fn ($p, $pd, $d, $dd, $r, $rd) => ['purchase' => ['rate' => $p, 'days' => $pd], 'delivery' => ['rate' => $d, 'days' => $dd], 'ready' => ['rate' => $r, 'days' => $rd]];
$assets = [
  ['name' => 'Plot', 'asset_type' => 'land', 'amount' => 900000, 'start_date' => 3, 'end_date' => 8, 'depreciation_duration' => 5, 'replacement_cost_rate' => 10, 'payment_terms' => 'customize', 'custom_collection_policy' => [0 => 40, 60 => 60], 'from_total_or_executions' => [0 => 0, 60 => 0]],
  ['name' => 'Factory hall', 'asset_type' => 'construction', 'execution_curve' => 's_curve', 'amount' => 10000000, 'start_date' => 1, 'end_date' => 10, 'depreciation_duration' => 20, 'payment_terms' => 'customize',
   'custom_collection_policy' => [0 => 25, 30 => 50, 60 => 25], 'from_total_or_executions' => [0 => 1, 30 => 0, 60 => 0], 'equity_funding_rate' => 50, 'interest_rate' => 14, 'tenor' => 60, 'grace_period' => 12, 'installment_interval' => 'quarterly'],
  ['name' => 'Production line', 'asset_type' => 'machine', 'counts' => 2, 'amount' => 600000, 'start_date' => 12, 'delivery_date' => 18, 'end_date' => 20, 'depreciation_duration' => 10, 'replacement_cost_rate' => 15, 'replacement_cost_interval' => 2,
   'payment_terms' => 'milestone', 'milestone_payments' => $ms(50, 0, 30, 0, 20, 0), 'equity_funding_rate' => 40, 'interest_rate' => 12, 'tenor' => 36, 'grace_period' => 6, 'installment_interval' => 'quarterly'],
  ['name' => 'Packing line', 'asset_type' => 'machine', 'amount' => 800000, 'start_date' => 4, 'delivery_date' => 8, 'end_date' => 10, 'depreciation_duration' => 8,
   'payment_terms' => 'milestone', 'milestone_payments' => $ms(30, 0, 50, 30, 20, 60)],
  ['name' => 'Cash machine', 'asset_type' => 'machine', 'amount' => 300000, 'start_date' => 2, 'delivery_date' => 5, 'end_date' => 6, 'depreciation_duration' => 5, 'payment_terms' => 'cash'],
  ['name' => 'Custom machine', 'asset_type' => 'machine', 'amount' => 500000, 'start_date' => 3, 'delivery_date' => 7, 'end_date' => 9, 'depreciation_duration' => 6, 'payment_terms' => 'customize',
   'custom_collection_policy' => [0 => 40, 60 => 60], 'from_total_or_executions' => [0 => 0, 60 => 0], 'equity_funding_rate' => 60, 'interest_rate' => 10, 'tenor' => 24, 'grace_period' => 3],
  ['name' => 'Instalment machine', 'asset_type' => 'machine', 'amount' => 700000, 'start_date' => 6, 'delivery_date' => 6, 'end_date' => 8, 'depreciation_duration' => 7, 'payment_terms' => 'installment',
   'reservation_rate' => 20, 'contractual_rate' => 30, 'after_months' => 2, 'installment_count' => 6, 'installment_grace_period' => 1],
  ['name' => 'Forklift', 'asset_type' => 'other', 'amount' => 250000, 'start_date' => 5, 'end_date' => 11, 'depreciation_duration' => 3, 'payment_terms' => 'installment', 'reservation_rate' => 25, 'contractual_rate' => 0, 'installment_count' => 4,
   'equity_funding_rate' => 70, 'interest_rate' => 18, 'tenor' => 12, 'grace_period' => 3],
  ['name' => 'Hall flat', 'asset_type' => 'construction', 'amount' => 2400000, 'start_date' => 2, 'end_date' => 7, 'depreciation_duration' => 15, 'payment_terms' => 'customize', 'custom_collection_policy' => [0 => 100], 'from_total_or_executions' => [0 => 0]],
  ['name' => 'Short machine', 'asset_type' => 'machine', 'amount' => 120000, 'start_date' => 9, 'delivery_date' => 9, 'end_date' => 9, 'depreciation_duration' => 4, 'payment_terms' => 'milestone', 'milestone_payments' => $ms(100, 0, 0, 0, 0, 0)],
];
foreach ($assets as $i => &$a) { $a = array_merge($base, ['id' => $i + 1], $a); }
unset($a);
$d['fixed_assets'] = $assets;
return $d;
