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
     * Deletes QR invoices that are no longer needed.
     *
     * @return int Number of files actually deleted.
     */
    public function clean()
    {
        $deleted_files = 0;

        // How many days old.
        $days = $this->expired_date;

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
                if (wp_delete_attachment($attachment_id, true)) {
                    $deleted_files++;
                    $logs .= ' Deleted sqrip attachment ' . $attachment_id . '.';
                }
            }

        } else {
            $logs = "Sqrip_Media_Cleaner Delete after field not enabled.";
        }

        return $deleted_files;
    }
}


new Sqrip_Media_Clearner;
