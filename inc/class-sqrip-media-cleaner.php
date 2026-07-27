<?php

/**
 * sqrip Media Cleaner
 *
 * @since 1.6
 */

class Sqrip_Media_Clearner
{

    public $cron_hook;
    
    public $expired_date;

    public function __construct()
    {
        $this->expired_date = sqrip_get_plugin_option('expired_date');

        if (!$this->expired_date) {
            return;
        }

        // error_log('Deletion job starting...');
        // Store our cron hook name
        $this->cron_hook = 'sqrip_media_cleaner';
        // Install cron!
        $this->setup_cron();

        // Add action that points to class method
        add_action($this->cron_hook, array($this, 'clean'));
    }

    public function setup_cron()
    {
        // Return if existing hooks
        if (wp_next_scheduled($this->cron_hook)) return;
        // wp_clear_scheduled_hook($this->cron_hook);

        // Add schedule event
        wp_schedule_event(time(), 'daily', $this->cron_hook);
    }

    /**
     * Order statuses in which a customer may still pay, so the QR invoice has to stay.
     *
     * Covers WooCommerce's own pre-payment statuses plus whatever the shop configured,
     * including the partial-payment statuses — a partially paid order is not settled yet.
     *
     * @return array Status slugs without the 'wc-' prefix.
     */
    protected function awaiting_payment_statuses()
    {
        $statuses = array('pending', 'on-hold', 'failed', 'checkout-draft');

        $option_keys = array(
            'status_awaiting',
            'qr_order_status',
            'status_suppressed',
            'partial_invoice_1_status',
            'partial_invoice_2_status',
            'partial_invoice_3_status',
        );

        foreach ($option_keys as $key) {
            $status = sqrip_get_plugin_option($key);

            if ($status) {
                $statuses[] = str_replace('wc-', '', (string) $status);
            }
        }

        return array_values(array_unique(array_filter($statuses)));
    }

    /**
     * Every media-library attachment that belongs to one order.
     *
     * Collects all variants: the single invoice, the slips of a multi-invoice order, the
     * refund QR code and the PNG used for the PDF-invoice integration — the narrow lookup
     * on one meta key is exactly why files used to be left behind.
     *
     * @param WC_Order $order
     * @return array Attachment ids.
     */
    protected function attachment_ids_for_order($order)
    {
        $ids = array();

        $id_keys = array('sqrip_qr_pdf_attachment_id', 'sqrip_refund_qr_attachment_id');
        $url_keys = array('sqrip_pdf_file_url', 'sqrip_png_file_url');

        for ($i = 1; $i <= 5; $i++) {
            $id_keys[] = 'sqrip_qr_pdf_attachment_id_' . $i;
            $url_keys[] = 'sqrip_pdf_file_url_' . $i;
        }

        foreach ($id_keys as $key) {
            $value = (int) sqrip_get_order_meta_value($order, $key);

            if ($value) {
                $ids[] = $value;
            }
        }

        foreach ($url_keys as $key) {
            $url = sqrip_get_order_meta_value($order, $key);

            if ($url && $url !== 'deleted') {
                $value = (int) attachment_url_to_postid($url);

                if ($value) {
                    $ids[] = $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }


    /**
     * Marks orders whose QR-invoice files no longer exist.
     *
     * Without this the order still points at a deleted attachment: the sqrip box shows a
     * dead link and, worse, the e-mail filter hands a missing file to PHPMailer, which
     * makes wp_mail() fail so the customer receives no e-mail at all.
     *
     * @return void
     */
    protected function mark_orders_without_invoice()
    {
        $orders = (array) wc_get_orders(array(
            'limit' => -1,
            'payment_method' => 'sqrip',
        ));

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $changed = false;

            $url_keys = array('sqrip_pdf_file_url', 'sqrip_png_file_url');
            $path_keys = array('sqrip_pdf_file_path');

            for ($i = 1; $i <= 5; $i++) {
                $url_keys[] = 'sqrip_pdf_file_url_' . $i;
                $path_keys[] = 'sqrip_pdf_file_path_' . $i;
            }

            foreach ($url_keys as $key) {
                $url = sqrip_get_order_meta_value($order, $key);

                if ($url && $url !== 'deleted' && !attachment_url_to_postid($url)) {
                    $order->update_meta_data($key, 'deleted');
                    $changed = true;
                }
            }

            foreach ($path_keys as $key) {
                $path = sqrip_get_order_meta_value($order, $key);

                if ($path && $path !== 'deleted' && !file_exists($path)) {
                    $order->update_meta_data($key, 'deleted');
                    $changed = true;
                }
            }

            if ($changed) {
                $order->save();
            }
        }
    }

    /**
     * Deletes QR invoices that are no longer needed.
     *
     * Files of orders that are still waiting for payment are ALWAYS kept — the customer
     * may still need that QR code. Everything else sqrip created is fair game.
     *
     * @param bool $ignore_age true = every file up to today (the manual button),
     *                         false = only files past the configured retention (cron).
     * @return int Number of files actually deleted.
     */
    public function clean($ignore_age = false)
    {
        $deleted_files = 0;

        // Collect the files that must survive: everything belonging to an order in which
        // the customer may still pay.
        $keep = array();

        $awaiting_orders = (array) wc_get_orders(array(
            'limit' => -1,
            'payment_method' => 'sqrip',
            'status' => $this->awaiting_payment_statuses(),
        ));

        foreach ($awaiting_orders as $awaiting_order) {
            if (!$awaiting_order instanceof WC_Order) {
                continue;
            }

            foreach ($this->attachment_ids_for_order($awaiting_order) as $keep_id) {
                $keep[$keep_id] = true;
            }
        }

        // How many days old.
        $days = $this->expired_date;

        if ($ignore_age) {
            // Manual clean-up: delete every sqrip file that is not protected above.
            $sweep = get_posts(array(
                'post_type' => 'attachment',
                'posts_per_page' => -1,
                'post_status' => 'any',
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => 'sqrip_invoice',
                        'compare' => 'EXISTS',
                    ),
                ),
            ));

            foreach ($sweep as $attachment_id) {
                if (isset($keep[(int) $attachment_id])) {
                    continue;
                }

                if (wp_delete_attachment($attachment_id, true)) {
                    $deleted_files++;
                }
            }

            $this->mark_orders_without_invoice();

            return $deleted_files;
        }

        if ($days && is_numeric($days)) {

            $time_delay = 60 * 60 * 24 * $days;
            $current_time = strtotime(date('Y-m-d H:00:00'));
            $targeted_time = $current_time - $time_delay;
            $targeted_date = date('Y-m-d', $targeted_time);

            $completed_orders = (array)wc_get_orders(array(
                'limit' => -1,
                'date_created' => '<' . $targeted_time,
                // 'status' => array( 'wc-completed' ),
                'payment_method' => 'sqrip',
            ));
    
            $logs = 'Sqrip_Media_Cleaner starting...';
    
            if ($completed_orders) {
                foreach ($completed_orders as $order) {
                    if (!$order instanceof WC_Order) {
                        continue;
                    }

                    // Never touch an order in which the customer may still pay.
                    if ($order->has_status($this->awaiting_payment_statuses())) {
                        continue;
                    }

                    $order_id = $order->get_id();

                    // sqrip_get_order_meta_value() reads through the order object, so this
                    // works on HPOS and classic storage without branching.
                    $att_id = sqrip_get_order_meta_value($order, 'sqrip_qr_pdf_attachment_id');

                    if (!$att_id) {
                        $attach_url = sqrip_get_order_meta_value($order, 'sqrip_pdf_file_url');
                        $att_id = ($attach_url && $attach_url !== 'deleted') ? attachment_url_to_postid($attach_url) : 0;
                    }

                    $att_id = (int) $att_id;

                    if (!$att_id) {
                        // Nothing to delete. Do NOT write the 'deleted' markers here — they
                        // are also used as an attachment path, so claiming a deletion that
                        // never happened silently breaks later e-mails for this order.
                        $logs .= ' No attachement deleted for order #' . $order_id;
                        continue;
                    }

                    $deleted_att = wp_delete_attachment($att_id, true);

                    $logs .= $deleted_att ? ' Deleted attachement ' . $att_id . ' in order #' . $order_id . '.' : ' No attachement deleted for order #' . $order_id;

                    if ($deleted_att) {
                        $deleted_files++;

                        $order->update_meta_data('sqrip_pdf_file_path', 'deleted');
                        $order->update_meta_data('sqrip_pdf_file_url', 'deleted');
                        $order->save();

                        $order_notes = sprintf(__('The PDF file for order #%s has been deleted from the media library', 'sqrip-swiss-qr-invoice'), $order_id);
                        $order->add_order_note($order_notes);
                    }
                }
    
                $logs .= 'Sqrip_Media_Cleaner ran and deleted ' . count($completed_orders) . ' invoices!';
    
            } else {
                $logs .= 'Sqrip_Media_Cleaner ran and deleted 0 invoices!';
            }
    
            // Sweep every file sqrip itself created that is past the retention period.
            //
            // The order loop above only ever finds the ONE invoice a given order currently
            // points at, which left a lot behind:
            //   - PNG files (the refund QR code and the PNG for the PDF-invoice
            //     integration) — never referenced by 'sqrip_qr_pdf_attachment_id';
            //   - files from earlier regenerations ("…-1.pdf", "…_001.pdf") that no order
            //     references any more, so nothing could ever find them again;
            //   - the individual slips of a multi-slip order (…_url_1, …_url_2);
            //   - the test-e-mail invoices.
            //
            // Selecting by the 'sqrip_invoice' meta that every sqrip upload carries covers
            // all of them at once and, by construction, cannot touch a file that sqrip did
            // not create. The attachment's own date is the right clock here: the setting
            // says "delete x days after creation".
            $sweep = get_posts(array(
                'post_type' => 'attachment',
                'posts_per_page' => -1,
                'post_status' => 'any',
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => 'sqrip_invoice',
                        'compare' => 'EXISTS',
                    ),
                ),
                'date_query' => array(
                    array(
                        'before' => date('Y-m-d H:i:s', $targeted_time),
                        'inclusive' => false,
                    ),
                ),
            ));

            foreach ($sweep as $attachment_id) {
                // Files of orders that are still waiting for payment stay, no matter how
                // old they are — the customer may still need that QR code.
                if (isset($keep[(int) $attachment_id])) {
                    continue;
                }

                if (wp_delete_attachment($attachment_id, true)) {
                    $deleted_files++;
                    $logs .= ' Deleted sqrip attachment ' . $attachment_id . '.';
                }
            }

            $this->mark_orders_without_invoice();

        } else {
            $logs = "Sqrip_Media_Cleaner Delete after field not enabled.";
        }

        return $deleted_files;
    }
}


new Sqrip_Media_Clearner;
