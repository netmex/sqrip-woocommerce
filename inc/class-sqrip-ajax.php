<?php
/** If this file is called directly, abort. */
defined( 'ABSPATH' ) || exit;


class Sqrip_Ajax {

	function __construct() {

		add_action( 'wp_ajax_sqrip_generate_new_qr_code', array( $this, 'generate_new_qr_code' ) );

		add_action( 'wp_ajax_sqrip_preview_address',  array( $this, 'preview_address' ) );

		add_action( 'wp_ajax_sqrip_validation_iban',  array( $this, 'validate_iban' ) );

		add_action( 'wp_ajax_sqrip_validation_token', array( $this, 'validate_token' ) );

		add_action( 'wp_ajax_sqrip_mark_refund_paid',  array( $this, 'mark_refund_paid' ) );

		add_action( 'wp_ajax_sqrip_mark_refund_unpaid',  array( $this, 'mark_refund_unpaid' ) );

		/**
		 * @deprecated
		 * Active/Deactive service
		 */

		// add_action( 'wp_ajax_sqrip_connect_ebics_service', array( $this, 'connect_ebics_service' ));
		// add_action( 'wp_ajax_sqrip_connect_camt_service', array( $this, 'connect_camt_service' ));

		add_action( 'wp_ajax_sqrip_upload_camt_file', array( $this, 'upload_camt_file' ));

		add_action( 'wp_ajax_sqrip_compare_ebics', array( $this, 'compare_ebics' ));
	}

	/**
	 * Ajax action to mark a sqrip refund as unpaid
	 */
	function mark_refund_unpaid()
	{
		check_ajax_referer('sqrip-mark-refund-unpaid', 'security');

		$refund_id = isset( $_POST['refund_id'] ) ? absint( $_POST['refund_id'] ) : 0;
		$refund    = wc_get_order( $refund_id );

		if ( !$refund ) { return; }

		$refund->delete_meta_data('sqrip_refund_paid');
		$refund->save();

	    // add woocommerce message to original order
	    $order = wc_get_order($refund->get_parent_id());
	    $order->add_order_note( __('sqrip refund was marked as \'unbezahlt\'', 'sqrip-swiss-qr-invoice') );

		wp_send_json(['result' => 'success']);

		die();
	}


	/**
	 * Ajax action to mark a sqrip refund as paid
	 */
	function mark_refund_paid()
	{
		check_ajax_referer('sqrip-mark-refund-paid', 'security');

		$refund_id = isset( $_POST['refund_id'] ) ? absint( $_POST['refund_id'] ) : 0;
		$refund    = wc_get_order( $refund_id );

		if ( !$refund ) { return; }

		// stores the current date and time
		$date = date('Y-m-d H:i:s');
		$refund->update_meta_data('sqrip_refund_paid', $date);
		$refund->save();

	    // add woocommerce message to original order
	    $order = wc_get_order($refund->get_parent_id());
	    $order->add_order_note( __('sqrip refund was marked as \'paid\'', 'sqrip-swiss-qr-invoice') );

		wp_send_json(['date' => $date, 'result' => 'success']);

		die();
	}

	/**
	 * sqrip validation IBAN
	 *
	 * @since 1.0.3
	 */

	function validate_token()
	{
	    if ( !$_POST['token'] ) return;   

	    $response = sqrip_get_user_details( $_POST['token'] );

	    if ($response) {
	        $address_txt = __('from sqrip account: ','sqrip-swiss-qr-invoice');
	        $address_txt .= $response['name'].', '.$response['street'].', '.$response['city'].', '.$response['postal_code'].' '.$response['city'];

	        $result['result'] = true;
	        $result['message'] = __("API key confirmed", "sqrip-swiss-qr-invoice");
	        $result['address'] = $address_txt;
	    } else {
	        $result['result'] = false;
	        $result['message'] = __("API key NOT confirmed", "sqrip-swiss-qr-invoice");
	    }

	    wp_send_json($result);
	      
	    die();
	}

	/**
	 * sqrip validation IBAN
	 *
	 * @since 1.0.3
	 */

	function validate_iban()
	{
	    if (!$_POST['iban'] || !$_POST['token']) return;

	    $iban = $_POST['iban'];
	    $token = $_POST['token'];

	    $response = sqrip_validation_iban($iban, $token);
	    $result = [];
	    switch ($response->message) {
	        case 'Valid simple IBAN':
	            $result['result'] = true;
	            $result['message'] = __( "validated" , "sqrip" );
	            $result['description'] = __('This is a normal IBAN. The customer can make deposits without noting the reference number (RF...). Therefore, automatic matching with orders is not guaranteed throughout. Manual processing may be necessary. A QR-IBAN is required for automatic matching. This is available for the same bank account. Information about this is available from your bank.', 'sqrip-swiss-qr-invoice');
	            break;
	        
	        case 'Valid qr IBAN':
	            $result['result'] = true;
	            $result['message'] = __( "validated" , "sqrip" );
	            $result['description'] = __('This is a QR IBAN. The customer can make payments only by specifying a QR reference (number). You can uniquely assign the deposit to a customer / order. This enables automatic matching of payments received with orders. Want to automate this step? Contact us <a href="mailto:info@sqrip.ch">info@sqrip.ch</a>.', 'sqrip-swiss-qr-invoice');
	            break;

	        default:
	            $result['result'] = false;
	            $result['message'] = __( "incorrect" , "sqrip" );
	            $result['description'] = __('The (QR-)IBAN of your account to which the transfer should be made is ERROR.', 'sqrip-swiss-qr-invoice');
	            break;
	    }

	    wp_send_json($result);
	      
	    die();
	}

	/**
	 * sqrip preview address
	 *
	 * @since 1.0.3
	 */

	function preview_address()
	{
	    if (!$_POST['address']) return;

	    $address = $_POST['address'];

	    $response = sqrip_get_payable_to_address($address);

	    wp_send_json($response);
	      
	    die();
	}


	/**
	 * sqrip Generate new qr code ajax
	 *
	 * @since 1.0
	 */
	function generate_new_qr_code()
	{
	    check_ajax_referer('sqrip-generate-new-qrcode', 'security');

	    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	    $order    = wc_get_order( $order_id );

	    if ( ! $order ) {
	        return;
	    }

	    $user_id   = $order->get_user_id();
	    $cur_user_id = get_current_user_id();

	    if ($user_id == $cur_user_id) {
	        $sqrip_payment = new WC_Sqrip_Payment_Gateway;
	        $process_payment = $sqrip_payment->process_payment($order_id);

	        wp_send_json($process_payment);
	    }
	      
	    die();
	}


	/**
	 * sqrip Active EBICS Service
	 *
	 * @since 2.0
	 */
	function connect_ebics_service()
	{
		check_ajax_referer('sqrip-admin-settings', 'nonce');

	    if ( !$_POST['token'] ) return; 

	    if ( $_POST['active'] === "true" ) {
	      	$endpoint = 'activate-ebics-connection';
	    } else {
	    	$endpoint = 'deactivate-ebics-connection';
	    }

	    $response   = sqrip_remote_request($endpoint, '', 'GET', $token); 

	    wp_send_json($response);
	      
	    die();
	}



	/**
	 * sqrip Active CAMT053 Files Service
	 *
	 * @since 2.0
	 */
	function connect_camt_service()
	{
		check_ajax_referer('sqrip-admin-settings', 'nonce');

	    if ( !$_POST['token'] ) return; 

	    if ( $_POST['active'] === "true" ) {
	      	$endpoint = 'activate-camt-upload';
	    } else {
	    	$endpoint = 'deactivate-camt-upload';
	    }

	    $response   = sqrip_remote_request($endpoint, '', 'GET', $token); 

	    wp_send_json($response);
	      
	    die();
	}

	/**
	 * Upload CAMT053 File
	 *
	 * @since 2.0
	 */
	function upload_camt_file()
	{
	    check_ajax_referer('sqrip-admin-settings', 'nonce');

	    $local_file = $_FILES['file']['tmp_name'];

	    $boundary = md5( time() . 'xml' );
	    $payload  = '';
	    $payload .= '--' . $boundary;
	    $payload .= "\r\n";
	    $payload .= 'Content-Disposition: form-data; name="camt_file"; filename="' . $_FILES['file']['name'] . '"' . "\r\n";
	    $payload .= 'Content-Type: application/xml \r\n'; // If you know the mime-type
	    $payload .= 'Content-Transfer-Encoding: binary' . "\r\n";
	    $payload .= "\r\n";
	    $payload .= file_get_contents( $local_file );
	    $payload .= "\r\n";
	    $payload .= '--' . $boundary . '--';
	    $payload .= "\r\n\r\n";

	    $args = array(
	            'method'  => 'POST',
	            'headers' => array(
                    'accept'       => 'application/json', 
                    'content-type' => 'multipart/form-data;boundary=' . $boundary, 
                    'Authorization' => 'Bearer '.$_POST['token'],
	            ),
	            'body'    => $payload,
	    );

	    $endpoint = 'upload-camt-file';
	    $res_upload = wp_remote_request( SQRIP_ENDPOINT.$endpoint, $args );

		if ( is_wp_error($res_upload) ) return;

   	 	$body = wp_remote_retrieve_body($res_upload);
   	 	$body_decode = json_decode($body);

   	 	if ( $body_decode->statements ) {
   	 		$endpoint_confirm = 'confirm-order';
   	 		$body_confirm = [];

   	 		$statements = $body_decode->statements;

   	 		foreach ($statements as $statement) {
   	 			$order = [];
   	 			$order['order_id'] = $statement->id; 
   	 			$order['amount'] = $statement->amount; 
   	 			$order['reference'] = $statement->reference_number; 
   	 			$order['date'] = $statement->created_at; 

   	 			$body_confirm['orders'][] = $order;
   	 		}

   	 		$res_confirm = sqrip_remote_request( $endpoint_confirm, $body_confirm,'POST' );

   	 		if ($res_confirm) {

   	 			$html = $this->get_table_results($res_confirm, $statements);

				wp_send_json(array(
					'html' => $html,
					'success' => true
				));

   	 		} else {

   	 			wp_send_json(array(
					'success' => false
				));
   	 		}

   	 	} else {

   	 		wp_send_json($body);

   	 	}
    	

	    die();
	}

	function compare_ebics(){
		check_ajax_referer('sqrip-admin-settings', 'nonce');
		$status_awaiting = sqrip_get_plugin_option('status_awaiting');

		$awaiting_orders = (array) wc_get_orders( array(
            'limit'         => -1,
            'status'        => $status_awaiting,
        ) );

        $orders = [];

		if ( sizeof($awaiting_orders) > 0 ) {
            foreach ( $awaiting_orders as $aw_order ) {
            	$order['order_id'] 	= $aw_order->get_id();
            	$order['amount'] 	= $aw_order->get_total();
            	$order['reference'] = $aw_order->get_meta('sqrip_reference_id');
            	$order['date'] 		= $aw_order->get_date_created()->date('Y-m-d H:i:s');

            	array_push($orders, $order);
            }
        }

        if ($orders) {
        	$body = [];
        	$body['orders'] = $orders;
        	$endpoint = 'confirm-order';

        	$response = sqrip_remote_request( $endpoint, $body, 'POST' );

        	if ($response) {

        		$html = $this->get_table_results($response, $orders);

        		wp_send_json(array(
					'html' => $html,
					'success' => true
				));

        	} else {

        		wp_send_json(array(
					'success' => false
				));

        	}

        	
        } else {

        	wp_send_json(array(
        		'response' => false,
        		'message' => __('No orders found!','sqrip-swiss-qr-invoice')
        	));

        }
       
		die;
	}


	function get_table_results($data = [], $uploaded = []){
		$orders_matched = isset($data->orders_matched) && !empty($data->orders_matched) ? $data->orders_matched : [];
		$orders_unmatched = isset($data->orders_unmatched) && !empty($data->orders_unmatched) ? $data->orders_unmatched : [];
		$orders_not_found = isset($data->orders_not_found) && !empty($data->orders_not_found) ? $data->orders_not_found : [];

		$html = '<div class="sqrip-table-results">';
		$html .= '<h3><span class="dashicons dashicons-yes"></span> Orders status successfully updated</h3>';

		$html .= '<h4>'.sprintf(
			__('%s unpaid orders uploaded', 'sqrip-swiss-qr-invoice'), 
			count($uploaded)
		).'</h4>';

		$html .= '<h4>'.sprintf(
			__('%s paid order found and status updated', 'sqrip-swiss-qr-invoice'), 
			count($orders_matched)
		).'</h4>';

		if ($orders_matched) {
			$html .= '<table class="sqrip-table">
				<thead>
					<tr>
						<th>Order ID</th>
						<th>Payment Date</th>
						<th>Customer Name</th>
						<th>Amount</th>
					</tr>
				</thead>
				<tbody>';
				foreach ($orders_matched as $order_matched) {
					$html .= '<tr>
						<td>#'.$order_matched->order_id.'</td>
						<td>'.$order_matched->date.'</td>
						<td>'.$order_matched->reference.'</td>
						<td>'.wc_price($order_matched->amount).'</td>
					</tr>';
				}
			$html .= '</tbody>
			</table>';
		}

		$html .= '<h4>'.sprintf(
			__('%s transaction with unmatching amount', 'sqrip-swiss-qr-invoice'), 
			count($orders_unmatched)
		).'</h4>';

		if ($orders_unmatched) {
			$html .= '<table class="sqrip-table">
				<thead>
					<tr>
						<th>Order ID</th>
						<th>Payment Date</th>
						<th>Customer Name</th>
						<th>Amount</th>
						<th>Paid Amount</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>';

				foreach ($orders_unmatched as $order_unmatched) {
					$html .= '<tr>
						<td>#'.$order_unmatched->order_id.'</td>
						<td>'.$order_unmatched->date.'</td>
						<td>'.$order_unmatched->reference.'</td>
						<td>'.wc_price($order_unmatched->amount).'</td>
						<td>'.wc_price(0).'</td>
						<td><a class="sqrip-approve" href="#" data-order="'.$order_unmatched->order_id.'">Approve</a></td>
					</tr>';
				}
			$html .= '</tbody>
			</table>';
		}

		$html .= '<h4>'.sprintf(
			__('%s payments not found', 'sqrip-swiss-qr-invoice'), 
			count($orders_not_found)
		).'</h4>';


		if ( $orders_not_found ) {
			$html .= '<table class="sqrip-table">
				<thead>
					<tr>
						<th>Order ID</th>
						<th>Ref.-Nr</th>
						<th>Amount</th>
					</tr>
				</thead>
				<tbody>';
				foreach ($orders_not_found as $order_not_found) {
					$html .= '<tr>
						<td>#'.$order_not_found->order_id.'</td>
						<td>'.$order_not_found->reference.'</td>
						<td>'.wc_price($order_not_found->amount).'</td>
					</tr>';
				}
				$html .= '</tbody>
			</table>';
		}

		$html .= '</div>';

		return $html;
	}


	/** -------- AJAX ---------- */

	/**
	 * Verify the nonce. Exit if not verified.
	 * @return void
	 */
	function check_ajax_nonce() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sqrip-admin-settings' ) ) {
			exit;
		}
	}


}

new Sqrip_Ajax;

