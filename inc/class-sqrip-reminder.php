<?php

/**
 * Payment reminder with a late fee. (NET2-2327)
 *
 * A configurable number of days after the due date the customer receives a fresh QR
 * invoice over the original amount plus a fee. The fee is added to the order as a
 * WooCommerce fee item so it appears in the order total, the tax report and every
 * document WooCommerce produces — a fee that only exists on the QR slip would be
 * invisible to the shop's own bookkeeping.
 *
 * Level one only: one reminder per order. Chasing further is a separate decision.
 *
 * The arithmetic and the timing live here, free of orders and API calls, so they can
 * be tested on their own.
 *
 * @package sqrip
 * @since 1.11
 */

defined('ABSPATH') || exit;

class Sqrip_Reminder
{
    /**
     * @return bool
     */
    public static function is_enabled()
    {
        return sqrip_get_plugin_option('reminder_enabled') === 'yes';
    }

    /**
     * Days after the due date before the reminder goes out.
     *
     * @return int
     */
    public static function days_after_due()
    {
        $days = sqrip_get_plugin_option('reminder_days_after_due');

        return is_numeric($days) ? max(0, (int) $days) : 10;
    }

    /**
     * Days the customer is given to pay the reminder.
     *
     * @return int
     */
    public static function due_days()
    {
        $days = sqrip_get_plugin_option('reminder_due_days');

        return is_numeric($days) ? max(0, (int) $days) : 10;
    }

    /**
     * The late fee for a given order total.
     *
     * @param float $total Order total before the fee.
     * @return float Net of nothing — this is the amount as the shop entered it.
     */
    public static function fee_for_total($total)
    {
        $total = (float) $total;

        if (sqrip_get_plugin_option('reminder_fee_type') === 'percent') {
            $percent = self::to_number(sqrip_get_plugin_option('reminder_fee_percent'));

            if ($percent <= 0) {
                return 0.0;
            }

            return round($total * $percent / 100, 2);
        }

        return round(max(0, self::to_number(sqrip_get_plugin_option('reminder_fee_amount'))), 2);
    }

    /**
     * Is the fee subject to VAT?
     *
     * Deliberately a setting. A Swiss dunning fee is often treated as damages and
     * outside the scope of VAT, while other shops bill it as a taxable service. Wrong
     * either way is a problem on a real invoice, so the shop decides.
     *
     * @return bool
     */
    public static function fee_is_taxable()
    {
        return sqrip_get_plugin_option('reminder_fee_taxable') === 'yes';
    }

    /**
     * When does an order become eligible for a reminder?
     *
     * Counted from the order date plus the ordinary maturity plus the reminder delay.
     * The maturity is not stored per order, so a shop that changes that setting shifts
     * the reminders of orders that have not been reminded yet.
     *
     * @param int $order_created_timestamp
     * @return int Timestamp from which a reminder may be sent.
     */
    public static function reminder_due_from($order_created_timestamp)
    {
        $maturity = sqrip_get_plugin_option('due_date');
        $maturity = is_numeric($maturity) ? (int) $maturity : 30;

        return (int) $order_created_timestamp
            + (($maturity + self::days_after_due()) * DAY_IN_SECONDS);
    }

    /**
     * Is this order overdue far enough, and has it not been reminded already?
     *
     * @param WC_Order $order
     * @param int      $now Timestamp.
     * @return bool
     */
    public static function is_due($order, $now)
    {
        if (!self::is_enabled() || !is_a($order, 'WC_Order')) {
            return false;
        }

        // One reminder per order. Level two is a separate feature.
        if (sqrip_get_order_meta_value($order, 'sqrip_reminder_reference_id')) {
            return false;
        }

        // Nothing to remind about without an original invoice.
        $reference = sqrip_get_order_meta_value($order, 'sqrip_reference_id');

        if (!$reference || $reference === 'deleted') {
            return false;
        }

        $created = $order->get_date_created();

        if (!$created) {
            return false;
        }

        return $now >= self::reminder_due_from($created->getTimestamp());
    }

    /**
     * The text printed on the reminder invoice.
     *
     * @param string $order_number
     * @param float  $fee
     * @param string $currency
     * @return string
     */
    public static function message($order_number, $fee, $currency)
    {
        $message = sqrip_get_plugin_option('reminder_additional_information');

        if (!is_string($message) || trim($message) === '') {
            return '';
        }

        $message = str_replace(
            array('[reminder_fee]', '[currency]'),
            array(number_format((float) $fee, 2, '.', ''), $currency),
            $message
        );

        $due_date = strtotime(date('Y-m-d') . ' + ' . self::due_days() . ' days');
        $lang = sqrip_get_plugin_option('lang') ?: 'de';

        return sqrip_additional_information_shortcodes($message, $lang, $due_date, $order_number);
    }

    /**
     * The name the fee carries on the order and on every document.
     *
     * @return string
     */
    public static function fee_label()
    {
        $label = sqrip_get_plugin_option('reminder_fee_label');

        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        return __('Reminder fee', 'sqrip-swiss-qr-invoice');
    }

    /**
     * The suffix that tells the reminder PDF apart in the media library.
     *
     * @return string
     */
    public static function file_name_suffix()
    {
        return '_reminder';
    }

    /**
     * Daily sweep for overdue orders.
     *
     * @return void
     */
    public static function init()
    {
        add_action('sqrip_send_payment_reminders', array(__CLASS__, 'run'));

        if (!wp_next_scheduled('sqrip_send_payment_reminders')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sqrip_send_payment_reminders');
        }
    }

    /**
     * Find the orders that are overdue and remind about them.
     *
     * @param int|null $limit Safety cap per run.
     * @return int Number of reminders sent.
     */
    public static function run($limit = 200)
    {
        if (!self::is_enabled()) {
            return 0;
        }

        $status = (string) sqrip_get_plugin_option('status_awaiting');

        if ($status === '') {
            return 0;
        }

        if (strpos($status, 'wc-') !== 0) {
            $status = 'wc-' . $status;
        }

        $orders = wc_get_orders(array(
            'limit'   => (int) $limit,
            'status'  => $status,
            'type'    => 'shop_order',
            'orderby' => 'date',
            'order'   => 'ASC',
        ));

        if (!is_array($orders)) {
            return 0;
        }

        $now  = time();
        $sent = 0;

        foreach ($orders as $order) {
            if (!is_a($order, 'WC_Order') || $order->get_payment_method() !== 'sqrip') {
                continue;
            }

            if (!self::is_due($order, $now)) {
                continue;
            }

            if (self::send_for_order($order)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Add the fee, create the new invoice, tell the customer.
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function send_for_order($order)
    {
        $original_total = round((float) $order->get_total(), 2);
        $fee            = self::fee_for_total($original_total);

        if ($fee <= 0) {
            return false;
        }

        // The amount the ORIGINAL invoice was issued for. The fee is about to change
        // the order total, and without this the reconciliation would compare a payment
        // against the original QR slip to a total that has since grown. (B3)
        if (!sqrip_get_order_meta_value($order, 'sqrip_invoice_amount')) {
            $order->update_meta_data('sqrip_invoice_amount', $original_total);
        }

        // The fee has to be on the order before the new total can be read — it is the
        // tax engine that decides what the order now costs. Every exit below therefore
        // takes it off again: an order that was booked a fee but never received the
        // reminder would be charged again on the next daily run, and again the day
        // after, silently growing by one fee a day.
        $fee_item_id = self::add_fee_item($order, $fee);

        if (!$fee_item_id) {
            return false;
        }

        $new_total = round((float) $order->get_total(), 2);
        $currency  = $order->get_currency();

        $body = sqrip_prepare_qr_code_request_body($currency, $new_total, strval($order->get_id()));

        if (!$body) {
            self::remove_fee_item($order, $fee_item_id);
            $order->add_order_note(__('The payment reminder could not be created: the sqrip settings are incomplete. The late fee was not charged.', 'sqrip-swiss-qr-invoice'));

            return false;
        }

        $body['payable_by'] = sqrip_get_billing_address_from_order($order);
        $body['payable_to'] = sqrip_get_payable_to_address(sqrip_get_plugin_option('address'));

        $message = self::message($order->get_order_number(), $fee, $currency);

        if ($message !== '') {
            $body['payment_information']['message'] = $message;
        } else {
            unset($body['payment_information']['message']);
        }

        // A distinct reference is what lets the reconciliation tell the reminder from
        // the original invoice — and voiding the loser depends on exactly that. With
        // the shop deriving references from the order number both would be identical,
        // so this one is left to sqrip. Same reasoning as Sqrip_Skonto.
        unset($body['payment_information']['qr_reference']);
        unset($body['payment_information']['partial_invoice_message']);
        unset($body['invoice_fractions']);

        $response = wp_remote_post(SQRIP_ENDPOINT . 'code', sqrip_prepare_remote_args($body, 'POST'));

        if (is_wp_error($response)) {
            self::remove_fee_item($order, $fee_item_id);
            $order->add_order_note(sprintf(
                /* translators: %s: error message */
                __('The payment reminder could not be created: %s The late fee was not charged.', 'sqrip-swiss-qr-invoice'),
                esc_html($response->get_error_message())
            ));

            return false;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response));

        if (wp_remote_retrieve_response_code($response) !== 200
            || !isset($decoded->reference) || !isset($decoded->pdf_file)) {
            self::remove_fee_item($order, $fee_item_id);
            $order->add_order_note(sprintf(
                /* translators: %s: error reported by sqrip */
                __('The payment reminder could not be created: %s The late fee was not charged.', 'sqrip-swiss-qr-invoice'),
                isset($decoded->message) ? esc_html($decoded->message) : ''
            ));

            return false;
        }

        $attachment_id = file_upload_stt(
            $decoded->pdf_file,
            '.pdf',
            '',
            $order->get_id(),
            false,
            self::file_name_suffix()
        );

        $order->update_meta_data('sqrip_reminder_reference_id', $decoded->reference);
        $order->update_meta_data('sqrip_reminder_amount', $new_total);
        $order->update_meta_data('sqrip_reminder_fee', $fee);
        $order->update_meta_data('sqrip_reminder_qr_pdf_attachment_id', $attachment_id);
        $order->update_meta_data('sqrip_reminder_pdf_file_url', wp_get_attachment_url($attachment_id));
        $order->update_meta_data('sqrip_reminder_pdf_file_path', get_attached_file($attachment_id));
        $order->update_meta_data('sqrip_reminder_sent_at', time());

        // The customer is late, so the discount for paying early is gone. Left standing
        // it would still be payable, and paying it would settle the order at a discount
        // on top of never paying the fee.
        if (sqrip_void_invoice($order, 'skonto')) {
            $order->add_order_note(__('The QR invoice with Skonto has been voided: the payment is overdue.', 'sqrip-swiss-qr-invoice'));
        }

        $order->add_order_note(sprintf(
            /* translators: 1: currency, 2: fee, 3: new order total */
            __('Payment reminder created: late fee %1$s %2$s added, new QR invoice over %1$s %3$s. The original invoice stays valid until one of the two is paid.', 'sqrip-swiss-qr-invoice'),
            $currency,
            number_format($fee, 2, '.', ''),
            number_format($new_total, 2, '.', '')
        ));

        $order->save();

        self::send_email($order);

        return true;
    }

    /**
     * Put the fee on the order itself, so it shows up in the order, the tax report and
     * every document WooCommerce builds — not only on the QR slip.
     *
     * @param WC_Order $order
     * @param float    $fee
     * @return int Item id of the fee, 0 when it could not be added.
     */
    private static function add_fee_item($order, $fee)
    {
        if (!class_exists('WC_Order_Item_Fee')) {
            return 0;
        }

        $item = new WC_Order_Item_Fee();
        $item->set_name(self::fee_label());
        $item->set_amount((string) $fee);
        $item->set_total((string) $fee);
        $item->set_tax_status(self::fee_is_taxable() ? 'taxable' : 'none');

        $order->add_item($item);

        // Recalculates the tax on the new fee and the order total. Passing true also
        // refreshes the existing lines, which is what WooCommerce itself does when a
        // fee is added by hand in the admin.
        $order->calculate_totals(self::fee_is_taxable());
        $order->save();

        return (int) $item->get_id();
    }

    /**
     * Take the fee back off after a failed reminder.
     *
     * @param WC_Order $order
     * @param int      $item_id
     * @return void
     */
    private static function remove_fee_item($order, $item_id)
    {
        if (!$item_id) {
            return;
        }

        $order->remove_item($item_id);
        $order->calculate_totals(self::fee_is_taxable());
        $order->save();
    }

    /**
     * Send the reminder to the customer.
     *
     * Uses WooCommerce's own "Customer invoice" mail — the one built for sending an
     * order to the customer after the fact — so the shop keeps its template, its
     * sender and its styling.
     *
     * @param WC_Order $order
     * @return void
     */
    private static function send_email($order)
    {
        if (!function_exists('WC') || !WC()->mailer()) {
            return;
        }

        $emails = WC()->mailer()->get_emails();

        if (!isset($emails['WC_Email_Customer_Invoice'])) {
            return;
        }

        // Tells sqrip_attach_qrcode_pdf_to_email() to attach the reminder PDF to this
        // one mail, whatever the shop configured for ordinary invoices.
        $GLOBALS['sqrip_sending_reminder'] = $order->get_id();

        $emails['WC_Email_Customer_Invoice']->trigger($order->get_id(), $order);

        unset($GLOBALS['sqrip_sending_reminder']);

        $order->add_order_note(__('Payment reminder e-mailed to the customer.', 'sqrip-swiss-qr-invoice'));
        $order->save();
    }

    /**
     * @param mixed $value
     * @return float
     */
    private static function to_number($value)
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) str_replace(',', '.', (string) $value);
    }
}
