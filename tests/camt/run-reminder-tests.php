<?php
/**
 * Test bench for Sqrip_Reminder — the fee arithmetic and the timing of the reminder.
 *
 *     php tests/camt/run-reminder-tests.php
 *
 * @package sqrip
 * @since 1.11
 */

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['opts'] = array();

function sqrip_get_plugin_option($key)
{
    return isset($GLOBALS['opts'][$key]) ? $GLOBALS['opts'][$key] : null;
}

function sqrip_get_order_meta_value($order, $key)
{
    return $order->get_meta($key, true);
}

function __($text, $domain = null)
{
    return $text;
}

function sqrip_additional_information_shortcodes($message, $lang, $due_date, $order_number)
{
    $message = str_replace('[order_number]', $order_number, $message);

    return preg_replace('/\[due_date format="(.*?)"\]/', date('d.m.Y', $due_date), $message);
}

class WC_Order
{
    private $meta;
    private $created;

    public function __construct(array $meta, $created)
    {
        $this->meta = $meta;
        $this->created = $created;
    }

    public function get_meta($key, $single = true)
    {
        return isset($this->meta[$key]) ? $this->meta[$key] : '';
    }

    public function get_date_created()
    {
        return $this->created === null ? null : new DateTimeImmutable('@' . $this->created);
    }
}

require dirname(__DIR__, 2) . '/inc/class-sqrip-reminder.php';

$passed = 0;
$failed = 0;

function check($label, $got, $expected)
{
    global $passed, $failed;

    $ok = ($got === $expected);
    $ok ? $passed++ : $failed++;

    echo ($ok ? "  OK   " : "  FAIL ") . $label . "\n";

    if (!$ok) {
        echo "         expected: " . var_export($expected, true) . "\n";
        echo "         got:      " . var_export($got, true) . "\n";
    }
}

echo "\n=== Fee: fixed amount ===\n";

$GLOBALS['opts'] = array('reminder_enabled' => 'yes', 'reminder_fee_type' => 'fixed', 'reminder_fee_amount' => '20');
check('20.00 regardless of the total', Sqrip_Reminder::fee_for_total(149.50), 20.00);
check('also on a small order', Sqrip_Reminder::fee_for_total(5.00), 20.00);

$GLOBALS['opts']['reminder_fee_amount'] = '-5';
check('a negative fee is no fee', Sqrip_Reminder::fee_for_total(100.00), 0.00);

$GLOBALS['opts']['reminder_fee_amount'] = '19,50';
check('a comma is read as a decimal point', Sqrip_Reminder::fee_for_total(100.00), 19.50);

echo "\n=== Fee: percentage ===\n";

$GLOBALS['opts'] = array('reminder_enabled' => 'yes', 'reminder_fee_type' => 'percent', 'reminder_fee_percent' => '5');
check('5% of 149.50', Sqrip_Reminder::fee_for_total(149.50), 7.48);
check('5% of 100.00', Sqrip_Reminder::fee_for_total(100.00), 5.00);

$GLOBALS['opts']['reminder_fee_percent'] = '0';
check('0% is no fee', Sqrip_Reminder::fee_for_total(100.00), 0.00);

echo "\n=== VAT on the fee is the shop's decision ===\n";

$GLOBALS['opts'] = array('reminder_enabled' => 'yes');
check('not taxable unless said so', Sqrip_Reminder::fee_is_taxable(), false);
$GLOBALS['opts']['reminder_fee_taxable'] = 'yes';
check('taxable when switched on', Sqrip_Reminder::fee_is_taxable(), true);

echo "\n=== Timing ===\n";

$GLOBALS['opts'] = array(
    'reminder_enabled' => 'yes',
    'due_date' => '30',
    'reminder_days_after_due' => '10',
);

$ordered = strtotime('2026-01-01 10:00:00');
$due_from = Sqrip_Reminder::reminder_due_from($ordered);

check('30 days maturity plus 10 days delay',
    date('Y-m-d', $due_from), '2026-02-10');

$open = array('sqrip_reference_id' => '210000000003139471430009017');

check('not due one day early',
    Sqrip_Reminder::is_due(new WC_Order($open, $ordered), $due_from - DAY_IN_SECONDS), false);
check('due on the day',
    Sqrip_Reminder::is_due(new WC_Order($open, $ordered), $due_from), true);
check('still due later',
    Sqrip_Reminder::is_due(new WC_Order($open, $ordered), $due_from + 30 * DAY_IN_SECONDS), true);

$reminded = array_merge($open, array('sqrip_reminder_reference_id' => 'RF18539007547034'));
check('never a second reminder',
    Sqrip_Reminder::is_due(new WC_Order($reminded, $ordered), $due_from), false);

check('no reminder without an original invoice',
    Sqrip_Reminder::is_due(new WC_Order(array(), $ordered), $due_from), false);

check('no reminder once the invoice was voided',
    Sqrip_Reminder::is_due(new WC_Order(array('sqrip_reference_id' => 'deleted'), $ordered), $due_from), false);

$GLOBALS['opts']['reminder_enabled'] = 'no';
check('nothing at all while switched off',
    Sqrip_Reminder::is_due(new WC_Order($open, $ordered), $due_from), false);

echo "\n=== The text on the reminder ===\n";

$GLOBALS['opts'] = array(
    'reminder_enabled' => 'yes', 'reminder_due_days' => '10',
    'reminder_additional_information' => 'Please pay by [due_date format="dd.MM.y"]. Late fee [currency] [reminder_fee]. Order [order_number]',
);

$message = Sqrip_Reminder::message('4711', 20.00, 'CHF');

check('fee filled in', strpos($message, 'CHF 20.00') !== false, true);
check('order number filled in', strpos($message, '4711') !== false, true);
check('maturity filled in', strpos($message, date('d.m.Y', strtotime('+10 days'))) !== false, true);
check('no placeholder left over', strpos($message, '[') === false, true);

echo "\n----------------------------------------\n";
echo "  $passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);
