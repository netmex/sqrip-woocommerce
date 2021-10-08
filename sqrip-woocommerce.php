<?php

/**
 * Plugin Name:             sqrip – Swiss QR Invoice
 * Plugin URI:              https://sqrip.ch/
 * Description:             sqrip erweitert die Zahlungsmöglichkeiten von WooCommerce für Schweizer Shops und Schweizer Kunden um die neuen QR-Zahlungsteile.
 * Version:                 1.0.3
 * Author:                  netmex digital gmbh
 * Author URI:              #
 */

defined('ABSPATH') || exit;

// Make sure WooCommerce is active
if ( !in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) ) 
{
    return;
}

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/sqrip-ajax.php';

/**
 * Add plugin Settings link
 *
 * @since 1.0
 */

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sqrip_plugin_settings_page');

function sqrip_plugin_settings_page($links)
{
    $action_links = array(
        'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sqrip' ) . '" aria-label="' . esc_attr__( 'View sqrip settings', 'sqrip' ) . '">' . esc_html__( 'Settings', 'sqrip' ) . '</a>',
    );

    return array_merge( $action_links, $links );
}

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
 *  Adding Meta container admin shop_order pages
 *  
 *  @since 1.0
 */

add_action('add_meta_boxes', 'sqrip_add_meta_boxes');

if (!function_exists('sqrip_add_meta_boxes')) {
    function sqrip_add_meta_boxes()
    {
        add_meta_box('sqrip_detail_fields', __('sqrip Payment', 'sqrip'), 'sqrip_add_fields_for_order_details', 'shop_order', 'side', 'core');
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

        $reference_id = get_post_meta($post->ID, 'sqrip_reference_id', true);
        $pdf_file = get_post_meta($post->ID, 'sqrip_pdf_file_url', true);
        
        // check for legacy pdf meta file
        if(!$pdf_file) {
            $pdf_file = get_post_meta($post->ID, 'sqrip_pdf_file', true);
        }
        
        if ($reference_id || $pdf_file) {
            echo '<ul class="sqrip-payment">';

            echo $reference_id ? '<li><b>'.esc_html__('Reference Number','sqrip').' :</b> '.esc_html($reference_id).'</li>' : '';

            echo $pdf_file ? '<li><b>'.esc_html__( 'QR-Code PDF', 'sqrip' ).' :</b> <a target="_blank" href="'.esc_url($pdf_file).'"><span class="dashicons dashicons-media-document"></span></a></li>' : '';

            echo '</ul>';
        } else {
            echo esc_html__( 'Order not use sqrip method', 'sqrip' );
        }
    }
}

/**
 *  Add sqrip QR code image in email body/content
 *  
 * @since 1.0
 */

add_action('woocommerce_email_after_order_table', 'sqrip_add_qrcode_in_email_after_order_table', 99, 4);

function sqrip_add_qrcode_in_email_after_order_table($order, $sent_to_admin, $plain_text, $email)
{
    if ( empty($order) || ! isset($email->id) || !method_exists($order,'get_payment_method') ) {
        return;
    }

    $payment_method = $order->get_payment_method();

    $plugin_options = get_option('woocommerce_sqrip_settings', array());

    $integration_email = array_key_exists('integration_email', $plugin_options) ? $plugin_options['integration_email'] : '';

    $array_in = array('both', 'body');

    if ( $email->id === 'customer_on_hold_order' && $payment_method === 'sqrip' && in_array($integration_email, $array_in) ) {
        $order_id = $order->id;
        $png_file = get_post_meta($order_id, 'sqrip_png_file', true);

        echo $png_file ? '<div class="sqrip-qrcode-png"><p>' . esc_html__( 'Verwende die untenstehende QR Rechnung, um den ausstehenden Betrag zu bezahlen.' , 'sqrip') . '</p><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip').'" width="200"/></div>' : '';
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

    $integration_email = array_key_exists('integration_email', $plugin_options) ? $plugin_options['integration_email'] : '';

    $array_in = array('both', 'attachment');
    
    if ( $email_id === 'customer_on_hold_order' && $payment_method === 'sqrip' && in_array($integration_email, $array_in) ) {
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
 *  Insert sqrip QR code after customer details
 *  
 *  @since 1.0
 */

add_action('woocommerce_order_details_after_customer_details', 'sqrip_qr_action_order_details_after_order_table', 10, 1);

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

        $png_file = get_post_meta($order_id, 'sqrip_png_file_url', true);
        $pdf_file = get_post_meta($order_id, 'sqrip_pdf_file_url', true);

        echo '<div class="sqrip-order-details">';

        if ( in_array( $integration_order, array('both', 'qrcode') ) && $png_file ) {
            echo '<div class="sqrip-qrcode-png"><p>' . esc_html__( 'Verwende die untenstehende QR Rechnung, um den ausstehenden Betrag zu bezahlen.' , 'sqrip') . '</p><a href="' . esc_url($png_file) . '" target="_blank"><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip').'" width="200" /></a></div>';
        }

        if ( in_array( $integration_order, array('both', 'pdf') ) && $pdf_file ) {
            echo '<div class="sqrip-qrcode-pdf"><a href="' . esc_url($pdf_file) . '" >'.esc_html__('Herunterladen PDF QR-Code','sqrip').'</a></div>';
        }

        if ( is_wc_endpoint_url( 'view-order' ) ) {
            echo '<div class="sqrip-generate-new-qrcode"><button id="sqripGenerateNewQRCode" data-order="'.esc_attr($order_id).'" class="button button-sqrip-generate-qrcode">'. esc_html__('Neuen QR-Code generieren','sqrip'). '</a></button>';
        }

        echo '</div>';
    }
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
            $this->method_title = __( 'sqrip – Swiss QR-Invoice API' , 'sqrip' );
            $this->method_description = __( 'sqrip erstellt shop- und kundenspezifische QR-Codes und Zahlungsteile für QR-Rechnungen für die Rechnungsstellung in der Schweiz', 'sqrip' ); // will be displayed on the options page

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
            $this->due_date = $this->get_option('due_date');
            $this->iban = $this->get_option('iban');
            $this->token = $this->get_option('token');
            $this->file_type = $this->get_option('file_type');
            $this->product = $this->get_option('product');
            $this->address      = $this->get_option('address');

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
                    'title'       => __( 'Aktivieren/Deaktivieren', 'sqrip' ),
                    'label'       => __( 'Aktiviere QR-Rechnungen mit der sqrip API', 'sqrip' ),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => __( 'Name der Zahlungsmethode', 'sqrip' ),
                    'type'        => 'text',
                    'description' => __( 'Schweizer QR-Rechnungen mit sqrip', 'sqrip' ),
                    'default'     => 'QR-Rechnung',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Beschreibung', 'sqrip' ),
                    'type'        => 'textarea',
                    'description' => __( 'Beschreibung, was der Kunde von dieser Zahlungsmöglichkeit zu erwarten hat.', 'sqrip' ),
                    'default'     => 'Bezahlen Sie mit einer QR-Rechnung.',
                ),
                'token' => array(
                    'title'       => __( 'sqrip Token' , 'sqrip' ),
                    'type'        => 'textarea',
                    'description' => __( 'Eröffne ein Konto auf https://sqrip.ch, erstelle einen API Schlüssel, kopiere ihn und füge ihn hier ein. Fertig!', 'sqrip' )
                ),
                'product' => array(
                    'title'  => __( 'Produkt', 'sqrip' ),
                    'type' => 'select',
                    'desc' => __( 'Produkt auswählen', 'sqrip' ),
                    'desc_tip' => true,
                    'options' => array(
                        '' => __( 'Select the product type', 'sqrip' ),
                        'QR-Code' => __( 'nur den QR Code' , 'sqrip' ),
                        'Full A4' => __('A4 (leer) mit Zahlungsteil unten', 'sqrip' ),
                        'Invoice Slip' => __('nur den Zahlungsteil', 'sqrip' ),
                    )
                ),
                'file_type' => array(
                    'title'  => __( 'Format', 'sqrip' ),
                    'type' => 'select',
                    'desc' => __( 'Format auswählen', 'sqrip' ),
                    'desc_tip' => true,
                    'options' => array(
                        '' => __('Format auswählen', 'sqrip' ),
                        'svg' => __( 'SVG' ),
                        'png' => __('PNG'),
                        'pdf' => __('PDF')
                    )
                ),
                'integration_order' => array(
                    'title'  => __( 'Integration in Ordnung', 'sqrip' ),
                    'type' => 'select',
                    'options' => array(
                        '' => __('Wählen Sie die Methode', 'sqrip' ),
                        'qrcode' => __( 'QR Code', 'sqrip' ),
                        'pdf' => __('PDF', 'sqrip' ),
                        'both' => __('beides', 'sqrip' ),
                    )
                ),
                'integration_email' => array(
                    'title'  => __( 'Integration in die Rechnungs-E-Mail', 'sqrip' ),
                    'type' => 'select',
                    'options' => array(
                        '' => __('Ort auswählen', 'sqrip' ),
                        'body' => __( 'im Text', 'sqrip' ),
                        'attachment' => __('als Beilage', 'sqrip' ),
                        'both' => __('beides', 'sqrip' ),
                    )
                ),
                'due_date' => array(
                    'title'       => __( 'Fälligkeit (Tage nach Bestellung)', 'sqrip' ),
                    'type'        => 'number',
                    'default'     => 30
                ),
                'address' => array(
                    'title' => __( 'Address', 'sqrip' ),
                    'type' => 'select',
                    'description' => 'The merchant address use from',
                    'options' => array(
                        'sqrip' => __( 'Sqrip', 'sqrip' ),
                        'woocommerce' => __('WooCommerce', 'sqrip' ),
                    )
                ),
                'iban_type' => array(
                    'title' => __( 'IBAN Type', 'sqrip' ),
                    'type' => 'select',
                    'options' => array(
                        'qr' => __( 'QR-IBAN', 'sqrip' ),
                        'simple' => __('Simple IBAN', 'sqrip' ),
                    )
                ),
                'iban' => array(
                    'title' => __( 'IBAN', 'sqrip' ),
                    'type' => 'text',
                    'description' => __( 'QR-IBAN deines Kontos, auf das die Überweisung erfolgen soll', 'sqrip' ),
                ),
                'qr_reference' => array(
                    'title' => __( 'Reference number', 'sqrip' ),
                    'type' => 'select',
                    'options' => array(
                        'random' => __( 'Random', 'sqrip' ),
                        'order_number' => __('Order Number', 'sqrip' ),
                    )
                ),
            );
        }

        /**
         * Check Iban
         */
        public function check_iban_status($post_data)
        {
            $endpoint   = 'https://api.sqrip.madebycolorelephant.com/api/iban-status';
            $iban       = $post_data['woocommerce_sqrip_iban'];

            $body = '{
                "iban": "'.$iban.'"
            }';

            $response   = sqrip_remote_request($endpoint, $body, 'POST');  

            if ( isset($response->status) && $response->status== "inactive" ) {

                unset($_POST['woocommerce_sqrip_enabled']);

                $settings = new WC_Admin_Settings();

                $settings->add_error( $response->message.'! The Sqrip method can only be enabled when IBAN status is active.' );
            }  

        }

        /**
         * Get address infomation from sqrip and store on WordPress
         */
        public function save_address_detail_from_sqrip($post_data)
        {
            $endpoint = 'https://api.sqrip.ch/api/details';
            
            $response   = sqrip_remote_request($endpoint);                
            
            if ( $response ) {

                $sqrip_user = $response->user;
                $sqrip_user_address = $sqrip_user->address;

                $entity_name = $sqrip_user_address->entity_name;
                $building_number = $sqrip_user_address->building_number;
                $country_code = $sqrip_user_address->country_code;
                $city = $sqrip_user_address->city;
                $zip = $sqrip_user_address->zip;
                $street = $sqrip_user_address->street;

                // Save sqrip user address in WordPress
                $option_name = "sqrip_user_address";
                $option_value = array(
                    'entity_name' => $entity_name,
                    'building_number' => $building_number,
                    'country_code' => $country_code,
                    'city' => $city,
                    'zip' => $zip,
                    'street' => $street
                );

                if ( get_option( $option_name ) !== false ) {

                    update_option( $option_name, $option_value );

                } else {

                    $deprecated = null;
                    $autoload = 'no';
                    add_option( $option_name, $option_value, $deprecated, $autoload );

                }

            } else {

                $settings = new WC_Admin_Settings();
                $settings->add_error( 'sqrip Token Error. Please make sure you enter the correct sqrip Token!' );

            }
        }

        public function process_admin_options()
        {
            $post_data  = $this->get_post_data();

            $this->check_iban_status($post_data);

            // Save address return from sqrip
            // -----------
            // $address = $post_data['woocommerce_sqrip_address'];     
            // if ($address && $address == "sqrip") {
            //     $this->save_address_detail_from_sqrip($post_data);
            // } 

            return parent::process_admin_options();
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
            // sqrip API URL
            $endpoint   = 'https://api.sqrip.madebycolorelephant.com/api/code';

            // we need it to get any order detailes
            $order      = wc_get_order($order_id);
            $order_data = $order->get_data(); // order data

            ## BILLING INFORMATION:
            $order_billing_first_name   = $order_data['billing']['first_name'];
            $order_billing_last_name    = $order_data['billing']['last_name'];
            $order_billing_address_1    = $order_data['billing']['address_1'];
            $order_billing_city         = $order_data['billing']['city'];
            $order_billing_postcode     = intval($order_data['billing']['postcode']);
            $order_billing_country      = $order_data['billing']['country'];
                        
            $currency_symbol    =   $order_data['currency'];
            $amount             =   floatval($order_data['total']);

            $plugin_options     = get_option('woocommerce_sqrip_settings', array());

            $sqrip_due_date     = $plugin_options['due_date'];
            $token              = $plugin_options['token'];
            $iban               = $plugin_options['iban'];
            $iban_type          = $plugin_options['iban_type'];
            $file_type          = $plugin_options['file_type'];
            $product            = $plugin_options['product'];
            $qr_reference       = $plugin_options['qr_reference'];
            $address            = $plugin_options['address'];

            $date               = date('Y-m-d');
            $due_date           = date('Y-m-d', strtotime($date . " + ".$sqrip_due_date." days"));

            if ($iban == '') {
                $err_msg = esc_html( 'Please add IBAN in setting or SQPR dashboard', 'sqrip' );
                wc_add_notice($err_msg, 'error');
                return false;
            }

            if ($file_type == '') {
                $err_msg = esc_html( 'Please select file type in setting', 'sqrip' );
                wc_add_notice($err_msg, 'error');
                return false;
            }

            if ($product == '') {
                $err_msg = esc_html( 'Please select product in setting', 'sqrip' );
                wc_add_notice($err_msg, 'error');
                return false;
            }

            $body = [
                "iban" => [
                    "iban"      => $iban,
                    "iban_type" => $iban_type
                ],
                "payable_by" => [
                    "name"          => $order_billing_first_name.' '.$order_billing_last_name,
                    "street"        => $order_billing_address_1,
                    "postal_code"   => $order_billing_postcode,
                    "town"          => $order_billing_city,
                    "country_code"  => $order_billing_country
                ],
                "payment_information" => [
                    "currency_symbol"   => $currency_symbol,
                    "amount"            => $amount,
                    "due_date"          => $due_date,
                ],
                "lang"      => "de",
                "file_type" => $file_type,
                "product"   => $product,
                "source"    => "woocommerce"
            ];

            // If the user selects "Order Number" the API request will include param "qr_reference"
            if ( $qr_reference == "order_number" ) {
                $body['payment_information']['qr_reference'] = "order_number";
            }

            $body['payable_to'] = $address == "woocommerce" ? sqrip_get_payable_to_address("woocommerce") : [];

            $body = wp_json_encode($body);

            $args = [
                'method'      => 'POST',
                'headers'     => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json'
                ],
                'body'        => $body,
                'data_format' => 'body',
            ];

            $response = wp_remote_post($endpoint, $args);

            $status_code = $response['response']['code'];

            if ($status_code !== 200) {
                // Transaction was not succesful
                // Add notice to the cart
                $err_msg = explode(",", $response['body']);
                $err_msg = trim(strstr($err_msg[0], ':'), ': "');

                wc_add_notice( 
                    sprintf( 
                        __( 'Error: %s', 'sqrip' ), 
                        esc_html( $err_msg ) ), 
                    'error' 
                );

                // Add note to the order for your reference
                $order->add_order_note( 
                    sprintf( 
                        __( 'Error: %s', 'sqrip' ), 
                        esc_html($err_msg) 
                    ) 
                );

                return false;
            }

            $response_body = wp_remote_retrieve_body($response);
            $response_body = json_decode($response_body);

            if ($response_body->reference) {
                $sqrip_pdf       =    $response_body->pdf_file;
                $sqrip_png       =    $response_body->png_file;
                $sqrip_reference =    $response_body->reference;

                // TODO: replace with attachment ID and store this in meta instead of actual file
                $sqrip_qr_pdf_attachment_id = $this->file_upload($sqrip_pdf, '.pdf');
                $sqrip_qr_png_attachment_id = $this->file_upload($sqrip_png, '.png');

                $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
                $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);

                $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
                $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);

                $order->add_order_note( __('sqrip payment QR-Code generated.', 'sqrip') );

                $order->update_meta_data('sqrip_reference_id', $sqrip_reference);

                $order->update_meta_data('sqrip_pdf_file_url', $sqrip_qr_pdf_url);
                $order->update_meta_data('sqrip_pdf_file_path', $sqrip_qr_pdf_path);

                $order->update_meta_data('sqrip_png_file_url', $sqrip_qr_png_url);
                $order->update_meta_data('sqrip_png_file_path', $sqrip_qr_png_path);

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

                wc_add_notice( 
                    sprintf( 
                        __( 'Error: %s', 'sqrip' ), 
                        esc_html( $response_body->message ) 
                    ),
                    'error' 
                );

                // Add note to the order for your reference
                $order->add_order_note( 
                    sprintf( 
                        __( 'Error: %s', 'sqrip' ), 
                        esc_html( $response_body->message ) 
                    ) 
                );

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
        public function file_upload($fileurl, $type)
        {
            include_once(ABSPATH . 'wp-admin/includes/image.php');

            $uniq_name = date('dmY') . '' . (int) microtime(true);
            $filename = $uniq_name . $type;

            // Get the path to the upload directory.
            $uploaddir = wp_upload_dir();
            $uploadfile = $uploaddir['path'] . '/' . $filename;

            // initiate context with request settings
            $plugin_options = get_option('woocommerce_sqrip_settings', array());
            $token = $plugin_options['token'];
            $stream_options = [
                "http" => [
                    "method" => "GET",
                    "header" => "Authorization: Bearer $token\r\n"
                ]
            ];

            $context = stream_context_create($stream_options);

            $contents = file_get_contents($fileurl, false, $context);
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

            return $attach_id;
        }
    }
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
            $message = __( 'The SQRIP plugin only supports EUR and CHF currencies!', 'sqrip' );

            printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
        }
    }

    $allowed_types = get_allowed_mime_types();

    if ( !array_key_exists('pdf', $allowed_types) ) {
        $class = 'notice notice-error is-dismissible';
        $message = __( 'Your site is currently unable to upload pdf.', 'sqrip' );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }
}

/**
 * Adding script for settings sqrip page in admin
 *
 * @since 1.0
 */

add_action( 'admin_enqueue_scripts', 'sqrip_admin_enqueue_scripts' );

function sqrip_admin_enqueue_scripts()
{
    wp_enqueue_style('sqrip-admin', plugins_url( 'css/sqrip-admin.css', __FILE__ ), '', '1.0.3');

    wp_enqueue_script('sqrip-admin', plugins_url( 'js/sqrip-admin.js', __FILE__ ), array('jquery'), '1.0.3', true);
    wp_localize_script( 'sqrip-admin', 'sqrip',
        array( 
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        )
    );
}

/**
 * Adding script for FE
 *
 * @since 1.0
 */

add_action( 'wp_enqueue_scripts', 'sqrip_enqueue_scripts' );

function sqrip_enqueue_scripts() 
{
    wp_enqueue_script( 'sqrip', plugins_url( 'js/sqrip-fe.js', __FILE__ ), array('jquery'), '1.0.3', true);

    wp_localize_script( 'sqrip', 'sqrip',
        array( 
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'ajax_nonce' => wp_create_nonce( 'sqrip-generate-new-qrcode' )
        )
    );
}