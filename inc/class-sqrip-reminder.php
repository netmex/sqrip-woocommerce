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
