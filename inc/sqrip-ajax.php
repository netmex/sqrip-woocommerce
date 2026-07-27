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

    // Guest orders have user id 0 — never let a logged-out or unrelated visitor match.
    if (!$cur_user_id || $user_id != $cur_user_id) {
        die();
    }

    // Each call spends one paid sqrip credit, deletes the existing PDF and mints a NEW
    // reference number. Without the guards below a customer could loop this endpoint:
    // draining the shop's credits and changing the reference of a transfer already in
    // flight, so the incoming payment could no longer be reconciled.
    if ($order->get_payment_method() !== 'sqrip') {
        die();
    }

    // Only for orders that are still waiting to be paid.
    $status_awaiting = str_replace('wc-', '', (string) sqrip_get_plugin_option('status_awaiting'));
    $qr_order_status = str_replace('wc-', '', (string) sqrip_get_plugin_option('qr_order_status'));
    $allowed_statuses = array_filter(array('pending', 'on-hold', $status_awaiting, $qr_order_status));

    if (!$order->has_status($allowed_statuses)) {
        die();
    }

    // Simple per-order throttle so the endpoint cannot be hammered.
    $throttle_key = 'sqrip_regen_' . $order->get_id();

    if (get_transient($throttle_key)) {
        die();
    }

    set_transient($throttle_key, 1, MINUTE_IN_SECONDS);

    $process_payment = process_payment_stt($order_id);

    wp_send_json($process_payment);

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
    if (!current_user_can('manage_woocommerce')) {
        wp_die(-1, 403);
    }

    if (empty($_POST['address'])) return;

    $address = is_array($_POST['address'])
        ? array_map('sanitize_text_field', wp_unslash($_POST['address']))
        : sanitize_text_field(wp_unslash($_POST['address']));

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
    if (!current_user_can('manage_woocommerce')) {
        wp_die(-1, 403);
    }

    if (empty($_POST['iban']) || empty($_POST['token'])) return;

    $iban = sanitize_text_field(wp_unslash($_POST['iban']));
    $token = sanitize_text_field(wp_unslash($_POST['token']));

    $store_iban = isset($_POST['store_iban']) ? $_POST['store_iban'] : null;
    $order_id = isset($_POST['order_id']) ? $_POST['order_id'] : null;

    // Explicit admin "Check" action: always re-query the API and refresh the cache.
    $response = sqrip_validation_iban($iban, $token, true);
    $result = [];
    $bank = isset($response->bank_data->bank) ? $response->bank_data->bank : '';
    $order = wc_get_order($order_id);

    if ($store_iban == "true" && $order && current_user_can('manage_woocommerce')) {
        check_ajax_referer('sqrip-process-refund', 'security');

        $order->update_meta_data('sqrip_refund_iban_num', $iban);
        $order->save();
    }

    switch ($response->message) {
        case 'Valid simple IBAN':
            $result['result'] = true;
            $result['qriban'] = false;
            $result['message'] = __("validated", "sqrip-swiss-qr-invoice");
            $result['description'] = __('This is a normal IBAN. The customer can make deposits without noting the reference number (RF...). Therefore, automatic matching with orders is not guaranteed throughout. Manual processing may be necessary. A QR-IBAN is required for automatic matching. This is available for the same bank account. Information about this is available from your bank.', 'sqrip-swiss-qr-invoice');
            $result['bank'] = $bank ? sprintf('Bank: <b>%s</b>', $bank) : '';
            break;

        case 'Valid qr IBAN':
            $result['result'] = true;
            $result['qriban'] = true;
            $result['message'] = __("validated", "sqrip-swiss-qr-invoice");
            $result['description'] = __('This is a QR IBAN. The customer can make payments only by specifying a QR reference (number). You can uniquely assign the deposit to a customer / order. This enables automatic matching of payments received with orders. Want to automate this step? Contact us <a href="mailto:info@sqrip.ch">info@sqrip.ch</a>.', 'sqrip-swiss-qr-invoice');
            $result['bank'] = $bank ? sprintf('Bank: <b>%s</b>', $bank) : '';
            break;

        default:
            $result['result'] = false;
            $result['qriban'] = false;
            $result['message'] = __("incorrect", "sqrip-swiss-qr-invoice");
            $result['description'] = __('It seems that an invalid (QR-)IBAN has been submitted. Please check the designated transfers-receiving IBAN you submitted.', 'sqrip-swiss-qr-invoice');
            break;
    }
    if ($response->will_need_confirmation) {
        $result['result'] = false;
        $result['qriban'] = false;
        $result['message'] = __("incorrect", "sqrip-swiss-qr-invoice");
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
    if (!current_user_can('manage_woocommerce')) {
        wp_die(-1, 403);
    }

    if (empty($_POST['token'])) return;

    $token = sanitize_text_field(wp_unslash($_POST['token']));

    $endpoint = 'details';
    $plugin_version = '';
    $plugins = get_plugins();
    $sqrip_info = array_filter($plugins, fn($item) => $item["Name"] == "sqrip.ch");
    
    if ($sqrip_info) {
        $plugin_version = array_values($sqrip_info)[0]['Version'];
    }
    $args = sqrip_prepare_remote_args('', 'GET', $token);
    $params = $plugin_version ? "?version=".$plugin_version : "";
    $response = wp_remote_request(SQRIP_ENDPOINT . $endpoint . $params, $args);
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
            $result['version'] = $plugin_version;
            // $result['message'] = __("Valid, active API Key", "sqrip-swiss-qr-invoice");

            $address = isset($body_decode->user->address) ? $body_decode->user->address : [];

            if ($address) {
                $name = "";

                if (isset($address->title)) {

                    $name = $address->title;

                } elseif (isset($body_decode->user)) {

                    $name = $body_decode->user->first_name . ' ' . $body_decode->user->last_name;

                }

                $result['address_from_sqrip'] = $name . ', ' . $address->street . ', ' . $address->city . ' ' . $address->zip;
            }

            break;

        default:
            $result['result'] = false;
            $result['response_code'] = $response_code;
            $result['version'] = $plugin_version;
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

    if (!current_user_can('manage_woocommerce')) {
        wp_die(-1, 403);
    }

    $refund_id = isset($_POST['refund_id']) ? absint($_POST['refund_id']) : 0;
    $refund = wc_get_order($refund_id);

    if (!$refund || !($refund instanceof WC_Order_Refund)) {
        return;
    }

    // stores the current date and time
    $date = date('Y-m-d H:i:s');
    $refund->update_meta_data('sqrip_refund_paid', $date);
    $refund->save();

    // add woocommerce message to original order
    $order = wc_get_order($refund->get_parent_id());
    if ($order) {
        $order->add_order_note(__('sqrip refund was marked as \'paid\'', 'sqrip-swiss-qr-invoice'));
    }

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

    if (!current_user_can('manage_woocommerce')) {
        wp_die(-1, 403);
    }

    $refund_id = isset($_POST['refund_id']) ? absint($_POST['refund_id']) : 0;
    $refund = wc_get_order($refund_id);

    if (!$refund || !($refund instanceof WC_Order_Refund)) {
        return;
    }

    $refund->delete_meta_data('sqrip_refund_paid');
    $refund->save();

    // add woocommerce message to original order
    $order = wc_get_order($refund->get_parent_id());
    if ($order) {
        $order->add_order_note(__('sqrip refund was marked as \'unbezahlt\'', 'sqrip-swiss-qr-invoice'));
    }

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
    if (!current_user_can('manage_woocommerce')) {
        wp_die(-1, 403);
    }

    if (empty($_POST['token'])) return;

    $token = sanitize_text_field(wp_unslash($_POST['token']));

    $endpoint = 'verify-token';

    $response = sqrip_remote_request($endpoint, '', 'GET', $token);

    wp_send_json($response);

    die();
}


add_action('wp_ajax_sqrip_payment_confirmed', 'sqrip_payment_confirmed');

function sqrip_payment_confirmed()
{
    check_ajax_referer('sqrip_payment_confirmed', '_wpnonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_die(-1, 403);
    }

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;

    if (!$order_id) {
        return;
    }

    $status_completed = sqrip_get_plugin_option('status_completed');

    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    // Only ever confirm payments on sqrip orders. The nonce is not bound to a specific
    // order, so without this an edited order_id could confirm payment on any order.
    if ($order->get_payment_method() !== 'sqrip') {
        wp_die(-1, 403);
    }

    $paged = isset($_GET['paged']) ? '&paged=' . absint($_GET['paged']) : '';

    // The orders screen differs by order storage: HPOS uses admin.php?page=wc-orders,
    // classic storage edit.php?post_type=shop_order (which only still works because
    // WooCommerce 301-redirects it).
    $orders_url = (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled())
        ? admin_url('admin.php?page=wc-orders')
        : admin_url('edit.php?post_type=shop_order');

    $sqrip_paid_invoice = sqrip_get_order_meta_value($order, 'sqrip_paid_invoice_number');
    $sqrip_invoice_count = sqrip_get_order_meta_value($order, 'sqrip_multiple_invoice_count');

    if ($sqrip_invoice_count && isset($sqrip_paid_invoice)) {
        $sqrip_invoice_count = (int) $sqrip_invoice_count;
        $sqrip_paid_invoice = (int) $sqrip_paid_invoice;
        $sqrip_next_paid_invoice_number = $sqrip_paid_invoice + 1;

        // Never count past the number of invoices that actually exist. The action is a
        // plain link with a nonce that stays valid for hours, so a double click or a
        // browser prefetch used to keep incrementing the counter — marking a partially
        // paid order as fully paid, and afterwards dragging a completed order back into
        // a partial status.
        if ($sqrip_next_paid_invoice_number > $sqrip_invoice_count) {
            wp_safe_redirect($orders_url . $paged);
            die();
        }

        $payment_status = sqrip_get_plugin_option("partial_invoice_".$sqrip_next_paid_invoice_number."_status");

        if ($payment_status) {
            $order->update_status($payment_status, '');
        }

        $order->update_meta_data('sqrip_paid_invoice_number', $sqrip_next_paid_invoice_number);
        $order->save();

        if ($sqrip_invoice_count > $sqrip_next_paid_invoice_number) {
            wp_safe_redirect($orders_url . $paged);
            die();
        }
    }

    // Fall back to the shop's completed status only when it is actually configured;
    // update_status('') would make WooCommerce silently drop the order back to pending.
    if ($status_completed) {
        $order->update_status($status_completed, '');
    }

    wp_safe_redirect($orders_url . $paged);
    die();
}

/**
 * Run the QR-invoice clean-up immediately from the settings screen.
 *
 * The clean-up otherwise only runs on a daily cron, which makes the "delete after x days"
 * setting impossible to verify. This lets the shop owner trigger exactly the same routine
 * on demand — it deletes only PDFs that sqrip created itself.
 *
 * @since 1.10.1
 */
add_action('wp_ajax_sqrip_cleanup_invoices_now', 'sqrip_cleanup_invoices_now');

function sqrip_cleanup_invoices_now()
{
    check_ajax_referer('sqrip-cleanup-invoices', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_die(-1, 403);
    }

    if (!sqrip_get_plugin_option('expired_date')) {
        wp_send_json(array(
            'result' => false,
            'message' => __('Please first set after how many days QR invoices should be deleted, then save the settings.', 'sqrip-swiss-qr-invoice'),
        ));
    }

    if (!class_exists('Sqrip_Media_Clearner')) {
        wp_send_json(array(
            'result' => false,
            'message' => __('The clean-up could not be started.', 'sqrip-swiss-qr-invoice'),
        ));
    }

    $cleaner = new Sqrip_Media_Clearner();
    $cleaner->clean();

    wp_send_json(array(
        'result' => true,
        'message' => __('Clean-up finished. All QR invoices that are no longer needed have been deleted.', 'sqrip-swiss-qr-invoice'),
    ));
}
