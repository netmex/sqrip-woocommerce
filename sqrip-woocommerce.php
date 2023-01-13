<?php

/**
 * Plugin Name:             sqrip.ch
 * Plugin URI:              https://sqrip.ch/
 * Description:             sqrip – A comprehensive and clever WooCommerce finance tool for the most widely used payment method in Switzerland: the bank transfers. 
 * Version:                 1.6
 * Author:                  netmex digital gmbh
 * Author URI:              https://sqrip.ch/
 */

defined( 'ABSPATH' ) || exit;

// Make sure WooCommerce is active
if ( !in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) ) 
{
    return;
}

define( 'SQRIP_ENDPOINT', 'https://api.sqrip.ch/api/' );
define( 'SQRIP_PLUGIN_BASENAME', plugin_basename(__FILE__) );

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/sqrip-ajax.php';

/**
 * Add plugin Settings link
 *
 * @since 1.0
 */

add_filter('plugin_action_links_' . SQRIP_PLUGIN_BASENAME, function($links)
{
    $action_links = array(
        'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sqrip' ) . '" aria-label="' . esc_attr__( 'View sqrip settings', 'sqrip-swiss-qr-invoice' ) . '">' . esc_html__( 'Settings', 'sqrip-swiss-qr-invoice' ) . '</a>',
    );

    return array_merge( $action_links, $links );
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

    require_once __DIR__ . '/inc/class-sqrip-media-cleaner.php';
}



/**
 *  Add admin notices
 *  
 *  @since 1.0
 */
add_action('admin_notices', 'sqrip_add_admin_notice');

function sqrip_add_admin_notice()
{
    if ( function_exists('get_woocommerce_currency') ) {
        $currency = get_woocommerce_currency();
        $currency_arr = array('EUR', 'CHF');

        if ( !in_array($currency, $currency_arr) ) {
            $class = 'notice notice-error is-dismissible';
            $message = __( 'The sqrip plugin only supports EUR and CHF currencies!', 'sqrip-swiss-qr-invoice' );

            printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
        }
    }

    $allowed_types = get_allowed_mime_types();

    if ( !array_key_exists('pdf', $allowed_types) ) {
        $class = 'notice notice-error is-dismissible';
        $message = __( 'Your website is currently unable to upload a PDF.', 'sqrip-swiss-qr-invoice' );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    if ( false !== ( $msg = get_transient( "sqrip_admin_action_errors" ) ) && $msg) {

        $class = 'notice notice-error is-dismissible';

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $msg );
       
        delete_transient( "sqrip_admin_action_errors" );
    }

    $allow_url_fopen = ini_get('allow_url_fopen');

    if (!$allow_url_fopen) {
        $class = 'notice notice-error is-dismissible';
        $message = __( 'Damit sqrip die QR-Rechnungen in der Mediathek speichern kann muss "allow_url_open" in der php.ini oder htaccess-Datei zugelassen werden.' );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

}

/**
 * Adding scripts for settings sqrip page in admin
 *
 * @since 1.0
 */

add_action( 'admin_enqueue_scripts', function (){

    wp_enqueue_style('sqrip-admin', plugins_url( 'css/sqrip-admin.css', __FILE__ ), '', '1.1.1');

    if (isset($_GET['section']) && $_GET['section'] == "sqrip") {
        wp_enqueue_script('sqrip-admin', plugins_url( 'js/sqrip-admin.js', __FILE__ ), array('jquery'), '1.5.5', true);

        $sqrip_new_status  = sqrip_get_plugin_option('new_status');

        wp_localize_script( 'sqrip-admin', 'sqrip',
            array( 
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'txt_check_connection' => __( 'Connection test', 'sqrip-swiss-qr-invoice' ),
                'txt_validate_iban' => __( 'Check', 'sqrip-swiss-qr-invoice' ),
                'txt_create' => !$sqrip_new_status ? __( 'Create', 'sqrip-swiss-qr-invoice' ) : __( 'Update', 'sqrip-swiss-qr-invoice' ),
                'txt_send_test_email' => sprintf( 
                    __( 'Send test to %s', 'sqrip-swiss-qr-invoice' ), 
                    esc_html( get_option('admin_email') ) 
                )
            )
        );
    }

    global $post_type;

    if ( $post_type == 'shop_order' ) {
        wp_enqueue_script('sqrip-order', plugins_url( 'js/sqrip-order.js', __FILE__ ), array('jquery'), '1.1.1', true);

        wp_localize_script( 'sqrip-order', 'sqrip',
            array( 
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'ajax_refund_paid_nonce' => wp_create_nonce( 'sqrip-mark-refund-paid' ),
                'ajax_refund_unpaid_nonce' => wp_create_nonce( 'sqrip-mark-refund-unpaid' ),
                'status_completed' => sqrip_get_plugin_option('status_completed'),
            )
        );
    }

    
});

/**
 * Adding scripts for FE
 *
 * @since 1.0
 */

add_action( 'wp_enqueue_scripts', 'sqrip_enqueue_scripts' );

function sqrip_enqueue_scripts() 
{
    wp_enqueue_style( 'sqrip', plugins_url( 'css/sqrip-order.css', __FILE__ ), false);
    
    wp_enqueue_script( 'sqrip', plugins_url( 'js/sqrip-fe.js', __FILE__ ), array('jquery'), '1.0.3', true);

    wp_localize_script( 'sqrip', 'sqrip',
        array( 
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'ajax_nonce' => wp_create_nonce( 'sqrip-generate-new-qrcode' )
        )
    );
}



/**
 *  Adding Meta container admin shop_order pages
 *  
 *  @since 1.0
 */

add_action('add_meta_boxes', 'sqrip_add_meta_boxes');

if (!function_exists('sqrip_add_meta_boxes')) {
    function sqrip_add_meta_boxes()
    {
        add_meta_box('sqrip_detail_fields', __('sqrip Payment', 'sqrip-swiss-qr-invoice'), 'sqrip_add_fields_for_order_details', 'shop_order', 'side', 'core');
    }
}

/**
 *  Adding Meta field in the meta container admin shop_order pages to dislay sqrip payment details
 *  @since 1.0
 */

if (!function_exists('sqrip_add_fields_for_order_details')) {
    function sqrip_add_fields_for_order_details()
    {
        global $post;
        $order_id = $post->ID;
        $reference_id = get_post_meta($post->ID, 'sqrip_reference_id', true);
        $pdf_file = get_post_meta($post->ID, 'sqrip_pdf_file_url', true);
        
        // check for legacy pdf meta file
        if(!$pdf_file) {
            $pdf_file = get_post_meta($post->ID, 'sqrip_pdf_file', true);
        }
        
        if ($reference_id || $pdf_file) {
            echo '<ul class="sqrip-payment">';

            echo $reference_id ? '<li><b>'.__('Reference number','sqrip-swiss-qr-invoice').' :</b> '.esc_html($reference_id).'</li>' : '';

            echo $pdf_file ? '<li><b>'.__( 'QR-Code PDF', 'sqrip-swiss-qr-invoice' ).' :</b> <a target="_blank" href="'.esc_url($pdf_file).'"><span class="dashicons dashicons-media-document"></span></a></li>' : '';

            echo '<li><button class="button button-secondary sqrip-re-generate-qrcode">'.__( 'Renew QR Invoice', 'sqrip-swiss-qr-invoice' ).'</button></li>';

            $status_awaiting = sqrip_get_plugin_option('status_awaiting');

            $order = wc_get_order( $order_id );
            $order_status = 'wc-'.$order->get_status();

            if ($status_awaiting == $order_status) {
                echo '<li><button class="button button-primary sqrip-payment-confirmed">'.__( 'Payment confirmed', 'sqrip-swiss-qr-invoice' ).'</button></li>';

            }

            echo '</ul>';

        } else {

            echo '<p>'.__( 'Payment is not made with sqrip.', 'sqrip-swiss-qr-invoice' ).'</p>';

            echo '<button class="button button-secondary sqrip-initiate-payment">'.__( 'Initiate sqrip payment now', 'sqrip-swiss-qr-invoice' ).'</button>';
        }
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
    if ( empty($order) || ! isset($email->id) || !method_exists($order,'get_payment_method') ) {
        return;
    }

    $payment_method = $order->get_payment_method();

    // Integration By default is attachment.
    $integration_email = sqrip_get_plugin_option('integration_email');
    $email_attached = sqrip_get_plugin_option('email_attached');

    $array_in = array('both', 'body');

    if ( $email->id === $email_attached && $payment_method === 'sqrip' && in_array($integration_email, $array_in) ) {
        $order_id = $order->id;
        $png_file = get_post_meta($order_id, 'sqrip_png_file_url', true);

        echo $png_file ? '<div class="sqrip-qrcode-png"><p>' . esc_html__( 'Use the QR invoice below to pay the outstanding balance.' , 'sqrip-swiss-qr-invoice') . '</p><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip-swiss-qr-invoice').'" width="200"/></div>' : '';
    }
}

/**
 *  sqrip QR code file attach in email
 *  
 *  @since 1.0
 */

add_filter('woocommerce_email_attachments', 'sqrip_attach_qrcode_pdf_to_email', 99, 3);

function sqrip_attach_qrcode_pdf_to_email($attachments, $email_id, $order)
{
    if (empty($order) || ! isset( $email_id ) || !method_exists($order,'get_payment_method')) {
        return $attachments;
    }

    $payment_method = $order->get_payment_method();

    $plugin_options = get_option('woocommerce_sqrip_settings', array());

    // $integration_email = array_key_exists('integration_email', $plugin_options) ? $plugin_options['integration_email'] : '';

    // Integration By default is attachment.
    $integration_email = 'attachment';
    $email_attached = sqrip_get_plugin_option('email_attached');

    $array_in = array('both', 'attachment');
    
    if ( $email_id === $email_attached && $payment_method === 'sqrip' && in_array($integration_email, $array_in) ) {
        $order_id = $order->id;

        $pdf_file_path = get_post_meta($order_id, 'sqrip_pdf_file_path', true);

        // WARNING: attachments must be local file paths and not URLs
        if ($pdf_file_path) {
            $attachments[] = $pdf_file_path;
        }
    }

    return $attachments;
}

/**
 *  Insert sqrip QR code after order table
 *  
 *  @since 1.5.3
 */

add_action('woocommerce_order_details_after_order_table', 'sqrip_qr_action_order_details_after_order_table', 10, 1);

function sqrip_qr_action_order_details_after_order_table($order)
{
    if ( !method_exists($order,'get_payment_method') ) {
        return;
    }

    $payment_method = $order->get_payment_method();

    if ($payment_method === 'sqrip') {
        $order_id = $order->get_id(); 

        $plugin_options = get_option('woocommerce_sqrip_settings', array());

        $integration_order = array_key_exists('integration_order', $plugin_options) ? $plugin_options['integration_order'] : '';

        // $png_file = get_post_meta($order_id, 'sqrip_png_file_url', true);
        $pdf_file = get_post_meta($order_id, 'sqrip_pdf_file_url', true);

        echo '<div class="sqrip-order-details">';

        if ( $integration_order == "yes" && $pdf_file ) {
            /**
             *  Insert sqrip QR code PNG after customer details
             * 
             *  @deprecated
             *  @since 1.1.1
             */
            // echo '<div class="sqrip-qrcode-png"><p>' . __( 'Use the QR invoice below to pay the outstanding balance.' , 'sqrip-swiss-qr-invoice') . '</p><a href="' . esc_url($png_file) . '" target="_blank"><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip-swiss-qr-invoice').'" width="300" /></a></div>';

            // Insert download button PDF
            echo '<div class="sqrip-qrcode-pdf"><p>' . __( 'Use the QR invoice below to pay the outstanding balance.' , 'sqrip-swiss-qr-invoice') . '</p><a class="button button-sqrip-invoice" href="' . esc_url($pdf_file) . '" >'. __('Download Invoice','sqrip-swiss-qr-invoice').' <i class="dashicons dashicons-pdf"></i></a></div>';
        }

        /**
         *  Insert Generate New QR code PNG after customer details
         * 
         *  @deprecated
         *  @since 1.5.6
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
 *  @since 1.0.3
 */

add_filter( 'wp_insert_post_data' , function ( $data , $postarr, $unsanitized_postarr ) {
    
    if ( 
        'shop_order' === $data['post_type'] && 
        ( 
            isset($postarr['_sqrip_regenerate_qrcode']) ||
            isset($postarr['_sqrip_initiate_payment']) 
        )
    )
    {
        $order      = wc_get_order($postarr['ID']);
        $order_data = $order->get_data(); // order data

        ## BILLING INFORMATION:
        $order_billing_first_name   = $postarr['_billing_first_name'];
        $order_billing_last_name    = $postarr['_billing_last_name'];
        $order_billing_address      = $postarr['_billing_address_1'];
        $order_billing_address      .= $postarr['_billing_address_2'] ? ', '.$postarr['_billing_address_2'] : "";
        $order_billing_city         = $postarr['_billing_city'];
        $order_billing_postcode     = $postarr['_billing_postcode'];
        $order_billing_country      = $postarr['_billing_country'];
                    
        $currency_symbol    =   $order_data['currency'];
        $amount             =   floatval($order_data['total']);
   
        $body = sqrip_prepare_qr_code_request_body($currency_symbol, $amount, $postarr['ID']);

        $body["payable_by"] = sqrip_get_billing_address_from_order($order);

        $address = sqrip_get_plugin_option('address');

        $body['payable_to'] = sqrip_get_payable_to_address($address);

        $args = sqrip_prepare_remote_args($body, 'POST');

        $endpoint = 'code';
        $response = wp_remote_post(SQRIP_ENDPOINT.$endpoint, $args);

        $response_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($response_body);

        if ( is_wp_error($response) ) {

            $order->add_order_note( 
                sprintf( 
                    __( 'Error: %s', 'sqrip-swiss-qr-invoice' ), 
                    esc_html( $response_body->message ) 
                ) 
            );

        } else  {

            if ( isset($postarr['_sqrip_regenerate_qrcode']) ) {
                $order_notes = __('sqrip payment QR code was successfully regenerated', 'sqrip-swiss-qr-invoice');
                $error_title = __( 'Renew QR Invoice error:', 'sqrip-swiss-qr-invoice' );
            }

            elseif ( isset($postarr['_sqrip_initiate_payment']) ) {
                $error_title = __( 'Initiate sqrip payment error:', 'sqrip-swiss-qr-invoice' );
                $order_notes = __('sqrip payment initiation was successful', 'sqrip-swiss-qr-invoice');
                $order->set_payment_method('sqrip');
            }

            if (isset($response_body->reference)) {

                $pdf_file_old = get_post_meta($postarr['ID'], 'sqrip_pdf_file_url', true);

                if ( $pdf_file_old && isset($postarr['_sqrip_regenerate_qrcode']) ) {

                    $pdf_file_old_id = attachment_url_to_postid($pdf_file_old);

                    if ($pdf_file_old_id) {

                        require_once( ABSPATH . 'wp-settings.php' );

                        wp_delete_attachment($pdf_file_old_id, true);

                    }

                }

                $sqrip_pdf       =    $response_body->pdf_file;
                // $sqrip_png       =    $response_body->png_file;
                $sqrip_reference =    $response_body->reference;

                // TODO: replace with attachment ID and store this in meta instead of actual file
                $sqrip_class_payment = new WC_Sqrip_Payment_Gateway;

                $sqrip_qr_pdf_attachment_id = $sqrip_class_payment->file_upload($sqrip_pdf, '.pdf');
                // $sqrip_qr_png_attachment_id = $sqrip_class_payment->file_upload($sqrip_png, '.png');

                $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
                $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);

                // $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
                // $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);

                $order->add_order_note( $order_notes );

                $order->update_meta_data('sqrip_reference_id', $sqrip_reference);

                $order->update_meta_data('sqrip_pdf_file_url', $sqrip_qr_pdf_url);
                $order->update_meta_data('sqrip_pdf_file_path', $sqrip_qr_pdf_path);

                // $order->update_meta_data('sqrip_png_file_url', $sqrip_qr_png_url);
                // $order->update_meta_data('sqrip_png_file_path', $sqrip_qr_png_path);

                $order->save();

            } else {

                $errors_output = "";
                
                if (isset($response_body->errors)) {
                    $errors_output = json_encode($response_body->errors, JSON_PRETTY_PRINT); 
                    // $error_goto = 'Please add your address correctly at <a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sqrip' ) . '" aria-label="' . esc_attr__( 'sqrip settings', 'sqrip-swiss-qr-invoice' ) . '">' . esc_html__( 'sqrip settings', 'sqrip-swiss-qr-invoice' ) . '</a>';

                } 

                set_transient('sqrip_admin_action_errors', sprintf( 
                    __( '<b>%s</b> %s</br>%s', 'sqrip-swiss-qr-invoice' ), 
                    $error_title,
                    esc_html( $response_body->message ),
                    esc_html( $errors_output )
                ), 60);

                $order->add_order_note( 
                    sprintf( 
                        __( '%s %s <p>%s</p>', 'sqrip-swiss-qr-invoice' ), 
                        $error_title,
                        esc_html( $response_body->message ),
                        esc_html( $errors_output )
                    )
                );
            }
        }
    }

    return $data;

}, 99, 3);

add_action('woocommerce_after_order_refund_item_name', "sqrip_display_refund_qr_code", 10,1);

/**
 * Displays UI for marking a sqrip refund as completed within the WooCommerce UI
 * @param $refund WC_Order_Refund
 */
function sqrip_display_refund_qr_code($refund) {

	$refund_qr_attachment_id = $refund->get_meta('sqrip_refund_qr_attachment_id');

	if (!$refund_qr_attachment_id) {
		return;
	}

    $refund_qr_pdf_url = wp_get_attachment_url($refund_qr_attachment_id);
	$refund_qr_pdf_path = get_attached_file($refund_qr_attachment_id);
    $refund_id = $refund->get_id();
    $title = __("Show QR Code",'sqrip-swiss-qr-invoice');
    $hidden_title = __("Hide QR Code",'sqrip-swiss-qr-invoice');

    $paid_title = __("Mark as paid", 'sqrip-swiss-qr-invoice');
    $unpaid_title = __("Mark as unpaid", 'sqrip-swiss-qr-invoice');

	$paid_status = __("paid on", 'sqrip-swiss-qr-invoice');
	$unpaid_status = __("unpaid", 'sqrip-swiss-qr-invoice');

    $paid = $refund->get_meta('sqrip_refund_paid');
    $status = $paid ? $paid_status." $paid" : $unpaid_status;

    $hide_paid_action_css = !$paid ?: 'display: none';
	$hide_unpaid_action_css = $paid ?: 'display: none';

    echo "<span class='woocommerce_sqrip_refund_status' data-paid='$paid_status' data-unpaid='$unpaid_status'>[$status]</span>";
    echo "<br/>";
    echo "<a class='woocommerce_sqrip_toggle_qr' href='$refund_qr_pdf_url' title='$title' target='_blank' data-title-hide='$hidden_title' data-title='$title' style='margin-right: 10px; $hide_paid_action_css'>$title</a>";
    echo "<a class='woocommerce_sqrip_refund_paid' href='#' title='$paid_title' style='margin-right: 10px; color: green; $hide_paid_action_css' data-refund='$refund_id'>$paid_title</a>";
	echo "<a class='woocommerce_sqrip_refund_unpaid' href='#' title='$unpaid_title' style='color: darkred; $hide_unpaid_action_css' data-refund='$refund_id'>$unpaid_title</a>";
    echo "<div class='woocommerce_sqrip_qr_wrapper' style='display:none; margin: 5px;'>";
    echo    "<img src='$refund_qr_pdf_url' width='300' height='300'/>";
    echo "</div>";

}

add_action( 'woocommerce_order_refunded', 'action_woocommerce_order_refunded', 10, 2 );

/**
 * Called when an order is refunded using WooCommerce
 * @param $order_id int
 * @param $refund_id int
 */
function action_woocommerce_order_refunded( $order_id, $refund_id ) {

	$order = wc_get_order($order_id);

	/**
	 * @var WC_Order_Refund
	 */
    $refund = wc_get_order($refund_id);

	if ( !method_exists($order,'get_payment_method') ) {
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


add_action( 'show_user_profile', 'sqrip_extra_user_profile_fields' );
add_action( 'edit_user_profile', 'sqrip_extra_user_profile_fields' );

/**
 * Displays extra field in user profile page to set iban for refund
 * @param $user
 * @return void
 */
function sqrip_extra_user_profile_fields( $user ) {

    $sqrip_return_enabled = sqrip_get_plugin_option('return_enabled');

    if($sqrip_return_enabled) {
        ?>
        <h3><?php _e("Refunds with sqrip", "sqrip"); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="iban"><?php _e("IBAN"); ?></label></th>
                <td>
                    <input type="text" name="iban" id="iban" value="<?php echo esc_attr( sqrip_get_customer_iban($user)); ?>" class="regular-text" /><br />
                    <span class="description"><?php _e("This iban will be used to generate a sqrip qr code in case of a refund."); ?></span>
                </td>
            </tr>
        </table>
        <?php
    }
}

add_action( 'personal_options_update', 'sqrip_save_extra_user_profile_fields' );
add_action( 'edit_user_profile_update', 'sqrip_save_extra_user_profile_fields' );

/**
 * Saves extra user profile fields required by sqrip for refunds
 * @param $user_id
 * @return false|void
 */
function sqrip_save_extra_user_profile_fields( $user_id ) {
    if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'update-user_' . $user_id ) ) {
        return;
    }

    if ( !current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }

    $user = get_user_by('id', $user_id);
    sqrip_set_customer_iban($user, $_POST['iban']);

}

// Disable the Zip/postcode validation 
add_filter( 'woocommerce_validate_postcode' , '__return_true' );

// Register new status
function sqrip_register_new_order_status() {
    $sqrip_new_status  = sqrip_get_plugin_option('new_status');
    $enabled_new_status  = sqrip_get_plugin_option('enabled_new_status');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return;
    }

    register_post_status( 'wc-sqrip-paid', array(
        'label'                     => $sqrip_new_status,
        'public'                    => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => false,
        'label_count'               => _n_noop( $sqrip_new_status.' <span class="count">(%s)</span>', $sqrip_new_status.' <span class="count">(%s)</span>' )

    ) );
}

add_action( 'init', 'sqrip_register_new_order_status' );

// Add custom status to order status list
function sqrip_add_new_order_statuses( $order_statuses ) {
    $sqrip_new_status  = sqrip_get_plugin_option('new_status');
    $enabled_new_status  = sqrip_get_plugin_option('enabled_new_status');

    if (!$sqrip_new_status || $enabled_new_status == "no") {
        return $order_statuses;
    }

    $new_order_statuses = array();

    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;
        if ( 'wc-completed' === $key ) {
            $new_order_statuses['wc-sqrip-paid'] = $sqrip_new_status;
        }
    }
    return $new_order_statuses;
}

add_filter( 'wc_order_statuses', 'sqrip_add_new_order_statuses' );