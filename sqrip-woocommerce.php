<?php

/**
 * Plugin Name:             sqrip – Swiss QR Invoice Provider
 * Plugin URI:              https://sqrip.ch/
 * Description:             sqrip erweitert die Zahlungsmöglichkeiten von WooCommerce für Schweizer Shops und Schweizer Kunden um die neuen QR-Zahlungsteile.
 * Version:                 1.2.2
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
            $this->method_description = __( 'sqrip erstellt QR-Codes, A6 QR-Zahlungsteile und A4 QR-Rechnungen für die Rechnungsstellung in der Schweiz', 'sqrip' ); // will be displayed on the options page

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
            $address_woocommerce = sqrip_get_payable_to_address_txt('woocommerce');
            $address_sqrip = sqrip_get_payable_to_address_txt('sqrip');
            
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => __( 'Aktivieren/Deaktivieren', 'sqrip' ),
                    'label'       => __( 'Aktiviere QR-Rechnungen mit der sqrip API', 'sqrip' ),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'token' => array(
                    'title'       => __( 'API Schlüssel' , 'sqrip' ),
                    'type'        => 'textarea',
                    'description' => __( 'Eröffne ein Konto auf <a href="https://sqrip.ch" target="_blank">https://sqrip.ch</a>, erstelle einen API Schlüssel, kopiere und füge ihn hier ein. Fertig!', 'sqrip' ),
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
                'section_payment_recevier' => array(
                    'title' => __( 'Zahlungsempfänger', 'sqrip' ),
                    'type' => 'section',
                ),
                'address' => array(
                    'title' => __( 'Adresse', 'sqrip' ),
                    'type' => 'select',
                    'description' => __( 'Die auf der QR-Rechnung zu erscheinende Adresse', 'sqrip' ),
                    'options' => array(
                        'sqrip'         => __( 'vom sqrip-Konto: '.esc_attr($address_sqrip) , 'sqrip' ),
                        'woocommerce'   => __( 'aus WooCommerce: '.esc_attr($address_woocommerce) , 'sqrip' ),
                        'individual'    => __( 'Drittadresse' , 'sqrip' ),
                    )
                ),
                'address_name' => array(
                    'title' => __( 'Name', 'sqrip' ),
                    'type' => 'text',
                    'class' => 'sqrip-address-individual',
                ),
                'address_street' => array(
                    'title' => __( 'Strasse', 'sqrip' ),
                    'type' => 'text',
                    'class' => 'sqrip-address-individual',
                ),
                'address_postcode' => array(
                    'title' => __( 'PLZ', 'sqrip' ),
                    'type' => 'text',
                    'class' => 'sqrip-address-individual',
                ),
                'address_city' => array(
                    'title' => __( 'Ort', 'sqrip' ),
                    'type' => 'text',
                    'class' => 'sqrip-address-individual',
                ),
                'address_country' => array(
                    'title' => __( 'Ländercode', 'sqrip' ),
                    'type' => 'text',
                    'class' => 'sqrip-address-individual',
                ),
                'iban' => array(
                    'title' => __( '(QR-)IBAN', 'sqrip' ),
                    'type' => 'text',
                    'description' => __( '(QR-)IBAN des Kontos, auf das die Überweisung erfolgen soll', 'sqrip' ),
                ),
                'qr_reference' => array(
                    'title' => __( 'Grundlage der (QR-)Referenznummer', 'sqrip' ),
                    'type' => 'radio',
                    'options' => array(
                        'random' => __( 'zufällige Nummer', 'sqrip' ),
                        'order_number' => __('Bestellnummer', 'sqrip' ),
                    ),
                ),
                'due_date' => array(
                    'title'       => __( 'Fälligkeit (Heute in x Tagen)', 'sqrip' ),
                    'type'        => 'number',
                    'default'     => 30,
                    'css'         => "width:70px"
                ),
                'section_invoice_settings' => array(
                    'title' => __('Anzeige der QR-Rechnung', 'sqrip'),
                    'type'        => 'section',
                ),
                'integration_order' => array(
                    'title'       => __( 'auf der Bestätigungsseite', 'sqrip' ),
                    'label'       => __( 'QR-Rechnung zum Download anbieten', 'sqrip' ),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'yes'
                ),
                'product' => array(
                    'title'         => __( 'in der Bestätigungs-E-Mail', 'sqrip' ),
                    'type'          => 'select',
                    'description' => __( 'Format auswählen', 'sqrip' ),
                    'options'       => array(
                        'Full A4'   => __('auf einem leeren A4-PDF', 'sqrip' ),
                        'Invoice Slip' => __('nur den A6-Zahlungsteil als PDF', 'sqrip' ),
                    )
                ),
                'lang' => array(
                    'title'         => __( 'Sprache', 'sqrip' ),
                    'type'          => 'select',
                    'options'       => array(
                        'de'    => __( 'Deutsch', 'sqrip' ),
                        'fr'    => __( 'Français', 'sqrip' ),
                        'it'    => __( 'Italiano', 'sqrip' ),
                        'en'    => __( 'English', 'sqrip' )
                    ),
                    'default' => 'de'
                ),
                'test_email' => array(
                    'title'       => '',
                    'type'        => 'checkbox',
                    'default'     => 'no',
                    'css'         => 'visibility: hidden'  
                ),
                
            );
        }

        public function generate_radio_html($key, $data)
        {
            $field_key = $this->get_field_key( $key );
            $defaults  = array(
              'title'             => '',
              'disabled'          => false,
              'class'             => '',
              'css'               => '',
              'placeholder'       => '',
              'type'              => 'text',
              'desc_tip'          => false,
              'description'       => '',
              'custom_attributes' => array(),
            );

            $data = wp_parse_args( $data, $defaults );
            $value = $this->get_option( $key );

            ob_start();
            ?>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
                    </th>
                    <td class="forminp">
                        <fieldset>
                            <legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>

                            <?php foreach ( (array) $data['options'] as $option_key => $option_value ) : 
                                $ip_id = $option_key.'_'.$field_key;
                                $checked = (string) $option_key == esc_attr($value) ? "checked" : "";
                                ?>
                                <div class="sqrip-radio-field <?php echo esc_attr( $data['class'] ); ?>">
                                <input id="<?php echo esc_attr($ip_id); ?>" type="radio" name="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $option_key ); ?>" <?php echo esc_attr($checked); ?> />
                                <label for="<?php echo esc_attr($ip_id); ?>"><?php echo esc_html( $option_value ); ?></label>
                                </div>
                            <?php endforeach; ?>
                           
                            <?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>
                        </fieldset>
                    </td>
                </tr>
                <?php

            return ob_get_clean();
        }

        public function generate_section_html($key, $data)
        {
            $field_key = $this->get_field_key( $key );
            $defaults  = array(
              'title'             => '',
              'disabled'          => false,
              'class'             => '',
              'css'               => '',
              'placeholder'       => '',
              'type'              => 'text',
              'desc_tip'          => false,
              'description'       => '',
              'custom_attributes' => array(),
            );

            $data = wp_parse_args( $data, $defaults );

            ob_start();
            ?>
                <tr valign="top sqrip-section">
                    <th scope="row" class="titledesc"  colspan="2">
                        <h2><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></h2>
                        <?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>
                    </th>
                </tr>
            <?php

            return ob_get_clean();
        }

        /**
         * Check Iban
         */
        public function check_iban_status($post_data)
        {
            $endpoint   = 'https://api.sqrip.ch/api/iban-status';
            $iban       = $post_data['woocommerce_sqrip_iban'];

            $body = '{
                "iban": "'.$iban.'"
            }';

            $response   = sqrip_remote_request($endpoint, $body, 'POST');  

            if ( isset($response->status) && $response->status== "inactive" ) {

                unset($_POST['woocommerce_sqrip_enabled']);

                $settings = new WC_Admin_Settings();

                $settings->add_error( __( 'Die (QR-)IBAN wurde geändert. Bitte bestätige die neue (QR-)IBAN in deinem sqrip.ch-Konto.', 'sqrip' ) );
            }  

        }

        /**
         * Update Iban
         */
        public function update_iban($post_data)
        {
            $endpoint   = 'https://api.sqrip.ch/api/update-iban';
            $iban       = $post_data['woocommerce_sqrip_iban'];

            $body = '{
                "iban": {
                    "iban": "'.$iban.'"
                }
            }';

            $response   = sqrip_remote_request($endpoint, $body, 'POST');  

            /**
             * Do something after Update IBAN | Example API Response
             * 'message' => 'IBAN updated.
             * 'type' => 'qr'
             * 'confirmation_type' => 'active'
             */

            if ( isset($response->confirmation_type) ) {

                switch ($response->confirmation_type) {
                    case 'active':
                        $message = __( 'IBAN-Änderungen: Aktive Bestätigung (siehe API Schlüssel im sqrip.ch Konto)' , 'sqrip' );
                        break;
                    
                    case 'passive':
                        $message = __( 'IBAN-Änderungen: Passive Bestätigung (siehe API Schlüssel im sqrip.ch Konto)' , 'sqrip' );
                        break;
                }
                
                $settings = new WC_Admin_Settings();

                $settings->add_message( $message );
            }  

        }

        public function process_admin_options()
        {
            $post_data  = $this->get_post_data();

            $this->check_iban_status($post_data);

            $this->update_iban($post_data);

            if ( isset($post_data['woocommerce_sqrip_test_email']) ) {

                $this->send_test_email($post_data);

                unset($_POST['woocommerce_sqrip_test_email']);

            }

            return parent::process_admin_options();
        }

        public function send_test_email($post_data)
        {
            $endpoint       = 'https://api.sqrip.ch/api/code';
            $token          = $post_data['woocommerce_sqrip_token'];
            $iban           = $post_data['woocommerce_sqrip_iban'];
            $product        = $post_data['woocommerce_sqrip_product'];
            $sqrip_due_date = $post_data['woocommerce_sqrip_due_date'];
            $address        = $post_data['woocommerce_sqrip_address'];
            $qr_reference   = $post_data['woocommerce_sqrip_qr_reference'];
            $lang           = isset($post_data['woocommerce_sqrip_lang']) ? $post_data['woocommerce_sqrip_lang'] : "de";

            // Integration By default is attachment.
            $integration    = 'attachment';

            $date               = date('Y-m-d');
            $due_date           = date('Y-m-d', strtotime($date . " + ".$sqrip_due_date." days"));

            $body = [
                "iban" => [
                    "iban"      => $iban,
                ],
                "payable_by" =>
                [
                    "name"          => "Sophie Mustermann",
                    "street"        => "Max Muster, Laurenzenvorstadt 11",
                    "postal_code"   => 5000,
                    "town"          => "Aarau",
                    "country_code"  => "CH"
                ],
                "payment_information" =>
                [
                    "currency_symbol" => 'CHF',
                    "amount" => 107.77,
                    "due_date" => $due_date,
                ],
                "lang" => $lang,
                "product" => $product,
                "source" => "woocommerce"
            ];

            if ( $qr_reference == "order_number" ) {
                $body['payment_information']['qr_reference'] = '5000';
            }

            if ($address == "individual") {

                $body['payable_to'] = array(
                    'name' => $post_data['woocommerce_sqrip_address_name'],
                    'street' => $post_data['woocommerce_sqrip_address_street'],
                    'city' => $post_data['woocommerce_sqrip_address_city'],
                    'postal_code' => $post_data['woocommerce_sqrip_address_postcode'],
                    'country_code' => $post_data['woocommerce_sqrip_address_country'],
                );

            } else {

                $body['payable_to'] = sqrip_get_payable_to_address($address);

            }

            $body = wp_json_encode($body);

            $args = [
                'method'      => 'POST',
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json'
                ],
                'body'        => $body,
                'data_format' => 'body',
            ];

            $response = wp_remote_post($endpoint, $args);

            $response_body = wp_remote_retrieve_body($response);
            $response_body = json_decode($response_body);

            $settings = new WC_Admin_Settings();

            if (isset($response_body->reference)) {
                $sqrip_pdf       =    $response_body->pdf_file;
                $sqrip_png       =    $response_body->png_file;
                $sqrip_reference =    $response_body->reference;

                // TODO: replace with attachment ID and store this in meta instead of actual file
                $sqrip_qr_pdf_attachment_id = $this->file_upload($sqrip_pdf, '.pdf', $token);
                $sqrip_qr_png_attachment_id = $this->file_upload($sqrip_png, '.png', $token);

                $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
                $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);

                $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
                $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);

                $to = get_option('admin_email');
                $subject = 'Test E-Mail von sqrip.ch';
                $body = 'Hier das eingestellte Resultat:';
                $attachments = [];

                $headers[] = 'From: the Admin-E-Mail <'.$to.'>';
                $headers[] = 'Content-Type: text/html; charset=UTF-8';

                switch ($integration) {
                    case 'body':
                        $body .= '<img src="'.$sqrip_qr_png_url.'" />';
                        break;

                    case 'attachment':
                        $attachments[] = $sqrip_qr_pdf_path;
                        break;
                    
                    default:
                        $body = '<img src="'.$sqrip_qr_png_url.'" />';
                        $attachments[] = $sqrip_qr_pdf_path;
                        break;
                }
                 
                $wp_mail = wp_mail( $to, $subject, $body, $headers, $attachments );
                
                if ( $wp_mail ) {
                    $settings->add_message( __('Test-E-Mail wurde gesendet!', 'sqrip') );
                } else {
                    $settings->add_error( __('E-Mail can not be sent, please check WP MAIL SMTP', 'sqrip') );
                }
            } else {
                $settings->add_error( 
                    sprintf( 
                        __( 'sqrip Error: %s', 'sqrip' ), 
                        esc_html( $response_body->message ) 
                    ),
                );
            }

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
            $endpoint   = 'https://api.sqrip.ch/api/code';

            // we need it to get any order detailes
            $order      = wc_get_order($order_id);
            $order_data = $order->get_data(); // order data

            ## BILLING INFORMATION:
            $order_billing_first_name   = $order_data['billing']['first_name'];
            $order_billing_last_name    = $order_data['billing']['last_name'];
            $order_billing_address      = $order_data['billing']['address_1'];
            $order_billing_address      .= $order_data['billing']['address_2'] ? ', '.$order_data['billing']['address_2'] : "";
            $order_billing_city         = $order_data['billing']['city'];
            $order_billing_postcode     = intval($order_data['billing']['postcode']);
            $order_billing_country      = $order_data['billing']['country'];
                        
            $currency_symbol    =   $order_data['currency'];
            $amount             =   floatval($order_data['total']);

            $plugin_options     = get_option('woocommerce_sqrip_settings', array());

            $sqrip_due_date     = $plugin_options['due_date'];
            $token              = $plugin_options['token'];
            $iban               = $plugin_options['iban'];
            
            $product            = $plugin_options['product'];
            $qr_reference       = $plugin_options['qr_reference'];
            $address            = $plugin_options['address'];
            $lang               = $plugin_options['lang'] ? $plugin_options['lang'] : "de";

            $date               = date('Y-m-d');
            $due_date           = date('Y-m-d', strtotime($date . " + ".$sqrip_due_date." days"));

            if ($iban == '') {
                $err_msg = esc_html( 'Please add IBAN in settings or sqrip dashboard', 'sqrip' );
                wc_add_notice($err_msg, 'error');
                return false;
            }

            if ($product == '') {
                $err_msg = esc_html( 'Please select product in settings', 'sqrip' );
                wc_add_notice($err_msg, 'error');
                return false;
            }

            $body = [
                "iban" => [
                    "iban"      => $iban,
                ],
                "payable_by" =>
                [
                    "name"          => $order_billing_first_name.' '.$order_billing_last_name,
                    "street"        => $order_billing_address,
                    "postal_code"   => $order_billing_postcode,
                    "town"          => $order_billing_city,
                    "country_code"  => $order_billing_country
                ],
                "payment_information" =>
                [
                    "currency_symbol" => $currency_symbol,
                    "amount" => $amount,
                    "due_date" => $due_date,
                ],
                "lang" => $lang,
                "product" => $product,
                "source" => "woocommerce"
            ];

            // If the user selects "Order Number" the API request will include param "qr_reference"
            if ( $qr_reference == "order_number" ) {
                $body['payment_information']['qr_reference'] = $order_id;
            }

            // if ($address == "sqrip") {
            //     $body['payable_to'] = []; 
            // } else{

            $body['payable_to'] = sqrip_get_payable_to_address($address);
            
            // }


            $body = wp_json_encode($body);

            $args = [
                'method'      => 'POST',
                'headers'     => [
                    'Content-Type' => 'application/json',
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

            if (isset($response_body->reference)) {
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

                $order->add_order_note( __('sqrip QR-Rechnung erstellt.', 'sqrip') );

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
        public function file_upload($fileurl, $type, $token = "")
        {
            include_once(ABSPATH . 'wp-admin/includes/image.php');

            $uniq_name = date('dmY') . '' . (int) microtime(true);
            $filename = $uniq_name . $type;

            // Get the path to the upload directory.
            $uploaddir = wp_upload_dir();
            $uploadfile = $uploaddir['path'] . '/' . $filename;

            // initiate context with request settings
            $plugin_options = get_option('woocommerce_sqrip_settings', array());
            $token = $token ? $token : $plugin_options['token'];
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
            $message = __( 'The sqrip plugin only supports EUR and CHF currencies!', 'sqrip' );

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
 * Adding scripts for settings sqrip page in admin
 *
 * @since 1.0
 */

add_action( 'admin_enqueue_scripts', function (){

    wp_enqueue_style('sqrip-admin', plugins_url( 'css/sqrip-admin.css', __FILE__ ), '', '1.1.1');

    if (isset($_GET['section']) && $_GET['section'] == "sqrip") {
        wp_enqueue_script('sqrip-admin', plugins_url( 'js/sqrip-admin.js', __FILE__ ), array('jquery'), '1.1.1', true);

        wp_localize_script( 'sqrip-admin', 'sqrip',
            array( 
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'txt_check_connection' => __( 'Verbindung prüfung', 'sqrip' ),
                'txt_validate_iban' => __( 'Prüfen', 'sqrip' ),
                'txt_send_test_email' =>  __( 'Test an '.get_option('admin_email').' senden', 'sqrip' )
            )
        );
    }

    global $post_type;

    if ( $post_type == 'shop_order' ) {
        wp_enqueue_script('sqrip-order', plugins_url( 'js/sqrip-order.js', __FILE__ ), array('jquery'), '1.1.1', true);

        wp_localize_script( 'sqrip-order', 'sqrip',
            array( 
                'ajax_url' => admin_url( 'admin-ajax.php' ),
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

            echo $reference_id ? '<li><b>'.__('Referenznummer','sqrip').' :</b> '.esc_html($reference_id).'</li>' : '';

            echo $pdf_file ? '<li><b>'.__( 'QR-Code PDF', 'sqrip' ).' :</b> <a target="_blank" href="'.esc_url($pdf_file).'"><span class="dashicons dashicons-media-document"></span></a></li>' : '';

            echo '<li><button class="button button-secondary sqrip-re-generate-qrcode">'.__( 'QR-Rechnung erneuern', 'sqrip' ).'</button><p>'.__('für Referenznummern auf Basis der Bestellnummer demnächst auch verfügbar', 'sqrip').'</p></li>';

            echo '</ul>';
        } else {
            echo __( 'Order not use sqrip method', 'sqrip' );
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

    $plugin_options = get_option('woocommerce_sqrip_settings', array());

    // Integration By default is attachment.
    $integration_email = array_key_exists('integration_email', $plugin_options) ? $plugin_options['integration_email'] : '';

    $array_in = array('both', 'body');

    if ( $email->id === 'customer_on_hold_order' && $payment_method === 'sqrip' && in_array($integration_email, $array_in) ) {
        $order_id = $order->id;
        $png_file = get_post_meta($order_id, 'sqrip_png_file_url', true);

        echo $png_file ? '<div class="sqrip-qrcode-png"><p>' . esc_html__( 'Verwende die untenstehende QR-Rechnung, um den ausstehenden Betrag zu bezahlen.' , 'sqrip') . '</p><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip').'" width="200"/></div>' : '';
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

        if ( $integration_order == "yes" && $pdf_file ) {
            /**
             *  Insert sqrip QR code PNG after customer details
             * 
             *  @deprecated
             *  @since 1.1.1
             */
            // echo '<div class="sqrip-qrcode-png"><p>' . __( 'Verwende die untenstehende QR-Rechnung, um den ausstehenden Betrag zu bezahlen.' , 'sqrip') . '</p><a href="' . esc_url($png_file) . '" target="_blank"><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip').'" width="300" /></a></div>';

            // Insert download button PDF
            echo '<div class="sqrip-qrcode-pdf"><p>' . __( 'Verwende die untenstehende QR-Rechnung, um den ausstehenden Betrag zu bezahlen.' , 'sqrip') . '</p><a href="' . esc_url($pdf_file) . '" ><i class="dashicons dashicons-pdf"></i></a></div>';
        }

        if ( is_wc_endpoint_url( 'view-order' ) ) {
            echo '<div class="sqrip-generate-new-qrcode"><button id="sqripGenerateNewQRCode" data-order="'.esc_attr($order_id).'" class="button button-sqrip-generate-qrcode">'. __('Neuen QR-Code generieren','sqrip'). '</a></button>';
        }

        echo '</div>';
    }
}

/**
 *  Re-Generate QR-Code in Admin Order page
 * 
 *  @since 1.0.3
 */

add_filter( 'wp_insert_post_data' , function ( $data , $postarr, $unsanitized_postarr ) {
    
    if ( 'shop_order' === $data['post_type'] && isset($postarr['_sqrip_regenerate_qrcode']) )
    {
        // sqrip API URL
        $endpoint   = 'https://api.sqrip.ch/api/code';

        $order      = wc_get_order($postarr['ID']);
        $order_data = $order->get_data(); // order data

        ## BILLING INFORMATION:
        $order_billing_first_name   = $postarr['_billing_first_name'];
        $order_billing_last_name    = $postarr['_billing_last_name'];
        $order_billing_address      = $postarr['_billing_address_1'];
        $order_billing_address      .= $postarr['_billing_address_2'] ? ', '.$postarr['_billing_address_2'] : "";
        $order_billing_city         = $postarr['_billing_city'];
        $order_billing_postcode     = intval($postarr['_billing_postcode']);
        $order_billing_country      = $postarr['_billing_country'];
                    
        $currency_symbol    =   $order_data['currency'];
        $amount             =   floatval($order_data['total']);

        $plugin_options     = get_option('woocommerce_sqrip_settings', array());

        $sqrip_due_date     = $plugin_options['due_date'];
        $token              = $plugin_options['token'];
        $iban               = $plugin_options['iban'];
        
        $qr_reference       = $plugin_options['qr_reference'];
        $address            = $plugin_options['address'];
        $product            = $plugin_options['product'];
        $lang               = $plugin_options['lang'] ? $plugin_options['lang'] : "de";

        $date               = date('Y-m-d');
        $due_date           = date('Y-m-d', strtotime($date . " + ".$sqrip_due_date." days"));

        $body = [
            "iban" => [
                "iban"      => $iban,
            ],
            "payable_by" =>
            [
                "name"          => $order_billing_first_name.' '.$order_billing_last_name,
                "street"        => $order_billing_address,
                "postal_code"   => $order_billing_postcode,
                "town"          => $order_billing_city,
                "country_code"  => $order_billing_country
            ],
            "payment_information" =>
            [
                "currency_symbol" => $currency_symbol,
                "amount" => $amount,
                "due_date" => $due_date,
            ],
            "lang" => $lang,
            "product" => $product,
            "source" => "woocommerce"
        ];

        // If the user selects "Order Number" the API request will include param "qr_reference"
        if ( $qr_reference == "order_number" ) {
            $body['payment_information']['qr_reference'] = $data['ID'];
        }

        $body['payable_to'] = sqrip_get_payable_to_address($address);
        
        $body = wp_json_encode($body);

        $args = [
            'method'      => 'POST',
            'headers'     => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json'
            ],
            'body'        => $body,
            'data_format' => 'body',
        ];

        $response = wp_remote_post($endpoint, $args);

        $response_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($response_body);

        if ( is_wp_error($response) ) {

            $order->add_order_note( 
                sprintf( 
                    __( 'Error: %s', 'sqrip' ), 
                    esc_html( $response_body->message ) 
                ) 
            );

        } else  {

            if (isset($response_body->reference)) {
                $sqrip_pdf       =    $response_body->pdf_file;
                $sqrip_png       =    $response_body->png_file;
                $sqrip_reference =    $response_body->reference;

                // TODO: replace with attachment ID and store this in meta instead of actual file
                $sqrip_class_payment = new WC_Sqrip_Payment_Gateway;

                $sqrip_qr_pdf_attachment_id = $sqrip_class_payment->file_upload($sqrip_pdf, '.pdf');
                $sqrip_qr_png_attachment_id = $sqrip_class_payment->file_upload($sqrip_png, '.png');

                $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
                $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);

                $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
                $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);

                $order->add_order_note( __('sqrip payment QR-Code are re-generated succesfully', 'sqrip') );

                $order->update_meta_data('sqrip_reference_id', $sqrip_reference);

                $order->update_meta_data('sqrip_pdf_file_url', $sqrip_qr_pdf_url);
                $order->update_meta_data('sqrip_pdf_file_path', $sqrip_qr_pdf_path);

                $order->update_meta_data('sqrip_png_file_url', $sqrip_qr_png_url);
                $order->update_meta_data('sqrip_png_file_path', $sqrip_qr_png_path);

                $order->save();

            } else {

                $order->add_order_note( 
                    sprintf( 
                        __( 'sqrip Error: %s', 'sqrip' ), 
                        esc_html( $response_body->message ) 
                    ) 
                );

            }

        }
        
    }

    return $data;

}, 99, 3);

