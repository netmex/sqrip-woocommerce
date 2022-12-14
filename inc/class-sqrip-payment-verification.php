<?php 
/**
 * Sqrip Payment verification class
 *
 * @package sqrip
 */
class Sqrip_Payment_Verification {

    public $webhook_url;

    /**
     * Construdor :)
     */
    public function __construct($webhook_url = "") {
        $this->webhook = $webhook_url;
    }

    public function send_to_api(){
        $endpoint = 'plugin-settings';

        $body = '{
           "plugin_url": "'.$this->webhook.'"
        }';

        $response = sqrip_remote_request($endpoint, $body, 'POST');  

        return $response;
    }

    public function verify() {
        $logs = array();
        $logs[] = '[Sqrip_Payment_Verification] is starting...';

        $orders = sqrip_get_awaiting_orders();
        $isSent = false;
        $return = [];

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

                    if ($email_sent) {
                        $isSent = true;
                    }

                    $logs[] = $email_sent ? 'Sent report to '.$email_sent.'! ' : 'Email failed to send. Please check your Email SMTP settings.';
                }

                $return = [
                    'status' => 200,
                    'message' => 'Successful Comparison'
                ];
            } else {
                $return = [
                    'status' => 302,
                    'message' => 'Request to sqrip to confirm order failed!'
                ];
            }

        } else {

            $logs[] = sprintf('No order with %s status found!', $status_awaiting);

            $return = [
                'status' => 203,
                'message' => 'No awaiting orders found!'
            ];

        }

        $logs[] = '[Sqrip_Payment_Verification] Finished!';

        error_log(print_r($logs, true));

        $return['email_sent'] = $isSent;
        return $return;
    }

    public function update_order_status($orders){

        if (!$orders) {
            return;
        }

        $status_completed = sqrip_get_plugin_option('status_completed');

        $updated  = [];

        foreach ($orders as $order) {

            if ($order->paid_amount >= $order->amount) {
                $order_id = $order->order_id;
                $order = new WC_Order($order_id);
               
                $updated[$order_id] =  $order->update_status($status_completed);
            }
            
        }

        return $updated;
    }

} 
