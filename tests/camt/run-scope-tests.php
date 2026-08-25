<?php
/**
 * Test bench for the reconciliation scope helpers (which orders sqrip may reconcile).
 *
 *     php tests/camt/run-scope-tests.php
 *
 * Plain PHP. Exercises sqrip_avis_extra_statuses() and sqrip_order_in_avis_scope() — the
 * opt-in that lets sqrip reconcile non-sqrip orders (e.g. bank transfers) whose status the
 * shop added, while keeping the sqrip-only default for everyone else.
 *
 * @package sqrip
 * @since 1.11
 */

define('ABSPATH', __DIR__);

$GLOBALS['test_settings'] = array();

function get_option($key, $default = false)
{
    if ($key === 'woocommerce_sqrip_settings') {
        return $GLOBALS['test_settings'];
    }
    return $default;
}

class WC_Order
{
    private $pm;
    private $st;
    public function __construct($pm, $st) { $this->pm = $pm; $this->st = $st; }
    public function get_payment_method() { return $this->pm; }
    public function get_status() { return $this->st; }
}

require __DIR__ . '/../../inc/functions.php';

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

function set_extra($value) { $GLOBALS['test_settings']['avis_extra_statuses'] = $value; }
function clear_settings() { $GLOBALS['test_settings'] = array(); }

echo "\nsqrip_avis_extra_statuses() normalisation\n";

clear_settings();
check('unset = empty', sqrip_avis_extra_statuses(), array());
set_extra('');
check('blank = empty', sqrip_avis_extra_statuses(), array());
set_extra(array('wc-pending', 'on-hold'));
check('array, missing wc- prefix added', sqrip_avis_extra_statuses(), array('wc-pending', 'wc-on-hold'));
set_extra('wc-pending');
check('single string wrapped', sqrip_avis_extra_statuses(), array('wc-pending'));
set_extra(array('', 'wc-processing'));
check('empty entries dropped', sqrip_avis_extra_statuses(), array('wc-processing'));

echo "\nsqrip_order_in_avis_scope()\n";

clear_settings();
check('sqrip order is always in scope', sqrip_order_in_avis_scope(new WC_Order('sqrip', 'pending')), true);
check('non-sqrip, no extra statuses -> out of scope', sqrip_order_in_avis_scope(new WC_Order('bacs', 'pending')), false);

set_extra(array('wc-pending'));
check('non-sqrip in an opted-in status -> in scope', sqrip_order_in_avis_scope(new WC_Order('bacs', 'pending')), true);
check('non-sqrip in a different status -> out of scope', sqrip_order_in_avis_scope(new WC_Order('bacs', 'processing')), false);
check('sqrip order still in scope regardless', sqrip_order_in_avis_scope(new WC_Order('sqrip', 'processing')), true);
check('non-WC_Order -> false', sqrip_order_in_avis_scope(null), false);

echo "\n----------------------------------------\n";
echo "  $passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);
