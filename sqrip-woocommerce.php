<?php

/**
 * Plugin Name:             sqrip – Swiss QR Invoice
 * Plugin URI:              https://sqrip.ch/
 * Description:             sqrip extends WooCommerce payment options for Swiss stores and Swiss customers with the new QR payment parts.
 * Version:                 1.3
 * Author:                  netmex digital gmbh
 * Author URI:              #
 */

defined( 'SQRIP_QR_CODE_ENDPOINT' ) or define( 'SQRIP_QR_CODE_ENDPOINT', 'https://api.sqrip.ch/api/code' );

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
            $this->method_description = __( 'sqrip creates QR codes, A6 QR payment parts and A4 QR invoices for billing in Switzerland', 'sqrip' ); // will be displayed on the options page

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
            $this->return_enabled = $this->get_option('return_enabled');
            $this->return_token = $this->get_option('return_token');

            // Add support for refunds if option is set
            if($this->return_enabled == "yes") {
                $this->supports[] = 'refunds';
            }

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields()
        {
            require ('inc/countries-array.php');

            $address_woocommerce = sqrip_get_payable_to_address_txt('woocommerce');
            $address_sqrip = sqrip_get_payable_to_address_txt('sqrip');

            $address_options = [];

            if ($address_sqrip) {
                $address_options['sqrip'] = __( 'from sqrip account: '.esc_attr($address_sqrip) , 'sqrip' );
            }

            if ($address_woocommerce) {
                $address_options['woocommerce'] = __( 'from WooCommerce: '.esc_attr($address_woocommerce) , 'sqrip' );
            }

            $address_options['individual'] = __( 'Third address' , 'sqrip' );
            
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => __( 'Enable/Disable', 'sqrip' ),
                    'label'       => __( 'Enable QR invoices with sqrip API', 'sqrip' ),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'token' => array(
                    'title'       => __( 'API key' , 'sqrip' ),
                    'type'        => 'textarea',
                    'description' => __( 'Open an account at <a href="https://sqrip.ch" target="_blank">https://sqrip.ch</a>, create an API key, copy and paste it here. Done!', 'sqrip' ),
                ),
                'title' => array(
                    'title'       => __( 'Payment method name', 'sqrip' ),
                    'type'        => 'text',
                    'description' => __( 'Swiss QR invoices with sqrip', 'sqrip' ),
                    'default'     => 'QR-Rechnung',
                ),
                'description' => array(
                    'title'       => __( 'Description', 'sqrip' ),
                    'type'        => 'textarea',
                    'description' => __( 'Description of what the customer can expect from this payment option.', 'sqrip' ),
                ),
                'section_payment_recevier' => array(
                    'title' => __( 'Payee', 'sqrip' ),
                    'type' => 'section',
                ),
                'address' => array(
                    'title' => __( 'Address', 'sqrip' ),
                    'type' => 'select',
                    'description' => __( 'The address to appear on the QR invoice', 'sqrip' ),
                    'options' => $address_options
                ),
                'address_name' => array(
                    'title' => __( 'Name', 'sqrip' ),
                    'type' => 'text',
                    'class' => 'sqrip-address-individual',
                ),
                'address_street' => array(
                    'title' => __( 'Street', 'sqrip' ),
                    'type' => 'text',
                    'class' => 'sqrip-address-individual',
                ),
                'address_postcode' => array(
                    'title' => __( 'ZIP CODE', 'sqrip' ),
                    'type' => 'text',
                    'class' => 'sqrip-address-individual',
                ),
                'address_city' => array(
                    'title' => __( 'City', 'sqrip' ),
                    'type' => 'text',
                    'class' => 'sqrip-address-individual',
                ),
                'address_country' => array(
                    'title' => __( 'Country code', 'sqrip' ),
                    'type' => 'select',
                    'class' => 'sqrip-address-individual',
                    'options' => $countries_list
                ),
                'iban' => array(
                    'title' => __( '(QR-)IBAN', 'sqrip' ),
                    'type' => 'text',
                    'description' => __( '(QR-)IBAN of the account to which the transfer is to be made', 'sqrip' ),
                ),
                'qr_reference' => array(
                    'title' => __( 'Basis of the (QR) reference number', 'sqrip' ),
                    'type' => 'radio',
                    'options' => array(
                        'random' => __( 'random number', 'sqrip' ),
                        'order_number' => __('Order number', 'sqrip' ),
                    ),
                ),
                'due_date' => array(
                    'title'       => __( 'Maturity (Today in x days)', 'sqrip' ),
                    'type'        => 'number',
                    'default'     => 30,
                    'css'         => "width:70px"
                ),
                'section_invoice_settings' => array(
                    'title' => __('QR Invoice Display', 'sqrip'),
                    'type'        => 'section',
                ),
                'integration_order' => array(
                    'title'       => __( 'on the confirmation page', 'sqrip' ),
                    'label'       => __( 'Offer QR invoice for download', 'sqrip' ),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'yes'
                ),
                'product' => array(
                    'title'         => __( 'in the confirmation e-mail', 'sqrip' ),
                    'type'          => 'select',
                    'description' => __( 'Select format', 'sqrip' ),
                    'options'       => array(
                        'Full A4'   => __('on a blank A4 PDF', 'sqrip' ),
                        'Invoice Slip' => __('only the A6 payment part as PDF', 'sqrip' ),
                    )
                ),
                'lang' => array(
                    'title'         => __( 'Language', 'sqrip' ),
                    'type'          => 'select',
                    'options'       => array(
                        'de'    => __( 'German', 'sqrip' ),
                        'fr'    => __( 'French', 'sqrip' ),
                        'it'    => __( 'Italian', 'sqrip' ),
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
                'section_return_settings' => array(
	                'title' => __('Rückerstattungen', 'sqrip'),
	                'type'        => 'section',
                ),
                'return_enabled' => array(
	                'title'       => __( 'Rückerstattungen Aktivieren/Deaktivieren', 'sqrip' ),
	                'label'       => __( 'Aktiviere QR-Rechnungen für Rückerstattungen mit der sqrip API', 'sqrip' ),
	                'type'        => 'checkbox',
	                'description' => 'Wenn du diese Option aktivierst, generiert die sqrip API im Fall einer Rückerstattung per WooCommerce einen QR Code, welchen du für die Überweisung des Betrags an den Kunden verwenden kannst.',
	                'default'     => 'no'
                ),
                'return_token' => array(
	                'title'       => __( 'API Schlüssel für Rückerstattungen' , 'sqrip' ),
	                'type'        => 'textarea',
	                'description' => __( 'Aus Sicherheitsgründen muss für die Rückerstattungen zwingend ein sqrip API Schlüssel verwendet werden, bei welchem die IBAN Überprüfung <strong>deaktiviert</strong> ist.', 'sqrip' ),
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
                                <input id="<?php echo esc_attr($ip_id); ?>" type="radio" name="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $option_key ); ?>" <?php echo esc_attr($checked); ?> required="required" />
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

                $settings->add_error( __( 'The (QR-)IBAN has been changed. Please confirm the new (QR-)IBAN in your sqrip.ch account.', 'sqrip' ) );
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
                        $message = __( 'IBAN changes: Active confirmation (see API key in sqrip.ch account).' , 'sqrip' );
                        break;
                    
                    case 'passive':
                        $message = __( 'IBAN changes: Passive confirmation (see API key in sqrip.ch account)' , 'sqrip' );
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
            $endpoint       = SQRIP_QR_CODE_ENDPOINT;
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
                    "street"        => "Laurenzenvorstadt 11",
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
                'file_type' => 'pdf',
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
                // $sqrip_png       =    $response_body->png_file;
                $sqrip_reference =    $response_body->reference;

                // TODO: replace with attachment ID and store this in meta instead of actual file
                $sqrip_qr_pdf_attachment_id = $this->file_upload($sqrip_pdf, '.pdf', $token);
                // $sqrip_qr_png_attachment_id = $this->file_upload($sqrip_png, '.png', $token);

                $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
                $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);

                // $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
                // $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);

                $to = get_option('admin_email');
                $subject = 'Test E-Mail von sqrip.ch';
                $body = 'Hier das eingestellte Resultat:';
                $attachments = [];

                $headers[] = 'From: sqrip Test-Mail <'.$to.'>';
                $headers[] = 'Content-Type: text/html; charset=UTF-8';

                $attachments[] = $sqrip_qr_pdf_path;

                // switch ($integration) {
                //     case 'body':
                //         $body .= '<img src="'.$sqrip_qr_png_url.'" />';
                //         break;

                //     case 'attachment':
                //         $attachments[] = $sqrip_qr_pdf_path;
                //         break;
                    
                //     default:
                //         $body = '<img src="'.$sqrip_qr_png_url.'" />';
                //         $attachments[] = $sqrip_qr_pdf_path;
                //         break;
                // }
                 
                $wp_mail = wp_mail( $to, $subject, $body, $headers, $attachments );
                
                if ( $wp_mail ) {
                    $settings->add_message( __('Test email has been sent!', 'sqrip') );
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

	    /**
         * Process the refund by creating another qr code that the owner of the
         * shop can use to refund the money to the customer
	     *
         * @param int $order_id
	     * @param null $amount
	     * @param string $reason
	     *
	     * @return bool|WP_Error
	     */
	    public function process_refund($order_id, $amount = null, $reason = "") {

	        global $woocommerce;
	        $order      = wc_get_order($order_id);
	        $order_data = $order->get_data(); // order data

	        $currency_symbol    =   $order_data['currency'];

	        $body = sqrip_prepare_qr_code_request_body($currency_symbol, $amount, strval($order_id));

            // change product to Credit to just get QR Code
            $body['product'] = 'Credit';

            // replace sqrip IBAN with IBAN of customer
            $user = $order->get_user();
            $iban = sqrip_get_customer_iban($user);

            if(!$iban) {
	            // Add note to the order for your reference
	            $order->add_order_note(
		            __( "IBAN des Kunden wurde nicht gefunden. Stelle sicher, dass sie im Meta-Feld 'iban_num' hinterlegt ist.", 'sqrip' )
	            );
	            return false;
            }

            // TODO: do we need to handle QR ibans and simple ibans differently?
            $body['iban']['iban'] = $iban;

	        // we need to switch payable_to and payable_by addresses
            // $address = sqrip_get_plugin_option('address');
	        $payable_by = sqrip_get_payable_to_address('woocommerce');
	        $payable_to = sqrip_get_billing_address_from_order($order);

	        // since the two addresses have different names for the
	        // city / town field we need to switch them
	        $payable_by['town'] = $payable_by['city'];
	        unset($payable_by['city']);

	        $payable_to['city'] = $payable_to['town'];
	        unset($payable_to['town']);

	        $body['payable_by'] = $payable_by;
	        $body['payable_to'] = $payable_to;

		    $token = sqrip_get_plugin_option('return_token');
		    if(!$token) {
			    $order->add_order_note(
				    __( 'Error: Es wurde kein API Schlüssel für die Rückerstattungen angegeben. Bitte ergänze dies in den sqrip Plugin Einstellungen.', 'sqrip' )
			    );
			    return false;
		    }

	        $args = sqrip_prepare_remote_args($body, 'POST', $token);
	        $response = wp_remote_post(SQRIP_QR_CODE_ENDPOINT, $args);

	        $status_code = $response['response']['code'];

	        if ($status_code !== 200) {
		        // Transaction was not successful
		        $err_msg = explode(",", $response['body']);
		        $err_msg = trim(strstr($err_msg[0], ':'), ': "');

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
		        $sqrip_png       =    $response_body->png_file;
                $sqrip_qr_png_attachment_id = $this->file_upload($sqrip_png, '.png');

		        $order->add_order_note( __('sqrip QR-Code für Rückerstattung erstellt.', 'sqrip') );

		        $order->update_meta_data('sqrip_refund_qr_attachment_id', $sqrip_qr_png_attachment_id);
		        $order->save(); // without calling save() the meta data is not updated

		        return true;
	        } else {
		        // Add note to the order for your reference
		        $order->add_order_note(
			        sprintf(
				        __( 'Error: %s', 'sqrip' ),
				        esc_html( $response_body->message )
			        )
		        );

		        return false;
	        }
        }

        /*
         *  Processing payment
         */
        public function process_payment($order_id)
        {
            global $woocommerce;
            // sqrip API URL
            $endpoint   = SQRIP_QR_CODE_ENDPOINT;

            // we need it to get any order details
            $order      = wc_get_order($order_id);
            $order_data = $order->get_data(); // order data

            $currency_symbol    =   $order_data['currency'];
            $amount             =   floatval($order_data['total']);

            $address = sqrip_get_plugin_option('address');

            $body = sqrip_prepare_qr_code_request_body($currency_symbol, $amount, strval($order_id));
            $body["payable_by"] = sqrip_get_billing_address_from_order($order);
            $body['payable_to'] = sqrip_get_payable_to_address($address);

	        $args = sqrip_prepare_remote_args($body, 'POST');
	        $response = wp_remote_post(SQRIP_QR_CODE_ENDPOINT, $args);

            $status_code = $response['response']['code'];

            if ($status_code !== 200) {
                // Transaction was not succesful
                // Add notice to the cart
                $err_msg = explode(",", $response['body']);
                $err_msg = trim(strstr($err_msg[0], ':'), ': "');

                wc_add_notice( 
                    sprintf( 
                        __( 'sqrip Payment Error: %s', 'sqrip' ),
                        esc_html( $err_msg ) ), 
                    'error' 
                );

                // Add note to the order for your reference
                $order->add_order_note( 
                    sprintf( 
                        __( 'sqrip Payment Error: %s', 'sqrip' ),
                        esc_html($err_msg) 
                    ) 
                );

                return false;
            }

            $response_body = wp_remote_retrieve_body($response);
            $response_body = json_decode($response_body);

            if (isset($response_body->reference)) {
                $sqrip_pdf       =    $response_body->pdf_file;
                // $sqrip_png       =    $response_body->png_file;
                $sqrip_reference =    $response_body->reference;

                // TODO: replace with attachment ID and store this in meta instead of actual file
                $sqrip_qr_pdf_attachment_id = $this->file_upload($sqrip_pdf, '.pdf');
                // $sqrip_qr_png_attachment_id = $this->file_upload($sqrip_png, '.png');

                $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
                $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);

                // $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
                // $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);

                $order->add_order_note( __('sqrip QR Invoice created.', 'sqrip') );

                $order->update_meta_data('sqrip_reference_id', $sqrip_reference);

                $order->update_meta_data('sqrip_pdf_file_url', $sqrip_qr_pdf_url);
                $order->update_meta_data('sqrip_pdf_file_path', $sqrip_qr_pdf_path);

                // $order->update_meta_data('sqrip_png_file_url', $sqrip_qr_png_url);
                // $order->update_meta_data('sqrip_png_file_path', $sqrip_qr_png_path);

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
        $message = __( 'Your website is currently unable to upload a PDF.', 'sqrip' );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    if ( false !== ( $msg = get_transient( "sqrip_regenerate_qrcode_errors" ) ) && $msg) {

        $class = 'notice notice-error is-dismissible';

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $msg );
       
        delete_transient( "sqrip_regenerate_qrcode_errors" );
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
                'txt_check_connection' => __( 'Connection test', 'sqrip' ),
                'txt_validate_iban' => __( 'Check', 'sqrip' ),
                'txt_send_test_email' => sprintf( 
                    __( 'Send test to %s', 'sqrip' ), 
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
                'ajax_refund_unpaid_nonce' => wp_create_nonce( 'sqrip-mark-refund-unpaid' )
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

            echo $reference_id ? '<li><b>'.__('Reference number','sqrip').' :</b> '.esc_html($reference_id).'</li>' : '';

            echo $pdf_file ? '<li><b>'.__( 'QR-Code PDF', 'sqrip' ).' :</b> <a target="_blank" href="'.esc_url($pdf_file).'"><span class="dashicons dashicons-media-document"></span></a></li>' : '';

            echo '<li><button class="button button-secondary sqrip-re-generate-qrcode">'.__( 'Renew QR Invoice', 'sqrip' ).'</button><p>'.__('for reference numbers based on the order number soon also available', 'sqrip').'</p></li>';

            echo '</ul>';
        } else {
            echo __( 'Payment is not made with sqrip.', 'sqrip' );
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

        echo $png_file ? '<div class="sqrip-qrcode-png"><p>' . esc_html__( 'Use the QR invoice below to pay the outstanding balance.' , 'sqrip') . '</p><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip').'" width="200"/></div>' : '';
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
            // echo '<div class="sqrip-qrcode-png"><p>' . __( 'Use the QR invoice below to pay the outstanding balance.' , 'sqrip') . '</p><a href="' . esc_url($png_file) . '" target="_blank"><img src="' . esc_url($png_file) . '" alt="'.esc_attr('sqrip QR-Code','sqrip').'" width="300" /></a></div>';

            // Insert download button PDF
            echo '<div class="sqrip-qrcode-pdf"><p>' . __( 'Use the QR invoice below to pay the outstanding balance.' , 'sqrip') . '</p><a href="' . esc_url($pdf_file) . '" ><i class="dashicons dashicons-pdf"></i></a></div>';
        }

        if ( is_wc_endpoint_url( 'view-order' ) ) {
            echo '<div class="sqrip-generate-new-qrcode"><button id="sqripGenerateNewQRCode" data-order="'.esc_attr($order_id).'" class="button button-sqrip-generate-qrcode">'. __('Generate new QR code','sqrip'). '</a></button>';
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

        $body = sqrip_prepare_qr_code_request_body($currency_symbol, $amount, $data['ID']);

        $body["payable_by"] = [
		      "name"          => $order_billing_first_name.' '.$order_billing_last_name,
		      "street"        => $order_billing_address,
		      "postal_code"   => $order_billing_postcode,
		      "town"          => $order_billing_city,
		      "country_code"  => $order_billing_country
	      ];

        $address = sqrip_get_plugin_option('address');

        $body['payable_to'] = sqrip_get_payable_to_address($address);

        $args = sqrip_prepare_remote_args($body, 'POST');

        $response = wp_remote_post(SQRIP_QR_CODE_ENDPOINT, $args);

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

                $order->add_order_note( __('sqrip payment QR code is successfully regenerated', 'sqrip') );

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
                    $error_goto = 'Please add correct your address at <a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sqrip' ) . '" aria-label="' . esc_attr__( 'sqrip settings', 'sqrip' ) . '">' . esc_html__( 'sqrip Settings', 'sqrip' ) . '</a>';

                    set_transient('sqrip_regenerate_qrcode_errors', sprintf( 
                        __( '<b>Renew QR Invoice error:</b> %s <p>%s</p><p>%s</p>', 'sqrip' ), 
                        esc_html( $response_body->message ),
                        esc_html( $errors_output ),
                        $error_goto
                    ), 60);
                }

                $order->add_order_note( 
                    sprintf( 
                        __( 'Renew QR Invoice error: %s <p>%s</p>', 'sqrip' ), 
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
    $title = __("QR Code anzeigen",'sqrip');
    $hidden_title = __("QR Code verbergen",'sqrip');

    $paid_title = __("Als bezahlt markieren", 'sqrip');
    $unpaid_title = __("Als unbezahlt markieren", 'sqrip');

	$paid_status = __("bezahlt am", 'sqrip');
	$unpaid_status = __("unbezahlt", 'sqrip');

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
        <h3><?php _e("Sqrip Refund Information", "sqrip"); ?></h3>
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