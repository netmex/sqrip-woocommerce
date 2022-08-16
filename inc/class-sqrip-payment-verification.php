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
        
        if (!is_array($recurrence)) {
            return;
        }

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

    public function setup_cron($timestamp){
        // Add schedule event
        return wp_schedule_event($timestamp, 'weekly', $this->cron_hook);
    }

    public function clear_cron(){
        $clear = wp_clear_scheduled_hook($this->cron_hook);

        return $clear;
    }

    public function verify() {

        $logs = '[Sqrip_Payment_Verification] is starting...';

        $status_awaiting = sqrip_get_plugin_option('status_awaiting');

        $awaiting_orders = (array) wc_get_orders( array(
            'limit'         => -1,
            'status'        => $status_awaiting,
        ) );

        $orders = [];

        if ( sizeof($awaiting_orders) > 0 ) {
            foreach ( $awaiting_orders as $aw_order ) {
                $order['order_id']  = $aw_order->get_id();
                $order['amount']    = $aw_order->get_total();
                $order['reference'] = $aw_order->get_meta('sqrip_reference_id');
                $order['date']      = $aw_order->get_date_created()->date('Y-m-d H:i:s');

                array_push($orders, $order);
            }
        }

        if ($orders) {
            $body = [];
            $body['orders'] = $orders;
            $endpoint = 'confirm-order';

            $logs .= 'Starting request to sqrip to confirm order...';
            $response = sqrip_remote_request( $endpoint, $body, 'POST' );
            $logs .= print_r($response, true);

            if ($response) {

                if (isset($response->orders_unmatched) && isset($response->orders_matched)) {
                    $orders = array_merge($response->orders_unmatched, $response->orders_matched);

                    $updated = $this->update_order_status($orders);

                    $logs .= print_r($updated, true);
                }

                $send_report = sqrip_get_plugin_option('comparison_report');
                
                if ( $send_report === "true" ) {
                    $Sqrip_Ajax = new Sqrip_Ajax;

                    $html = $Sqrip_Ajax->get_table_results($response, $orders);
                    $Sqrip_Ajax->send_report($html);

                    $logs .= 'Sent report to Admin email! \r\n';
                }
            } 

        } else {

            $logs .= sprintf('No order with %s status found!', $status_awaiting);

        }

        $logs .= 'Finished!';

        error_log($logs);
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