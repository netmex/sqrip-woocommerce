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
    if (!$_POST['iban'] || !$_POST['iban_type']) return;

    $iban = $_POST['iban'];
    $iban_type = $_POST['iban_type'];

    $response = sqrip_validation_iban($iban, $iban_type);

    wp_send_json($response);
      
    die();
}



