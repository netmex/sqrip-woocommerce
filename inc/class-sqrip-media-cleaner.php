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

    public function clean()
    {
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
    
            // Clean up leftover test-e-mail invoices (the test mail uses order id 11111).
            // This MUST stay restricted to attachments sqrip created itself: every upload
            // from this plugin carries the 'sqrip_invoice' meta. Without that filter the
            // query matched ANY PDF in the media library whose title, content or excerpt
            // contained "11111" and force-deleted it — including files that had nothing
            // to do with sqrip.
            $args = array(
                'post_type' => 'attachment',
                'post_mime_type' => 'application/pdf',
                'posts_per_page' => -1,
                'post_status' => 'any',
                's' => '11111',
                'meta_query' => array(
                    array(
                        'key' => 'sqrip_invoice',
                        'compare' => 'EXISTS',
                    ),
                ),
                'date_query' => array(
                    array(
                        'before' => date('Y-m-d H:00:00', $targeted_time),
                        'inclusive' => false
                    )
                )
            );
    
            $attachments = get_posts($args);
    
            if ($attachments) {
                foreach ($attachments as $attachment) {
                    $deleted_attachment = wp_delete_attachment($attachment->ID, true);
    
                    $logs .= $deleted_attachment ? ' Deleted test email attachement ' . $attachment->ID . '.' : ' No attachement deleted for id ' . $attachment->ID;
                }
            }
    
        } else {
            $logs = "Sqrip_Media_Cleaner Delete after field not enabled.";
        }
    }
}


new Sqrip_Media_Clearner;
