<?php 
/**
 * sqrip Generate new qr code ajax
 *
 * @since 1.0
 */

add_action( 'wp_ajax_sqrip_generate_new_qr_code', 'sqrip_generate_new_qr_code' );

function sqrip_generate_new_qr_code()
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
 * sqrip preview address
 *
 * @since 1.0.3
 */

add_action( 'wp_ajax_sqrip_preview_address', 'sqrip_preview_address_ajax' );

function sqrip_preview_address_ajax()
{
    if (!$_POST['address']) return;

    $address = $_POST['address'];

    $response = sqrip_get_payable_to_address($address);

    wp_send_json($response);
      
    die();
}


/**
 * sqrip validation IBAN
 *
 * @since 1.0.3
 */

add_action( 'wp_ajax_sqrip_validation_iban', 'sqrip_validation_iban_ajax' );

function sqrip_validation_iban_ajax()
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
            $result['description'] = __('This is a normal IBAN. The customer can make deposits without noting the reference number (RF...). Therefore, automatic matching with orders is not guaranteed throughout. Manual processing may be necessary. A QR-IBAN is required for automatic matching. This is available for the same bank account. Information about this is available from your bank.', 'sqrip');
            break;
        
        case 'Valid qr IBAN':
            $result['result'] = true;
            $result['message'] = __( "validated" , "sqrip" );
            $result['description'] = __('This is a QR IBAN. The customer can make payments only by specifying a QR reference (number). You can uniquely assign the deposit to a customer / order. This enables automatic matching of payments received with orders. Want to automate this step? Contact us <a href="mailto:info@sqrip.ch">info@sqrip.ch</a>.', 'sqrip');
            break;

        default:
            $result['result'] = false;
            $result['message'] = __( "incorrect" , "sqrip" );
            $result['description'] = __('The (QR-)IBAN of your account to which the transfer should be made is ERROR.', 'sqrip');
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

add_action( 'wp_ajax_sqrip_validation_token', 'sqrip_validation_token_ajax' );

function sqrip_validation_token_ajax()
{
    if ( !$_POST['token'] ) return;   

    $response = sqrip_get_user_details( $_POST['token'] );

    if ($response) {
        $address_txt = __('from sqrip account: ','sqrip');
        $address_txt .= $response['name'].', '.$response['street'].', '.$response['city'].', '.$response['postal_code'].' '.$response['city'];

        $result['result'] = true;
        $result['message'] = __("API key confirmed", "sqrip");
        $result['address'] = $address_txt;
    } else {
        $result['result'] = false;
        $result['message'] = __("API key NOT confirmed", "sqrip");
    }

    wp_send_json($result);
      
    die();
}



