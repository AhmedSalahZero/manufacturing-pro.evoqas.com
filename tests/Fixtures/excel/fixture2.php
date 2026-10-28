<?php
$d = require __DIR__.'/fixture1.php';
$d['fixed_assets'][0] = array_merge($d['fixed_assets'][0], ['payment_terms' => 'installment', 'reservation_rate' => 10, 'contractual_rate' => 20, 'after_months' => 2,
  'installment_grace_period' => 1, 'installment_count' => 7, 'payment_installment_interval' => 'quarterly', 'equity_funding_rate' => 30, 'interest_rate' => 12,
  'tenor' => 40, 'installment_interval' => 'monthly', 'grace_period' => 0, 'replacement_cost_rate' => 10, 'replacement_cost_interval' => 1, 'depreciation_duration' => 3, 'start_date'=>1,'end_date'=>1]);
$d['fixed_assets'][1] = array_merge($d['fixed_assets'][1], ['equity_funding_rate' => 20, 'tenor' => 120, 'installment_interval' => 'semi-annually', 'grace_period' => 12, 'start_date'=>0,'end_date'=>8]);
return $d;
