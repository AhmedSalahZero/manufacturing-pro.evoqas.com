<?php
use App\Calculations\ProductSalesCalculator;
// Fixture one with sales channels and export markets: Juice has two local channels (one on its own collection terms) and two export
// markets (USD and EUR); Jam has export markets only (so its local sales are the implicit channel). Exchange rates are forecast per year.
$study = require __DIR__.'/fixture1.php';
$pol = fn ($cash, $r1, $d1, $r2, $d2) => ['cash_payment' => $cash, 'rate' => [$r1, $r2], 'due_in_days' => [$d1, $d2]];
$yrs = fn (float|int ...$v) => $v;

$study['fx_rates'] = [
    ['currency' => 'usd', 'rates' => [0 => 50, 1 => 52, 2 => 54, 3 => 54]],
    ['currency' => 'euro', 'rates' => [0 => 55, 1 => 57, 2 => 59, 3 => 59]],
];

$study['products'][0]['segments'] = [
    ['kind' => 'local', 'name' => 'Supermarkets', 'currency' => null, 'allocation_percentages' => $yrs(60, 60, 60, 60), 'price_differences' => $yrs(5, 5, 4, 4),
     'first_foreign_price' => 0, 'foreign_growth_rates' => [], 'uses_own_terms' => false, 'collection_policy_value' => []],
    ['kind' => 'local', 'name' => 'Wholesalers', 'currency' => null, 'allocation_percentages' => $yrs(40, 40, 40, 40), 'price_differences' => $yrs(-5, -5, -5, -5),
     'first_foreign_price' => 0, 'foreign_growth_rates' => [], 'uses_own_terms' => true, 'collection_policy_value' => [$pol(10, 50, 40, 40, 80), $pol(0, 60, 45, 40, 90)]],
    ['kind' => 'export', 'name' => 'Saudi Arabia', 'currency' => 'usd', 'allocation_percentages' => $yrs(70, 70, 70, 70), 'price_differences' => [],
     'first_foreign_price' => 0.5, 'foreign_growth_rates' => $yrs(0, 3, 3, 3), 'uses_own_terms' => true, 'collection_policy_value' => [$pol(50, 50, 60, 0, null), $pol(100, 0, null, 0, null)]],
    ['kind' => 'export', 'name' => 'Italy', 'currency' => 'euro', 'allocation_percentages' => $yrs(30, 30, 30, 30), 'price_differences' => [],
     'first_foreign_price' => 0.45, 'foreign_growth_rates' => $yrs(0, 2, 2, 2), 'uses_own_terms' => false, 'collection_policy_value' => []],
];
$study['products'][0]['export_seasonality'] = ['type' => 'monthly', 'percentages' => [2, 2, 3, 5, 8, 12, 15, 15, 12, 10, 8, 8]];

// Jam: export markets only. 30% of its quantity is exported.
$study['products'][1]['local_target_quantity_percentages'] = [0 => 70, 1 => 70, 2 => 70, 3 => 70];
$study['products'][1]['segments'] = [
    ['kind' => 'export', 'name' => 'Italy', 'currency' => 'euro', 'allocation_percentages' => $yrs(100, 100, 100, 100), 'price_differences' => [],
     'first_foreign_price' => 0.9, 'foreign_growth_rates' => $yrs(0, 0, 5, 5), 'uses_own_terms' => false, 'collection_policy_value' => []],
];
$study['products'][1]['export_price_per_unit'] = [0 => 38, 1 => 38, 2 => 38, 3 => 38];

foreach ($study['products'] as &$pr) {
    $q = ProductSalesCalculator::yearlyQuantities($pr['max_capacity'], $pr['target_percentages'], $pr['local_target_quantity_percentages']);
    $pr['local_target_quantities'] = $q['local'];
    $pr['export_target_quantities'] = $q['export'];
}
unset($pr);

return $study;
