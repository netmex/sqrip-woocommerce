<?php
/**
 * Test bench for the money-critical bits of Sqrip_Avis.
 *
 *     php tests/camt/run-avis-tests.php
 *
 * Plain PHP, no WordPress. Exercises the pure decision (book / hold / notify), the
 * release-limit semantics (blank = book all, 0 = confirm all), the overpayment mode,
 * reference normalisation and the one-time dedup key. The parts that need WooCommerce
 * (booking, e-mail, logging) are not covered here — only the logic that decides money.
 *
 * @package sqrip
 * @since 1.11
 */

define('ABSPATH', __DIR__);

$GLOBALS['stub_opts'] = array();

function sqrip_get_plugin_option($key)
{
    return array_key_exists($key, $GLOBALS['stub_opts']) ? $GLOBALS['stub_opts'][$key] : null;
}

// Only what the exercised methods touch.
function wp_json_encode($data)
{
    return json_encode($data);
}

require __DIR__ . '/../../inc/class-sqrip-avis.php';

$passed = 0;
$failed = 0;

function check($label, $got, $want)
{
    global $passed, $failed;
    if ($got === $want) {
        $passed++;
        echo "  OK   $label\n";
    } else {
        $failed++;
        echo "  FAIL $label\n";
        echo "         got:  " . var_export($got, true) . "\n";
        echo "         want: " . var_export($want, true) . "\n";
    }
}

/** Call a private/protected static method for testing. */
function priv($method, array $args = array())
{
    $m = new ReflectionMethod('Sqrip_Avis', $method);
    if (PHP_VERSION_ID < 80100) {
        $m->setAccessible(true);
    }
    return $m->invokeArgs(null, $args);
}

echo "\nDecision (book / hold_approval / hold_notify)\n";

// decide($amount, $threshold, $manual, $is_overpaid, $overpay_mode, $has_checksum)
check('below the limit books', Sqrip_Avis::decide(50.0, 100.0, false, false, 'hold', false), 'book');
check('above the limit is held for release', Sqrip_Avis::decide(150.0, 100.0, false, false, 'hold', false), 'hold_approval');
check('exactly at the limit books (strict >)', Sqrip_Avis::decide(100.0, 100.0, false, false, 'hold', false), 'book');
check('manual ignores the limit', Sqrip_Avis::decide(150.0, 100.0, true, false, 'hold', false), 'book');
check('overpaid + hold is held, even below limit', Sqrip_Avis::decide(50.0, 100.0, false, true, 'hold', false), 'hold_approval');
check('overpaid + hold is held even on manual', Sqrip_Avis::decide(50.0, 100.0, true, true, 'hold', false), 'hold_approval');
check('overpaid + pay books below the limit', Sqrip_Avis::decide(50.0, 100.0, false, true, 'pay', false), 'book');
check('overpaid + pay still respects the limit', Sqrip_Avis::decide(150.0, 100.0, false, true, 'pay', false), 'hold_approval');
check('checksum overrides everything', Sqrip_Avis::decide(10.0, 100.0, true, true, 'pay', true), 'hold_notify');

echo "\nRelease limit semantics\n";

$GLOBALS['stub_opts'] = array();                       // never configured
check('unset limit = book all (very high)', priv('threshold'), 1000000.0);
$GLOBALS['stub_opts'] = array('avis_threshold' => '');  // blank field
check('blank limit = book all', priv('threshold'), 1000000.0);
$GLOBALS['stub_opts'] = array('avis_threshold' => '0'); // explicit zero
check('explicit 0 = confirm all by hand', priv('threshold'), 0.0);
$GLOBALS['stub_opts'] = array('avis_threshold' => '49.90');
check('a real limit is read as a float', priv('threshold'), 49.90);

echo "\nOverpayment mode\n";

$GLOBALS['stub_opts'] = array();
check('default overpayment mode is hold', priv('overpayment_mode'), 'hold');
$GLOBALS['stub_opts'] = array('avis_overpayment' => 'pay');
check('pay is honoured', priv('overpayment_mode'), 'pay');
$GLOBALS['stub_opts'] = array('avis_overpayment' => 'nonsense');
check('anything else falls back to hold', priv('overpayment_mode'), 'hold');

echo "\nReference normalisation\n";

check('spaces and case are stripped', priv('normalize', array('ch 44 0483 5000')), 'CH4404835000');
check('punctuation is removed', priv('normalize', array('rf-18 5390 01')), 'RF18539001');
check('empty stays empty', priv('normalize', array('')), '');

echo "\nOne-time dedup key\n";

$a = priv('dedup_key', array(array('match', 'REF1', 50.0, '2026-08-07', '')));
$b = priv('dedup_key', array(array('match', 'REF1', 50.0, '2026-08-07', '')));
$c = priv('dedup_key', array(array('match', 'REF1', 51.0, '2026-08-07', '')));
check('same input = same key', $a, $b);
check('different amount = different key', $a !== $c, true);

echo "\n----------------------------------------\n";
echo "  $passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);
