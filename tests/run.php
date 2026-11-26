<?php

declare(strict_types=1);

// Tiny dependency-free test runner: `php tests/run.php`

require __DIR__ . '/../examples/Pos.php';

use ModaShowcase\Pos\Checkout;
use ModaShowcase\Pos\Costing;
use ModaShowcase\Pos\Ingredient;
use ModaShowcase\Pos\MenuItem;

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (\Throwable) {
        return true;
    }

    return false;
}

// Demo data only.
$ingredients = [
    'BEAN' => new Ingredient('BEAN', 'g', Ingredient::costPerUsageUnit(200000, 1000)), // Rp200.000 / kg
    'MILK' => new Ingredient('MILK', 'ml', Ingredient::costPerUsageUnit(20000, 1000)),  // Rp20.000 / litre
    'SYRUP' => new Ingredient('SYRUP', 'ml', 100),
];
$latte = new MenuItem('DRK-COF-01', 25000, ['BEAN' => 18, 'MILK' => 150]);
$vanilla = new MenuItem('DRK-COF-02', 30000, ['BEAN' => 18, 'MILK' => 150, 'SYRUP' => 15]);
$costing = new Costing($ingredients);

check('unit conversion: Rp200.000/kg = Rp200/g', $ingredients['BEAN']->costPerUnit === 200.0);
check('HPP latte = 18×200 + 150×20 = 6600', $costing->hpp($latte) === 6600.0);
check('margin latte = 73.6 %', $costing->marginPercent($latte) === 73.6);

$checkout = new Checkout(['DRK-COF-01' => $latte, 'DRK-COF-02' => $vanilla], servicePercent: 5, taxPercent: 10);

$bill = $checkout->settle([
    ['code' => 'DRK-COF-01', 'qty' => 2, 'clientPrice' => 1],   // tampered price is ignored
    ['code' => 'DRK-COF-02', 'qty' => 1],
]);
check('server recomputes subtotal from the menu', $bill['subtotal'] === 80000);
check('service then tax: 4000 + 8400', $bill['service'] === 4000 && $bill['tax'] === 8400 && $bill['total'] === 92400);
check('ledger deducts recipe × qty', in_array(['ingredient' => 'BEAN', 'qty' => -54.0], $bill['ledger'], true)
    && in_array(['ingredient' => 'SYRUP', 'qty' => -15.0], $bill['ledger'], true));

check('25 % discount refused for a cashier', throws(fn () => $checkout->settle([['code' => 'DRK-COF-01', 'qty' => 1]], 25)));
$approved = $checkout->settle([['code' => 'DRK-COF-01', 'qty' => 1]], 25, 'manager');
check('25 % discount allowed with manager approval', $approved['discount'] === 6250);
check('small discount needs no approval', $checkout->settle([['code' => 'DRK-COF-01', 'qty' => 1]], 10)['discount'] === 2500);
check('unknown menu code is rejected', throws(fn () => $checkout->settle([['code' => 'NOPE', 'qty' => 1]])));

echo PHP_EOL . ($count - $failures) . "/$count passed" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
