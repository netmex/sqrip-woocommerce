<?php 
class Sqrip_Orders_Reminder {

    public $cron_hook;

    public function __construct() {
        // Store our cron hook name
        $this->cron_hook = 'sqrip_orders_reminder_email';
        // Install cron!
        $this->setup_cron();

        // Add action that points to class method
        add_action($this->cron_hook, array($this, 'reminders'));
    }

    public function setup_cron() {
        // Return if existing hooks
        if (wp_next_scheduled($this->cron_hook)) return;
        // wp_clear_scheduled_hook($this->cron_hook);

        // Add schedule event
        wp_schedule_event(time(), 'daily', $this->cron_hook);
    }

    public function reminders() {

        $today      = strtotime( date('Y-m-d') );
        $due_date = sqrip_get_plugin_option('due_date');
        $status_reminders = sqrip_get_plugin_option('status_reminders');
        $due_reminder = sqrip_get_plugin_option('due_reminder');
        $one_day = 24*60*60;
        $sent_reminder_key = '_sent_email_reminder';

        $processing_orders = (array) wc_get_orders( array(
            'limit'         => -1,
            'status'        => $status_reminders,
            'date_created'  => '<' . ( $today - ($due_reminder * $one_day) - ($due_date * $one_day) ), 
            'meta_key'      => $sent_reminder_key,
            'meta_compare'  => 'NOT EXISTS'
        ) );


        if ( sizeof($processing_orders) > 0 ) {
            $reminder_text = __("Email Reminder sent", "sqrip-swiss-qr-invoice");
            $sqrip_email = $this->get_remail_reminder();

            foreach ( $processing_orders as $order ) {
                $order_id = $order->get_id();
                $email_sent = $sqrip_email->trigger( $order_id ); // Send email
                
                if ( $email_sent ) {
                    $order->update_meta_data( $sent_reminder_key, true );
                    $order->add_order_note( $reminder_text );
                } else {
                    delete_post_meta($order_id, $sent_reminder_key);
                    $order->add_order_note( __("Email Reminder failed to send", "sqrip-swiss-qr-invoice") );
                }                
            }
        }
    }

    function get_remail_reminder(){
        $sqrip_email = null;
        $email_reminder = sqrip_get_plugin_option('email_reminder');
        $wc_emails = WC()->mailer()->get_emails(); // Get all WC_emails objects instances

        foreach ($wc_emails as $wc_email) {
            if ($wc_email->id == $email_reminder) {
                $sqrip_email = $wc_email;
                break;
            }
        }

        return $sqrip_email;
    }
} 


new Sqrip_Orders_Reminder;
