<?php 
/**
 * Sqrip Payment verification class
 *
 * @package sqrip
 */
class Sqrip_Payment_Verification {

    public $cron_hook;

    public function __construct() {
        // Store our cron hook name
        $this->cron_hook = 'sqrip_payment_verification';
        // Add action that points to class method
        add_action($this->cron_hook, array($this, 'verify'));
    }

    public function refresh_cron() {   
        $clear = $this->clear_cron();

        $recurrence = sqrip_get_plugin_option('payment_frequence');
        $times = sqrip_get_plugin_option('payment_frequence_time');
        
        if (!is_array($recurrence) || !is_array($times)) {
            return;
        }

        $this->send_to_api($recurrence, $times);

        $hour = 0;
        $min = 0;

        $setup_cron = false;
        foreach ($recurrence as $day) {
            $date = new DateTime('next '.$day);

            foreach ($times as $time) {
                $time_arr = explode(':', $time);

                if (is_array($time_arr)) {
                    $hour = $time_arr[0];
                    $min = $time_arr[1];
                }

                $date->setTime($hour, $min);
                $timestamp = $date->getTimestamp();

                $setup_cron = $this->setup_cron($timestamp);
            }
        }    

        return $setup_cron;    
    }

    public function send_to_api($recurrence, $times){
        $endpoint = 'plugin-settings';

        $hours = [];
        foreach ($times as $time) {
            $time_arr = explode(':', $time);

            if (is_array($time_arr)) {
                $hours[] = $time_arr[0];
            }
        }

        $plugin_url = site_url( 'wp-cron.php' );

        $body = '{
           "period": "all",
           "hours": '.json_encode($hours).',
           "week_days": '.json_encode($recurrence).',
           "plugin_url": "'.$plugin_url.'"
        }';

        $response = sqrip_remote_request($endpoint, $body, 'POST');  
    }

    public function setup_cron($timestamp){
        // Add schedule event
        return wp_schedule_event($timestamp, 'weekly', $this->cron_hook);
    }

    public function clear_cron(){
        $clear = wp_clear_scheduled_hook($this->cron_hook);

        return $clear;
    }

    public function verify() {
        $logs = array();
        $logs[] = '[Sqrip_Payment_Verification] is starting...';

        $orders = sqrip_get_awaiting_orders();

        if ($orders) {
            $body = [];
            $body['orders'] = $orders;
            $endpoint = 'confirm-order';

            $logs[] = 'Starting request to sqrip to confirm order...';
            $response = sqrip_remote_request( $endpoint, $body, 'POST' );

            $logs[] = 'SQRIP response:';
            $logs[] = print_r($response, true);

            if ($response) {

                if (isset($response->orders_unmatched) && isset($response->orders_matched)) {
                    $orders = array_merge($response->orders_unmatched, $response->orders_matched);

                    $updated = $this->update_order_status($orders);

                    $logs[] = $updated ? print_r($updated, true) : 'No order status updated!';
                }

                $send_report = sqrip_get_plugin_option('comparison_report');

                $logs[] = 'Checking Comparison Report setting: '.$send_report;

                if ( $send_report == "yes" || $send_report == "true" ) {
                    $Sqrip_Ajax = new Sqrip_Ajax;

                    $html = $Sqrip_Ajax->get_table_results($response, $orders);
                    $email_sent = $Sqrip_Ajax->send_report($html);

                    $logs[] = $email_sent ? 'Sent report to '.$email_sent.'! ' : 'Email failed to send. Please check your Email SMTP settings.';
                }
            } 

        } else {

            $logs[] = sprintf('No order with %s status found!', $status_awaiting);

        }

        $logs[] = '[Sqrip_Payment_Verification] Finished!';

        error_log(print_r($logs, true));
    }

    public function update_order_status($orders){

        if (!$orders) {
            return;
        }

        $status_completed = sqrip_get_plugin_option('status_completed');

        $updated  = [];

        foreach ($orders as $order) {

            if ($order['paid_amount'] >= $order['amount']) {
                $order_id = $order['order_id'];
                $order = new WC_Order($order_id);
               
                $updated[$order_id] =  $order->update_status($status_completed);
            }
            
        }

        return $updated;
    }

} 

new Sqrip_Payment_Verification;