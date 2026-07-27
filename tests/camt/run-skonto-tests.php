<?php
/**
 * Test bench for Sqrip_Skonto — the arithmetic of the discounted second invoice.
 *
 *     php tests/camt/run-skonto-tests.php
 *
 * @package sqrip
 * @since 1.11
 */

define('ABSPATH', __DIR__);
$GLOBALS['opts'] = array();
function sqrip_get_plugin_option($k) { return isset($GLOBALS['opts'][$k]) ? $GLOBALS['opts'][$k] : null; }
function sqrip_additional_information_shortcodes($m, $l, $d, $o) {
    $m = str_replace('[order_number]', $o, $m);
    return preg_replace('/\[due_date format="(.*?)"\]/', date('d.m.Y', $d), $m);
}
require dirname(__DIR__, 2) . '/inc/class-sqrip-skonto.php';

$pass = 0; $fail = 0;
function check($l, $g, $e) {
    global $pass, $fail;
    $ok = ($g === $e); $ok ? $pass++ : $fail++;
    echo ($ok ? "  OK   " : "  FAIL ") . $l . "\n";
    if (!$ok) echo "         expected: " . var_export($e, true) . "  got: " . var_export($g, true) . "\n";
}

echo "\n=== Discounted amount, 2% without rounding ===\n";
$GLOBALS['opts'] = array('skonto_enabled' => 'yes', 'skonto_percentage' => '2');
check('100.00 -> 98.00', Sqrip_Skonto::discounted_amount(100.00), 98.00);
check('149.50 -> 146.51', Sqrip_Skonto::discounted_amount(149.50), 146.51);
check('19.90 -> 19.50',  Sqrip_Skonto::discounted_amount(19.90), 19.50);

echo "\n=== the same, rounded to 0.05 ===\n";
$GLOBALS['opts']['skonto_round_five'] = 'yes';
check('100.00 -> 98.00', Sqrip_Skonto::discounted_amount(100.00), 98.00);
check('149.50 -> 146.50', Sqrip_Skonto::discounted_amount(149.50), 146.50);
check('19.90 -> 19.50',  Sqrip_Skonto::discounted_amount(19.90), 19.50);
check('77.77 -> 76.20',  Sqrip_Skonto::discounted_amount(77.77), 76.20);
check('saving on 149.50', Sqrip_Skonto::discount_amount(149.50), 3.00);

echo "\n=== every rounded amount is a multiple of 0.05 ===\n";
$bad = 0;
for ($cents = 5; $cents <= 100000; $cents += 7) {
    $a = Sqrip_Skonto::discounted_amount($cents / 100);
    if (abs(round($a * 20) - $a * 20) > 0.000001) $bad++;
}
check('not one amount off', $bad, 0);

echo "\n=== nonsensical settings ===\n";
$GLOBALS['opts'] = array('skonto_enabled' => 'yes', 'skonto_percentage' => '0');
check('0% is not a discount', Sqrip_Skonto::is_enabled(), false);
$GLOBALS['opts']['skonto_percentage'] = '100';
check('100% is not a discount', Sqrip_Skonto::is_enabled(), false);
$GLOBALS['opts']['skonto_percentage'] = '-5';
check('a negative percentage is not a discount', Sqrip_Skonto::is_enabled(), false);
$GLOBALS['opts']['skonto_percentage'] = '2,5';
check('a comma instead of a dot is read', Sqrip_Skonto::percentage(), 2.5);
$GLOBALS['opts'] = array('skonto_percentage' => '2');
check('no discount without the switch', Sqrip_Skonto::is_enabled(), false);

echo "\n=== how the percentage reads ===\n";
$GLOBALS['opts'] = array('skonto_enabled' => 'yes', 'skonto_percentage' => '2');
check('2 rather than 2.00', Sqrip_Skonto::format_percentage(), '2');
$GLOBALS['opts']['skonto_percentage'] = '1.5';
check('1.5 rather than 1.50', Sqrip_Skonto::format_percentage(), '1.5');

echo "\n=== the text printed on the invoice ===\n";
$GLOBALS['opts'] = array(
    'skonto_enabled' => 'yes', 'skonto_percentage' => '2', 'skonto_due_date' => '10',
    'skonto_additional_information' => 'If you pay by [due_date format="dd.MM.y"] we grant you [skonto]% Skonto. Order [order_number]',
);
$msg = Sqrip_Skonto::message('4711');
check('percentage filled in', strpos($msg, '2% Skonto') !== false, true);
check('order number filled in', strpos($msg, '4711') !== false, true);
check('deadline filled in', strpos($msg, date('d.m.Y', strtotime('+10 days'))) !== false, true);
check('no placeholder left over', strpos($msg, '[') === false, true);

echo "\n----------------------------------------\n  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
