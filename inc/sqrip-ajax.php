<?php

/**
 * sqrip Generate new qr code ajax
 *
 * @since 1.0
 */

add_action('wp_ajax_sqrip_generate_new_qr_code', 'sqrip_generate_new_qr_code');

function sqrip_generate_new_qr_code()
{
    check_ajax_referer('sqrip-generate-new-qrcode', 'security');

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    $user_id = $order->get_user_id();
    $cur_user_id = get_current_user_id();

    if ($user_id == $cur_user_id) {
        $sqrip_payment = new WC_Sqrip_Payment_Gateway;
        $process_payment = $sqrip_payment->process_payment($order_id);

        wp_send_json($process_payment);
    }

    die();
}

add_action('wp_ajax_sqrip_get_shop_name', 'sqrip_get_shop_name');

function sqrip_get_shop_name()
{
    wp_send_json(get_bloginfo('name'));

    die();
}

/**
 * sqrip preview address
 *
 * @since 1.0.3
 */

add_action('wp_ajax_sqrip_preview_address', 'sqrip_preview_address_ajax');

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

add_action('wp_ajax_sqrip_validation_iban', 'sqrip_validation_iban_ajax');

function sqrip_validation_iban_ajax()
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
            $result['message'] = __("validated", "sqrip");
            $result['description'] = __('This is a normal IBAN. The customer can make deposits without noting the reference number (RF...). Therefore, automatic matching with orders is not guaranteed throughout. Manual processing may be necessary. A QR-IBAN is required for automatic matching. This is available for the same bank account. Information about this is available from your bank.', 'sqrip-swiss-qr-invoice');
            $result['bank'] = $bank ? sprintf('Bank: <b>%s</b>', $bank) : '';
            break;

        case 'Valid qr IBAN':
            $result['result'] = true;
            $result['qriban'] = true;
            $result['message'] = __("validated", "sqrip");
            $result['description'] = __('This is a QR IBAN. The customer can make payments only by specifying a QR reference (number). You can uniquely assign the deposit to a customer / order. This enables automatic matching of payments received with orders. Want to automate this step? Contact us <a href="mailto:info@sqrip.ch">info@sqrip.ch</a>.', 'sqrip-swiss-qr-invoice');
            $result['bank'] = $bank ? sprintf('Bank: <b>%s</b>', $bank) : '';
            break;

        default:
            $result['result'] = false;
            $result['qriban'] = false;
            $result['message'] = __("incorrect", "sqrip");
            $result['description'] = __('It seems that an invalid (QR-)IBAN has been submitted. Please check the designated transfers-receiving IBAN you submitted.', 'sqrip-swiss-qr-invoice');
            break;
    }
    if ($response->will_need_confirmation) {
        $result['result'] = false;
        $result['qriban'] = false;
        $result['message'] = __("incorrect", "sqrip");
        $result['description'] = __("Action required:\nPlease confirm change of IBAN on <a href='https://api.sqrip.ch/login' target='_blank'>sqrip.ch</a> in order to continue the service.", 'sqrip-swiss-qr-invoice');
        $result['bank'] = $bank ? sprintf('Bank: <b>%s</b>', $bank) : '';
    }

    wp_send_json($result);

    die();
}

/**
 * sqrip validation IBAN
 *
 * @since 1.0.3
 */

add_action('wp_ajax_sqrip_validation_token', 'sqrip_validation_token_ajax');

function sqrip_validation_token_ajax()
{
    if (!$_POST['token']) return;

    $endpoint = 'details';
    $args = sqrip_prepare_remote_args('', 'GET', $_POST['token']);
    $response = wp_remote_request(SQRIP_ENDPOINT . $endpoint, $args);
    $response_code = wp_remote_retrieve_response_code($response);

    switch ($response_code) {
        case 403:
            $result['result'] = false;
            $result['message'] = __("Your API key appears to be inactive. Please check that your API key is set to active in the sqrip settings.", "sqrip-swiss-qr-invoice");

            break;

        case 200:
            $body = wp_remote_retrieve_body($response);
            $body_decode = json_decode($body);
            $result['result'] = true;

            $result['message'] = $body_decode->message;
            $result['credits_left'] = $body_decode->credits_left;
            // $result['message'] = __("Valid, active API Key", "sqrip-swiss-qr-invoice");
            break;

        default:
            $result['result'] = false;
            $result['message'] = __("We can't seem to find the API key you're using in our database. Please check your API key, your sqrip settings, then contact our support.", "sqrip-swiss-qr-invoice");
            break;
    }

    wp_send_json($result);

    die();
}


add_action('wp_ajax_sqrip_mark_refund_paid', 'sqrip_mark_refund_paid');

/**
 * Ajax action to mark a sqrip refund as paid
 */
function sqrip_mark_refund_paid()
{
    check_ajax_referer('sqrip-mark-refund-paid', 'security');

    $refund_id = isset($_POST['refund_id']) ? absint($_POST['refund_id']) : 0;
    $refund = wc_get_order($refund_id);

    if (!$refund) {
        return;
    }

    // stores the current date and time
    $date = date('Y-m-d H:i:s');
    $refund->update_meta_data('sqrip_refund_paid', $date);
    $refund->save();

    // add woocommerce message to original order
    $order = wc_get_order($refund->get_parent_id());
    $order->add_order_note(__('sqrip refund was marked as \'paid\'', 'sqrip-swiss-qr-invoice'));

    wp_send_json(['date' => $date, 'result' => 'success']);

    die();
}

add_action('wp_ajax_sqrip_mark_refund_unpaid', 'sqrip_mark_refund_unpaid');

/**
 * Ajax action to mark a sqrip refund as unpaid
 */
function sqrip_mark_refund_unpaid()
{
    check_ajax_referer('sqrip-mark-refund-unpaid', 'security');

    $refund_id = isset($_POST['refund_id']) ? absint($_POST['refund_id']) : 0;
    $refund = wc_get_order($refund_id);

    if (!$refund) {
        return;
    }

    $refund->delete_meta_data('sqrip_refund_paid');
    $refund->save();

    // add woocommerce message to original order
    $order = wc_get_order($refund->get_parent_id());
    $order->add_order_note(__('sqrip refund was marked as \'unbezahlt\'', 'sqrip-swiss-qr-invoice'));

    wp_send_json(['result' => 'success']);

    die();
}

/**
 * sqrip Validate Refund Token
 *
 * @since 1.5.5
 */

add_action('wp_ajax_sqrip_validation_refund_token', 'sqrip_validate_refund_token');

function sqrip_validate_refund_token()
{
    if (!$_POST['token']) return;

    $endpoint = 'verify-token';

    $response = sqrip_remote_request($endpoint, '', 'GET', $_POST['token']);

    wp_send_json($response);

    die();
}


add_action('wp_ajax_sqrip_payment_confirmed', 'sqrip_payment_confirmed');
add_action('wp_ajax_nopriv_sqrip_payment_confirmed', 'sqrip_payment_confirmed');

function sqrip_payment_confirmed()
{
    check_ajax_referer('sqrip_payment_confirmed', '_wpnonce');

    if (!isset($_GET['order_id']) || empty($_GET['order_id'])) {
        return;
    }

    $order_id = $_GET['order_id'];
    $status_completed = sqrip_get_plugin_option('status_completed');

    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    $paged = isset($_GET['paged']) ? '&paged=' . $_GET['paged'] : '';

    $order->update_status($status_completed, '');

    wp_redirect(get_admin_url() . 'edit.php?post_type=shop_order' . $paged);
    die();
}
