<?php

/**
 * Matches the payments in a bank file against the shop's still open sqrip orders.
 *
 * Works strictly from the orders outwards: it collects the orders that are waiting for
 * payment, hands their references to Sqrip_Camt_Parser and sorts what comes back. The
 * statement is never surveyed for its own sake — payments belonging to no open order
 * are only ever counted, so a monthly statement can be used without the shop's other
 * banking business passing through here.
 *
 * Reconciling is a pure analysis. Nothing is written, no status is changed and no
 * result is stored; that is the caller's job once the shop has seen the preview.
 *
 * @package sqrip
 * @since 1.11
 */

defined('ABSPATH') || exit;

class Sqrip_Camt_Reconciler
{
    /**
     * Amounts are compared in whole cents; this keeps float arithmetic out of it.
     */
    const AMOUNT_TOLERANCE = 0.005;

    /**
     * Upper bound for the candidate query, so a shop with a huge backlog cannot run
     * the request out of memory. Reported when it bites instead of silently cutting.
     */
    const MAX_ORDERS = 5000;

    /** Payment found, amount agrees. The only state that may be applied automatically. */
    const PAID = 'paid';

    /** Reference found, amount does not agree. Always manual. */
    const AMOUNT_MISMATCH = 'amount_mismatch';

    /** Reference found more than once, or the slip was already settled. Always manual. */
    const DUPLICATE = 'duplicate';

    /** A later instalment was paid while an earlier one was not. Always manual. */
    const OUT_OF_SEQUENCE = 'out_of_sequence';

    /** No payment for this slip in the file. */
    const OPEN = 'open';

    /** Some instalments of the order are settled, others are not. */
    const PARTLY_PAID = 'partly_paid';

    /**
     * @var array
     */
    private $errors = array();

    /**
     * @return array List of arrays with 'code' and optional 'detail'.
     */
    public function get_errors()
    {
        return $this->errors;
    }

    /**
     * Compare a bank file against the open orders.
     *
     * @param string $file Absolute path to the camt file.
     * @return array|false Report, or false on failure; see get_errors().
     */
    public function reconcile($file)
    {
        $this->errors = array();

        $orders = $this->collect_open_orders();

        if ($orders === false) {
            return false;
        }

        $expectations = $this->build_expectations($orders['orders']);
        $references   = $this->references_of($expectations);

        $parser = new Sqrip_Camt_Parser();
        $found  = $parser->collect_matches($file, $references);

        if ($found === false) {
            $this->errors = array_merge($this->errors, $parser->get_errors());

            return false;
        }

        $report = $this->match($expectations, $found['matches']);

        $report['orders_scanned']    = $orders['scanned'];
        $report['orders_truncated']  = $orders['truncated'];
        $report['credits_total']     = $found['credits_total'];

        // A number, never a list. These belong to no open order and are none of the
        // reconciliation's business — they are reported only so the shop can tell
        // whether it picked the right file.
        $report['unmatched_credits'] = $found['other_credits'];

        return $report;
    }

    /**
     * The orders that are waiting for payment.
     *
     * Queried by status only and filtered down to sqrip in PHP. Status is a first class
     * column under both the classic order storage and HPOS, whereas a meta query for
     * the payment method behaves differently between them.
     *
     * @return array|false
     */
    public function collect_open_orders()
    {
        $status = (string) sqrip_get_plugin_option('status_awaiting');

        if ($status === '') {
            $this->errors[] = array('code' => 'no_awaiting_status');

            return false;
        }

        if (strpos($status, 'wc-') !== 0) {
            $status = 'wc-' . $status;
        }

        // Also cover any extra statuses the shop opted into (non-sqrip orders, e.g. bank
        // transfers or GiroCode/SEPA-managed orders). Empty by default → unchanged.
        $statuses = array_values(array_unique(array_merge(array($status), sqrip_avis_extra_statuses())));

        $found = wc_get_orders(array(
            'limit'   => self::MAX_ORDERS + 1,
            'status'  => $statuses,
            'type'    => 'shop_order',
            'orderby' => 'date',
            'order'   => 'ASC',
        ));

        $found = is_array($found) ? $found : array();

        $truncated = count($found) > self::MAX_ORDERS;

        if ($truncated) {
            $found = array_slice($found, 0, self::MAX_ORDERS);
        }

        $orders = array();

        foreach ($found as $order) {
            if (!is_a($order, 'WC_Order')) {
                continue;
            }

            if (!sqrip_order_in_avis_scope($order)) {
                continue;
            }

            $orders[] = $order;
        }

        return array(
            'orders'    => $orders,
            'scanned'   => count($orders),
            'truncated' => $truncated,
        );
    }

    /**
     * Turn orders into one expectation per QR slip.
     *
     * A plain order has a single reference and expects its total. An order with several
     * QR slips has one reference and one amount per slip, and a counter saying how many
     * of them have already been settled.
     *
     * @param array $orders WC_Order objects.
     * @return array
     */
    public function build_expectations($orders)
    {
        $expectations = array();

        foreach ($orders as $order) {
            $slip_count = (int) sqrip_get_order_meta_value($order, 'sqrip_multiple_invoice_count');
            $paid_slips = (int) sqrip_get_order_meta_value($order, 'sqrip_paid_invoice_number');
            $total      = round((float) $order->get_total(), 2);

            $slips = array();

            if ($slip_count > 1) {
                for ($index = 1; $index <= $slip_count; $index++) {
                    $reference = $this->clean_reference(
                        sqrip_get_order_meta_value($order, 'sqrip_reference_id_' . $index)
                    );

                    if ($reference === '') {
                        continue;
                    }

                    $amount = sqrip_get_order_meta_value($order, 'sqrip_partial_invoice_amount_' . $index);

                    $slips[] = array(
                        'index'        => $index,
                        'kind'         => 'instalment',
                        'reference'    => $reference,
                        // Missing on instalment invoices that were regenerated by hand
                        // before 1.11: that path wrote the reference but not the amount.
                        // Both checkout paths always wrote it.
                        'expected'     => $amount === '' || $amount === null
                            ? null
                            : round((float) $amount, 2),
                        'already_paid' => $index <= $paid_slips,
                    );
                }
            } else {
                $reference = $this->clean_reference(
                    sqrip_get_order_meta_value($order, 'sqrip_reference_id')
                );

                if ($reference !== '') {
                    // A reminder adds its late fee to the order, so the order total is
                    // no longer what the original invoice asked for. That amount is
                    // recorded when the fee is added; fall back to the total when there
                    // never was a reminder.
                    $issued_for = sqrip_get_order_meta_value($order, 'sqrip_invoice_amount');

                    $slips[] = array(
                        'index'        => null,
                        'kind'         => 'regular',
                        'reference'    => $reference,
                        'expected'     => ($issued_for === '' || $issued_for === null)
                            ? $total
                            : round((float) $issued_for, 2),
                        'already_paid' => false,
                    );
                }

                // Skonto: a second invoice over a reduced amount. The customer pays one
                // of the two, never both, so these are alternatives and not instalments.
                $skonto_reference = $this->clean_reference(
                    sqrip_get_order_meta_value($order, 'sqrip_skonto_reference_id')
                );

                if ($skonto_reference !== '') {
                    $skonto_amount = sqrip_get_order_meta_value($order, 'sqrip_skonto_amount');

                    $valid_until = sqrip_get_order_meta_value($order, 'sqrip_skonto_valid_until');

                    $slips[] = array(
                        'index'        => null,
                        'kind'         => 'skonto',
                        'reference'    => $skonto_reference,
                        'expected'     => $skonto_amount === '' || $skonto_amount === null
                            ? null
                            : round((float) $skonto_amount, 2),
                        // A discount for paying early is only owed while it is early.
                        'valid_until'  => $valid_until ? (string) $valid_until : '',
                        'already_paid' => false,
                    );
                }

                // Reminder: the original amount plus the late fee. Also an alternative
                // — a customer who pays the original invoice after the reminder went
                // out has still paid, just not the fee.
                $reminder_reference = $this->clean_reference(
                    sqrip_get_order_meta_value($order, 'sqrip_reminder_reference_id')
                );

                if ($reminder_reference !== '') {
                    $reminder_amount = sqrip_get_order_meta_value($order, 'sqrip_reminder_amount');

                    $slips[] = array(
                        'index'        => null,
                        'kind'         => 'reminder',
                        'reference'    => $reminder_reference,
                        'expected'     => $reminder_amount === '' || $reminder_amount === null
                            ? null
                            : round((float) $reminder_amount, 2),
                        'already_paid' => false,
                    );
                }
            }

            if (!$slips) {
                continue;
            }

            $expectations[] = array(
                'order_id'     => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'currency'     => $order->get_currency(),
                'status'       => $order->get_status(),
                'total'        => $total,
                'slip_count'   => $slip_count > 1 ? $slip_count : 1,
                'paid_slips'   => $paid_slips,
                'slips'        => $slips,
            );
        }

        return $expectations;
    }

    /**
     * Every reference the parser should look for.
     *
     * @param array $expectations
     * @return string[]
     */
    public function references_of(array $expectations)
    {
        $references = array();

        foreach ($expectations as $order) {
            foreach ($order['slips'] as $slip) {
                $references[$slip['reference']] = true;
            }
        }

        return array_keys($references);
    }

    /**
     * Sort what the parser found into the result categories.
     *
     * @param array $expectations
     * @param array $matches Normalised reference => list of payments.
     * @return array
     */
    public function match(array $expectations, array $matches)
    {
        $orders = array();
        $totals = array(
            self::PAID            => 0,
            self::PARTLY_PAID     => 0,
            self::AMOUNT_MISMATCH => 0,
            self::DUPLICATE       => 0,
            self::OUT_OF_SEQUENCE => 0,
            self::OPEN            => 0,
        );

        foreach ($expectations as $order) {
            $slips = array();

            foreach ($order['slips'] as $slip) {
                $payments = isset($matches[$slip['reference']]) ? $matches[$slip['reference']] : array();

                $slip['payments'] = $payments;
                $slip['category'] = $this->categorise_slip($order, $slip, $payments);

                $slips[] = $slip;
            }

            $order['slips']            = $slips;
            $order['alternatives']     = $this->has_alternatives($slips);
            $order['paid_alternative'] = null;
            $order['applicable_slips'] = $this->applicable_slips($order, $slips);
            $order['category']         = $this->categorise_order($order, $slips);

            if ($order['alternatives']) {
                foreach ($slips as $slip) {
                    if ($slip['category'] === self::PAID) {
                        $order['paid_alternative'] = $slip['kind'];
                        break;
                    }
                }
            }

            if (isset($totals[$order['category']])) {
                $totals[$order['category']]++;
            }

            $orders[] = $order;
        }

        return array(
            'orders' => $orders,
            'totals' => $totals,
        );
    }

    /**
     * The state of a single QR slip.
     *
     * @param array $order
     * @param array $slip
     * @param array $payments
     * @return string
     */
    private function categorise_slip($order, $slip, array $payments)
    {
        if (!$payments) {
            return self::OPEN;
        }

        // The same reference twice in one file, or a slip the shop already settled by
        // hand. Either way a human has to look at it.
        if (count($payments) > 1 || !empty($slip['already_paid'])) {
            return self::DUPLICATE;
        }

        $payment = $payments[0];

        if ($payment['currency'] !== '' && $order['currency'] !== ''
            && strtoupper($payment['currency']) !== strtoupper($order['currency'])) {
            return self::AMOUNT_MISMATCH;
        }

        // Instalment invoices regenerated by hand before 1.11 have no stored amount.
        // Without an expected amount there is nothing to verify, so this stays manual
        // rather than being confirmed on the strength of the reference alone.
        if ($slip['expected'] === null) {
            return self::AMOUNT_MISMATCH;
        }

        if (abs($payment['amount'] - $slip['expected']) >= self::AMOUNT_TOLERANCE) {
            return self::AMOUNT_MISMATCH;
        }

        // A discount granted for paying by a certain date is not owed to somebody who
        // paid after it. Held back rather than refused outright: whether to grant it
        // anyway is the shop's call, not this code's.
        if (self::paid_after_deadline($slip, $payment)) {
            return self::AMOUNT_MISMATCH;
        }

        return self::PAID;
    }

    /**
     * Did this payment arrive after the deadline the slip was valid for?
     *
     * @param array $slip
     * @param array $payment
     * @return bool
     */
    public static function paid_after_deadline($slip, $payment)
    {
        if (empty($slip['valid_until']) || empty($payment['value_date'])) {
            return false;
        }

        $deadline = strtotime($slip['valid_until']);
        $paid_on  = strtotime($payment['value_date']);

        if (!$deadline || !$paid_on) {
            return false;
        }

        return $paid_on > $deadline;
    }

    /**
     * Slips that may be settled without asking.
     *
     * Instalments are counted forward one by one, exactly as the confirm button in the
     * orders list does, so this only ever returns an unbroken run starting at the next
     * unpaid slip. A later instalment that was paid while an earlier one was not stops
     * the run and is left to the shop.
     *
     * @param array $order
     * @param array $slips
     * @return int[] Slip indexes. Always empty for a plain order without instalments —
     *               there the order category 'paid' is the signal to act on.
     */
    private function applicable_slips($order, array $slips)
    {
        $applicable = array();
        $by_index   = array();

        foreach ($slips as $slip) {
            if ($slip['index'] === null) {
                // Plain order: nothing to count forward.
                return array();
            }

            $by_index[$slip['index']] = $slip;
        }

        for ($index = $order['paid_slips'] + 1; $index <= $order['slip_count']; $index++) {
            if (!isset($by_index[$index]) || $by_index[$index]['category'] !== self::PAID) {
                break;
            }

            $applicable[] = $index;
        }

        return $applicable;
    }

    /**
     * The state of the order as a whole.
     *
     * @param array $order
     * @param array $slips
     * @return string
     */
    private function categorise_order($order, array $slips)
    {
        $states = array();

        foreach ($slips as $slip) {
            $states[$slip['category']] = true;
        }

        // Alternatives: the customer pays the ordinary invoice or the one with Skonto.
        // Exactly one payment settles the order; the untouched one staying open is the
        // normal case and must not count against it.
        if (!empty($order['alternatives'])) {
            $paid = 0;

            foreach ($slips as $slip) {
                if ($slip['category'] === self::PAID) {
                    $paid++;
                }
            }

            // Both were paid — the customer used each invoice once. Never settle that
            // automatically; the shop owes a refund and has to look at it.
            if ($paid > 1 || isset($states[self::DUPLICATE])) {
                return self::DUPLICATE;
            }

            if ($paid === 1) {
                // Paying the original invoice after a reminder went out leaves the late
                // fee unpaid, while the order total already includes it. Booking that as
                // settled would close the order with money missing, so the shop decides:
                // waive the fee, or chase it.
                if ($this->paid_short_of_the_fee($slips)) {
                    return self::AMOUNT_MISMATCH;
                }

                return self::PAID;
            }

            if (isset($states[self::AMOUNT_MISMATCH])) {
                return self::AMOUNT_MISMATCH;
            }

            return self::OPEN;
        }

        if (isset($states[self::DUPLICATE])) {
            return self::DUPLICATE;
        }

        if (isset($states[self::AMOUNT_MISMATCH])) {
            return self::AMOUNT_MISMATCH;
        }

        if (!isset($states[self::PAID])) {
            return self::OPEN;
        }

        // Something was paid. Was it everything that is still outstanding?
        $outstanding = 0;

        foreach ($slips as $slip) {
            if (empty($slip['already_paid']) && $slip['category'] !== self::PAID) {
                $outstanding++;
            }
        }

        if ($outstanding === 0) {
            return self::PAID;
        }

        // Paid, but not the next one in line: the shop has to decide what happened.
        if (!$order['applicable_slips']) {
            return self::OUT_OF_SEQUENCE;
        }

        return self::PARTLY_PAID;
    }

    /**
     * Was the ordinary invoice paid while a reminder had already raised the total?
     *
     * @param array $slips
     * @return bool
     */
    private function paid_short_of_the_fee(array $slips)
    {
        $paid_kind    = '';
        $has_reminder = false;

        foreach ($slips as $slip) {
            if ($slip['category'] === self::PAID) {
                $paid_kind = $slip['kind'];
            }

            if ($slip['kind'] === 'reminder') {
                $has_reminder = true;
            }
        }

        return $has_reminder && $paid_kind === 'regular';
    }

    /**
     * Does this order carry two invoices the customer may choose between?
     *
     * @param array $slips
     * @return bool
     */
    private function has_alternatives(array $slips)
    {
        $without_index = 0;

        foreach ($slips as $slip) {
            if ($slip['index'] === null) {
                $without_index++;
            }
        }

        return $without_index > 1;
    }

    /**
     * Order references can carry the marker 'deleted' once the invoice was removed.
     *
     * @param mixed $reference
     * @return string
     */
    private function clean_reference($reference)
    {
        $reference = (string) $reference;

        if ($reference === '' || strtolower(trim($reference)) === 'deleted') {
            return '';
        }

        return Sqrip_Camt_Parser::normalize_reference($reference);
    }
}
