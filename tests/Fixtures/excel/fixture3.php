<?php
$d = require __DIR__.'/fixture2.php';
foreach ($d['products'] as &$pr) {
    foreach (['local_price_per_unit', 'export_price_per_unit'] as $f) $pr[$f] = array_map(fn ($v) => $v * 3, $pr[$f]);
}
unset($pr);
return $d;
