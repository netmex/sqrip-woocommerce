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

		add_action( 'wp_ajax_sqrip_save_refund_iban',  array( $this, 'save_refund_iban' ) );

		/**
		 * @deprecated
		 * Active/Deactive service
		 */

		// add_action( 'wp_ajax_sqrip_connect_ebics_service', array( $this, 'connect_ebics_service' ));
		// add_action( 'wp_ajax_sqrip_connect_camt_service', array( $this, 'connect_camt_service' ));

		add_action( 'wp_ajax_sqrip_upload_camt_file', array( $this, 'upload_camt_file' ));

		add_action( 'wp_ajax_sqrip_compare_ebics', array( $this, 'compare_ebics' ));

		add_action( 'wp_ajax_sqrip_transfer',  array( $this, 'transfer' ) );

		add_action( 'wp_ajax_sqrip_approve_order',  array( $this, 'approve_order' ) );

		add_action( 'wp_ajax_sqrip_validation_refund_token',  array( $this, 'validate_refund_token' ) );
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
	 * sqrip Validation Token
	 *
	 * @since 1.0.3
	 */

	function validate_token()
	{
	    if ( !$_POST['token'] ) return;   

	    $endpoint = 'details';
	    $args = sqrip_prepare_remote_args('', 'GET', $_POST['token']);
    	$response = wp_remote_request(SQRIP_ENDPOINT.$endpoint, $args);
    	$response_code = wp_remote_retrieve_response_code( $response );

    	switch ($response_code) {
    		case 403:
    			$result['result'] = false;
		        $result['message'] = __("Valid token inactive", "sqrip-swiss-qr-invoice");

    			break;

    		case 200:
    			$body = wp_remote_retrieve_body($response);
    			$body_decode = json_decode($body);
    			$result['result'] = true;

    			$result['message'] = $body_decode->message;
		        // $result['message'] = __("Valid, active API Key", "sqrip-swiss-qr-invoice");
    			break;
    		
    		default:
    			$result['result'] = false;
		        $result['message'] = __("Invalid token", "sqrip-swiss-qr-invoice");
    			break;
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
	            $result['qriban'] = false;
	            $result['message'] = __( "validated" , "sqrip" );
	            $result['description'] = __('This is a normal IBAN. The customer can make deposits without noting the reference number (RF...). Therefore, automatic matching with orders is not guaranteed throughout. Manual processing may be necessary. A QR-IBAN is required for automatic matching. This is available for the same bank account. Information about this is available from your bank.', 'sqrip-swiss-qr-invoice');
	            $result['bank'] = $bank ? sprintf('Bank: <b>%s</b>', $bank) : '';
	            break;
	        
	        case 'Valid qr IBAN':
	            $result['result'] = true;
	            $result['qriban'] = true;
	            $result['message'] = __( "validated" , "sqrip" );
	            $result['description'] = __('This is a QR IBAN. The customer can make payments only by specifying a QR reference (number). You can uniquely assign the deposit to a customer / order. This enables automatic matching of payments received with orders. Want to automate this step? Contact us <a href="mailto:info@sqrip.ch">info@sqrip.ch</a>.', 'sqrip-swiss-qr-invoice');
	            $result['bank'] = $bank ? sprintf('Bank: <b>%s</b>', $bank) : '';
	            break;

	        default:
	            $result['result'] = false;
	            $result['qriban'] = false;
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
	    $orders = sqrip_get_awaiting_orders();

	    $boundary = md5( time() . 'xml' );
	    $payload  = '';
	    $payload .= '--' . $boundary. "\r\n";

	    $payload .= 'Content-Disposition: form-data; name="camt_file"; filename="' . $_FILES['file']['name'] . '"' . "\r\n";
	    $payload .= 'Content-Type: application/xml \r\n'; 
	    $payload .= 'Content-Transfer-Encoding: binary' . "\r\n";
	    $payload .= "\r\n";
	    $payload .= file_get_contents( $local_file );
	    $payload .= "\r\n";
	    $payload .= '--' . $boundary . "\r\n";
	    $payload .= 'Content-Disposition: form-data; name="orders"' . "\r\n";
		$payload .= 'Content-Type: application/json' . "\r\n\r\n";
		$payload .= json_encode($orders) . "\r\n";
		$payload .= '--' . $boundary . '--' . "\r\n";

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
					'success' => false,
					'html' => __('Sqrip Confirm order failed!','sqrip-swiss-qr-invoice')
				));

        	}

        } else {

        	wp_send_json(array(
        		'success' => false,
        		'html' => __('No awaiting orders found!','sqrip-swiss-qr-invoice')
        	));

        }
       
		die;
	}

	public function get_order_status($status) {
		$sqrip_new_status  = sqrip_get_plugin_option('new_status');

		$order_statuses = array(
			'wc-pending'    => _x( 'Pending payment', 'Order status', 'woocommerce' ),
			'wc-processing' => _x( 'Processing', 'Order status', 'woocommerce' ),
			'wc-on-hold'    => _x( 'On hold', 'Order status', 'woocommerce' ),
			'wc-completed'  => _x( 'Completed', 'Order status', 'woocommerce' ),
			'wc-cancelled'  => _x( 'Cancelled', 'Order status', 'woocommerce' ),
			'wc-refunded'   => _x( 'Refunded', 'Order status', 'woocommerce' ),
			'wc-failed'     => _x( 'Failed', 'Order status', 'woocommerce' ),
			'wc-sqrip-paid' => $sqrip_new_status
		);

		return isset($order_statuses[$status]) ? $order_statuses[$status] : $status;
	}


	function get_table_results($data = [], $uploaded = [], $email = false, $rp_options = ''){
		$orders_matched = isset($data->orders_matched) && !empty($data->orders_matched) ? $data->orders_matched : [];
		$orders_unmatched = isset($data->orders_unmatched) && !empty($data->orders_unmatched) ? $data->orders_unmatched : [];
		$orders_not_found = isset($data->orders_not_found) && !empty($data->orders_not_found) ? $data->orders_not_found : [];
		$payments_made_more_than_once = isset($data->payments_made_more_than_once) && !empty($data->payments_made_more_than_once) ? $data->payments_made_more_than_once : [];

		$html = '<style>
		.sqrip-table-results h3 {
		    color: #1AAE9F;
		    font-weight: bold;
		}

		h2,
		h3 {
		    color: #1d2327;
		    font-size: 1.3em;
		    margin: 1em 0;
		}

		.sqrip-table-results .sqrip-table thead tr th {
		    background-color: #fff;
		}

		table.sqrip-table thead tr th,
		table.sqrip-table tbody tr td {
		    padding: 10px;
		    border: 1px solid #7a7a7a;
		}

		table.sqrip-table thead tr th {
		    font-weight: bold;
		}
		.sqrip-table-results .sqrip-table tbody tr td {
		    padding: 10px;
		    background-color: #fff;
		}
		</style>';

		$html .= '<div class="sqrip-table-results">';

		if ($email) {
			$status_completed = sqrip_get_plugin_option('status_completed');

			$status_txt = $this->get_order_status($status_completed);

			$html .= '<h3>'.sprintf(
				__('%s orders updated to "%s"', 'sqrip-swiss-qr-invoice'), 
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

		if ($show_order_matched) {
			$html .= '<h4>'.sprintf(
				__('- %s paid orders found and status updated', 'sqrip-swiss-qr-invoice'), 
				count($orders_matched)
			).'</h4>';

			if ($orders_matched) {
				$html .= '<table class="sqrip-table" border="1" cellpadding="3px">';
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
							<td><a href="'.get_edit_post_link($order_id).'" target="_blank">#'.$order_id.'</a></td>
							<td>'.$order_matched->date.'</td>
							<td>'.$customer_name.'</td>
							<td>'.wc_price($order_matched->amount).'</td>
						</tr>';
					}

				$html .= '</tbody>
				</table>';

				$this->update_order_status($orders_matched);
			}
		}

		if ($show_orders_unmatched) {
			$html .= '<h4>'.sprintf(
				__('- %s transactions with unmatching amount', 'sqrip-swiss-qr-invoice'), 
				count($orders_unmatched)
			).'</h4>';

			if ($orders_unmatched) {
				$html .= '<table class="sqrip-table" border="1" cellpadding="3px">';
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
							<td><a href="'.get_edit_post_link($order_id).'" target="_blank">#'.$order_id.'</a></td>
							<td>'.$order_unmatched->date.'</td>
							<td>'.$customer_name.'</td>
							<td>'.wc_price($order_unmatched->amount).'</td>
							<td>'.wc_price($order_unmatched->paid_amount).'</td>
							<td><a class="sqrip-approve" href="'.get_edit_post_link($order_id).'" data-reference="'.$order_unmatched->reference.'" data-order_id="'.$order_id.'">Approve</a></td>
						</tr>';
					}
				$html .= '</tbody>
				</table>';
			}
		}

		if ($show_orders_not_found) {
			$html .= '<h4>'.sprintf(
				__('- %s payments not found', 'sqrip-swiss-qr-invoice'), 
				count($orders_not_found)
			).'</h4>';

			if ( $orders_not_found ) {
				$html .= '<table class="sqrip-table" border="1" cellpadding="3px">';
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
		}


		if ($show_payments_made_more_than_once) {
			$html .= '<h4>'.sprintf(
				__('- %s payments paid more then once', 'sqrip-swiss-qr-invoice'), 
				count($payments_made_more_than_once)
			).'</h4>';
			if ( $payments_made_more_than_once ) {
				$html .= '<table class="sqrip-table" border="1" cellpadding="3px">';
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

        return $wp_mail ? $to : false;
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

	function save_refund_iban(){
		$user = wp_get_current_user();
		$iban = $_POST['iban'];

		if (!$iban || !$user) {
			return;
		}

		$updated = sqrip_set_customer_iban($user, $iban);

		$status = false;
		$message = __('Update IBAN failed!', 'sqrip-swiss-qr-invoice');

		if ($updated) {
			$status = true;
			$message = "Success";
		} 

		wp_send_json(array(
    		'status' => $status,
    		'message' => $message,
    	));

		wp_die();
	}

	/**
	 * sqrip transfer
	 *
	 * @since 2.0.0
	 */

	function transfer()
	{
	    if ( !$_POST['token'] ) return;   

	    $endpoint = 'update-transfer';

	    $response = sqrip_remote_request( $endpoint, '', 'GET', $_POST['token'] );

	    if ($response) {
	        $result['result'] = true;
	        $result['message'] = $response->message;
	        $result['amount'] = sprintf(__('Amount : %s', 'sqrip-swiss-qr-invoice'), '<b>'.$response->amount.'</b>');
	    } else {
	        $result['result'] = false;
	        $result['message'] = isset($response->message) ? $response->message : __("No result", 'sqrip-swiss-qr-invoice');
	    }

	    wp_send_json($result);
	      
	    die();
	}

	/**
	 * sqrip approve order
	 *
	 * @since 2.0.0
	 */

	function approve_order()
	{
	    if ( !$order_id = $_POST['order_id'] ) return;   

	  
	   	$result = array(
	    	'result' => false,
	    	'message' => __('Something went wrong!', 'sqrip-swiss-qr-invoice')
	    );


    	$status_completed = sqrip_get_plugin_option('status_completed');
		$order = new WC_Order($order_id);

		if ($order) {
	        $updated = $order->update_status($status_completed);
		    
		    if ($updated) {
		    	$result['result'] = true;
	        	$result['message'] = __('Order status updated', 'sqrip-swiss-qr-invoice');
	    	}
	        
        }


	    wp_send_json($result);
	      
	    die();
	}

	/**
	 * sqrip Validate Refund Token
	 *
	 * @since 2.0.0
	 */

	function validate_refund_token()
	{
	    if ( !$_POST['token'] ) return; 

	    $endpoint = 'verify-token';

	    $response = sqrip_remote_request( $endpoint, '', 'GET', $_POST['token'] );

	    wp_send_json($response);
	      
	    die();
	}
}

new Sqrip_Ajax;

