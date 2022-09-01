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

		add_action( 'wp_ajax_sqrip_refund_valiation',  array( $this, 'refund_valiation' ) );

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
	    $bank = isset($response->bank_data->bank) ? $response->bank_data->bank : '';
	    switch ($response->message) {
	        case 'Valid simple IBAN':
	            $result['result'] = true;
	            $result['message'] = __( "validated" , "sqrip" );
	            $result['description'] = __('This is a normal IBAN. The customer can make deposits without noting the reference number (RF...). Therefore, automatic matching with orders is not guaranteed throughout. Manual processing may be necessary. A QR-IBAN is required for automatic matching. This is available for the same bank account. Information about this is available from your bank.', 'sqrip-swiss-qr-invoice');
	            $result['bank'] = sprintf('Bank: <b>%s</b>', $bank);
	            break;
	        
	        case 'Valid qr IBAN':
	            $result['result'] = true;
	            $result['message'] = __( "validated" , "sqrip" );
	            $result['description'] = __('This is a QR IBAN. The customer can make payments only by specifying a QR reference (number). You can uniquely assign the deposit to a customer / order. This enables automatic matching of payments received with orders. Want to automate this step? Contact us <a href="mailto:info@sqrip.ch">info@sqrip.ch</a>.', 'sqrip-swiss-qr-invoice');
	            $result['bank'] = sprintf('Bank: <b>%s</b>', $bank);
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

        $orders = sqrip_get_awaiting_orders();

        if ($orders) {
        	$body = [];
        	$body['orders'] = $orders;
        	$endpoint = 'confirm-order';

        	$rp_options = isset($_POST['send_report_options']) ? $_POST['send_report_options'] : false;
        	$rp_options = explode(',', $rp_options);

        	$response = sqrip_remote_request( $endpoint, $body, 'POST' );

        	if ($response) {
        		$send_report = isset($_POST['send_report']) ? $_POST['send_report'] : false;

        		$html = $this->get_table_results($response, $orders);

        		if ( $send_report === "true" ) {
        			$email_html = $this->get_table_results($response, $orders, true, $rp_options);
   	 				$this->send_report($email_html);
   	 			}

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

	public function get_order_status($status) {
		$order_statuses = array(
			'wc-pending'    => _x( 'Pending payment', 'Order status', 'woocommerce' ),
			'wc-processing' => _x( 'Processing', 'Order status', 'woocommerce' ),
			'wc-on-hold'    => _x( 'On hold', 'Order status', 'woocommerce' ),
			'wc-completed'  => _x( 'Completed', 'Order status', 'woocommerce' ),
			'wc-cancelled'  => _x( 'Cancelled', 'Order status', 'woocommerce' ),
			'wc-refunded'   => _x( 'Refunded', 'Order status', 'woocommerce' ),
			'wc-failed'     => _x( 'Failed', 'Order status', 'woocommerce' ),
		);

		return isset($order_statuses[$status]) ? $order_statuses[$status] : $status;
	}


	function get_table_results($data = [], $uploaded = [], $email = false, $rp_options = ''){
		$orders_matched = isset($data->orders_matched) && !empty($data->orders_matched) ? $data->orders_matched : [];
		$orders_unmatched = isset($data->orders_unmatched) && !empty($data->orders_unmatched) ? $data->orders_unmatched : [];
		$orders_not_found = isset($data->orders_not_found) && !empty($data->orders_not_found) ? $data->orders_not_found : [];
		$payments_made_more_than_once = isset($data->payments_made_more_than_once) && !empty($data->payments_made_more_than_once) ? $data->payments_made_more_than_once : [];

		$html = '<div class="sqrip-table-results">';

		if ($email) {
			$status_completed = sqrip_get_plugin_option('status_completed');

			$status_txt = $this->get_order_status($status_completed);

			$html .= '<h3>'.sprintf(
				__('%s orders updated to %s', 'sqrip-swiss-qr-invoice'), 
				count($orders_matched),
				$status_txt
			).'</h3>';

		} else {

			$html .= '<h3><span class="dashicons dashicons-yes"></span>'.__(' Orders status successfully updated', 'sqrip-swiss-qr-invoice').'</h3>';
		}
		
		$html .= '<h4>'.sprintf(
			__('- %s unpaid orders uploaded', 'sqrip-swiss-qr-invoice'), 
			count($uploaded)
		).'</h4>';

		$show_order_matched = !$email || in_array('orders_matched', $rp_options);
		$show_orders_not_found = !$email || in_array('orders_not_found', $rp_options);
		$show_orders_unmatched = !$email || in_array('orders_unmatched', $rp_options);
		$show_payments_made_more_than_once = !$email || in_array('payments_made_more_than_once', $rp_options);

		if ($orders_matched && $show_order_matched) {
			$html .= '<h4>'.sprintf(
				__('- %s paid orders found and status updated', 'sqrip-swiss-qr-invoice'), 
				count($orders_matched)
			).'</h4>';

			$html .= '<table class="sqrip-table">';
			$html .= '<thead><tr>';
			$html .= '<th>'.__('Order ID', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Payment Date', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Customer Name', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Amount', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '</tr></thead><tbody>';

				foreach ($orders_matched as $order_matched) {
					$order_id = $order_matched->order_id;
					$customer_name = $this->get_customer_name($order_id);

					$html .= '<tr>
						<td>#'.$order_id.'</td>
						<td>'.$order_matched->date.'</td>
						<td>'.$customer_name.'</td>
						<td>'.wc_price($order_matched->amount).'</td>
					</tr>';
				}

			$html .= '</tbody>
			</table>';

			$this->update_order_status($orders_matched);
		}

		if ($orders_unmatched && $show_orders_unmatched) {
			$html .= '<h4>'.sprintf(
				__('- %s transactions with unmatching amount', 'sqrip-swiss-qr-invoice'), 
				count($orders_unmatched)
			).'</h4>';

			$html .= '<table class="sqrip-table">';
			$html .= '<thead><tr>';
			$html .= '<th>'.__('Order ID', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Payment Date', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Customer Name', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Amount', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Paid Amount', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Action', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '</tr></thead><tbody>';

				foreach ($orders_unmatched as $order_unmatched) {
					$order_id = $order_unmatched->order_id;
					$customer_name = $this->get_customer_name($order_id);

					$html .= '<tr>
						<td>#'.$order_id.'</td>
						<td>'.$order_unmatched->date.'</td>
						<td>'.$customer_name.'</td>
						<td>'.wc_price($order_unmatched->amount).'</td>
						<td>'.wc_price(0).'</td>
						<td><a class="sqrip-approve" href="#" data-order="'.$order_unmatched->order_id.'">Approve</a></td>
					</tr>';
				}
			$html .= '</tbody>
			</table>';
		}

		if ( $orders_not_found && $show_orders_not_found ) {
			$html .= '<h4>'.sprintf(
				__('- %s payments not found', 'sqrip-swiss-qr-invoice'), 
				count($orders_not_found)
			).'</h4>';

			$html .= '<table class="sqrip-table">';
			$html .= '<thead><tr>';
			$html .= '<th>'.__('Order ID', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Customer Name', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Amount', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '</tr></thead><tbody>';


				foreach ($orders_not_found as $order_not_found) {
					$order_id = $order_not_found->order_id;
					$customer_name = $this->get_customer_name($order_id);

					$html .= '<tr>
						<td><a href="'.get_edit_post_link($order_id).'" target="_blank">#'.$order_id.'</a></td>
						<td>'.$customer_name.'</td>
						<td>'.wc_price($order_not_found->amount).'</td>
					</tr>';
				}
				$html .= '</tbody>
			</table>';
		}

		if ( $payments_made_more_than_once && $show_payments_made_more_than_once) {
			$html .= '<h4>'.sprintf(
				__('- %s payments paid more then once', 'sqrip-swiss-qr-invoice'), 
				count($payments_made_more_than_once)
			).'</h4>';

			$html .= '<table class="sqrip-table">';
			$html .= '<thead><tr>';
			$html .= '<th>'.__('Customer Name', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('(QR-)IBAN', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Amount and QR-Ref#', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Payment Dates', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '<th>'.__('Action', 'sqrip-swiss-qr-invoice').'</th>';
			$html .= '</tr></thead><tbody>';


				foreach ($payments_made_more_than_once as $payment) {
					$order_id = $payment->order_id;
					$customer_name = $this->get_customer_name($order_id);
					$png_file = get_post_meta($order_id, 'sqrip_png_file_url', true);

					$html .= '<tr>';
					$html .= '<td>'.$customer_name.'</td>';
					$html .= '<td><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip-swiss-qr-invoice').'" width="200"/></td>';
					$html .= '<td>'.wc_price($payment->amount).' - #'.$payment->reference.'</td>';
					$html .= '<td>'.$payment->dates.'</td>';
					$html .= '<td><a href="'.get_edit_post_link($order_id).'" target="_blank">#'.__('Refund', 'sqrip-swiss-qr-invoice').'</a></td>';
					$html .= '</tr>';
				}
				$html .= '</tbody>
			</table>';
		}

		$html .= '</div>';

		return $html;
	}

	public function update_order_status($orders_matched){
		if (!$orders_matched || !is_array($orders_matched)) return;

		$status_completed = sqrip_get_plugin_option('status_completed');

		foreach ($orders_matched as $order_matched) {
			$order_id = $order_matched->order_id;
			// Get an instance of the WC_Order Object from the Order ID (if required)
			$order = new WC_Order($order_id);
	        $order->update_status($status_completed);
	    }
    }

	public function get_customer_name($order_id){
		// Get an instance of the WC_Order Object from the Order ID (if required)
		$order = wc_get_order( $order_id );

		if (!$order) return;

		// Customer billing information details
		$billing_first_name = $order->get_billing_first_name();
		$billing_last_name  = $order->get_billing_last_name();
		$customer_name = $billing_first_name.' '.$billing_last_name;

		return $customer_name;
	}

	function send_report($html) {
		if ( !$html ) return;

		$to = get_option('admin_email');
        $subject = __('Report E-Mail from sqrip.ch', 'sqrip-swiss-qr-invoice');
        $body = __('Automatic Comparaison - EBICS Results:', 'sqrip-swiss-qr-invoice');
        $body = $html;
        $attachments = [];

        $headers[] = 'From: sqrip E-Mail <'.$to.'>';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        $wp_mail = wp_mail( $to, $subject, $body, $headers );
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

	function refund_valiation(){
		$user = wp_get_current_user();
		$iban = sqrip_get_customer_iban($user);

		$status = false;
		$message = __('Please enter IBAN to generate QR-Code.', 'sqrip-swiss-qr-invoice');

		if ($iban) {
			$status = true;
			$message = "Success";
		} 

		wp_send_json(array(
    		'status' => $status,
    		'message' => $message,
    	));

		wp_die();
	}
}

new Sqrip_Ajax;

