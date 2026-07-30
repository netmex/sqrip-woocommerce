<?php

/**
 * Skonto: a second QR invoice for customers who pay early. (NET2-2326)
 *
 * The shop sets a percentage and a shorter deadline. Alongside the ordinary QR bill
 * the customer receives a second one over the reduced amount. Whichever of the two is
 * paid settles the order — and the other one is voided, which is what keeps the shop
 * from chasing an amount that was never owed.
 *
 * This class holds the decisions and the arithmetic. It touches no orders and makes no
 * API calls, so it can be reasoned about and tested on its own.
 *
 * @package sqrip
 * @since 1.11
 */

defined('ABSPATH') || exit;

class Sqrip_Skonto
{
    /**
     * Is a discounted second invoice configured at all?
     *
     * @return bool
     */
    public static function is_enabled()
    {
        // [HOLD 1.11 — B2 Skonto] Feature parked while the focus moves to the camt
        // reconciliation (C). The whole class stays in place but is switched off here,
        // so no second invoice is ever generated regardless of any stored setting.
        // Remove this return to reactivate; the settings fields are commented out in
        // inc/class-wc-sqrip-payment-gateway.php with the same marker.
        return false;

        return sqrip_get_plugin_option('skonto_enabled') === 'yes'
            && self::percentage() > 0;
    }

    /**
     * The configured discount.
     *
     * @return float Percent, 0 when unset or outside a sensible range.
     */
    public static function percentage()
    {
        $percentage = sqrip_get_plugin_option('skonto_percentage');

        if ($percentage === null || $percentage === '') {
            return 0.0;
        }

        $percentage = (float) str_replace(',', '.', (string) $percentage);

        // A discount of 100% or more would produce an invoice over nothing, a negative
        // one a surcharge. Neither is a discount, so treat both as "not configured".
        if ($percentage <= 0 || $percentage >= 100) {
            return 0.0;
        }

        return $percentage;
    }

    /**
     * @return bool Whether the discounted amount is rounded to the nearest 5 centimes.
     */
    public static function rounds_to_five_cents()
    {
        return sqrip_get_plugin_option('skonto_round_five') === 'yes';
    }

    /**
     * Days until the discount expires.
     *
     * @return int
     */
    public static function due_date_days()
    {
        $days = sqrip_get_plugin_option('skonto_due_date');

        if (!is_numeric($days)) {
            return 10;
        }

        return max(0, (int) $days);
    }

    /**
     * What the customer pays when taking the discount.
     *
     * @param float $total Order total.
     * @return float
     */
    public static function discounted_amount($total)
    {
        $total = (float) $total;
        $percentage = self::percentage();

        if ($percentage <= 0 || $total <= 0) {
            return round($total, 2);
        }

        $amount = $total * (1 - $percentage / 100);

        if (self::rounds_to_five_cents()) {
            // Swiss shops round cash amounts to 5 centimes; the same is expected of a
            // discounted invoice total. round($amount * 20) / 20 is the usual way.
            return round(round($amount * 20) / 20, 2);
        }

        return round($amount, 2);
    }

    /**
     * What the customer saves. Only used for display and messages.
     *
     * @param float $total
     * @return float
     */
    public static function discount_amount($total)
    {
        return round((float) $total - self::discounted_amount($total), 2);
    }

    /**
     * Does this particular order get a discounted invoice?
     *
     * Not combined with instalments: a discount for paying early and a payment split
     * over months contradict each other, and the sqrip settings offer no way to say
     * what a discount on the third instalment would even mean.
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function applies_to_order($order)
    {
        if (!self::is_enabled() || !is_a($order, 'WC_Order')) {
            return false;
        }

        if (sqrip_get_plugin_option('multiple_qr_slips_enabled') === 'yes') {
            $number_of_invoices = (int) sqrip_get_plugin_option('number_of_invoices');

            if ($number_of_invoices > 1) {
                return false;
            }
        }

        return self::discounted_amount($order->get_total()) > 0;
    }

    /**
     * The text printed in the "Additional information" block of the discounted invoice.
     *
     * @param string $order_number
     * @return string
     */
    public static function message($order_number)
    {
        $message = sqrip_get_plugin_option('skonto_additional_information');

        if (!is_string($message) || trim($message) === '') {
            return '';
        }

        $message = str_replace('[skonto]', self::format_percentage(), $message);

        $due_date = strtotime(date('Y-m-d') . ' + ' . self::due_date_days() . ' days');
        $lang = sqrip_get_plugin_option('lang') ?: 'de';

        return sqrip_additional_information_shortcodes($message, $lang, $due_date, $order_number);
    }

    /**
     * The percentage as it should read in a sentence: "2" and not "2.00", "1.5" and
     * not "1.50".
     *
     * @return string
     */
    public static function format_percentage()
    {
        $percentage = self::percentage();

        return rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.');
    }

    /**
     * The suffix that tells the two PDFs apart in the media library.
     *
     * @return string
     */
    public static function file_name_suffix()
    {
        return '_Skonto';
    }

    /**
     * Create the discounted invoice for an order and store it.
     *
     * Called by all three generation paths after the ordinary invoice exists, with the
     * request body that was just used for it. A failure is recorded as an order note
     * and otherwise ignored: the ordinary invoice is already there, and the customer
     * losing a discount is a far smaller problem than an order that cannot be placed.
     *
     * @param WC_Order $order
     * @param array    $base_body The body of the ordinary invoice request.
     * @return bool Whether a discounted invoice was created.
     */
    public static function create_for_order($order, array $base_body)
    {
        if (!self::applies_to_order($order)) {
            return false;
        }

        $order_id = $order->get_id();
        $body     = $base_body;

        $body['payment_information']['amount'] = self::discounted_amount($order->get_total());

        $message = self::message($order->get_order_number());

        if ($message !== '') {
            $body['payment_information']['message'] = $message;
        } else {
            unset($body['payment_information']['message']);
        }

        // Both invoices must carry different references, otherwise a payment cannot be
        // told apart and voiding the other one is impossible. With the shop set to
        // "order number" sqrip derives the reference from exactly that number, so both
        // requests would come back with the same one. Letting sqrip assign this one
        // freely is the only way that holds under either setting.
        unset($body['payment_information']['qr_reference']);

        // Belongs to instalments, and this invoice is never one.
        unset($body['payment_information']['partial_invoice_message']);
        unset($body['invoice_fractions']);

        $args     = sqrip_prepare_remote_args($body, 'POST');
        $response = wp_remote_post(SQRIP_ENDPOINT . 'code', $args);

        if (is_wp_error($response)) {
            self::note_failure($order, $response->get_error_message());

            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response));

        if ($status !== 200 || !isset($decoded->reference) || !isset($decoded->pdf_file)) {
            $detail = isset($decoded->message) ? $decoded->message : '';

            self::note_failure($order, $detail);

            return false;
        }

        $attachment_id = file_upload_stt(
            $decoded->pdf_file,
            '.pdf',
            '',
            $order_id,
            false,
            self::file_name_suffix()
        );

        $order->update_meta_data('sqrip_skonto_reference_id', $decoded->reference);
        $order->update_meta_data('sqrip_skonto_amount', $body['payment_information']['amount']);
        $order->update_meta_data('sqrip_skonto_percentage', self::format_percentage());
        // The deadline the discount was granted for. Without it a payment of the
        // reduced amount would settle the order months later, and the shop would have
        // granted a discount for paying early to somebody who paid late.
        $order->update_meta_data(
            'sqrip_skonto_valid_until',
            date('Y-m-d', strtotime(date('Y-m-d') . ' + ' . self::due_date_days() . ' days'))
        );
        $order->update_meta_data('sqrip_skonto_qr_pdf_attachment_id', $attachment_id);
        $order->update_meta_data('sqrip_skonto_pdf_file_url', wp_get_attachment_url($attachment_id));
        $order->update_meta_data('sqrip_skonto_pdf_file_path', get_attached_file($attachment_id));

        $order->add_order_note(sprintf(
            /* translators: 1: discount percentage, 2: discounted amount */
            __('Second QR invoice with %1$s%% Skonto created, amount %2$s.', 'sqrip-swiss-qr-invoice'),
            self::format_percentage(),
            number_format((float) $body['payment_information']['amount'], 2, '.', '')
        ));

        $order->save();

        return true;
    }

    /**
     * Remove a previously created discounted invoice without leaving a note.
     *
     * Used before a regeneration: the old pair is being replaced, so the old discounted
     * PDF has to go rather than linger in the media library unreferenced.
     *
     * @param WC_Order $order
     * @return void
     */
    public static function discard_existing($order)
    {
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $attachment_id = (int) sqrip_get_order_meta_value($order, 'sqrip_skonto_qr_pdf_attachment_id');

        if ($attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }

        foreach (array(
            'sqrip_skonto_reference_id',
            'sqrip_skonto_amount',
            'sqrip_skonto_percentage',
            'sqrip_skonto_qr_pdf_attachment_id',
            'sqrip_skonto_pdf_file_url',
            'sqrip_skonto_pdf_file_path',
        ) as $key) {
            $order->delete_meta_data($key);
        }
    }

    /**
     * @param WC_Order $order
     * @param string   $detail
     * @return void
     */
    private static function note_failure($order, $detail)
    {
        $order->add_order_note(sprintf(
            /* translators: %s: error reported by sqrip */
            __('The second QR invoice with Skonto could not be created: %s The ordinary QR invoice is unaffected.', 'sqrip-swiss-qr-invoice'),
            $detail ? esc_html($detail) : ''
        ));
    }
}
