<?php

/**
 * Operating side of the camt reconciliation: menu entry, upload endpoints, result
 * table and applying the outcome. (C3)
 *
 * The uploaded file never enters the media library and is never copied. It is read
 * from PHP's own upload buffer, and that buffer is deleted as soon as the answer is
 * built. Nothing about the file is stored — not the file, not its contents, not the
 * result. Every run reads it afresh.
 *
 * @package sqrip
 * @since 1.11
 */

defined('ABSPATH') || exit;

class Sqrip_Camt_Admin
{
    const NONCE = 'sqrip-camt-reconcile';

    /**
     * @return void
     */
    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 99);
        add_action('wp_ajax_sqrip_camt_preview', array(__CLASS__, 'ajax_preview'));
        add_action('wp_ajax_sqrip_camt_apply', array(__CLASS__, 'ajax_apply'));
    }

    /**
     * The camt reconciliation is a sub-feature of the payment comparison and needs
     * both switches.
     *
     * @return bool
     */
    public static function is_enabled()
    {
        return sqrip_get_plugin_option('payment_comparison_enabled') === 'yes'
            && sqrip_get_plugin_option('camt_reconciliation_enabled') === 'yes';
    }

    /**
     * @return string Direct link to the camt tab of the sqrip settings.
     */
    public static function page_url()
    {
        return admin_url('admin.php?page=wc-settings&tab=checkout&section=sqrip#camt');
    }

    /**
     * One click from the WooCommerce menu instead of four through the settings.
     *
     * @return void
     */
    public static function register_menu()
    {
        if (!self::is_enabled() || !current_user_can('manage_woocommerce')) {
            return;
        }

        // A menu slug containing '.php' is used by WordPress as the link target as it
        // stands, so this needs no page of its own and no redirect.
        add_submenu_page(
            'woocommerce',
            __('sqrip camt Reconciliation', 'sqrip-swiss-qr-invoice'),
            __('camt Reconciliation', 'sqrip-swiss-qr-invoice'),
            'manage_woocommerce',
            'admin.php?page=wc-settings&tab=checkout&section=sqrip#camt'
        );
    }

    /**
     * Show what would happen. Changes nothing.
     *
     * @return void
     */
    public static function ajax_preview()
    {
        self::guard();

        $file   = self::receive_file();
        $report = self::run($file['path']);

        self::discard($file['path']);

        if (!is_array($report)) {
            wp_send_json_error(array('message' => $report));
        }

        wp_send_json_success(array(
            'html'       => self::render($report, $file['name'], array()),
            'applicable' => count(self::applicable_orders($report)),
        ));
    }

    /**
     * Apply what can be applied.
     *
     * The file is read and matched again from scratch rather than trusting anything
     * the browser sends back from the preview. Orders may have changed in between, and
     * a result that decides order statuses must not be a client-side claim.
     *
     * @return void
     */
    public static function ajax_apply()
    {
        self::guard();

        $file   = self::receive_file();
        $report = self::run($file['path']);

        self::discard($file['path']);

        if (!is_array($report)) {
            wp_send_json_error(array('message' => $report));
        }

        $applied = self::apply($report, $file['name']);

        wp_send_json_success(array(
            'html'       => self::render($report, $file['name'], $applied),
            'applicable' => 0,
        ));
    }

    /**
     * @return void Dies with a JSON error when the caller has no business here.
     */
    private static function guard()
    {
        check_ajax_referer(self::NONCE, 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(
                array('message' => __('You are not allowed to do this.', 'sqrip-swiss-qr-invoice')),
                403
            );
        }

        if (!self::is_enabled()) {
            wp_send_json_error(
                array('message' => __('The camt reconciliation is switched off.', 'sqrip-swiss-qr-invoice')),
                403
            );
        }
    }

    /**
     * Take the upload without ever copying it anywhere.
     *
     * @return array {'path', 'name'} Dies with a JSON error when the file is not usable.
     */
    private static function receive_file()
    {
        if (empty($_FILES['camt_file']) || !is_array($_FILES['camt_file'])) {
            wp_send_json_error(array('message' => __('Please choose a file first.', 'sqrip-swiss-qr-invoice')));
        }

        $upload = $_FILES['camt_file'];
        $error  = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            wp_send_json_error(array(
                'message' => __('The file is larger than this server accepts for uploads.', 'sqrip-swiss-qr-invoice'),
            ));
        }

        if ($error !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => __('The file could not be uploaded.', 'sqrip-swiss-qr-invoice')));
        }

        $path = isset($upload['tmp_name']) ? $upload['tmp_name'] : '';

        // Guarantees we are looking at this request's upload and not at a path someone
        // slipped in to have an arbitrary server file read out.
        if (!$path || !is_uploaded_file($path)) {
            wp_send_json_error(array('message' => __('The file could not be uploaded.', 'sqrip-swiss-qr-invoice')));
        }

        $name = sanitize_file_name(isset($upload['name']) ? (string) $upload['name'] : 'camt.xml');

        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'xml') {
            self::discard($path);

            wp_send_json_error(array(
                'message' => __('Only the XML file from your e-banking can be read here.', 'sqrip-swiss-qr-invoice'),
            ));
        }

        $size = isset($upload['size']) ? (int) $upload['size'] : 0;

        if ($size > Sqrip_Camt_Parser::MAX_FILE_SIZE) {
            self::discard($path);

            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: maximum file size, e.g. 20 MB */
                    __('The file is larger than %s.', 'sqrip-swiss-qr-invoice'),
                    size_format(Sqrip_Camt_Parser::MAX_FILE_SIZE)
                ),
            ));
        }

        return array('path' => $path, 'name' => $name);
    }

    /**
     * @param string $path
     * @return array|string Report, or a message when it did not work.
     */
    private static function run($path)
    {
        $reconciler = new Sqrip_Camt_Reconciler();
        $report     = $reconciler->reconcile($path);

        if ($report === false) {
            return self::error_message($reconciler->get_errors());
        }

        return $report;
    }

    /**
     * PHP would drop the upload at the end of the request anyway; doing it here keeps
     * the file on disk for as short a time as possible.
     *
     * @param string $path
     * @return void
     */
    private static function discard($path)
    {
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Turn the parser's and reconciler's error codes into something readable.
     *
     * @param array $errors
     * @return string
     */
    private static function error_message(array $errors)
    {
        $code = isset($errors[0]['code']) ? $errors[0]['code'] : '';

        switch ($code) {
            case 'no_awaiting_status':
                return __('No order status for "waiting for payment" is configured yet. Please set it under Payment Comparison first.', 'sqrip-swiss-qr-invoice');
            case 'empty':
                return __('The file is empty.', 'sqrip-swiss-qr-invoice');
            case 'too_large':
                return __('The file is too large.', 'sqrip-swiss-qr-invoice');
            case 'not_xml':
            case 'malformed_xml':
                return __('This file could not be read. Please download the camt file from your e-banking again.', 'sqrip-swiss-qr-invoice');
            case 'no_entries':
                return __('There are no bookings in this file. Please check whether you downloaded the right period.', 'sqrip-swiss-qr-invoice');
        }

        return __('The file could not be processed.', 'sqrip-swiss-qr-invoice');
    }

    /**
     * The orders whose outcome may be applied without asking.
     *
     * @param array $report
     * @return array
     */
    private static function applicable_orders(array $report)
    {
        $applicable = array();

        foreach ($report['orders'] as $entry) {
            if ($entry['category'] === Sqrip_Camt_Reconciler::PAID
                || ($entry['category'] === Sqrip_Camt_Reconciler::PARTLY_PAID && $entry['applicable_slips'])) {
                $applicable[] = $entry;
            }
        }

        return $applicable;
    }

    /**
     * Book the payments that need no judgement.
     *
     * @param array  $report
     * @param string $file_name
     * @return array Order ids that were changed, with what was booked.
     */
    private static function apply(array $report, $file_name)
    {
        $status_completed = sqrip_get_plugin_option('status_completed');
        $applied          = array();

        foreach (self::applicable_orders($report) as $entry) {
            $order = wc_get_order($entry['order_id']);

            if (!$order || $order->get_payment_method() !== 'sqrip') {
                continue;
            }

            $booked = array();

            if ($entry['applicable_slips']) {
                foreach ($entry['applicable_slips'] as $index) {
                    $slip = self::slip_by_index($entry, $index);

                    if (!$slip) {
                        continue;
                    }

                    $order->add_order_note(self::note($slip, $file_name, $index, $entry['slip_count']));
                    $booked[] = $slip;
                }

                $last = (int) end($entry['applicable_slips']);
                $order->update_meta_data('sqrip_paid_invoice_number', $last);
                $order->save();

                // Only the status the order ends up in is applied. Walking through the
                // intermediate ones would fire a status change — and its customer
                // e-mail — for every instalment settled in the same run.
                $status = $last >= (int) $entry['slip_count']
                    ? $status_completed
                    : sqrip_get_plugin_option('partial_invoice_' . $last . '_status');
            } else {
                // With a Skonto invoice the order carries two references and only one
                // of them was paid, so pick the one that actually matched.
                $slip = null;

                foreach ($entry['slips'] as $candidate) {
                    if ($candidate['category'] === Sqrip_Camt_Reconciler::PAID) {
                        $slip = $candidate;
                        break;
                    }
                }

                if (!$slip) {
                    continue;
                }

                $order->add_order_note(self::note($slip, $file_name));
                $booked[] = $slip;

                // Whichever invoice was used, the others must stop being payable.
                if (!empty($entry['alternatives']) && !empty($entry['paid_alternative'])) {
                    sqrip_void_other_invoices($order, $entry['paid_alternative']);
                }

                $status = $status_completed;
            }

            // update_status('') would make WooCommerce quietly drop the order back to
            // pending, so only touch the status when one is actually configured.
            if ($status) {
                $order->update_status($status, '');
            } else {
                $order->save();
            }

            $applied[$entry['order_id']] = array(
                'order_number' => $entry['order_number'],
                'slips'        => $booked,
            );
        }

        return $applied;
    }

    /**
     * @param array $entry
     * @param int   $index
     * @return array|null
     */
    private static function slip_by_index($entry, $index)
    {
        foreach ($entry['slips'] as $slip) {
            if ((int) $slip['index'] === (int) $index) {
                return $slip;
            }
        }

        return null;
    }

    /**
     * The order note left behind for every booked payment.
     *
     * @param array       $slip
     * @param string      $file_name
     * @param int|null    $index
     * @param int|null    $count
     * @return string
     */
    private static function note($slip, $file_name, $index = null, $count = null)
    {
        $payment = isset($slip['payments'][0]) ? $slip['payments'][0] : array();

        $amount   = isset($payment['amount']) ? number_format((float) $payment['amount'], 2, '.', '') : '';
        $currency = isset($payment['currency']) ? $payment['currency'] : '';
        $date     = isset($payment['value_date']) ? $payment['value_date'] : '';

        if ($date) {
            $timestamp = strtotime($date);
            $date      = $timestamp ? date_i18n(get_option('date_format'), $timestamp) : $date;
        }

        $what = $index
            ? sprintf(
                /* translators: 1: instalment number, 2: number of instalments */
                __('Instalment %1$d of %2$d paid', 'sqrip-swiss-qr-invoice'),
                $index,
                $count
            )
            : __('Payment received', 'sqrip-swiss-qr-invoice');

        return sprintf(
            /* translators: 1: what was paid, 2: currency, 3: amount, 4: reference, 5: value date, 6: file name */
            __('%1$s: %2$s %3$s, reference %4$s, value date %5$s. Reconciled from the bank file %6$s.', 'sqrip-swiss-qr-invoice'),
            $what,
            $currency,
            $amount,
            $slip['reference'],
            $date,
            $file_name
        );
    }

    /**
     * The result table.
     *
     * @param array  $report
     * @param string $file_name
     * @param array  $applied Empty for a preview.
     * @return string
     */
    private static function render(array $report, $file_name, array $applied)
    {
        $is_preview = empty($applied);

        ob_start();
        ?>
        <div class="sqrip-camt-report">
            <p>
                <?php
                printf(
                    /* translators: 1: file name, 2: number of credits, 3: number of orders */
                    esc_html__('File %1$s: %2$d incoming payments, checked against %3$d orders waiting for payment.', 'sqrip-swiss-qr-invoice'),
                    '<strong>' . esc_html($file_name) . '</strong>',
                    (int) $report['credits_total'],
                    (int) $report['orders_scanned']
                );
                ?>
            </p>

            <?php if (!empty($report['unmatched_credits'])) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %d: number of credits that belong to no open order */
                        esc_html(
                            _n(
                                '%d further incoming payment in this file belongs to no order waiting for payment. It was counted and otherwise ignored.',
                                '%d further incoming payments in this file belong to no order waiting for payment. They were counted and otherwise ignored.',
                                (int) $report['unmatched_credits'],
                                'sqrip-swiss-qr-invoice'
                            )
                        ),
                        (int) $report['unmatched_credits']
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($report['orders_truncated'])) : ?>
                <p class="sqrip-notice error">
                    <?php esc_html_e('There are more orders waiting for payment than can be checked in one go. Please reconcile again after applying this run.', 'sqrip-swiss-qr-invoice'); ?>
                </p>
            <?php endif; ?>

            <?php
            $ready     = self::applicable_orders($report);
            $attention = self::orders_needing_attention($report);
            $open      = self::count_by_category($report, Sqrip_Camt_Reconciler::OPEN);

            if (!$is_preview) {
                self::render_applied($applied);
            } elseif ($ready) {
                self::render_table(
                    __('Will be marked as paid', 'sqrip-swiss-qr-invoice'),
                    $ready,
                    ''
                );
            } else {
                echo '<p>' . esc_html__('There is no payment that can be booked without checking.', 'sqrip-swiss-qr-invoice') . '</p>';
            }

            if ($attention) {
                self::render_table(
                    __('Please check these yourself', 'sqrip-swiss-qr-invoice'),
                    $attention,
                    __('sqrip changes nothing here.', 'sqrip-swiss-qr-invoice')
                );
            }
            ?>

            <?php if ($open) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %d: number of orders */
                        esc_html(
                            _n(
                                '%d order is still waiting for payment; there is nothing for it in this file.',
                                '%d orders are still waiting for payment; there is nothing for them in this file.',
                                $open,
                                'sqrip-swiss-qr-invoice'
                            )
                        ),
                        $open
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * @param array $applied
     * @return void
     */
    private static function render_applied(array $applied)
    {
        if (!$applied) {
            echo '<p>' . esc_html__('Nothing was changed.', 'sqrip-swiss-qr-invoice') . '</p>';

            return;
        }

        echo '<h4>' . esc_html__('Marked as paid', 'sqrip-swiss-qr-invoice') . '</h4>';
        echo '<table class="widefat striped"><tbody>';

        foreach ($applied as $order_id => $entry) {
            foreach ($entry['slips'] as $slip) {
                $payment = isset($slip['payments'][0]) ? $slip['payments'][0] : array();

                echo '<tr>';
                echo '<td>' . self::order_link($order_id, $entry['order_number']) . '</td>';
                echo '<td>' . esc_html(self::format_amount($payment)) . '</td>';
                echo '<td><code>' . esc_html($slip['reference']) . '</code></td>';
                echo '<td>' . esc_html(isset($payment['value_date']) ? $payment['value_date'] : '') . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    /**
     * @param string $title
     * @param array  $entries
     * @param string $hint
     * @return void
     */
    private static function render_table($title, array $entries, $hint)
    {
        echo '<h4>' . esc_html($title) . '</h4>';

        if ($hint) {
            echo '<p class="description">' . esc_html($hint) . '</p>';
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Order', 'sqrip-swiss-qr-invoice') . '</th>';
        echo '<th>' . esc_html__('Expected', 'sqrip-swiss-qr-invoice') . '</th>';
        echo '<th>' . esc_html__('Received', 'sqrip-swiss-qr-invoice') . '</th>';
        echo '<th>' . esc_html__('Reference', 'sqrip-swiss-qr-invoice') . '</th>';
        echo '<th>' . esc_html__('Note', 'sqrip-swiss-qr-invoice') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            foreach ($entry['slips'] as $slip) {
                if ($slip['category'] === Sqrip_Camt_Reconciler::OPEN) {
                    continue;
                }

                $payment = isset($slip['payments'][0]) ? $slip['payments'][0] : array();

                echo '<tr>';
                echo '<td>' . self::order_link($entry['order_id'], $entry['order_number']);

                if ($slip['index']) {
                    echo ' <span class="description">' . esc_html(sprintf('[%d/%d]', $slip['index'], $entry['slip_count'])) . '</span>';
                }

                echo '</td>';
                echo '<td>' . esc_html($slip['expected'] === null ? '—' : $entry['currency'] . ' ' . number_format((float) $slip['expected'], 2, '.', '')) . '</td>';
                echo '<td>' . esc_html(self::format_amount($payment)) . '</td>';
                echo '<td><code>' . esc_html($slip['reference']) . '</code></td>';
                echo '<td>' . esc_html(self::reason($slip)) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    /**
     * Plain words for why something is not being booked.
     *
     * @param array $slip
     * @return string
     */
    private static function reason($slip)
    {
        switch ($slip['category']) {
            case Sqrip_Camt_Reconciler::PAID:
                return __('Amount agrees', 'sqrip-swiss-qr-invoice');
            case Sqrip_Camt_Reconciler::DUPLICATE:
                return count($slip['payments']) > 1
                    ? __('Paid more than once', 'sqrip-swiss-qr-invoice')
                    : __('Was already confirmed', 'sqrip-swiss-qr-invoice');
            case Sqrip_Camt_Reconciler::AMOUNT_MISMATCH:
                if ($slip['expected'] === null) {
                    return __('No amount stored for this instalment', 'sqrip-swiss-qr-invoice');
                }

                $payment = isset($slip['payments'][0]) ? $slip['payments'][0] : array();

                if ($payment && Sqrip_Camt_Reconciler::paid_after_deadline($slip, $payment)) {
                    return __('Skonto taken after the deadline', 'sqrip-swiss-qr-invoice');
                }

                if ($payment && abs((float) $payment['amount'] - (float) $slip['expected']) < 0.005) {
                    return __('Amount agrees, but the late fee is unpaid', 'sqrip-swiss-qr-invoice');
                }

                return __('Amount differs', 'sqrip-swiss-qr-invoice');
            case Sqrip_Camt_Reconciler::OUT_OF_SEQUENCE:
                return __('An earlier instalment is still unpaid', 'sqrip-swiss-qr-invoice');
        }

        return '';
    }

    /**
     * @param array $report
     * @return array
     */
    private static function orders_needing_attention(array $report)
    {
        $manual = array(
            Sqrip_Camt_Reconciler::AMOUNT_MISMATCH,
            Sqrip_Camt_Reconciler::DUPLICATE,
            Sqrip_Camt_Reconciler::OUT_OF_SEQUENCE,
        );

        $entries = array();

        foreach ($report['orders'] as $entry) {
            if (in_array($entry['category'], $manual, true)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param array  $report
     * @param string $category
     * @return int
     */
    private static function count_by_category(array $report, $category)
    {
        $count = 0;

        foreach ($report['orders'] as $entry) {
            if ($entry['category'] === $category) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array $payment
     * @return string
     */
    private static function format_amount($payment)
    {
        if (empty($payment) || !isset($payment['amount'])) {
            return '—';
        }

        return trim($payment['currency'] . ' ' . number_format((float) $payment['amount'], 2, '.', ''));
    }

    /**
     * @param int    $order_id
     * @param string $order_number
     * @return string Escaped markup.
     */
    private static function order_link($order_id, $order_number)
    {
        $url = admin_url('post.php?post=' . (int) $order_id . '&action=edit');

        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $url = admin_url('admin.php?page=wc-orders&action=edit&id=' . (int) $order_id);
        }

        return '<a href="' . esc_url($url) . '" target="_blank">#' . esc_html($order_number) . '</a>';
    }
}
