<?php 

/**
 * sqrip Media Cleaner
 *
 * @since 1.6
 */

class Sqrip_Media_Clearner {

    public $cron_hook;

    public function __construct() {
        $this->expired_date = sqrip_get_plugin_option('expired_date');
       
        if (!$this->expired_date) {
            return;
        }
        // Store our cron hook name
        $this->cron_hook = 'sqrip_media_cleaner';
        // Install cron!
        $this->setup_cron();

        // Add action that points to class method
        add_action($this->cron_hook, array($this, 'clean'));
    }

    public function setup_cron() {
        // Return if existing hooks
        if (wp_next_scheduled($this->cron_hook)) return;
        // wp_clear_scheduled_hook($this->cron_hook);

        // Add schedule event
        wp_schedule_event(time(), 'daily', $this->cron_hook);
    }

    public function clean() {
        // How many days old.
        $days = $this->expired_date;

        // TRUE = bypasses the trash and force deletion.
        $status_completed = sqrip_get_plugin_option('status_completed');

        $time_delay       = 60 * 60 * 24 * $days; 
        $current_time     = strtotime( date('Y-m-d H:00:00') );
        $targeted_time    = $current_time - $time_delay;

        $completed_orders = (array) wc_get_orders( array(
            'limit'             => -1,
            'status'            => $status_completed,
            'date_created'      => '<' . $targeted_time,
            'payment_method'    => 'sqrip',
        ) );

        $logs = 'Sqrip_Media_Cleaner starting...';

        if ($completed_orders) {
            foreach ( $completed_orders as $order ) {
                $att_id = get_post_meta($order->ID, 'sqrip_qr_pdf_attachment_id', true);
              
                wp_delete_attachment( $att_id, true );

                $logs .= ' Deleted attachement '.$att_id.' in order #'.$order->ID.'.';
            }

            $logs .= 'Sqrip_Media_Cleaner ran and deleted '.count($completed_orders).' invoices!';

        } else {
            $logs .= 'Sqrip_Media_Cleaner ran and deleted 0 invoices!';
        }

        error_log($logs);
        
    }
} 


new Sqrip_Media_Clearner;
