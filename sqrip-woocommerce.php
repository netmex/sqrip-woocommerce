<?php

/**
 * Plugin Name:             sqrip – Swiss QR Invoice
 * Plugin URI:              https://sqrip.ch/
 * Description:             sqrip erweitert die Zahlungsmöglichkeiten von WooCommerce für Schweizer Shops und Schweizer Kunden um die neuen QR-Zahlungsteile.
 * Version:                 1.0
 * Author:                  netmex digital gmbh
 * Author URI:              #
 */

defined('ABSPATH') || exit;

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

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
 * Adding script for settings sqrip page in admin
 *
 * @since 1.0
 */

add_action( 'admin_enqueue_scripts', 'sqrip_admin_enqueue_scripts' );

function sqrip_admin_enqueue_scripts()
{
    wp_enqueue_script('sqrip-admin', plugins_url( 'js/sqrip-admin.js', __FILE__ ), array('jquery'), '1.0', true);
}

/**
 * Adding script for FE
 *
 * @since 1.0
 */

add_action( 'wp_enqueue_scripts', 'sqrip_enqueue_scripts' );

function sqrip_enqueue_scripts() 
{
    wp_enqueue_script( 'sqrip', plugins_url( 'js/sqrip-fe.js', __FILE__ ), array('jquery'), '1.0', true);

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
                'iban' => array(
                    'title' => __( 'IBAN', 'sqrip' ),
                    'type' => 'text',
                    'description' => __( 'QR-IBAN deines Kontos, auf das die Überweisung erfolgen soll', 'sqrip' ),
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

            $plugin_options = get_option('woocommerce_sqrip_settings', array());

            $sqrip_due_date   = $plugin_options['due_date'];
            $token            = $plugin_options['token'];
            $iban             = $plugin_options['iban'];
            $file_type        = $plugin_options['file_type'];
            $product          = $plugin_options['product'];

            $date            = date('Y-m-d');
            $due_date        = date('Y-m-d', strtotime($date . " + ".$sqrip_due_date." days"));

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
                    "iban" => $iban,
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
                    'Authorization' => 'Bearer '.$token,
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

                wc_add_notice( sprintf( __( 'Error: %s', 'sqrip' ), esc_html( $err_msg ) ), 'error' );
                // Add note to the order for your reference
                $order->add_order_note( sprintf( __( 'Error: %s', 'sqrip' ), esc_html($err_msg) ) );
                return false;
            }

            $getbody = wp_remote_retrieve_body($result);
            $data = json_decode($getbody);

            if ($data->reference) {
                $sqrip_pdf       =    $data->pdf_file;
                $sqrip_png       =    $data->png_file;
                $sqrip_reference =    $data->reference;

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

                wc_add_notice( sprintf( __( 'Error: %s', 'sqrip' ), esc_html( $data->message ) ), 'error' );

                // Add note to the order for your reference
                $order->add_order_note( sprintf( __( 'Error: %s', 'sqrip' ), esc_html( $data->message ) ) );

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
