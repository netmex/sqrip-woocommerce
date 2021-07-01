<?php

/**
 * Plugin Name:             sqrip.ch
 * Plugin URI:              #
 * Description:             QR-Rechnungen für Online-Shops
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
 *  Adding Meta container admin shop_order pages
 *  @since 1.0
 */

add_action('add_meta_boxes', 'pb_add_meta_boxes');
if (!function_exists('pb_add_meta_boxes')) {
    function pb_add_meta_boxes()
    {
        add_meta_box('pb_detail_fields', __('sqrip Payment', 'woocommerce'), 'pb_add_other_fields_for_payment_details', 'shop_order', 'side', 'core');
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


    if (isset($email->id) && $email->id === 'customer_on_hold_order' && $payment_method === 'sqrip') {

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

	// $order can either be of type Order, WC_Order or WC_Product / WC_Product_Variation or completely omitted
	// fix as per: https://stackoverflow.com/a/53253862/5991864
    if (empty($order) || ! isset( $email_id ) || !method_exists($order,'get_payment_method')) {
        return $attachments;
    }

    $payment_method = $order->get_payment_method();
    if ($email_id === 'customer_on_hold_order' && $payment_method === 'sqrip') {

        $order_id = $order->id;
        $attachments[] = get_post_meta($order_id, 'pm_pdf_file', true);
    }
    return $attachments;
}

add_action('woocommerce_thankyou', 'pm_qr_image_display_thankyou', 99, 1);

function pm_qr_image_display_thankyou($order_id)
{
    $order = new WC_Order($order_id);
    $payment_method = $order->get_payment_method();

    if ($payment_method === 'sqrip') {

        $pm_qr_img = get_post_meta($order_id, 'pm_png_file', true);
        echo '<p>Scan below QR code and pay</p><img src="' . $pm_qr_img . '" alt="img"  height=200 width=200/><p></p>';
    }
}


/**
 * The class itself, please note that it is inside plugins_loaded action hook
 *
 * @since 1.0
 */

add_action('plugins_loaded', 'pb_init_gateway_class');

function pb_init_gateway_class()
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
            $this->method_title = 'sqrip – Swiss QR-Invoice API';
            $this->method_description = 'sqrip erstellt shop- und kundenspezifische QR-Codes und Zahlungsteile für QR-Rechnungen für die Rechnungsstellung in der Schweiz'; // will be displayed on the options page

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
                    'title'       => 'Aktivieren/Deaktivieren',
                    'label'       => 'Aktiviere QR-Rechnungen mit der sqrip API',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Name der Zahlungsmethode',
                    'type'        => 'text',
                    'description' => 'Schweizer QR-Rechnungen mit sqrip',
                    'default'     => 'QR-Rechnung',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Beschreibung',
                    'type'        => 'textarea',
                    'description' => 'Beschreibung, was der Kunde von dieser Zahlungsmöglichkeit zu erwarten hat.',
                    'default'     => 'Bezahlen Sie mit einer QR-Rechnung.',
                ),
                'pm_token' => array(
                    'title'       => 'sqrip Token',
                    'type'        => 'textarea',
  		   'description' => 'Eröffne ein Konto auf https://sqrip.ch, erstelle einen API Schlüssel, kopiere ihn und füge ihn hier ein. Fertig!'
                ),
                'product' => array(
                    'title'  => 'Produkt',
                    'name' => __( 'Product' ),
                    'type' => 'select',
                    'desc' => __( 'Produkt auswählen'),
                    'desc_tip' => true,
                    'options' => array(
                        '' => __( 'Select the product type'),
                        'QR-Code' => __( 'nur den QR Code' ),
                        'Full A4' => __('A4 (leer) mit Zahlungsteil unten'),
                        'Invoice Slip' => __('nur den Zahlungsteil')
                    )
                ),
                'file_type' => array(
                    'title'  => 'Format',
                    'name' => __( 'Format' ),
                    'type' => 'select',
                    'desc' => __( 'Format auswählen'),
                    'desc_tip' => true,
                    'options' => array(
                        '' => __('Format auswählen'),
                        'svg' => __( 'SVG' ),
                        'png' => __('PNG'),
                        'pdf' => __('PDF')
                    )
                ),
                'integration_email' => array(
                    'title'  => 'Integration in die Rechnungs-E-Mail',
                    'type' => 'select',
                    'options' => array(
                        '' => __('Ort auswählen'),
                        'body' => __( 'im Text' ),
                        'attachment' => __('als Beilage'),
                        'both' => __('beides')
                    )
                ),
                'pm_due_date' => array(
                    'title'       => 'Fälligkeit (Tage nach Bestellung)',
                    'type'        => 'number',
                    'default'     => 30
                ),
                'pm_iban' => array(
                    'title' => 'IBAN',
                    'type' > 'text',
                    'description' => 'QR-IBAN deines Kontos, auf das die Überweisung erfolgen soll'
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


            // sqrip API URL
            $endpoint = 'https://api.sqrip.ch/api/code';


            $name            =   $data['billing']['first_name'] . ' ' . $data['billing']['last_name'];
            $street          =   $data['billing']['address_1'];

            $postal_code     =   $data['shipping']['postcode'];
            $town            =   $data['shipping']['city'];
            $country_code    =   $data['shipping']['country'];

            $currency_symbol =   $data['currency'];
            $amount          =   $data['total'];;

            $pm_plugin_options = get_option('woocommerce_sqrip_settings', array());


            $pm_day   = $pm_plugin_options['pm_due_date'];
            $pm_token = $pm_plugin_options['pm_token'];
            $pm_iban = $pm_plugin_options['pm_iban'];
            $file_type = $pm_plugin_options['file_type'];
            $product = $pm_plugin_options['product'];

            $date            = date('Y-m-d');
            $due_date        = date('Y-m-d', strtotime($date . " + $pm_day days"));

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
                    "message" => "Invoice : Test",
                    "due_date" => $due_date,
                    "qr_reference" => "253573889212346"
                ],
                "payable_to" =>
                [
                	"title" => "Zirkl"
                ],
                "lang" => "de",
                "file_type" => $file_type,
                "product" => $product,
                "source" => "woocommerce"
            ];

            $body = wp_json_encode($body);

            var_dump($body);

            $options = [
                'method'      => 'POST',
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Authorization' => "Bearer $pm_token",
                    'Accept' => 'application/json'
                ],
                'body'        => $body,
                // 'timeout'     => 60,
                // 'redirection' => 5,
                // 'blocking'    => true,
                // 'httpversion' => '1.0',
                //'sslverify'   => false,
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
                // if($pm_iban == '') {
                //     wc_add_notice($date['message'], 'Please enter IBAN in settings or sqrip dashboard');
                // }
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
