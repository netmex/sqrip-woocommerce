<?php

/**
 * Plugin Name:             sqrip.ch
 * Plugin URI:              https://sqrip.ch/
 * Description:             sqrip – A comprehensive, flexible and clever WooCommerce finance tool for the most widely used payment method in Switzerland: the bank transfers.
 * Version:                 1.9
 * Author:                  netmex digital gmbh
 * Author URI:              https://sqrip.ch/
 */

defined('ABSPATH') || exit;

// Make sure WooCommerce is active

if (is_multisite()) {
    try {
        $site_wide_plugins = get_site_option('active_sitewide_plugins', [], true);
        if (!isset($site_wide_plugins['woocommerce/woocommerce.php'])) {
            return;
        }
    } catch (\Throwable $th) {
        error_log($th->getMessage());
    }
} else {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        return;
    }
}

define('SQRIP_ENDPOINT', 'https://api.sqrip.ch/api/');
define('SQRIP_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/sqrip-ajax.php';

/**
 * Add plugin Settings link
 *
 * @since 1.0
 */

add_filter('plugin_action_links_' . SQRIP_PLUGIN_BASENAME, function ($links) {
    $action_links = array(
        'settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=sqrip') . '" aria-label="' . esc_attr__('View sqrip settings', 'sqrip-swiss-qr-invoice') . '">' . esc_html__('Settings', 'sqrip-swiss-qr-invoice') . '</a>',
    );

    return array_merge($action_links, $links);
});

/**
 * This action hook registers our PHP class as a WooCommerce payment gateway
 *
 * @since 1.0
 */

add_filter('woocommerce_payment_gateways', 'sqrip_add_gateway_class');

function sqrip_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Sqrip_Payment_Gateway'; // your class name is here
    return $gateways;
}

/**
 * The class itself, please note that it is inside plugins_loaded action hook
 *
 * @since 1.0
 */

add_action('plugins_loaded', 'sqrip_init_gateway_class');

function sqrip_init_gateway_class()
{
    require_once __DIR__ . '/inc/class-wc-sqrip-payment-gateway.php';
}

add_action('plugins_loaded', 'sqrip_media_cleaner_require');

function sqrip_media_cleaner_require()
{
    require_once __DIR__ . '/inc/class-sqrip-media-cleaner.php';
}

add_action('plugins_loaded', 'sqrip_version_sync_require');

function sqrip_version_sync_require()
{
    require_once __DIR__ . '/inc/class-sqrip-version-sync.php';
}

/**
 * Unschedule media clean cron job on sqrip deactivation
 * @since 1.84
 */
register_deactivation_hook( __FILE__, 'sqrip_deactivation_action' ); 

function sqrip_deactivation_action() {
    $timestamp = wp_next_scheduled( 'sqrip_media_cleaner' );
    wp_unschedule_event( $timestamp, 'sqrip_media_cleaner' );

    $timestamp2 = wp_next_scheduled( 'sqrip_version_sync' );
    wp_unschedule_event( $timestamp2, 'sqrip_version_sync' );
}

/**
 *  Add admin notices
 *
 * @since 1.0
 */
add_action('admin_notices', 'sqrip_add_admin_notice');

function sqrip_add_admin_notice()
{
    if (function_exists('get_woocommerce_currency')) {
        $currency = get_woocommerce_currency();
        $currency_arr = array('EUR', 'CHF');

        if (!in_array($currency, $currency_arr)) {
            $class = 'notice notice-error is-dismissible';
            $message = __('The sqrip plugin only supports EUR and CHF currencies!', 'sqrip-swiss-qr-invoice');

            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        }
    }

    $allowed_types = get_allowed_mime_types();

    if (!array_key_exists('pdf', $allowed_types)) {
        $class = 'notice notice-error is-dismissible';
        $message = __('Your website is currently unable to upload a PDF.', 'sqrip-swiss-qr-invoice');

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
    }

    if (false !== ($msg = get_transient("sqrip_admin_action_errors")) && $msg) {

        $class = 'notice notice-error is-dismissible';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $msg);

        delete_transient("sqrip_admin_action_errors");
    }

    $allow_url_fopen = ini_get('allow_url_fopen');

    if (!$allow_url_fopen) {
        $class = 'notice notice-error is-dismissible';
        $message = __('Damit sqrip die QR-Rechnungen in der Mediathek speichern kann muss "allow_url_open" in der php.ini oder htaccess-Datei zugelassen werden.');

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
    }

}

/**
 * Adding scripts for settings sqrip page in admin
 *
 * @since 1.0
 */

add_action('admin_enqueue_scripts', function ($hook_suffix) {

    wp_enqueue_style('sqrip-admin', plugins_url('css/sqrip-admin.css', __FILE__), '', '1.1.1');

    if (isset($_GET['section']) && $_GET['section'] == "sqrip") {
        wp_enqueue_script('sqrip-admin', plugins_url('js/sqrip-admin.js', __FILE__), array('jquery'), '1.5.5', true);

        $sqrip_new_status = sqrip_get_plugin_option('enabled_new_status');
        $sqrip_new_awaiting_status = sqrip_get_plugin_option('enabled_new_awstatus');
        $sqrip_new_suppressed_status = sqrip_get_plugin_option('enabled_new_sustatus');
        $sqrip_new_qr_status = sqrip_get_plugin_option('enabled_new_qrstatus');
        $sqrip_new_partial_invoice_1_status = sqrip_get_plugin_option('enabled_new_partial_invoice_1_status');
        $sqrip_new_partial_invoice_2_status = sqrip_get_plugin_option('enabled_new_partial_invoice_2_status');
        $sqrip_new_partial_invoice_3_status = sqrip_get_plugin_option('enabled_new_partial_invoice_3_status');

        $sqrip_details = sqrip_get_user_details('', 'full');

        wp_localize_script('sqrip-admin', 'sqrip',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'txt_check_connection' => __('Connection test', 'sqrip-swiss-qr-invoice'),
                'txt_validate_iban' => __('Check', 'sqrip-swiss-qr-invoice'),
                'txt_create' => $sqrip_new_status == 'yes' ? __('Update', 'sqrip-swiss-qr-invoice') : __('Create', 'sqrip-swiss-qr-invoice'),
                'txt_awaiting_create' => $sqrip_new_awaiting_status == 'yes' ? __('Update', 'sqrip-swiss-qr-invoice') : __('Create', 'sqrip-swiss-qr-invoice'),
                'txt_suppressed_create' => $sqrip_new_suppressed_status == 'yes' ? __('Update', 'sqrip-swiss-qr-invoice') : __('Create', 'sqrip-swiss-qr-invoice'),
                'txt_qr_create' => $sqrip_new_qr_status == 'yes' ? __('Update', 'sqrip-swiss-qr-invoice') : __('Create', 'sqrip-swiss-qr-invoice'),
                'txt_partial_invoice_1_create' => $sqrip_new_partial_invoice_1_status == 'yes' ? __('Update', 'sqrip-swiss-qr-invoice') : __('Create', 'sqrip-swiss-qr-invoice'),
                'txt_partial_invoice_2_create' => $sqrip_new_partial_invoice_2_status == 'yes' ? __('Update', 'sqrip-swiss-qr-invoice') : __('Create', 'sqrip-swiss-qr-invoice'),
                'txt_partial_invoice_3_create' => $sqrip_new_partial_invoice_3_status == 'yes' ? __('Update', 'sqrip-swiss-qr-invoice') : __('Create', 'sqrip-swiss-qr-invoice'),
                'txt_send_test_email' => sprintf(
                    __('Send test to %s', 'sqrip-swiss-qr-invoice'),
                    esc_html(get_option('admin_email'))
                ),
                'txt_send_test_email_warning' => __('You have no free credits anymore, a paid credit will be charged', 'sqrip-swiss-qr-invoice'),
                'txt_send_test_email_no_credit' => __('You have no credits left', 'sqrip-swiss-qr-invoice'),
                'details' => $sqrip_details,
                'field_required_txt' => __('This field is required', 'sqrip-swiss-qr-invoice')
            )
        );

        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css', array(), '4.1.0');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
    }

    global $post_type;
    $screen = get_current_screen();

    if (is_object($screen) && in_array($screen->post_type, ['shop_order', 'shop_order_placehold'])) {

        wp_enqueue_script('sqrip-order', plugins_url('js/sqrip-order.js', __FILE__), array('jquery'), '1.1.1', true);
        wp_enqueue_script('sqrip-refund', plugins_url('js/sqrip-refund.js', __FILE__), array('jquery'), '1.1.1', true);

        wp_localize_script('sqrip-order', 'sqrip',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'ajax_refund_paid_nonce' => wp_create_nonce('sqrip-mark-refund-paid'),
                'ajax_refund_unpaid_nonce' => wp_create_nonce('sqrip-mark-refund-unpaid'),
                'ajax_refund_nonce' => wp_create_nonce('sqrip-process-refund'),
                'status_completed' => sqrip_get_plugin_option('status_completed'),
                'field_required_txt' => __('This field is required', 'sqrip-swiss-qr-invoice')
            )
        );
    }

    if (in_array($hook_suffix, ['user-edit.php', 'profile.php'])) {
        wp_enqueue_script('sqrip-customer-profile', plugins_url('js/sqrip-customer-profile.js', __FILE__), array('jquery'), '1.1.1', true);
        wp_localize_script('sqrip-customer-profile', 'sqrip', array('ajax_url' => admin_url('admin-ajax.php')));
    }


});

/**
 * Adding scripts for FE
 *
 * @since 1.0
 */

add_action('wp_enqueue_scripts', 'sqrip_enqueue_scripts');

function sqrip_enqueue_scripts()
{
    wp_enqueue_style('sqrip', plugins_url('css/sqrip-order.css', __FILE__), false);

    wp_enqueue_script('sqrip', plugins_url('js/sqrip-fe.js', __FILE__), array('jquery'), '1.0.3', true);

    wp_localize_script('sqrip', 'sqrip',
        array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'ajax_nonce' => wp_create_nonce('sqrip-generate-new-qrcode')
        )
    );
}


/**
 * Declare plugin compatibility with WooCommerce HPOS (High Performance Order Storage)
 * 
 * @since 1.8.4
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );


/**
 *  Adding Meta container admin shop_order pages
 *
 * @since 1.0
 */

add_action('add_meta_boxes', 'sqrip_add_meta_boxes');

if (!function_exists('sqrip_add_meta_boxes')) {
    function sqrip_add_meta_boxes()
    {
        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';
        
        add_meta_box('sqrip_detail_fields', __('sqrip Payment', 'sqrip-swiss-qr-invoice'), 'sqrip_add_fields_for_order_details', $screen, 'side', 'core');
        /**
         * Deprecated 
         * Now using WooCommerce HPOS with backwards compatibility
         * @since 1.8.4
         */
        // add_meta_box('sqrip_detail_fields', __('sqrip Payment', 'sqrip-swiss-qr-invoice'), 'sqrip_add_fields_for_order_details', 'shop_order', 'side', 'core');
    }
}

/**
 *  Adding Meta field in the meta container admin shop_order pages to dislay sqrip payment details
 * @since 1.0
 */

if (!function_exists('sqrip_add_fields_for_order_details')) {
    function sqrip_add_fields_for_order_details( $post_or_order_object )
    {
        $order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;
        $order_id = $order->get_id();
        $order_data = $order->get_data(); // order data

        $payment_method = '';
        $reference_id = '';
        $pdf_file = '';
        $payment_method = $order->get_payment_method();
        $user = $order->get_user();
        $isHPOS = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $refund_iban = $user ?
            sqrip_get_customer_iban($user) :
            sqrip_get_order_meta_value($order, 'sqrip_refund_iban_num');
        $sqrip_refund_token = sqrip_get_plugin_option('return_token');

        // Implement compatibility with WooCommerce HPOS since 1.8.4
        $reference_id = sqrip_get_order_meta_value($order, 'sqrip_reference_id');
        $reference_id_html = "";
        $pdf_file_link = "";
        $invoice_count = sqrip_get_order_meta_value($order, 'sqrip_multiple_invoice_count');
        $currency_symbol = $order_data['currency'];
        $multiple_invoice_details = "";

        if ($invoice_count) {
            $invoice_count = (int) $invoice_count;

            for ($i=1; $i <= $invoice_count; $i++) {
                $partial_title = __('Partial Payment', 'sqrip-swiss-qr-invoice')." [".$i."/".$invoice_count."] <br>";
                $partial_amount = $currency_symbol . (sqrip_get_order_meta_value($order, 'sqrip_partial_invoice_amount_'.$i) ?: '0.00');

                $qr_order_status_options = wc_get_order_statuses();
                $payment_status = sqrip_get_plugin_option("partial_invoice_".$i."_status");
                $status_fullname = __('Status changing to', 'sqrip-swiss-qr-invoice') . ": " . $payment_status && isset($qr_order_status_options[$payment_status]) ?
                    $qr_order_status_options[$payment_status] : $payment_status;
                //confirm btn
                $sqrip_paid_invoice = sqrip_get_order_meta_value($order, 'sqrip_paid_invoice_number');
                $sqrip_next_paid_invoice_number = (int) $sqrip_paid_invoice + 1;
                $confirm_btn = $sqrip_next_paid_invoice_number > $i ? "<li><span class='sqrip-paid-invoice'>".__('Paid', 'sqrip-swiss-qr-invoice')." [".$i."/".$invoice_count."]</span></li>" : "";
                if ($i == $sqrip_next_paid_invoice_number) {
                    $confirm_url = wp_nonce_url(admin_url('admin-ajax.php?action=sqrip_payment_confirmed&order_id=' . $order_id), 'sqrip_payment_confirmed');
                    $confirm_btn = "<li><button class='button button-primary partial-payment-confirm-btn' data-confirm-url=".$confirm_url.">".__('Confirm payment', 'sqrip-swiss-qr-invoice')." [".$i."/".$invoice_count."]</button></li>";
                }

                $reference_id = sqrip_get_order_meta_value($order, 'sqrip_reference_id_'.$i);
                $reference_id_formatted = sqrip_format_reference_id($reference_id, $order_id);
                $reference_id_html = "<p>" . $reference_id == "deleted" ? __('Deleted', 'sqrip-swiss-qr-invoice') : $reference_id_formatted . "</p>";

                $pdf_file = sqrip_get_order_meta_value($order, 'sqrip_pdf_file_url_'.$i);
                if ($pdf_file == "deleted") {
                    $pdf_file_link = "<p>".__('Deleted', 'sqrip-swiss-qr-invoice')."</p>";
                } else {
                    $pdf_file_link = "<p><a target='_blank' href='".esc_url($pdf_file)."'><span class='dashicons dashicons-media-document'></span></a><p>";
                }

                $multiple_invoice_details .= $partial_title . $partial_amount . "<br>" . $reference_id_html .$pdf_file_link . $status_fullname . $confirm_btn . "<br><hr><br>";
            }

        } else {
            $reference_id_formatted = sqrip_format_reference_id($reference_id, $order_id);
            $reference_id_html = $reference_id == "deleted" ? __('Deleted', 'sqrip-swiss-qr-invoice') : $reference_id_formatted;
            $pdf_file = sqrip_get_order_meta_value($order, 'sqrip_pdf_file_url');
            if ($pdf_file == "deleted") {
                $pdf_file_link = __('Deleted', 'sqrip-swiss-qr-invoice');
            } else {
                $pdf_file_link = "<a target='_blank' href='".esc_url($pdf_file)."'><span class='dashicons dashicons-media-document'></span></a>";
            }
        }


        // check for legacy pdf meta file
        if (!$pdf_file) {
            $pdf_file = get_post_meta($order_id, 'sqrip_pdf_file', true);

            if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {            
                $pdf_file = $order->get_meta('sqrip_pdf_file', true);
            }
        }

        if ($reference_id || $pdf_file) {
            $btn_txt = __('Renew QR Invoice', 'sqrip-swiss-qr-invoice');
        } else {
            $btn_txt = __('Generate QR Invoice', 'sqrip-swiss-qr-invoice');
        }

        if ($payment_method == 'sqrip') {
            $status_awaiting = sqrip_get_plugin_option('status_awaiting');

            $order = wc_get_order($order_id);
            $order_status = 'wc-' . $order->get_status();

            ?>

            <ul class="sqrip-payment">

                <?php if ($invoice_count): ?>
                    <?php echo $multiple_invoice_details ?>
                <?php else: ?>

                    <?php if ($reference_id_html) { ?>
                        <li>
                            <b><?php echo __('Reference number', 'sqrip-swiss-qr-invoice'); ?>
                                :</b><br><?php echo $reference_id_html; ?>
                        </li>
                    <?php } ?>

                    <?php if ($pdf_file_link) { ?>
                        <li>
                            <b><?php echo __('QR-Code PDF', 'sqrip-swiss-qr-invoice') ?> :</b>
                            <?php echo $pdf_file_link; ?>
                        </li>
                    <?php } ?>

                    <li>
                        <button class="button button-secondary sqrip-re-generate-qrcode"><?php echo $btn_txt; ?></button>
                    </li>

                    <?php if ($status_awaiting == $order_status && sqrip_get_plugin_option('payment_comparison_enabled') == 'yes'): ?>
                        <li>
                            <button class="button button-primary sqrip-payment-confirmed">
                                <?php echo __('Confirm payment', 'sqrip-swiss-qr-invoice'); ?>
                            </button>
                        </li>
                    <?php endif; ?>
                    
                <?php endif; ?>
            </ul>

            <?php

        } else {
            if (sqrip_get_plugin_option('enabled') == 'no') {
                echo '<p>' . __('Please note that the sqrip payment method is disabled in the sqrip plugin settings.', 'sqrip-swiss-qr-invoice') . '</p>';
            } else {
                echo '<p>' . __('Automatic QR-invoice generation by sqrip is disabled.', 'sqrip-swiss-qr-invoice') . '</p>';
                echo '<button class="button button-secondary sqrip-initiate-payment">' . __('Generate QR Invoice', 'sqrip-swiss-qr-invoice') . '</button>';
            }
        }

        if ($refund_iban) {
            echo '<input type="hidden" id="sqrip-customer-iban" value="'.$refund_iban.'" />';
        }
        echo '<input type="hidden" id="sqrip-refund-token" value="'.$sqrip_refund_token.'" />';
    }
}

/**
 * Add sqrip QR code image in email body/content
 * @since 1.0
 *
 * @deprecated 29-10-2021 v1.0.3 | Integration By default is attachment.
 */

// add_action('woocommerce_email_after_order_table', 'sqrip_add_qrcode_in_email_after_order_table', 99, 4);

function sqrip_add_qrcode_in_email_after_order_table($order, $sent_to_admin, $plain_text, $email)
{
    if (empty($order) || !isset($email->id) || !method_exists($order, 'get_payment_method')) {
        return;
    }

    $payment_method = $order->get_payment_method();

    // Integration By default is attachment.
    $integration_email = sqrip_get_plugin_option('integration_email');
    $email_attached = sqrip_get_plugin_option('email_attached');

    $array_in = array('both', 'body');

    if ($email->id === $email_attached && $payment_method === 'sqrip' && in_array($integration_email, $array_in)) {
        // $order_id = $order->id;
        $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
        $png_file = get_post_meta($order_id, 'sqrip_png_file_url', true);

        echo $png_file ? '<div class="sqrip-qrcode-png"><p>' . esc_html__('Use the QR invoice below to pay the outstanding balance.', 'sqrip-swiss-qr-invoice') . '</p><img src="' . esc_url($png_file) . '" alt="' . esc_attr('sqrip QR-Code', 'sqrip-swiss-qr-invoice') . '" width="200"/></div>' : '';
    }
}

/**
 *  sqrip QR code file attach in email
 *
 * @since 1.0
 */

add_filter('woocommerce_email_attachments', 'sqrip_attach_qrcode_pdf_to_email', 99, 3);

function sqrip_attach_qrcode_pdf_to_email($attachments, $email_id, $order)
{
    if (empty($order) || !isset($email_id) || !(is_object($order) && method_exists($order, 'get_payment_method'))) {
        return $attachments;
    }

    $payment_method = $order->get_payment_method();

    $plugin_options = get_option('woocommerce_sqrip_settings', array());

    // $integration_email = array_key_exists('integration_email', $plugin_options) ? $plugin_options['integration_email'] : '';

    // Integration By default is attachment.
    $integration_email = 'attachment';
    $email_attached = sqrip_get_plugin_option('email_attached');

    $array_in = array('both', 'attachment');
    $isEmailAttached = is_array($email_attached) ?
        in_array($email_id, $email_attached) :
        $email_id == $email_attached;

    if ($payment_method === 'sqrip' && in_array($integration_email, $array_in)) {
        // $order_id = $order->id;
        $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;

        $pdf_file_path = '';
        
        // Implement compatibility with WooCommerce HPOS since 1.8.4
        $isHPOS = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        $invoice_count = sqrip_get_order_meta_value($order, 'sqrip_multiple_invoice_count');
        if ($invoice_count) {
            $invoice_count = (int) $invoice_count;

            for ($i=1; $i <= $invoice_count; $i++) { 
                $pdf_file_path = sqrip_get_order_meta_value($order, 'sqrip_pdf_file_path_'.$i);
                $email_to_attach = sqrip_get_order_meta_value($order, 'email_attached_invoice_'.$i);

                if ($pdf_file_path) {
                    if (is_array($email_to_attach) && in_array($email_id, $email_to_attach)) {
                        $attachments[] = $pdf_file_path;
                    } else if (!$email_to_attach && $isEmailAttached) {
                        $attachments[] = $pdf_file_path;
                    }
                }
            }

        } else {
            $pdf_file_path = sqrip_get_order_meta_value($order, 'sqrip_pdf_file_path');
            // WARNING: attachments must be local file paths and not URLs
            if ($pdf_file_path && $isEmailAttached) {
                $attachments[] = $pdf_file_path;
            }
        }
        // error_log("SQRIP:: Using HPOS pdf_file:: ".$pdf_file_path);
    }

    return $attachments;
}

/**
 *  Insert sqrip QR code after order table
 *
 * @since 1.5.3
 */

add_action('woocommerce_order_details_after_order_table', 'sqrip_qr_action_order_details_after_order_table', 10, 1);

function sqrip_qr_action_order_details_after_order_table($order)
{
    if (!method_exists($order, 'get_payment_method')) {
        return;
    }

    $payment_method = $order->get_payment_method();

    if ($payment_method === 'sqrip') {
        $order_id = $order->get_id();

        $plugin_options = get_option('woocommerce_sqrip_settings', array());

        $integration_order = array_key_exists('integration_order', $plugin_options) ? $plugin_options['integration_order'] : '';
        $sqrip_suppress_generation = sqrip_get_plugin_option('suppress_generation');

        // $png_file = get_post_meta($order_id, 'sqrip_png_file_url', true);
        $pdf_file = '';
        $pdf_file_html = '';
        
        // Implement compatibility with WooCommerce HPOS since 1.8.4
        $isHPOS = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        // error_log("SQRIP:: Using HPOS pdf_file:: ".$pdf_file);
        $invoice_count = sqrip_get_order_meta_value($order, 'sqrip_multiple_invoice_count');
        $download_btn_align = sqrip_get_plugin_option('invoice_download_button_align') ?? 'left';
        if ($invoice_count) {
            $invoice_count = (int) $invoice_count;

            for ($i=1; $i <= $invoice_count; $i++) { 
                $pdf_file = sqrip_get_order_meta_value($order, 'sqrip_pdf_file_url_'.$i);
                if ($pdf_file) {
                    $pdf_file_html .= '<p style="text-align:'.$download_btn_align.';"><a class="button button-sqrip-invoice" href="' . esc_url($pdf_file) . '" >' . __('Download Invoice', 'sqrip-swiss-qr-invoice') . ' <i class="dashicons dashicons-pdf"></i></a><p>';
                }
            }

        } else {
            $pdf_file = sqrip_get_order_meta_value($order, 'sqrip_pdf_file_url');
            if ($pdf_file) {
                $pdf_file_html = '<p style="text-align:'.$download_btn_align.';"><a class="button button-sqrip-invoice" href="' . esc_url($pdf_file) . '" >' . __('Download Invoice', 'sqrip-swiss-qr-invoice') . ' <i class="dashicons dashicons-pdf"></i></a></p>';
            }
        }

        echo '<div class="sqrip-order-details">';

        //Display checkout remarks
        $checkout_remarks = sqrip_get_plugin_option('checkout_remarks') ?? "";

        if (strlen(trim($checkout_remarks)) > 0) {
            echo $checkout_remarks;
        }

        if ($integration_order == "yes" && $pdf_file) {
            /**
             *  Insert sqrip QR code PNG after customer details
             *
             * @deprecated
             * @since 1.1.1
             */
            // echo '<div class="sqrip-qrcode-png"><p>' . __( 'Use the QR invoice below to pay the outstanding balance.' , 'sqrip-swiss-qr-invoice') . '</p><a href="' . esc_url($png_file) . '" target="_blank"><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip-swiss-qr-invoice').'" width="300" /></a></div>';

            // Insert download button PDF
            echo '<div class="sqrip-qrcode-pdf"><p>' . __('Use the QR invoice below to pay the outstanding balance.', 'sqrip-swiss-qr-invoice') . '</p>'. $pdf_file_html .'</div>';
        }

        //add sqrip info to page
        echo '<input type="hidden" id="sqrip-invoice-info" data-suppressed="'.$sqrip_suppress_generation.'"  />';

        /**
         *  Insert Generate New QR code PNG after customer details
         *
         * @deprecated
         * @since 1.5.6
         */

        // if ( is_wc_endpoint_url( 'view-order' ) ) {
        //     echo '<div class="sqrip-generate-new-qrcode"><button id="sqripGenerateNewQRCode" data-order="'.esc_attr($order_id).'" class="button button-sqrip-generate-qrcode">'. __('Generate new QR code','sqrip-swiss-qr-invoice'). '</a></button>';
        // }

        echo '</div>';
    }
}

/**
 *  Re-Generate QR-Code in Admin Order page
 *
 * @since 1.0.3
 * 
 * Updated from wp_insert_post_data
 * @since 1.8.5
 */

add_action('woocommerce_process_shop_order_meta', function($post_id) {

    if (
        (
            isset($_POST['_sqrip_regenerate_qrcode']) ||
            isset($_POST['_sqrip_initiate_payment'])
        )
    ) {
        $order = wc_get_order($post_id);
        $order_data = $order->get_data(); // order data

        ## BILLING INFORMATION:
        $order_billing_first_name = $_POST['_billing_first_name'];
        $order_billing_last_name = $_POST['_billing_last_name'];
        $order_billing_address = $_POST['_billing_address_1'];
        $order_billing_address .= $_POST['_billing_address_2'] ? ', ' . $_POST['_billing_address_2'] : "";
        $order_billing_city = $_POST['_billing_city'];
        $order_billing_postcode = $_POST['_billing_postcode'];
        $order_billing_country = $_POST['_billing_country'];
        $order_billing_company = $_POST['_billing_company'];

        $currency_symbol = $order_data['currency'];
        $amount = floatval($order_data['total']);

        $body = sqrip_prepare_qr_code_request_body($currency_symbol, $amount, $post_id);

        $body["payable_by"] = array(
            'name' => "$order_billing_first_name $order_billing_last_name",
            'street' => $order_billing_address,
            'postal_code' => $order_billing_postcode,
            'town' => $order_billing_city,
            'country_code' => $order_billing_country
        );

        if (!empty($order_billing_company)) {
            $body["payable_by"]['name'] = $order_billing_company;
        }

        $plugin_options = sqrip_get_plugin_options();
        $payer = $plugin_options['payer'];
        if ($payer == 'both') {
            $body["payable_by"]['name'] = "$order_billing_first_name $order_billing_last_name" . "\n" . $order_billing_company;
        }

        $address = sqrip_get_plugin_option('address');

        $body['payable_to'] = sqrip_get_payable_to_address($address);

        $number_of_invoices = sqrip_get_plugin_option('number_of_invoices');
        if ($number_of_invoices && $number_of_invoices > 1) {
            $body["invoice_fractions"] = [];
            for ($i=1; $i <= $number_of_invoices; $i++) {
                $invoice_fraction = sqrip_get_plugin_option('invoice_fraction_'.$i);
                if ($invoice_fraction) {
                    $body["invoice_fractions"][$i] = $invoice_fraction;
                }
            }
        }

        $args = sqrip_prepare_remote_args($body, 'POST');

        $endpoint = 'code';
        $response = wp_remote_post(SQRIP_ENDPOINT . $endpoint, $args);

        $response_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($response_body);

        if (is_wp_error($response)) {

            $order->add_order_note(
                sprintf(
                    __('Error: %s', 'sqrip-swiss-qr-invoice'),
                    esc_html($response_body->message)
                )
            );

        } else {

            if (isset($_POST['_sqrip_regenerate_qrcode'])) {
                $order_notes = __('sqrip payment QR code was successfully regenerated', 'sqrip-swiss-qr-invoice');
                $error_title = __('Renew QR Invoice error:', 'sqrip-swiss-qr-invoice');
            } elseif (isset($_POST['_sqrip_initiate_payment'])) {
                $error_title = __('Initiate sqrip payment error:', 'sqrip-swiss-qr-invoice');
                $order_notes = __('sqrip payment initiation was successful', 'sqrip-swiss-qr-invoice');
                $order->set_payment_method('sqrip');
            }

            $is_multiple_invoices = $number_of_invoices && $number_of_invoices > 1;
            $response_reference = $is_multiple_invoices && count($response_body) ? $response_body[0]->reference : $response_body->reference;
    
            if (isset($response_reference)) {
                if ($is_multiple_invoices) {
                    $num = 1;
                    foreach ($response_body as $key => $invoice) {
        
                        $sqrip_pdf = $invoice->pdf_file;
                        $sqrip_reference = $invoice->reference;
            
                        $sqrip_class_payment = new WC_Sqrip_Payment_Gateway;

                        $sqrip_qr_pdf_attachment_id = $sqrip_class_payment->file_upload($sqrip_pdf, '.pdf', '', $order->ID."-".$num);
            
                        $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
                        $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);
    
                        $order->update_meta_data('sqrip_reference_id_'.$num, $sqrip_reference);
                        $order->update_meta_data('sqrip_qr_pdf_attachment_id_'.$num, $sqrip_qr_pdf_attachment_id);
                        $order->update_meta_data('sqrip_pdf_file_url_'.$num, $sqrip_qr_pdf_url);
                        $order->update_meta_data('sqrip_pdf_file_path_'.$num, $sqrip_qr_pdf_path);
                        $num++;
                    }
    
                    $order->add_order_note($order_notes);
                    $order->update_meta_data('sqrip_multiple_invoice_count', $number_of_invoices);
                }
                else {
        
                    $sqrip_pdf = $response_body->pdf_file;
                    // $sqrip_png       =    $response_body->png_file;
                    $sqrip_reference = $response_body->reference;
        
                    // TODO: replace with attachment ID and store this in meta instead of actual file
                    $sqrip_class_payment = new WC_Sqrip_Payment_Gateway;

                    $sqrip_qr_pdf_attachment_id = $sqrip_class_payment->file_upload($sqrip_pdf, '.pdf', '', $order->ID);
        
                    $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
                    $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);
        
                    // $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
                    // $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);
        
                    $order->add_order_note($order_notes);
        
                    $order->update_meta_data('sqrip_reference_id', $sqrip_reference);
                    $order->update_meta_data('sqrip_qr_pdf_attachment_id', $sqrip_qr_pdf_attachment_id);
                    $order->update_meta_data('sqrip_pdf_file_url', $sqrip_qr_pdf_url);
                    $order->update_meta_data('sqrip_pdf_file_path', $sqrip_qr_pdf_path);
                }

                $order->update_meta_data('sqrip_refund_iban_num', get_user_meta($order->get_user_id(), 'iban_num', true));

                // $order->update_meta_data('sqrip_png_file_url', $sqrip_qr_png_url);
                // $order->update_meta_data('sqrip_png_file_path', $sqrip_qr_png_path);

                $order->save();

            } else {

                $errors_output = "";

                if (isset($response_body->errors)) {
                    $errors_output = json_encode($response_body->errors, JSON_PRETTY_PRINT);
                    // $error_goto = 'Please add your address correctly at <a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sqrip' ) . '" aria-label="' . esc_attr__( 'sqrip settings', 'sqrip-swiss-qr-invoice' ) . '">' . esc_html__( 'sqrip settings', 'sqrip-swiss-qr-invoice' ) . '</a>';

                    $error_arr = (array)$response_body->errors;
                    if (
                        isset($error_arr['payable_by.name']) ||
                        isset($error_arr['payable_by.company'])
                    ) {
                        $errors_output = __('Please submit at least either Name / Last name or Company name to generate the qr-invoice', 'sqrip-swiss-qr-invoice');
                    }

                }

                set_transient('sqrip_admin_action_errors', sprintf(
                    __('<b>%s</b> %s</br>%s', 'sqrip-swiss-qr-invoice'),
                    $error_title,
                    esc_html($response_body->message),//." <a href='https://www.sqrip.ch/#pricing' target='_blank'>https://www.sqrip.ch/#pricing</a>"
                    esc_html($errors_output)
                ), 60);

                $order->add_order_note(
                    sprintf(
                        __('%s %s <p>%s</p>', 'sqrip-swiss-qr-invoice'),
                        $error_title,
                        esc_html($response_body->message),
                        esc_html($errors_output)
                    )
                );
            }
        }
    }
});

add_action('woocommerce_after_order_refund_item_name', "sqrip_display_refund_qr_code", 10, 1);

/**
 * Displays UI for marking a sqrip refund as completed within the WooCommerce UI
 * @param $refund WC_Order_Refund
 */
function sqrip_display_refund_qr_code($refund)
{

    $refund_qr_attachment_id = $refund->get_meta('sqrip_refund_qr_attachment_id');

    if (!$refund_qr_attachment_id) {
        return;
    }

    $refund_qr_pdf_url = wp_get_attachment_url($refund_qr_attachment_id);
    $refund_qr_pdf_path = get_attached_file($refund_qr_attachment_id);
    $refund_id = $refund->get_id();
    $title = __("Show QR Code", 'sqrip-swiss-qr-invoice');
    $hidden_title = __("Hide QR Code", 'sqrip-swiss-qr-invoice');

    $paid_title = __("Mark as paid", 'sqrip-swiss-qr-invoice');
    $unpaid_title = __("Mark as unpaid", 'sqrip-swiss-qr-invoice');

    $paid_status = __("paid on", 'sqrip-swiss-qr-invoice');
    $unpaid_status = __("unpaid", 'sqrip-swiss-qr-invoice');

    $paid = $refund->get_meta('sqrip_refund_paid');
    $status = $paid ? $paid_status . " $paid" : $unpaid_status;

    $hide_paid_action_css = !$paid ?: 'display: none';
    $hide_unpaid_action_css = $paid ?: 'display: none';

    echo "<span class='woocommerce_sqrip_refund_status' data-paid='$paid_status' data-unpaid='$unpaid_status'>[$status]</span>";
    echo "<br/>";
    echo "<a class='woocommerce_sqrip_toggle_qr' href='$refund_qr_pdf_url' title='$title' target='_blank' data-title-hide='$hidden_title' data-title='$title' style='margin-right: 10px; $hide_paid_action_css'>$title</a>";
    echo "<a class='woocommerce_sqrip_refund_paid' href='#' title='$paid_title' style='margin-right: 10px; color: green; $hide_paid_action_css' data-refund='$refund_id'>$paid_title</a>";
    echo "<a class='woocommerce_sqrip_refund_unpaid' href='#' title='$unpaid_title' style='color: darkred; $hide_unpaid_action_css' data-refund='$refund_id'>$unpaid_title</a>";
    echo "<div class='woocommerce_sqrip_qr_wrapper' style='display:none; margin: 5px;'>";
    echo "<img src='$refund_qr_pdf_url' width='300' height='300'/>";
    echo "</div>";

}

add_action('woocommerce_order_refunded', 'action_woocommerce_order_refunded', 10, 2);

/**
 * Called when an order is refunded using WooCommerce
 * @param $order_id int
 * @param $refund_id int
 */
function action_woocommerce_order_refunded($order_id, $refund_id)
{

    $order = wc_get_order($order_id);

    /**
     * @var WC_Order_Refund
     */
    $refund = wc_get_order($refund_id);

    if (!method_exists($order, 'get_payment_method')) {
        return;
    }

    $payment_method = $order->get_payment_method();

    if ($payment_method !== 'sqrip') {
        return;
    }

    // attach meta values to refund instead of order
    // because a single order can potentially have multiple refunds
    $refund_qr_attachment_id = $order->get_meta('sqrip_refund_qr_attachment_id');
    $refund->update_meta_data('sqrip_refund_qr_attachment_id', $refund_qr_attachment_id);

    $refund->save();
}


add_action('show_user_profile', 'sqrip_extra_user_profile_fields');
add_action('edit_user_profile', 'sqrip_extra_user_profile_fields');

/**
 * Displays extra field in user profile page to set iban for refund
 * @param $user
 * @return void
 */
function sqrip_extra_user_profile_fields($user)
{

    $sqrip_return_enabled = sqrip_get_plugin_option('return_enabled');
    $sqrip_refund_token = sqrip_get_plugin_option('return_token');

    if ($sqrip_return_enabled) {
        ?>
        <h3><?php _e("Refunds with sqrip", "sqrip"); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="iban"><?php _e("IBAN"); ?></label></th>
                <td>
                    <input type="text" name="iban" id="iban"
                           value="<?php echo esc_attr(sqrip_get_customer_iban($user)); ?>" class="regular-text"/><br/>
                           <button id="btn_sqrip_check_iban" class="button-secondary sqrip-btn-validate" style="margin-top: 10px;">Check</button><br/>
                    <span class="description"><?php _e("This iban will be used to generate a sqrip qr code in case of a refund."); ?></span>
                    <input type="hidden" id="sqrip-refund-token" value="<?=$sqrip_refund_token; ?>" />
                </td>
            </tr>
        </table>
        <?php
    }
}

add_action('personal_options_update', 'sqrip_save_extra_user_profile_fields');
add_action('edit_user_profile_update', 'sqrip_save_extra_user_profile_fields');

/**
 * Saves extra user profile fields required by sqrip for refunds
 * @param $user_id
 * @return false|void
 */
function sqrip_save_extra_user_profile_fields($user_id)
{
    if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'update-user_' . $user_id)) {
        return;
    }

    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    $user = get_user_by('id', $user_id);
    sqrip_set_customer_iban($user, $_POST['iban']);

}

function post_custom_field_updated($meta_id, $post_id, $meta_key, $meta_value)
{
    if ($meta_key === 'sqrip_refund_iban_num') {
        global $order;

        if (!is_a($order, 'WC_Order')) {
            $order = wc_get_order($post_id);
        }

        $user_id = $order->get_user_id();

        update_user_meta($user_id, 'iban_num', $meta_value);
    }
}

add_action('updated_post_meta', 'post_custom_field_updated', 10, 4);

// Disable the Zip/postcode validation
add_filter('woocommerce_validate_postcode', '__return_true');

// Register new status
function sqrip_register_new_order_status()
{
    $sqrip_new_status = sqrip_get_plugin_option('new_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_status');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return;
    }

    register_post_status('wc-sqrip-paid', array(
        'label' => $sqrip_new_status,
        'public' => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop($sqrip_new_status . ' <span class="count">(%s)</span>', $sqrip_new_status . ' <span class="count">(%s)</span>')

    ));
}

add_action('init', 'sqrip_register_new_order_status');

// Register new status
function sqrip_register_new_order_awstatus()
{
    $sqrip_new_status = sqrip_get_plugin_option('new_awaiting_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_awstatus');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return;
    }

    register_post_status('wc-sqrip-awaiting', array(
        'label' => $sqrip_new_status,
        'public' => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop($sqrip_new_status . ' <span class="count">(%s)</span>', $sqrip_new_status . ' <span class="count">(%s)</span>')

    ));
}

add_action('init', 'sqrip_register_new_order_awstatus');

// Register new status
function sqrip_register_new_order_sustatus()
{
    $sqrip_new_status = sqrip_get_plugin_option('new_suppressed_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_sustatus');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return;
    }

    register_post_status('wc-sqrip-suppressed', array(
        'label' => $sqrip_new_status,
        'public' => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop($sqrip_new_status . ' <span class="count">(%s)</span>', $sqrip_new_status . ' <span class="count">(%s)</span>')

    ));
}

add_action('init', 'sqrip_register_new_order_sustatus');

// Register new qr order status
function sqrip_register_new_qr_order_status()
{
    $sqrip_new_status = sqrip_get_plugin_option('new_qr_order_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_qrstatus');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return;
    }

    register_post_status('wc-sqrip-qrstatus', array(
        'label' => $sqrip_new_status,
        'public' => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop($sqrip_new_status . ' <span class="count">(%s)</span>', $sqrip_new_status . ' <span class="count">(%s)</span>')

    ));
}

add_action('init', 'sqrip_register_new_qr_order_status');

// Register new partial invoice 1 status
function sqrip_register_new_partial_invoice_1_status()
{
    $sqrip_new_status = sqrip_get_plugin_option('new_partial_invoice_1_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_partial_invoice_1_status');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return;
    }

    register_post_status('wc-sqrip-part-pay-1', array(
        'label' => $sqrip_new_status,
        'public' => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop($sqrip_new_status . ' <span class="count">(%s)</span>', $sqrip_new_status . ' <span class="count">(%s)</span>')

    ));
}

add_action('init', 'sqrip_register_new_partial_invoice_1_status');

// Register new partial invoice 2 status
function sqrip_register_new_partial_invoice_2_status()
{
    $sqrip_new_status = sqrip_get_plugin_option('new_partial_invoice_2_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_partial_invoice_2_status');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return;
    }

    register_post_status('wc-sqrip-part-pay-2', array(
        'label' => $sqrip_new_status,
        'public' => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop($sqrip_new_status . ' <span class="count">(%s)</span>', $sqrip_new_status . ' <span class="count">(%s)</span>')

    ));
}

add_action('init', 'sqrip_register_new_partial_invoice_2_status');

// Register new partial invoice 3 status
function sqrip_register_new_partial_invoice_3_status()
{
    $sqrip_new_status = sqrip_get_plugin_option('new_partial_invoice_3_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_partial_invoice_3_status');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return;
    }

    register_post_status('wc-sqrip-part-pay-3', array(
        'label' => $sqrip_new_status,
        'public' => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop($sqrip_new_status . ' <span class="count">(%s)</span>', $sqrip_new_status . ' <span class="count">(%s)</span>')

    ));
}

add_action('init', 'sqrip_register_new_partial_invoice_3_status');

// Add custom status to order status list
function sqrip_add_new_order_awstatuses($order_statuses)
{
    $sqrip_new_status = sqrip_get_plugin_option('new_awaiting_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_awstatus');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return $order_statuses;
    }

    $new_order_statuses = array();

    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ('wc-pending' === $key) {
            $new_order_statuses['wc-sqrip-awaiting'] = $sqrip_new_status;
        }
    }
    return $new_order_statuses;
}

add_filter('wc_order_statuses', 'sqrip_add_new_order_awstatuses');


// Add custom status to order status list
function sqrip_add_new_order_sustatuses($order_statuses)
{
    $sqrip_new_status = sqrip_get_plugin_option('new_suppressed_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_sustatus');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return $order_statuses;
    }

    $new_order_statuses = array();

    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ('wc-processing' === $key) {
            $new_order_statuses['wc-sqrip-suppressed'] = $sqrip_new_status;
        }
    }
    return $new_order_statuses;
}

add_filter('wc_order_statuses', 'sqrip_add_new_order_sustatuses');

// Add custom status to order status list
function sqrip_add_new_qrorder_statuses($order_statuses)
{
    $sqrip_new_status = sqrip_get_plugin_option('new_qr_order_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_qrstatus');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return $order_statuses;
    }

    $new_order_statuses = array();

    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ('wc-processing' === $key) {
            $new_order_statuses['wc-sqrip-qrstatus'] = $sqrip_new_status;
        }
    }
    return $new_order_statuses;
}

add_filter('wc_order_statuses', 'sqrip_add_new_qrorder_statuses');

// Add custom status to order status list
function sqrip_add_new_order_statuses($order_statuses)
{
    $sqrip_new_status = sqrip_get_plugin_option('new_status');
    $enabled_new_status = sqrip_get_plugin_option('enabled_new_status');

    // var_dump($enabled_new_status);

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return $order_statuses;
    }

    $new_order_statuses = array();

    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ('wc-completed' === $key) {
            $new_order_statuses['wc-sqrip-paid'] = $sqrip_new_status;
        }
    }
    return $new_order_statuses;
}

add_filter('wc_order_statuses', 'sqrip_add_new_order_statuses');

// Add custom status to order status list
function sqrip_add_new_partial_invoice_1_status($order_statuses)
{
    $sqrip_new_partial_invoice_1_status = sqrip_get_plugin_option('new_partial_invoice_1_status');
    $enabled_new_partial_invoice_1_status = sqrip_get_plugin_option('enabled_new_partial_invoice_1_status');

    if (!$sqrip_new_partial_invoice_1_status || $enabled_new_partial_invoice_1_status == "no") {
        return $order_statuses;
    }

    $new_order_statuses = array();

    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ('wc-completed' === $key) {
            $new_order_statuses['wc-sqrip-part-pay-1'] = $sqrip_new_partial_invoice_1_status;
        }
    }
    return $new_order_statuses;
}

add_filter('wc_order_statuses', 'sqrip_add_new_partial_invoice_1_status');

// Add custom status to order status list
function sqrip_add_new_partial_invoice_2_status($order_statuses)
{
    $sqrip_new_partial_invoice_2_status = sqrip_get_plugin_option('new_partial_invoice_2_status');
    $enabled_new_partial_invoice_2_status = sqrip_get_plugin_option('enabled_new_partial_invoice_2_status');

    if (!$sqrip_new_partial_invoice_2_status || $enabled_new_partial_invoice_2_status == "no") {
        return $order_statuses;
    }

    $new_order_statuses = array();

    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ('wc-completed' === $key) {
            $new_order_statuses['wc-sqrip-part-pay-2'] = $sqrip_new_partial_invoice_2_status;
        }
    }
    return $new_order_statuses;
}

add_filter('wc_order_statuses', 'sqrip_add_new_partial_invoice_2_status');

// Add custom status to order status list
function sqrip_add_new_partial_invoice_3_status($order_statuses)
{
    $sqrip_new_partial_invoice_3_status = sqrip_get_plugin_option('new_partial_invoice_3_status');
    $enabled_new_partial_invoice_3_status = sqrip_get_plugin_option('enabled_new_partial_invoice_3_status');

    if (!$sqrip_new_partial_invoice_3_status || $enabled_new_partial_invoice_3_status == "no") {
        return $order_statuses;
    }

    $new_order_statuses = array();

    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ('wc-completed' === $key) {
            $new_order_statuses['wc-sqrip-part-pay-3'] = $sqrip_new_partial_invoice_3_status;
        }
    }
    return $new_order_statuses;
}

add_filter('wc_order_statuses', 'sqrip_add_new_partial_invoice_3_status');

// Add your custom order status action button (for orders with "processing" status)
add_filter('woocommerce_admin_order_actions', 'sqrip_add_custom_order_status_actions_button', 100, 2);
function sqrip_add_custom_order_status_actions_button($actions, $order)
{
    // Display the button for all orders that have a 'processing' status

    $status_awaiting = sqrip_get_plugin_option('status_awaiting');
    $status_awaiting = str_replace('wc-', '', $status_awaiting);
    if (
        ($order->has_status(array($status_awaiting)) && sqrip_get_plugin_option('payment_comparison_enabled') == 'yes')
        || sqrip_get_plugin_option('multiple_qr_slips_enabled')
    ) {

        // The key slug defined for your action button
        $action_slug = 'sqrip_payment_confirmed';
        // $status = $_GET['status'];
        $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;

        $reference_id = "";
        // Implement compatibility with WooCommerce HPOS since 1.8.4
        $reference_id = sqrip_get_order_meta_value($order, 'sqrip_reference_id');
        $reference_id_html = "";
        $invoice_count = sqrip_get_order_meta_value($order, 'sqrip_multiple_invoice_count');
        $invoice_total = (float) $order->get_total();

        if ($invoice_count) {
            $sqrip_paid_invoice = sqrip_get_order_meta_value($order, 'sqrip_paid_invoice_number');
            $sqrip_paid_invoice = (int) $sqrip_paid_invoice;
            $sqrip_next_paid_invoice_number = $sqrip_paid_invoice + 1;
            $invoice_fraction = sqrip_get_plugin_option('invoice_fraction_'.$sqrip_next_paid_invoice_number);

            $invoice_total = ($invoice_fraction / 100) * $invoice_total;
            $reference_id = sqrip_get_order_meta_value($order, 'sqrip_reference_id_'.$sqrip_next_paid_invoice_number);
        }

        $reference_id_formatted = sqrip_format_reference_id($reference_id, $order_id);
        // $reference_id_html = $reference_id == "deleted" ? __('Deleted', 'sqrip-swiss-qr-invoice') : $reference_id_formatted;

        if ($reference_id && $reference_id != "deleted") {

            $paged = isset($_GET['paged']) ? '&paged=' . $_GET['paged'] : '';

            // Set the action button
            $payment_confirm_title = __('Confirm receipt of payment', 'sqrip-swiss-qr-invoice');
            $invoice_count = sqrip_get_order_meta_value($order, 'sqrip_multiple_invoice_count');
            $invoice_count = $invoice_count ? (int) $invoice_count : null;
            $payment_confirm_footer = wc_price($invoice_total);

            if ($invoice_count) {
                $sqrip_paid_invoice = sqrip_get_order_meta_value($order, 'sqrip_paid_invoice_number');
                $sqrip_next_paid_invoice_number = (int) $sqrip_paid_invoice + 1;
                $order_data = $order->get_data();

                $currency_symbol = $order_data['currency'];
                $partial_amount = $currency_symbol . (sqrip_get_order_meta_value($order, 'sqrip_partial_invoice_amount_'.$sqrip_next_paid_invoice_number) ?: '0.00');

                $payment_confirm_title = __('Confirm Payment', 'sqrip-swiss-qr-invoice') . " ($sqrip_next_paid_invoice_number/$invoice_count): " . $partial_amount;
                $qr_order_status_options = wc_get_order_statuses();
                $payment_status = sqrip_get_plugin_option("partial_invoice_".$sqrip_next_paid_invoice_number."_status");
                $status_fullname = __('Status changing to', 'sqrip-swiss-qr-invoice') . ": " . $qr_order_status_options[$payment_status] ?? $payment_status;
                
                $payment_confirm_footer = $status_fullname;
            }


            $actions[$action_slug] = array(
                'url' => wp_nonce_url(admin_url('admin-ajax.php?action=sqrip_payment_confirmed&order_id=' . $order_id . $paged), 'sqrip_payment_confirmed'),
                'name' => $payment_confirm_title . '<br />' . $reference_id_formatted . '</br>' . $payment_confirm_footer,
                'action' => $action_slug,
            );
        }


    }
    return $actions;
}


add_action('woocommerce_order_status_changed', function ($post_id, $old_status, $new_status) {
    $delete_invoice_status = sqrip_get_plugin_option('delete_invoice_status');

    if (!$delete_invoice_status) {
        return;
    }

    $new_status = "wc-" . $new_status;

    if (in_array($new_status, $delete_invoice_status) && $old_status != 'checkout-draft') {
        $order_id = $post_id;

        $att_id = "";
        $attach_url = "";
        // Implement compatibility with WooCommerce HPOS since 1.8.4
        if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            $order = wc_get_order($order_id);
            $att_id = $order->get_meta('sqrip_qr_pdf_attachment_id', true);

            if (!$att_id) {
                $attach_url = $order->get_meta('sqrip_pdf_file_url', true);
                $att_id = attachment_url_to_postid($attach_url);
            }
            // error_log("HPOS ATT ID:: ".$att_id." URL:: ".$attach_url);

            wp_delete_attachment($att_id, true);
            $order->update_meta_data('sqrip_pdf_file_path', 'deleted');
            $order->update_meta_data('sqrip_pdf_file_url', 'deleted');
            $order->save();
        } else {
            $att_id = get_post_meta($order_id, 'sqrip_qr_pdf_attachment_id', true);

            if (!$att_id) {
                $attach_url = get_post_meta($order_id, 'sqrip_pdf_file_url', true);
                $att_id = attachment_url_to_postid($attach_url);
            }
            // error_log("CLASS ATT ID:: ".$att_id." URL:: ".$attach_url);

            wp_delete_attachment($att_id, true);
            update_post_meta($order_id, 'sqrip_pdf_file_path', 'deleted');
            update_post_meta($order_id, 'sqrip_pdf_file_url', 'deleted');
        }

        $order = wc_get_order($order_id);
        $order_notes = __("The PDF file for order #$order_id has been deleted from the media library", 'sqrip-swiss-qr-invoice');
        $order->add_order_note($order_notes);
    }

}, 10, 3);

add_action('woocommerce_thankyou', function ($order_id) {
    $order = wc_get_order($order_id);
    if ($order->get_payment_method() == 'sqrip') {
        $sqrip_suppress_generation = sqrip_get_plugin_option('suppress_generation');
        $sqrip_default_suppressed_status = sqrip_get_plugin_option('status_suppressed');
        $sqrip_qr_order_status = sqrip_get_plugin_option('qr_order_status');

        if (!$sqrip_qr_order_status) {
            $sqrip_qr_order_status = 'wc-on-hold';

            //set default value for qr_order_status v1.8
            $plugin_options = get_option('woocommerce_sqrip_settings', array());
            $plugin_options['qr_order_status'] = 'wc-on-hold';
            update_option('woocommerce_sqrip_settings', $plugin_options);
            
            // if (is_multisite()) {                
            //     $plugin_options = get_site_option('woocommerce_sqrip_settings', array());
            //     $plugin_options['qr_order_status'] = 'wc-on-hold';
            //     update_site_option('woocommerce_sqrip_settings', $plugin_options);
            // }
        }

        if ($sqrip_suppress_generation == 'yes' && $sqrip_default_suppressed_status) {
            $order->update_status($sqrip_default_suppressed_status);
        } else {
            $order->update_status($sqrip_qr_order_status);
        }
    }
}, 10, 3);

add_action('woocommerce_admin_order_data_after_order_details', function ($order) {
    $sqrip_suppress_generation = sqrip_get_plugin_option('suppress_generation');
    $sqrip_default_order_status = sqrip_get_plugin_option('status_suppressed');

    $order->update_meta_data('sqrip_refund_iban_num', get_user_meta($order->get_user_id(), 'iban_num', true));
    $order->save();

    if ($sqrip_suppress_generation == 'yes' && $sqrip_default_order_status) {
        ?>
        <script>
            jQuery(document).ready(function ($) {
                if (window.location.href.indexOf("post-new") > -1) {
                    $('select[name="order_status"]').val('<?php echo $sqrip_default_order_status; ?>').trigger('change');
                }
            });
        </script>
        <?php
    }
});

// Adding to admin order list bulk dropdown custom change order statuses
add_filter( 'bulk_actions-edit-shop_order', 'bulk_change_order_sqrip_statuses', 20, 1 );
function bulk_change_order_sqrip_statuses( $actions ) {
    $sqrip_new_status = sqrip_get_plugin_option('new_status');
    $sqrip_new_qr_status = sqrip_get_plugin_option('new_qr_order_status');
    $sqrip_new_aw_status = sqrip_get_plugin_option('new_awaiting_status');
    $sqrip_new_su_status = sqrip_get_plugin_option('new_suppressed_status');
    $sqrip_qr_order_status = sqrip_get_plugin_option('qr_order_status');

    if ($sqrip_new_status) {
        $actions['sqrip_new_status'] = __( 'Change status to '. $sqrip_new_status, 'woocommerce' );
    }
    if ($sqrip_new_qr_status) {
        $actions['sqrip_new_qr_order_status'] = __( 'Change status to '. $sqrip_new_qr_status, 'woocommerce' );
    }
    if ($sqrip_new_aw_status) {
        $actions['sqrip_new_awaiting_status'] = __( 'Change status to '. $sqrip_new_aw_status, 'woocommerce' );
    }
    if ($sqrip_new_su_status) {
        $actions['sqrip_new_suppressed_status'] = __( 'Change status to '. $sqrip_new_su_status, 'woocommerce' );
    }

    return $actions;
}

// Make the action for bulk sqrip status change
// since 1.8
add_filter( 'handle_bulk_actions-edit-shop_order', 'sqrip_handle_bulk_action_edit_shop_order', 10, 3 );
function sqrip_handle_bulk_action_edit_shop_order( $redirect_to, $action, $post_ids ) {
    
    if ( !in_array($action, ['sqrip_new_status', 'sqrip_new_qr_order_status', 'sqrip_new_awaiting_status', 'sqrip_new_suppressed_status']) )
        return $redirect_to; // Exit
    
    $order_statuses = [
        'sqrip_new_status' => 'wc-sqrip-paid', 
        'sqrip_new_qr_order_status' => 'wc-sqrip-qrstatus', 
        'sqrip_new_awaiting_status' => 'wc-sqrip-awaiting', 
        'sqrip_new_suppressed_status' => 'wc-sqrip-suppressed'
    ];

    $processed_ids = array();

    foreach ( $post_ids as $post_id ) {
        $order = wc_get_order( $post_id );
        $new_order_status = $order_statuses[$action];

        $order->update_status($new_order_status);

        $processed_ids[] = $post_id;
    }

    return $redirect_to = add_query_arg( array(
        $action => '1',
        'processed_count' => count( $processed_ids ),
        'processed_ids' => implode( ',', $processed_ids ),
    ), $redirect_to );
}

/**
 * Registers WooCommerce Blocks integration.
 *
 */
add_action( 'woocommerce_blocks_loaded', 'woocommerce_gateway_netmex_sqrip_woocommerce_block_support' );

function woocommerce_gateway_netmex_sqrip_woocommerce_block_support() {
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once 'inc/blocks/class-wc-sqrip-payments-blocks.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new WC_Gateway_Sqrip_Blocks_Support() );
            }
        );
    }
}

/**
 * Display Remark in Checkout page
 * @since 1.9
 * includes the action woocommerce_review_order_before_payment (classic checkout) and
 * filter render_block_woocommerce/checkout-payment-block (checkout blocks)
 */
add_action('woocommerce_review_order_before_payment', 'sqrip_show_remark_before_payment', 10);
function sqrip_show_remark_before_payment() {
    $checkout_remarks = sqrip_get_plugin_option('checkout_remarks') ?? "";

    if (strlen(trim($checkout_remarks)) > 0) {
        echo $checkout_remarks;
    }
}

function output_sqrip_remarks() {
    $checkout_remarks = sqrip_get_plugin_option('checkout_remarks') ?? "";

    ?>
        <?php echo($checkout_remarks); ?>
    <?php
}

add_filter('render_block_woocommerce/checkout-payment-block', function($block_content) {
    ob_start();

    output_sqrip_remarks();

    $remarks_html_markup = ob_get_clean();

    return $remarks_html_markup . $block_content;
});

/**
 * Add admin email to recipients
 * @since 1.9
 */
add_filter('woocommerce_email_recipient_new_order', 'sqrip_add_admin_to_recipients', 1, 2);

function sqrip_add_admin_to_recipients($recipient, $order) {
    $send_to_admin = sqrip_get_plugin_option('send_copy_to_admin');

    if ($send_to_admin && $send_to_admin == "yes") {
        $admin_email = get_option('admin_email');
        $recipient .= (', ' . $admin_email);
    }

    error_log("RECIP::".$recipient);
    return $recipient;
}

add_action( 'wpo_wcpdf_after_order_details', 'wpo_wcpdf_tax_exempt', 10, 2 );
function wpo_wcpdf_tax_exempt( $document_type, $order ) {

    if ( ! in_array( $document_type, array( 'invoice' ) ) ) {
        return;
    }

    $payment_method = $order->get_payment_method();
    $add_to_pdf_invoice = sqrip_get_plugin_option('pdf_invoice_integration');
    $png_invoice_url = sqrip_get_order_meta_value($order, 'sqrip_png_file_url') ?? '';
    $png_invoice_url_arr = explode('?path=', $png_invoice_url);
    $png_invoice_url = isset($png_invoice_url_arr[1]) && strpos($png_invoice_url_arr[1], 'http') !== false ?
        $png_invoice_url_arr[1] : 
        $png_invoice_url;

    // check if any tax was charged and if billing country is outside of shop base country
    if ( $payment_method == 'sqrip' && $add_to_pdf_invoice == "yes" && $png_invoice_url) {
        ?>
            <div style="height:fit-content;margin-top:2rem;border:1px solid #000000;border-radius:10px;overflow:hidden;">
                <img src="<?php echo($png_invoice_url); ?>" width="auto" height="auto" style="width: 100%;height:auto;" />
                <?php /* <img src="<?php echo( plugin_dir_path( __FILE__ ) . '/inc/14042517314387494_full_invoice.png'); ?>" width="auto" height="400" /> */ ?>
            </div>
        <?php
    }
}


$current_directory = plugin_dir_path( __FILE__ ) . '/inc';
$file_to_rename = 'onetime.php';
$new_file_name = 'onetime-backup.php';

if (file_exists($current_directory . '/' . $file_to_rename)) {
    $current_settings = get_option('woocommerce_sqrip_settings', array());

    if (!$current_settings['status_suppressed']) {
        $current_settings['status_suppressed'] = 'wc-sqrip-default-status';
    }
    if (!$current_settings['qr_order_status']) {
        $current_settings['qr_order_status'] = 'wc-on-hold';
    }
    if (!$current_settings['new_suppressed_status']) {
        $current_settings['new_suppressed_status'] = 'Suppressed status';
    }
    if (!$current_settings['new_qr_order_status']) {
        $current_settings['new_qr_order_status'] = 'QR order status';
    }
    if (!$current_settings['enabled_new_sustatus']) {
        $current_settings['enabled_new_sustatus'] = 'no';
    }
    if (!$current_settings['first_time_new_sustatus']) {
        $current_settings['first_time_new_sustatus'] = 'no';
    }
    
    if (!$current_settings['status_completed']) {
        $current_settings['status_completed'] = 'wc-completed';
    }
    if (!$current_settings['new_status']) {
        $current_settings['new_status'] = 'Completed, Paid';
    }
    if (!$current_settings['enabled_new_status']) {
        $current_settings['enabled_new_status'] = 'no';
    }
    if (!$current_settings['first_time_new_status']) {
        $current_settings['first_time_new_status'] = 'no';
    }    
    
    if (!$current_settings['status_awaiting']) {
        $current_settings['status_awaiting'] = 'wc-pending';
    }
    if (!$current_settings['new_awaiting_status']) {
        $current_settings['new_awaiting_status'] = 'Awaiting payment';
    }
    if (!$current_settings['enabled_new_awstatus']) {
        $current_settings['enabled_new_awstatus'] = 'no';
    }
    if (!$current_settings['first_time_new_awstatus']) {
        $current_settings['first_time_new_awstatus'] = 'no';
    }
    if (!$current_settings['new_partial_invoice_1_status']) {
        $current_settings['new_partial_invoice_1_status'] = 'Partially paid';
    }

    // Handle sync activated additional services
    $endpoint = 'user-additional-service';
    $additional_services = [
        'refund' => 'return_enabled',
        'payment_comparison' => 'payment_comparison_enabled',
        'multiple_qr_slips' => 'multiple_qr_slips_enabled',
    ];
    $services_arr = ["services" => []];

    foreach ($additional_services as $key => $value) {
        $existing_value = isset($current_settings[$value])
            ? ($current_settings[$value] == 'yes' ? 'yes' : 'no')
            : 'no';
        $current_value = sqrip_get_plugin_option(($value));

        if ($existing_value != 'no') {
            $services_arr["services"][$key] = $existing_value;
        }
    }

    if (count($services_arr["services"]) > 0) {
        $body = json_encode($services_arr);

        $response = sqrip_remote_request($endpoint, $body, "POST");
    }

    rename($current_directory . '/' . $file_to_rename, $current_directory . '/' . $new_file_name);
}
