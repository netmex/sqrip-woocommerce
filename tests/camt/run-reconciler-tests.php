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
     * Read the credit references out of a file so the test can pretend they are orders.
     *
     * Test-only: the plugin itself never walks a statement this way — it always starts
     * from the open orders. Returns reference => list of amounts.
     */
    function sqrip_test_harvest($file)
    {
        libxml_use_internal_errors(true);

        $payments = array();
        $reader   = new XMLReader();

        if (!@$reader->open($file, null, LIBXML_NONET)) {
            return $payments;
        }

        $document = new DOMDocument();

        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Ntry') {
                continue;
            }

            $entry = @$reader->expand($document);

            if (!$entry) {
                @$reader->next();
                continue;
            }

            $xpath = new DOMXPath($entry->ownerDocument);

            $indicator = $xpath->query('./*[local-name()="CdtDbtInd"]', $entry);

            if (!$indicator->length || trim($indicator->item(0)->textContent) !== 'CRDT') {
                @$reader->next();
                continue;
            }

            $reversal = $xpath->query('./*[local-name()="RvslInd"]', $entry);

            if ($reversal->length && strtolower(trim($reversal->item(0)->textContent)) === 'true') {
                @$reader->next();
                continue;
            }

            $entry_amount = $xpath->query('./*[local-name()="Amt"]', $entry);
            $entry_amount = $entry_amount->length ? $entry_amount->item(0)->textContent : '0';

            $transactions = $xpath->query('./*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]', $entry);
            $units = $transactions->length ? iterator_to_array($transactions) : array($entry);

            foreach ($units as $unit) {
                $found = $xpath->query('.//*[local-name()="CdtrRefInf"]/*[local-name()="Ref"]', $unit);

                if (!$found->length) {
                    continue;
                }

                $amount = $xpath->query('./*[local-name()="Amt"]', $unit);
                $amount = $amount->length ? $amount->item(0)->textContent : $entry_amount;

                $reference = Sqrip_Camt_Parser::normalize_reference($found->item(0)->textContent);
                $payments[$reference][] = round((float) $amount, 2);
            }

            @$reader->next();
        }

        $reader->close();
        libxml_clear_errors();

        return $payments;
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

    echo "\n=== Skonto: two invoices, only one of them gets paid ===\n";

    /**
     * 501 carries the ordinary invoice over 100.00 and a Skonto invoice over 98.00.
     */
    function skonto_order($id = 501)
    {
        return new WC_Order($id, '100.00', array(
            'sqrip_reference_id'        => '210000000003139471430009017',
            'sqrip_skonto_reference_id' => 'RF18539007547034',
            'sqrip_skonto_amount'       => '98.00',
        ));
    }

    function payment($reference, $amount)
    {
        return array($reference => array(array(
            'reference' => $reference, 'amount' => $amount, 'currency' => 'CHF',
            'value_date' => '2026-07-24', 'booking_date' => '2026-07-24',
        )));
    }

    $skonto = $reconciler->build_expectations(array(skonto_order()));

    check('both invoices are looked for', count($skonto[0]['slips']), 2);
    check('the Skonto invoice expects the reduced amount',
        $skonto[0]['slips'][1]['expected'], 98.00);

    // Paid with Skonto.
    $report = $reconciler->match($skonto, payment('RF18539007547034', 98.00));
    $order = order_in($report, 501);

    check('paying the Skonto invoice settles the order',
        $order['category'], Sqrip_Camt_Reconciler::PAID);
    check('and is recognised as the Skonto one', $order['paid_alternative'], 'skonto');
    check('the untouched ordinary invoice does not hold it back',
        $order['slips'][0]['category'], Sqrip_Camt_Reconciler::OPEN);

    // Paid without Skonto, after the deadline.
    $report = $reconciler->match($skonto, payment('210000000003139471430009017', 100.00));
    $order = order_in($report, 501);

    check('paying the ordinary invoice settles the order',
        $order['category'], Sqrip_Camt_Reconciler::PAID);
    check('and is recognised as the ordinary one', $order['paid_alternative'], 'regular');

    // Neither.
    $report = $reconciler->match($skonto, array());
    check('nothing paid leaves the order open',
        order_in($report, 501)['category'], Sqrip_Camt_Reconciler::OPEN);

    // Both — the customer used each invoice once.
    $both = array_merge(
        payment('RF18539007547034', 98.00),
        payment('210000000003139471430009017', 100.00)
    );
    $report = $reconciler->match($skonto, $both);
    $order = order_in($report, 501);

    check('paying both is never settled automatically',
        $order['category'], Sqrip_Camt_Reconciler::DUPLICATE);
    check('and nothing is applied', $order['applicable_slips'], array());

    // The discount was taken but the amount is wrong.
    $report = $reconciler->match($skonto, payment('RF18539007547034', 90.00));
    check('a wrong amount on the Skonto invoice is not a payment',
        order_in($report, 501)['category'], Sqrip_Camt_Reconciler::AMOUNT_MISMATCH);

    // Full amount paid against the Skonto reference: an overpayment, not a settlement.
    $report = $reconciler->match($skonto, payment('RF18539007547034', 100.00));
    check('the full amount on the Skonto reference is held back',
        order_in($report, 501)['category'], Sqrip_Camt_Reconciler::AMOUNT_MISMATCH);

    // A voided Skonto invoice must drop out of the comparison entirely.
    $voided = $reconciler->build_expectations(array(new WC_Order(502, '100.00', array(
        'sqrip_reference_id'        => '210000000003139471430009017',
        'sqrip_skonto_reference_id' => 'deleted',
    ))));

    check('a voided Skonto invoice is no longer looked for',
        count($voided[0]['slips']), 1);

    $report = $reconciler->match($voided, payment('210000000003139471430009017', 100.00));
    check('and the order still settles normally',
        order_in($report, 502)['category'], Sqrip_Camt_Reconciler::PAID);

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

    /**
     * Optional: check the whole chain against real bank files.
     *
     *     php tests/camt/run-reconciler-tests.php ~/Downloads
     *
     * Every payment in a file is turned into an order expecting exactly that amount,
     * and the reconciliation has to find it again. A reference paid twice has to come
     * back as a duplicate, and every order is checked a second time with its amount
     * five centimes off, which has to be refused.
     *
     * Prints counts only — no reference, amount, name or account number is ever put on
     * screen, so a real statement can be checked without its contents leaking into a
     * terminal or a shell history. Files are only read, never written or copied.
     */
    if (isset($argv[1])) {
        $directory = rtrim($argv[1], '/');
        $files = glob($directory . '/*.xml');

        echo "\n=== Real bank files in " . basename($directory) . " ===\n";

        if (!$files) {
            echo "  no XML files found\n";
        }

        $refs = $single = $paid = $duplicates = $duplicates_ok = $mismatch_ok = $unreadable = 0;

        foreach ($files as $file) {
            $parser  = new Sqrip_Camt_Parser();
            $probe   = $parser->collect_matches($file, array());

            if ($probe === false) {
                $unreadable++;
                continue;
            }

            // Read the file once more to learn which references it carries, so the
            // simulated orders can be built from it.
            $payments = sqrip_test_harvest($file);

            if (!$payments) {
                continue;
            }

            $orders = array();
            $id     = 1000;

            foreach ($payments as $reference => $amounts) {
                $orders[] = new WC_Order($id++, number_format($amounts[0], 2, '.', ''), array(
                    'sqrip_reference_id' => $reference,
                ));
            }

            $expectations = $reconciler->build_expectations($orders);
            $found        = $parser->collect_matches($file, $reconciler->references_of($expectations));

            if ($found === false) {
                $unreadable++;
                continue;
            }

            $report = $reconciler->match($expectations, $found['matches']);

            foreach ($report['orders'] as $entry) {
                $reference = $entry['slips'][0]['reference'];
                $refs++;

                if (count($payments[$reference]) === 1) {
                    $single++;

                    if ($entry['category'] === Sqrip_Camt_Reconciler::PAID) {
                        $paid++;
                    }
                } else {
                    $duplicates++;

                    if ($entry['category'] === Sqrip_Camt_Reconciler::DUPLICATE) {
                        $duplicates_ok++;
                    }
                }
            }

            $wrong = array();
            $id    = 2000;

            foreach ($payments as $reference => $amounts) {
                if (count($amounts) !== 1) {
                    continue;
                }

                $wrong[] = new WC_Order($id++, number_format($amounts[0] + 0.05, 2, '.', ''), array(
                    'sqrip_reference_id' => $reference,
                ));
            }

            if ($wrong) {
                $control = $reconciler->match($reconciler->build_expectations($wrong), $found['matches']);

                foreach ($control['orders'] as $entry) {
                    if ($entry['category'] === Sqrip_Camt_Reconciler::AMOUNT_MISMATCH) {
                        $mismatch_ok++;
                    }
                }
            }
        }

        echo "  files read:                  " . (count($files) - $unreadable) . " of " . count($files) . "\n";
        echo "  references on the credit side: $refs\n";
        echo "  paid once:                   $single\n";
        echo "  paid more than once:         $duplicates\n";

        if ($refs > 0) {
            check('every single payment is recognised', $paid, $single);
            check('every repeated payment is held back', $duplicates_ok, $duplicates);
            check('five centimes off is refused', $mismatch_ok, $single);
        }

        check('no file was unreadable', $unreadable, 0);
    }

    echo "\n----------------------------------------\n";
    echo "  $passed passed, $failed failed\n\n";

    exit($failed === 0 ? 0 : 1);
}
