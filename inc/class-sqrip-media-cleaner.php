<?php

/**
 * sqrip Media Cleaner
 *
 * @since 1.6
 */

class Sqrip_Media_Clearner
{

    public $cron_hook;

    public function __construct()
    {
        $this->expired_date = sqrip_get_plugin_option('expired_date');

        if (!$this->expired_date) {
            return;
        }

        error_log('Deletion job starting...');
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

        $time_delay = 60 * 60 * 24 * $days;
        $current_time = strtotime(date('Y-m-d H:00:00'));
        $targeted_time = $current_time - $time_delay;

        $completed_orders = (array)wc_get_orders(array(
            'limit' => -1,
            'date_created' => '<' . $targeted_time,
            'payment_method' => 'sqrip',
        ));

        $logs = 'Sqrip_Media_Cleaner starting...';

        if ($completed_orders) {
            foreach ($completed_orders as $order) {
                $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->ID;
                
                $att_id = "";
                $attach_url = "";
                // Implement compatibility with WooCommerce HPOS
                if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
                    $order = wc_get_order($order_id);
                    $att_id = $order->get_meta('sqrip_qr_pdf_attachment_id', true);

                    if (!$att_id) {
                        $attach_url = $order->get_meta('sqrip_pdf_file_url', true);
                        $att_id = attachment_url_to_postid($attach_url);
                    }

                    $deleted_att = wp_delete_attachment($att_id, true);
                    $order->update_meta_data('sqrip_pdf_file_path', 'deleted');
                    $order->update_meta_data('sqrip_pdf_file_url', 'deleted');
                    $order->save();
                } else {
                    $att_id = get_post_meta($order_id, 'sqrip_qr_pdf_attachment_id', true);

                    if (!$att_id) {
                        $attach_url = get_post_meta($order_id, 'sqrip_pdf_file_url', true);
                        $att_id = attachment_url_to_postid($attach_url);
                    }

                    $deleted_att = wp_delete_attachment($att_id, true);
                    update_post_meta($order_id, 'sqrip_pdf_file_path', 'deleted');
                    update_post_meta($order_id, 'sqrip_pdf_file_url', 'deleted');
                }

                $logs .= $deleted_att ? ' Deleted attachement ' . $att_id . ' in order #' . $order_id . '.' : ' No attachement deleted for order #' . $order_id;

                $logs .= ' Deleted sqrip_reference_id, sqrip_qr_pdf_attachment_id, sqrip_pdf_file_path & sqrip_pdf_file_url for order #' . $order_id . '.';

                $order_notes = __("The PDF file for order #$order_id has been deleted from the media library", 'sqrip-swiss-qr-invoice');
                $order->add_order_note($order_notes);
            }

            $logs .= 'Sqrip_Media_Cleaner ran and deleted ' . count($completed_orders) . ' invoices!';

        } else {
            $logs .= 'Sqrip_Media_Cleaner ran and deleted 0 invoices!';
        }

        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'posts_per_page' => -1,
            'post_status' => 'any',
            's' => '11111',
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

        error_log($logs);
    }
}


new Sqrip_Media_Clearner;
