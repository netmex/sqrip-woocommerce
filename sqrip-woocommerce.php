<?php

/**
 * Plugin Name:             sqrip – Swiss QR Invoice
 * Plugin URI:              https://sqrip.ch/
 * Description:             sqrip erweitert die Zahlungsmöglichkeiten von WooCommerce für Schweizer Shops und Schweizer Kunden um die neuen QR-Zahlungsteile.
 * Version:                 1.0
 */

defined('ABSPATH') || exit;

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Add plugin Settings link
 *
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pb_settings_page');

function pb_settings_page($links)
{
   $action_links = array(
        'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sqrip' ) . '" aria-label="' . esc_attr__( 'View settings', 'woocommerce' ) . '">' . esc_html__( 'Settings', 'woocommerce' ) . '</a>',
    );

    return array_merge( $action_links, $links );
}

/**
 * This action hook registers our PHP class as a WooCommerce payment gateway
 *
 * @since 1.0
 */

add_filter('woocommerce_payment_gateways', 'pb_add_gateway_class');

function pb_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Sqrip_Payment_Gateway'; // your class name is here
    return $gateways;
}

/**
 * Adding script for settings page
 */

add_action( 'admin_enqueue_scripts', 'pb_load_admin_scripts' );
function pb_load_admin_scripts(){
    wp_register_script('pb-admin-script', plugins_url( 'js/admin-script.js', __FILE__ ), array('jquery'), '1.0.0', true);
    wp_enqueue_script('pb-admin-script');
}

/**
 * Adding script for FE
 */

add_action( 'wp_enqueue_scripts', 'sqrip_enqueue_scripts' );

function sqrip_enqueue_scripts() 
{
    wp_enqueue_script( 'sqrip', plugins_url( 'js/fe-sqrip.js', __FILE__ ), array('jquery'), '1.0.0', true);

    wp_localize_script( 'sqrip', 'sqrip',
        array( 
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        )
    );
}

/**
 *  Adding Meta container admin shop_order pages
 *  @since 1.0
 */

add_action('add_meta_boxes', 'pb_add_meta_boxes');
if (!function_exists('pb_add_meta_boxes')) {
    function pb_add_meta_boxes()
    {
        add_meta_box('pb_detail_fields', __('Sqrip Payment', 'woocommerce'), 'pb_add_other_fields_for_payment_details', 'shop_order', 'side', 'core');
    }
}

/**
 *  Adding Meta field in the meta container admin shop_order pages to dislay sqrip payment details
 *  @since 1.0
 */

if (!function_exists('pb_add_other_fields_for_payment_details')) {
    function pb_add_other_fields_for_payment_details()
    {
        global $post;

        $pm_referecne_id = get_post_meta($post->ID, 'pm_referecne_id', true) ? get_post_meta($post->ID, 'pm_referecne_id', true) : '';
        $pm_pdf_file = get_post_meta($post->ID, 'pm_pdf_file', true) ? get_post_meta($post->ID, 'pm_pdf_file', true) : '';

        if (($pm_referecne_id != '' && $pm_pdf_file != '')) {

            echo '<p style="border-bottom:solid 1px #eee;padding-bottom:13px;"><label >Reference Number : </label>
            <input readonly type="text" style="width:250px;";" name="my_field_name" placeholder="' . $pm_referecne_id . '" value="' . $pm_referecne_id . '"></p>
            <p style="border-bottom:solid 1px #eee;padding-bottom:15px;">sqrip QR Code PDF : <a style="cursor:pointer;color:red;text-decoration: none;" target="_blank" href="' . $pm_pdf_file . '"> <span class="dashicons dashicons-media-document"></span></a>';
        }
    }
}

/**
 *  Add sqrip QR code image in email body/content
 * @since 1.0
 */

add_action('woocommerce_email_after_order_table', 'pb_email_after_order_table', 99, 4);

function pb_email_after_order_table($order, $sent_to_admin, $plain_text, $email)
{
    $payment_method = $order->get_payment_method();

    $pm_plugin_options = get_option('woocommerce_sqrip_settings', array());
    $integration_email = $pm_plugin_options['integration_email'];
    $integration_email_arr = array('both', 'body');

    if ( isset($email->id) && $email->id === 'customer_on_hold_order' && $payment_method === 'sqrip' && in_array($integration_email, $integration_email_arr)) {
        $order_id = $order->id;
        $pm_qr_img = get_post_meta($order_id, 'pm_png_file', true);
        echo '<p>Scan below QR code and pay</p><img src="' . $pm_qr_img . '" alt="img" /><p></p>';
    }
}

/**
 *  sqrip QR code file attach in email
 *  @since 1.0
 */

add_filter('woocommerce_email_attachments', 'pm_attach_qrcode_pdf_to_email', 99, 3);

function pm_attach_qrcode_pdf_to_email($attachments, $email_id, $order)
{

    if ( empty($order) ||  !is_a($order, 'WC_Order') || !isset( $email_id ) ) {
        return $attachments;
    }
    $pm_plugin_options = get_option('woocommerce_sqrip_settings', array());
    $integration_email = $pm_plugin_options['integration_email'];
    $integration_email_arr = array('both', 'attachment');
    $payment_method = $order->get_payment_method();

    if ( $email_id === 'customer_on_hold_order' && $payment_method === 'sqrip' && in_array($integration_email, $integration_email_arr)) {
        $order_id = $order->id;
        $attachments[] = get_post_meta($order_id, 'pm_pdf_file', true);
    }
    return $attachments;
}

/**
 *  Add sqrip QR code image in order detail
 *  @since 1.0
 */

add_action('woocommerce_order_details_after_customer_details', 'pm_qr_action_order_details_after_order_table', 10, 4);

function pm_qr_action_order_details_after_order_table($order, $sent_to_admin = '', $plain_text = '', $email = '')
{
    $payment_method = $order->get_payment_method();

    if ($payment_method === 'sqrip') {
        $order_id = $order->get_id(); 
        $pm_plugin_options = get_option('woocommerce_sqrip_settings', array());
        $integration_order = $pm_plugin_options['integration_order'];

        if ( in_array( $integration_order, array('both', 'qrcode') ) ) {
            $pm_qr_img = get_post_meta($order_id, 'pm_png_file', true);
            echo '<p class="sqrip-img">Scan below QR code and pay</p><img src="' . $pm_qr_img . '" alt="img"  height=200 width=200/><p></p>';
        }

        if ( in_array( $integration_order, array('both', 'pdf') ) ) {
            $pm_qr_pdf = get_post_meta($order_id, 'pm_pdf_file', true);
            echo '<p class="sqrip-pdf"><a href="' . $pm_qr_pdf . '" >Download PDF QR-Code</a></p>';
        }

        if ( is_wc_endpoint_url( 'view-order' ) ) {
            echo '<p class="sqrip-generate-new-qr-code"><a href="#" id="sqripGenerateNewQRCode" data-order="'.$order_id.'">Generate New QR-Code</a></p>';
        }
    }
}

/**
 * sqrip Generate new qr code ajax
 *
 * @since 1.0
 */
add_action( 'wp_ajax_sqrip_generate_new_qr_code', 'sqrip_generate_new_qr_code' );

function sqrip_generate_new_qr_code() {
 
    $order_id = (isset($_POST['order_id'])) ? esc_attr($_POST['order_id']) : '';

    if ($order_id) {
        $order = wc_get_order( $order_id );
        $user_id   = $order->get_user_id();
        $cur_user_id = get_current_user_id();

        if ($user_id == $cur_user_id) {
            $sqrip_payment = new WC_Sqrip_Payment_Gateway;
            $process_payment = $sqrip_payment->process_payment($order_id);

            wp_send_json($process_payment);
        }
        
    }
    die();
}

/**
 * The class itself, please note that it is inside plugins_loaded action hook
 *
 * @since 1.0
 */

add_action('plugins_loaded', 'sqrip_init_gateway_class');

function sqrip_init_gateway_class()
{

    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Sqrip_Payment_Gateway extends WC_Payment_Gateway
    {

        /**
         * Class constructor add payment gateway information
         */
        public function __construct()
        {

            $this->id = 'sqrip'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'sqrip Swiss QR-Invoice API';
            $this->method_description = 'sqrip erstellt für Sie shop- und kundenspezifische QR-Codes für die Rechnungsstellung in der Schweiz'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products'
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->pm_due_date = $this->get_option('pm_due_date');
            $this->pm_iban = $this->get_option('pm_iban');
            $this->pm_token = $this->get_option('pm_token');
            $this->file_type = $this->get_option('file_type');
            $this->product = $this->get_option('product');

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable sqrip Payment Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Name der Zahlungsmethode',
                    'type'        => 'text',
                    'description' => 'sqrip Payment Gateway',
                    'default'     => 'QR-Rechnung',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Beschreibung',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Beschreibung',
                ),
                'pm_token' => array(
                    'title'       => 'sqrip Token',
                    'type'        => 'textarea'
                ),
                'product' => array(
                    'title'  => 'Product',
                    'name' => __( 'Product' ),
                    'type' => 'select',
                    'desc' => __( 'Select the product type'),
                    'desc_tip' => true,
                    'options' => array(
                        '' => __( 'Select the product type'),
                        'QR-Code' => __( 'QR Code' ),
                        'Full A4' => __('Invoice A4'),
                        'Invoice Slip' => __('Payment Slip')
                    )
                ),
                'file_type' => array(
                    'title'  => 'Format',
                    'name' => __( 'Format' ),
                    'type' => 'select',
                    'desc' => __( 'Select the format'),
                    'desc_tip' => true,
                    'options' => array(
                        '' => __('Select the format'),
                        'svg' => __( 'SVG' ),
                        'png' => __('PNG'),
                        'pdf' => __('PDF')
                    )
                ),
                'integration_order' => array(
                    'title'  => 'Integration into Order',
                    'type' => 'select',
                    'options' => array(
                        '' => __('Select the method'),
                        'qrcode' => __( 'QR Code' ),
                        'pdf' => __('PDF'),
                        'both' => __('Both')
                    )
                ),
                'integration_email' => array(
                    'title'  => 'Integration into E-Mail',
                    'type' => 'select',
                    'options' => array(
                        '' => __('Select the email method'),
                        'body' => __( 'Body' ),
                        'attachment' => __('Attachment'),
                        'both' => __('Both')
                    )
                ),
                'pm_due_date' => array(
                    'title'       => 'Due Date (days after order)',
                    'type'        => 'number',
                    'default'     => 30
                ),
                'pm_iban' => array(
                    'title' => 'IBAN',
                    'type' > 'text',
                    'description' => 'You can add IBAN from sqrip dashboard as well.'
                ),

            );
        }

        /**
         * You will need it if you want your custom credit card form,
         */
        public function payment_fields()
        {
            // ok, let's display some description before the payment form
            if ($this->description) {
                // you can instructions for test mode, I mean test card numbers etc.
                $this->description  = trim($this->description);
                // display the description with <p> tags etc.
                echo wpautop(wp_kses_post($this->description));
            }
        }

        /*
         * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
         */
        public function payment_scripts()
        {
        }

        /*
         * Fields validation
         */
        public function validate_fields()
        {
        }


        /*
         *  Processing payment
         */
        public function process_payment($order_id)
        {

            global $woocommerce;
            // we need it to get any order detailes
            $order = wc_get_order($order_id);

            $data = $order->get_data(); // order data
            // Get this Order's information so that we know
            // 
            $store_address     = get_option( 'woocommerce_store_address' );
            $store_address_2   = get_option( 'woocommerce_store_address_2' );
            $store_city        = get_option( 'woocommerce_store_city' );
            $store_postcode    = get_option( 'woocommerce_store_postcode' );

            // The country/state
            $store_raw_country = get_option( 'woocommerce_default_country' );

            // Split the country/state
            $split_country = explode( ":", $store_raw_country );

            // Country and state separated:
            $store_country = $split_country[0];
            $store_state   = $split_country[1];

            $store_name = get_bloginfo('name');

            // sqrip API URL
            $endpoint = 'https://api.sqrip.ch/api/code';
            
            $name            =   $store_name;
            $street          =   $store_address;
            $postal_code     =   intval($store_postcode);
            $town            =   $store_city;
            $country_code    =   $store_country;
            
            $currency_symbol =   $data['currency'];
            $amount          =   floatval($data['total']);

            $pm_plugin_options = get_option('woocommerce_sqrip_settings', array());

            $pm_day   = $pm_plugin_options['pm_due_date'];
            $pm_token = $pm_plugin_options['pm_token'];
            $pm_iban = $pm_plugin_options['pm_iban'];
            $file_type = $pm_plugin_options['file_type'];
            $product = $pm_plugin_options['product'];

            $date            = date('Y-m-d');
            $due_date        = date('Y-m-d', strtotime($date . " + ".$pm_day." days"));

            if ($pm_iban == '') {
                $err_msg = 'Please add IBAN in setting or SQPR dashboard';
                wc_add_notice($err_msg, 'error');
                return false;
            }

            if ($file_type == '') {
                $err_msg = 'Please select file type in setting';
                wc_add_notice($err_msg, 'error');
                return false;
            }

            if ($product == '') {
                $err_msg = 'Please select product in setting';
                wc_add_notice($err_msg, 'error');
                return false;
            }

            $body = [
                "iban" => [
                    "iban" => $pm_iban,
                    "iban_type" => "simple"
                ],
                "payable_by" =>
                [
                    "name" => $name,
                    "street" => $street,
                    "postal_code" => $postal_code,
                    "town" => $town,
                    "country_code" => $country_code
                ],
                "payment_information" =>
                [
                    "currency_symbol" => $currency_symbol,
                    "amount" => $amount,
                    "due_date" => $due_date,
                ],
                "payable_to" =>
                [
                    "title" => "sqrip"
                ],
                "lang" => "de",
                "file_type" => $file_type,
                "product" => $product,
                "source" => "woocommerce"
            ];

            $body = wp_json_encode($body);

            $options = [
                'method'      => 'POST',
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Authorization' => "Bearer ".$pm_token,
                    'Accept' => 'application/json'
                ],
                'body'        => $body,
                'data_format' => 'body',
            ];

            $result = wp_remote_post($endpoint, $options);

            $status_code = $result['response']['code'];

            if ($status_code !== 200) {
                // Transaction was not succesful
                // Add notice to the cart
                $err_msg = explode(",", $result['body']);
                $err_msg = trim(strstr($err_msg[0], ':'), ': "');

                wc_add_notice($err_msg, 'error');
                // Add note to the order for your reference
                $order->add_order_note('Error: ' . $err_msg);
                return false;
            }

            $getbody = wp_remote_retrieve_body($result);
            $data = json_decode($getbody);

            if ($data->reference) {
                $pm_pdf       =    $data->pdf_file;
                $pm_png       =    $data->png_file;
                $pm_reference =    $data->reference;

                $pm__wp_qr_pdf = $this->pb_qr_file_upload($pm_pdf, '.pdf');
                $pm__wp_qr_png = $this->pb_qr_file_upload($pm_png, '.png');

                $order->add_order_note(__('sqrip payment QR code generated .', 'pm'));

                $order->update_meta_data('pm_referecne_id', $pm_reference);
                $order->update_meta_data('pm_pdf_file', $pm__wp_qr_pdf);
                $order->update_meta_data('pm_png_file', $pm__wp_qr_png);

                // Update order status
                $order->update_status('on-hold');
                // Empty the cart (Very important step)
                $woocommerce->cart->empty_cart();
                $order->save();

                // Redirect to thank you page
                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            } else {
                wc_add_notice($date['message'], 'error');
                // Add note to the order for your reference
                $order->add_order_note('Error: ' . $date['message']);
                return false; // Bail early
            }
        }

        /*
         * In case you need a webhook, like PayPal IPN etc
         */
        public function webhook()
        {
        }

        /*
        *  sqrip QR Code PDF  Download in medialibrary and set
        */
        public function pb_qr_file_upload($fileurl, $type)
        {

            include_once(ABSPATH . 'wp-admin/includes/image.php');

            $uniq_name = date('dmY') . '' . (int) microtime(true);
            $filename = $uniq_name . $type;

            // Get the path to the upload directory.
            $uploaddir = wp_upload_dir();
            $uploadfile = $uploaddir['path'] . '/' . $filename;

            $contents = file_get_contents($fileurl);
            $savefile = fopen($uploadfile, 'w');
            fwrite($savefile, $contents);
            fclose($savefile);

            $wp_filetype = wp_check_filetype(basename($filename), null);

            $attachment = array(
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => $filename,
                'post_content' => '',
                'post_status' => 'inherit'
            );
            // Insert the attachment.
            $attach_id = wp_insert_attachment($attachment, $uploadfile);

            // Generate the metadata for the attachment, and update the database record.
            $attach_data = wp_generate_attachment_metadata($attach_id, $uploadfile);
            wp_update_attachment_metadata($attach_id, $attach_data);

            return wp_get_attachment_url($attach_id);
        }
    }
}

/*
*  Add admin notice
*/
function pb_general_admin_notice(){
    $currency = get_woocommerce_currency();

    $currency_arr = array('EUR', 'CHF');
    if ( !in_array($currency, $currency_arr) ) {
        echo '<div class="notice notice-error is-dismissible">
             <p><span style="color: red; font-weight: bold">WARNING! </span><b> The SQRIP plugin</b> only supports <b>EUR</b> and <b>CHF</b> currencies!</p>
        </div>';
    }

    $allowed_types = get_allowed_mime_types();
    if ( !array_key_exists('pdf', $allowed_types) ) {
        echo '<div class="notice notice-error is-dismissible">
             <p><span style="color: red; font-weight: bold">WARNING! </span>Your site is currently unable to upload pdf. Please set the value <b>ALLOW_UNFILTERED_UPLOADS</b> to <b>true</b> in wp-config.php</p>
        </div>';
    }
}
add_action('admin_notices', 'pb_general_admin_notice');