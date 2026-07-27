<?php
/**
 * Test bench for Sqrip_Camt_Reconciler.
 *
 * Plain PHP, no WordPress:
 *
 *     php tests/camt/run-reconciler-tests.php
 *
 * Exercises the two pure halves — turning orders into expectations, and sorting the
 * parser's findings into categories — against the same fixtures the parser tests use.
 *
 * @package sqrip
 * @since 1.11
 */

/**
 * Minimal stand-ins for the WooCommerce pieces the reconciler touches.
 */
namespace Automattic\WooCommerce\Utilities {
    class OrderUtil
    {
        public static function custom_orders_table_usage_is_enabled()
        {
            return true;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__);

    class WC_Order
    {
        private $id;
        private $meta;
        private $total;
        private $currency;
        private $status;

        public function __construct($id, $total, array $meta, $currency = 'CHF', $status = 'pending')
        {
            $this->id       = $id;
            $this->total    = $total;
            $this->meta     = $meta;
            $this->currency = $currency;
            $this->status   = $status;
        }

        public function get_id() { return $this->id; }
        public function get_order_number() { return (string) $this->id; }
        public function get_total() { return $this->total; }
        public function get_currency() { return $this->currency; }
        public function get_status() { return $this->status; }
        public function get_payment_method() { return 'sqrip'; }

        public function get_meta($key, $single = true)
        {
            return isset($this->meta[$key]) ? $this->meta[$key] : '';
        }
    }

    function sqrip_get_order_meta_value($order, $meta_key)
    {
        return $order->get_meta($meta_key, true);
    }

    require dirname(__DIR__, 2) . '/inc/class-sqrip-camt-parser.php';
    require dirname(__DIR__, 2) . '/inc/class-sqrip-camt-reconciler.php';

    $fixtures = __DIR__ . '/fixtures';

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

    /**
     * Find an order in the report by its id.
     */
    function order_in($report, $id)
    {
        foreach ($report['orders'] as $order) {
            if ($order['order_id'] === $id) {
                return $order;
            }
        }

        return null;
    }

    $reconciler = new Sqrip_Camt_Reconciler();

    /**
     * The orders. References line up with tests/camt/fixtures/camt054-mixed.xml.
     */
    $orders = array(
        // 101: plain order, paid exactly. Reference stored spaced, as sqrip returns it.
        new WC_Order(101, '149.50', array(
            'sqrip_reference_id' => '21 00000 00003 13947 14300 09017',
        )),
        // 102: plain order, paid too little (80.00 arrives, 95.00 expected)
        new WC_Order(102, '95.00', array(
            'sqrip_reference_id' => 'RF18539007547034',
        )),
        // 103: three instalments, the first two are in the file, the third is not
        new WC_Order(103, '400.00', array(
            'sqrip_multiple_invoice_count'    => '3',
            'sqrip_paid_invoice_number'       => '0',
            'sqrip_reference_id_1'            => '210000000003139471430009024',
            'sqrip_partial_invoice_amount_1'  => '200.00',
            'sqrip_reference_id_2'            => '210000000003139471430009031',
            'sqrip_partial_invoice_amount_2'  => '100.25',
            'sqrip_reference_id_3'            => '210000000003139471430009062',
            'sqrip_partial_invoice_amount_3'  => '99.75',
        )),
        // 104: nothing in the file at all
        new WC_Order(104, '55.00', array(
            'sqrip_reference_id' => '210000000003139471430009079',
        )),
        // 105: invoice was deleted, so there is no reference to match
        new WC_Order(105, '20.00', array(
            'sqrip_reference_id' => 'deleted',
        )),
    );

    echo "\n=== Building expectations from orders ===\n";

    $expectations = $reconciler->build_expectations($orders);

    check('orders without a usable reference are dropped', count($expectations), 4);
    check('plain order expects its total', $expectations[0]['slips'][0]['expected'], 149.50);
    check('reference is normalised for comparison',
        $expectations[0]['slips'][0]['reference'], '210000000003139471430009017');
    check('instalment order yields one expectation per slip',
        count($expectations[2]['slips']), 3);
    check('instalment expects the per-slip amount, not the total',
        $expectations[2]['slips'][1]['expected'], 100.25);

    $references = $reconciler->references_of($expectations);
    check('every reference is asked for exactly once', count($references), 6);

    echo "\n=== Matching against camt.054 ===\n";

    $parser = new Sqrip_Camt_Parser();
    $found  = $parser->collect_matches($fixtures . '/camt054-mixed.xml', $references);

    if ($found === false) {
        echo "  FAIL fixture unreadable\n";
        $failed++;
    } else {
        $report = $reconciler->match($expectations, $found['matches']);

        $order101 = order_in($report, 101);
        $order102 = order_in($report, 102);
        $order103 = order_in($report, 103);
        $order104 = order_in($report, 104);

        // 101 is paid twice in the fixture, which must never confirm silently.
        check('101 duplicate payment is flagged, not booked',
            $order101['category'], Sqrip_Camt_Reconciler::DUPLICATE);
        check('101 is not applied automatically', $order101['applicable_slips'], array());

        check('102 underpayment is a mismatch',
            $order102['category'], Sqrip_Camt_Reconciler::AMOUNT_MISMATCH);
        check('102 payment amount is reported',
            $order102['slips'][0]['payments'][0]['amount'], 80.00);

        check('103 partly paid', $order103['category'], Sqrip_Camt_Reconciler::PARTLY_PAID);
        check('103 first two instalments may be applied',
            $order103['applicable_slips'], array(1, 2));
        check('103 third instalment stays open',
            $order103['slips'][2]['category'], Sqrip_Camt_Reconciler::OPEN);

        check('104 has no payment', $order104['category'], Sqrip_Camt_Reconciler::OPEN);

        check('foreign credits stay a number', $found['other_credits'], 1);
    }

    echo "\n=== Instalments paid out of order ===\n";

    // Only the second instalment arrives; the first is still missing.
    $out_of_order = $reconciler->build_expectations(array(
        new WC_Order(201, '400.00', array(
            'sqrip_multiple_invoice_count'   => '2',
            'sqrip_paid_invoice_number'      => '0',
            'sqrip_reference_id_1'           => '210000000003139471430009024',
            'sqrip_partial_invoice_amount_1' => '200.00',
            'sqrip_reference_id_2'           => '210000000003139471430009031',
            'sqrip_partial_invoice_amount_2' => '100.25',
        )),
    ));

    $report = $reconciler->match($out_of_order, array(
        '210000000003139471430009031' => array(array(
            'reference' => '210000000003139471430009031', 'amount' => 100.25,
            'currency' => 'CHF', 'value_date' => '2026-07-24', 'booking_date' => '2026-07-24',
        )),
    ));

    $order201 = order_in($report, 201);
    check('later instalment paid first is held back',
        $order201['category'], Sqrip_Camt_Reconciler::OUT_OF_SEQUENCE);
    check('nothing may be applied automatically', $order201['applicable_slips'], array());

    echo "\n=== Reconciling the same file twice ===\n";

    $already = $reconciler->build_expectations(array(
        new WC_Order(301, '400.00', array(
            'sqrip_multiple_invoice_count'   => '2',
            'sqrip_paid_invoice_number'      => '1',
            'sqrip_reference_id_1'           => '210000000003139471430009024',
            'sqrip_partial_invoice_amount_1' => '200.00',
            'sqrip_reference_id_2'           => '210000000003139471430009031',
            'sqrip_partial_invoice_amount_2' => '200.00',
        )),
    ));

    $report = $reconciler->match($already, array(
        '210000000003139471430009024' => array(array(
            'reference' => '210000000003139471430009024', 'amount' => 200.00,
            'currency' => 'CHF', 'value_date' => '2026-07-24', 'booking_date' => '2026-07-24',
        )),
    ));

    $order301 = order_in($report, 301);
    check('an instalment already settled is flagged, not booked again',
        $order301['slips'][0]['category'], Sqrip_Camt_Reconciler::DUPLICATE);
    check('order is held back', $order301['category'], Sqrip_Camt_Reconciler::DUPLICATE);
    check('nothing is applied', $order301['applicable_slips'], array());

    echo "\n=== Safety rails ===\n";

    $wrong_currency = $reconciler->build_expectations(array(
        new WC_Order(401, '100.00', array('sqrip_reference_id' => '210000000003139471430009017'), 'CHF'),
    ));

    $report = $reconciler->match($wrong_currency, array(
        '210000000003139471430009017' => array(array(
            'reference' => '210000000003139471430009017', 'amount' => 100.00,
            'currency' => 'EUR', 'value_date' => '2026-07-24', 'booking_date' => '2026-07-24',
        )),
    ));

    check('right amount in the wrong currency is not a payment',
        order_in($report, 401)['category'], Sqrip_Camt_Reconciler::AMOUNT_MISMATCH);

    $no_amount = $reconciler->build_expectations(array(
        new WC_Order(402, '300.00', array(
            'sqrip_multiple_invoice_count' => '2',
            'sqrip_paid_invoice_number'    => '0',
            'sqrip_reference_id_1'         => '210000000003139471430009024',
            'sqrip_reference_id_2'         => '210000000003139471430009031',
        )),
    ));

    check('pre-1.11 slips without a stored amount expect nothing',
        $no_amount[0]['slips'][0]['expected'], null);

    $report = $reconciler->match($no_amount, array(
        '210000000003139471430009024' => array(array(
            'reference' => '210000000003139471430009024', 'amount' => 150.00,
            'currency' => 'CHF', 'value_date' => '2026-07-24', 'booking_date' => '2026-07-24',
        )),
    ));

    check('and are never confirmed on the reference alone',
        order_in($report, 402)['category'], Sqrip_Camt_Reconciler::AMOUNT_MISMATCH);

    $rounding = $reconciler->build_expectations(array(
        new WC_Order(403, '19.90', array('sqrip_reference_id' => '210000000003139471430009017')),
    ));

    $report = $reconciler->match($rounding, array(
        '210000000003139471430009017' => array(array(
            'reference' => '210000000003139471430009017', 'amount' => 19.90,
            'currency' => 'CHF', 'value_date' => '2026-07-24', 'booking_date' => '2026-07-24',
        )),
    ));

    check('exact amount matches despite float arithmetic',
        order_in($report, 403)['category'], Sqrip_Camt_Reconciler::PAID);

    $one_cent = $reconciler->match($rounding, array(
        '210000000003139471430009017' => array(array(
            'reference' => '210000000003139471430009017', 'amount' => 19.89,
            'currency' => 'CHF', 'value_date' => '2026-07-24', 'booking_date' => '2026-07-24',
        )),
    ));

    check('one cent short is not paid',
        order_in($one_cent, 403)['category'], Sqrip_Camt_Reconciler::AMOUNT_MISMATCH);

    echo "\n----------------------------------------\n";
    echo "  $passed passed, $failed failed\n\n";

    exit($failed === 0 ? 0 : 1);
}
