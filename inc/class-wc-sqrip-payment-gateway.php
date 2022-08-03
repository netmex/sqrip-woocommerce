<?php     

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
        $this->method_title = __( 'sqrip – Swiss QR-Invoice Payment' , 'sqrip-swiss-qr-invoice' );
        $this->method_description = __( 'sqrip – Modern and clever tools for the most widely used payment method in Switzerland: the bank transfers. ', 'sqrip-swiss-qr-invoice' ); // will be displayed on the options page

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

        require __DIR__ . '/countries-array.php';
    
        $this->form_fields = array(
            'tabs' => array(
                'type'  => 'tab',
                'tabs' => [
                    [
                        'id' => 'qrinvoice',
                        'title' => __( 'QR-Invoice', 'sqrip-swiss-qr-invoice' ),
                        'class' => 'active',
                    ],
                    [
                        'id' => 'reminders',
                        'title' => __('Reminders', 'sqrip-swiss-qr-invoice'),
                        'class' => '',
                        'description' => __( 'If an invoice has not been paid within the defined time, you can send a reminder to the client', 'sqrip-swiss-qr-invoice'),
                    ],
                    [
                        'id' => 'comparison',
                        'title' => __( 'Payment Comparison', 'sqrip-swiss-qr-invoice' ),
                        'description' => __('<p>sqrip offers two ways to synchronize customer made payments to your bank account with your WooCommerce store:</p>

                        a) Manually: By uploading the camt053-file which has been downloaded by you from your online-banking; </br>

                        b) Automatic: By using a direct, safe connection to your bank account via EBICS.

                        <p>In order to activate one of these services, please go to your account on <a href="https://sqrip.ch" target="_blank">sqrip.ch</a> for further details.</p>', 'sqrip-swiss-qr-invoice' ),
                        'class' => '',
                    ],
                    [
                        'id' => 'refunds',
                        'title' => __('Refunds', 'sqrip-swiss-qr-invoice'),
                        'class' => '',
                    ]
                ]
            ),
            'enabled' => array(
                'title'       => __( 'Enable/Disable', 'sqrip-swiss-qr-invoice' ),
                'label'       => __( 'Enable QR invoices with sqrip API', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'class'       => 'qrinvoice-tab'  
            ),
            'token' => array(
                'title'       => __( 'API key' , 'sqrip-swiss-qr-invoice' ),
                'type'        => 'textarea',
                'description' => __( 'Open an account at <a href="https://sqrip.ch" target="_blank">https://sqrip.ch</a>, create an API key, copy and paste it here. Done!', 'sqrip-swiss-qr-invoice' ),
                'class'       => 'qrinvoice-tab'  
            ),
            'title' => array(
                'title'       => __( 'Payment method name', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'text',
                'description' => __( 'Swiss QR invoices with sqrip', 'sqrip-swiss-qr-invoice' ),
                'default'     => 'QR-Rechnung',
                'class'       => 'qrinvoice-tab'  
            ),
            'description' => array(
                'title'       => __( 'Description', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'textarea',
                'description' => __( 'Description of what the customer can expect from this payment option.', 'sqrip-swiss-qr-invoice' ),
                'class'       => 'qrinvoice-tab'  
            ),
            'expired_date' => array(
                'title'       => __( 'Delete QR-Invoices automatically (after X days)', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'number',
                'description' => __( 'When after creation a invoice will be deleted and then deleted from the media library.', 'sqrip-swiss-qr-invoice' ),
                'default'     => 30,
                'css'         => "width:70px",
                'class'       => 'qrinvoice-tab'  
            ),
            'section_payee' => array(
                'title'         => __('Payee', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'section',
                'class'       => 'qrinvoice-tab' 
            ),
            'address' => array(
                'title' => __( 'Address', 'sqrip-swiss-qr-invoice' ),
                'type' => 'select',
                'description' => __( 'The address to appear on the QR invoice', 'sqrip-swiss-qr-invoice' ),
                'options'     => $this->get_address_options(),
                'class'       => 'qrinvoice-tab'  
            ),
            'address_name' => array(
                'title' => __( 'Name', 'sqrip-swiss-qr-invoice' ),
                'type' => 'text',
                'class' => 'sqrip-address-individual',
            ),
            'address_street' => array(
                'title' => __( 'Street', 'sqrip-swiss-qr-invoice' ),
                'type' => 'text',
                'class' => 'sqrip-address-individual',
            ),
            'address_postcode' => array(
                'title' => __( 'ZIP CODE', 'sqrip-swiss-qr-invoice' ),
                'type' => 'text',
                'class' => 'sqrip-address-individual',
            ),
            'address_city' => array(
                'title' => __( 'City', 'sqrip-swiss-qr-invoice' ),
                'type' => 'text',
                'class' => 'sqrip-address-individual',
            ),
            'address_country' => array(
                'title' => __( 'Country code', 'sqrip-swiss-qr-invoice' ),
                'type' => 'select',
                'class' => 'sqrip-address-individual',
                'options' => $countries_list
            ),
            'iban' => array(
                'title' => __( '(QR-)IBAN', 'sqrip-swiss-qr-invoice' ),
                'type' => 'text',
                'description' => __( '(QR-)IBAN of the account to which the transfer is to be made', 'sqrip-swiss-qr-invoice' ),
                'class'       => 'qrinvoice-tab'  
            ),
            'qr_reference' => array(
                'title' => __( 'Basis of the (QR) reference number', 'sqrip-swiss-qr-invoice' ),
                'type'  => 'radio',
                'options' => array(
                    'random'        => __( 'random number', 'sqrip-swiss-qr-invoice' ),
                    'order_number'  => __('Order number', 'sqrip-swiss-qr-invoice' ),
                ),
                'class' => 'qrinvoice-tab'  
            ),
            'section_qr_invoice' => array(
                'title'        => __('QR Invoice Display', 'sqrip-swiss-qr-invoice' ),
                'type'         => 'section',
                'class'        => 'qrinvoice-tab' 
            ),
            'due_date' => array(
                'title'       => __( 'Maturity (Today in x days)', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'number',
                'default'     => 30,
                'css'         => "width:70px",
                'class'       => 'qrinvoice-tab'  
            ),
            'integration_order' => array(
                'title'       => __( 'on the confirmation page', 'sqrip-swiss-qr-invoice' ),
                'label'       => __( 'Offer QR invoice for download', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes',
                'class'       => 'qrinvoice-tab'  
            ),
            'additional_information' => array(
                'title'       => __( 'Additional Information' , 'sqrip-swiss-qr-invoice' ),
                'type'        => 'textarea',
                'class'       => 'qrinvoice-tab sqrip-additional-information',
                'maxlength'   => 140,
                'default'     => __("Due date: [due_date format=\"%Y-%m-%d\"]\nOrder: #[order_number]\nThank you for your purchase!","sqrip-swiss-qr-invoice"),
                'description' => __( 'Will be displayed on the QR invoice in the section “Additional information”. <br>The following short codes are available:<br>[order_number] the order number.<br>[due_date format="%Y-%m-%d"] to insert the due date of the invoice.<br><a href="https://www.php.net/strftime" target="_blank">Supported formats</a> are:<br>%Y-%m-%d -> 2022-04-06<br>%m.%d.%y -> 06.31.22<br>%d. %B %Y -> 06. April 2022<br>%e. %b %Y -> 6. Apr 2022', 'sqrip-swiss-qr-invoice' ),
            ),
            'email_attached' => array(
                'title'         => __( 'Attach QR-Invoice to', 'sqrip-swiss-qr-invoice' ),
                'description'   => __( 'Select email template to which the QR-invoice is attached.', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'select',
                'options'       => sqrip_get_wc_emails(),
                'class'         => 'qrinvoice-tab'  
            ),
            'product' => array(
                'title'         => __( 'Format', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'select',
                'description'   => '',
                'options'       => array(
                    'Full A4'   => __('on a blank A4 PDF', 'sqrip-swiss-qr-invoice' ),
                    'Invoice Slip' => __('only the A6 payment part as PDF', 'sqrip-swiss-qr-invoice' ),
                ),
                'class'       => 'qrinvoice-tab'  
            ),
            'lang' => array(
                'title'         => __( 'Language', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'select',
                'options'       => array(
                    'de'    => __( 'German', 'sqrip-swiss-qr-invoice' ),
                    'fr'    => __( 'French', 'sqrip-swiss-qr-invoice' ),
                    'it'    => __( 'Italian', 'sqrip-swiss-qr-invoice' ),
                    'en'    => __( 'English', 'sqrip-swiss-qr-invoice' )
                ),
                'default' => 'de',
                'class'       => 'qrinvoice-tab'  
            ),
            'test_email' => array(
                'title'       => '',
                'type'        => 'checkbox',
                'default'     => 'no',
                'css'         => 'visibility: hidden'  
            ),
            'enabled_reminder' => array(
                'title'       => __( 'Turn on/off Reminders', 'sqrip-swiss-qr-invoice' ),
                'label'       => __( 'Active', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'checkbox',
                'default'     => 'no',
                'class'       => 'reminders-tab'  
            ),
            'status_reminders' => array(
                'title'         => __( 'Status of awaiting payment orders', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'select',
                'options'       => wc_get_order_statuses(),
                'default' => 'wc-pending',
                'description' => __('What is the order status that waits for confirmation of made payment to your bank account?
                We will only check for payments at the bank account for these statuses.', 'sqrip-swiss-qr-invoice' ),
                'class'       => 'reminders-tab'  
            ),

            'due_reminder' => array(
                'title'       => __( 'Pas Due Reminder', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'number',
                'default'     => 1,
                'description' => __( 'How many days after the due date (see Tab "QR-Invoice", "Maturity") an e-mail should be send to the client.', 'sqrip-swiss-qr-invoice' ),
                'css'         => "width:70px",
                'class'       => 'reminders-tab'  
            ),
            'email_reminder' => array(
                'title' => __( 'Email Template', 'sqrip-swiss-qr-invoice' ),
                'description' => __( 'Choose the e-mail template to be used as reminder.', 'sqrip-swiss-qr-invoice' ),
                'type' => 'select',
                'options' => sqrip_get_wc_emails(),
                'class'       => 'reminders-tab'  
            ),
            'active_service' => array(
                'title'       => __( 'Connect services', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'info',
                'label'       => $this->get_active_service('active_service_txt'),
                'class'       => 'comparison-tab '.$this->get_active_service('active_service'), 
            ),
            'remaining_credits' => array(
                'title'       => __( 'Remaining Credits', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'info',
                'label'       => $this->get_active_service('remaining_credits'),
                'class'       => 'comparison-tab' 
            ),
            'section_general_settings' => array(
                'title'         => __('General Settings', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'section',
                'class'       => 'comparison-tab' 
            ),
            'status_awaiting' => array(
                'title'         => __( 'Status of awaiting payment orders', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'select',
                'options'       => wc_get_order_statuses(),
                'default' => 'wc-pending',
                'description' => __('What is the order status that waits for confirmation of made payment to your bank account?
                We will only check for payments at the bank account for these statuses.', 'sqrip-swiss-qr-invoice' ),
                'class'       => 'comparison-tab'  
            ),
            'status_completed' => array(
                'title'         => __( 'Completed Orders Status', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'select',
                'options'       => wc_get_order_statuses(),
                'placeholder' => 'Select Status',
                'default' => 'wc-completed',
                'description' => __('To what order status should we change your order, once the payment has been confirmed?', 'sqrip-swiss-qr-invoice' ),
                'class'       => 'comparison-tab'  
            ),
            'section_manual_comparison' => array(
                'title'         => __('Manual Comparison - camt053', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'section',
                'class'       => 'comparison-tab' 
            ),
            'camt_service' => array(
                'title'       => __( 'Enable/Disable', 'sqrip-swiss-qr-invoice' ),
                'label'       => __( 'Enable camt053 files', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'class'       => 'comparison-tab'  
            ),
            'camt053_file' => array(
                'title'       => __( 'Upload camt053 File', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'file',
                'description' => __('Download from your online banking your latest camt053-file of your bank account that receives client payments. Be sure that it reaches back before the last day you did this comparison.', 'sqrip-swiss-qr-invoice' ),
                'default'     => 'no',
                'class'       => 'comparison-tab camt-service'  
            ),
            'section_automatic_comparison' => array(
                'title'         => __('Automatic Comparaison - EBICS', 'sqrip-swiss-qr-invoice' ),
                'type'          => 'section',
                'class'       => 'comparison-tab' 
            ),
            'ebics_service' => array(
                'title'       => __( 'Payment verification', 'sqrip-swiss-qr-invoice' ),
                'label'       => __( 'Enable Payment Verification', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'class'       => 'comparison-tab'  
            ),
            'payment_frequence' => array(
                'title'       => __( 'Payment Fréquence', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'multiselect',
                'options'       => array(
                    'monday'        => __( 'Monday' , 'sqrip-swiss-qr-invoice' ),
                    'tuesday'       => __( 'Tuesday' , 'sqrip-swiss-qr-invoice' ),
                    'wednesday'     => __( 'Wednesday' , 'sqrip-swiss-qr-invoice' ),
                    'thursday'      => __( 'Thursday' , 'sqrip-swiss-qr-invoice' ),
                    'friday'        => __( 'Friday' , 'sqrip-swiss-qr-invoice' ),
                    'saturday'      => __( 'Saturday' , 'sqrip-swiss-qr-invoice' ),
                    'sunday'        => __( 'Sunday' , 'sqrip-swiss-qr-invoice' ),
                    
                ),
                'class'         => 'comparison-tab ebics-service'  
            ),
            'payment_frequence_time' => array(
                'type'        => 'multiselect',
                'options'     => array(
                    '04:00'      => '04:00',
                    '08:00'      => '08:00',
                    '13:00'      => '13:00',
                    '18:00'      => '18:00',
                    '21:00'      => '21:00',
                ),
                 'description'   => __( 'Select the days and the time when sqrip should execute a comparison of the awaiting payment orders with your bank account.</br>
                    We charge your account for every comparison made.', 'sqrip-swiss-qr-invoice' ),
                'desc_tip'      => __('Based on your selection, your weekly cost for this service is X credits.', 'sqrip-swiss-qr-invoice'),
                'class'       => 'comparison-tab ebics-service'  
            ),

            'forward_payments' => array(
                'title'       => __( 'Forward Payments', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'info',
                'label'        => sprintf( 
                    __( 'You forward your payments to %s', 'sqrip-swiss-qr-invoice' ), 
                    $this->get_fund_management('main_account'),
                ),
                'description'       => __( 'In order to forward the payments from your incoming bank account to your main bank account, please configure this service on sqrip.ch', 'sqrip-swiss-qr-invoice' ),
                'class'       => 'comparison-tab ebics-service',
                'default'     => 'yes',
                'disabled'    => true,
            ),
            'comparison_report' => array(
                'title'       => __( 'Send report to Admin E-Mail', 'sqrip-swiss-qr-invoice' ),
                'label'       => __( 'Send report by email', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'checkbox',
                'description' => 'Get an e-mail with a report for every comparison executed.',
                'default'     => 'no',
                'class'       => 'comparison-tab ebics-service'  
            ),
            'compare_btn' => array(
                'title'       => __( 'Start Comparisson', 'sqrip-swiss-qr-invoice' ),
                'label'       => __( 'Compare Now' ),
                'type'        => 'info',
                'description' => 'Initiate and test the service.',
                'default'     => 'no',
                'class'       => 'comparison-tab ebics-service'  
            ),
            
            'return_enabled' => array(
                'title'       => __( 'Activate/Deactivate Refunds', 'sqrip-swiss-qr-invoice' ),
                'label'       => __( 'Activate sqrip for Refunds', 'sqrip-swiss-qr-invoice' ),
                'type'        => 'checkbox',
                'description' => __( 'If activated, sqrip makes refunding easier by creating a QR-code that can be scanned with the banking app to initiate a bank transfer to the client.', 'sqrip-swiss-qr-invoice' ),
  	            'default'     => 'no',
                'class'       => 'refunds-tab'
            ),
            'return_token' => array(
                'title'       => __( 'API key for Refunds' , 'sqrip-swiss-qr-invoice' ),
                'type'        => 'textarea',
                'description' => __( 'For security reasons, a separate API key with <strong>deactived</strong> confirmation is needed for the Refund function.', 'sqrip-swiss-qr-invoice' ),
                'class'       => 'refunds-tab'
            ),
            
        );
    }

    public function get_fund_management($param = ""){
        $endpoint   = 'get-fund-management-details';

        $service = [
            "main_account"  => "XXXX XXXX XXXX XXXX",
            "debit_account" => "XXXX XXXX XXXX XXXX",
            "trigger_level" => "XXX"
        ];

        if (!isset($service[$param])) {
            return;
        }

        $response = sqrip_remote_request($endpoint);  

        // var_dump($response); exit;


        if ( isset($response->main_account) ) {

            $service['main_account'] = $response->main_account;
            $service['debit_account'] = $response->debit_account;
            $service['trigger_level'] = $response->trigger_level;

        }  

        return !empty($param) ? $service[$param] : $service;
    }


    public function get_active_service($param = ""){
        $endpoint   = 'get-active-service-type';

        $service = [
            "iban"                  => "XXXX XXXX XXXX XXXX",
            "remaining_credits"     => "0",
            "active_service"        => "none",
            "active_service_txt"    => __('No service active', 'sqrip-swiss-qr-invoice')
        ];

        $response = sqrip_remote_request($endpoint);  

        // var_dump($response); exit;

        if ( isset($response->active_service) ) {

            $service['iban'] = $response->iban;
            $service['remaining_credits'] = $response->remaining_credits;
            $service['active_service'] = $response->active_service;

            switch ($response->active_service) {
                case 'camt_upload_service':
                    $service['active_service_txt'] = __('You have CAMT File upload service active', 'sqrip-swiss-qr-invoice');
                    break;

                case 'ebics_service':
                    $service['active_service_txt'] = __('You have EBICS service active', 'sqrip-swiss-qr-invoice');
                    break;
                
                default:
                    $service['active_service_txt'] = __('No service active', 'sqrip-swiss-qr-invoice');
                    break;
            }

        }  

        return !empty($param) ? $service[$param] : $service;
    }

    public function get_address_options(){
        $address_woocommerce = sqrip_get_payable_to_address_txt('woocommerce');
        $address_sqrip = sqrip_get_payable_to_address_txt('sqrip');

        $address_options = [];

        if ($address_sqrip) {
            $address_options['sqrip'] = __( 'from sqrip account: '.esc_attr($address_sqrip) , 'sqrip-swiss-qr-invoice' );
        }

        if ($address_woocommerce) {
            $address_options['woocommerce'] = __( 'from WooCommerce: '.esc_attr($address_woocommerce) , 'sqrip-swiss-qr-invoice' );
        }

        $address_options['individual'] = __( 'Third address' , 'sqrip-swiss-qr-invoice' );

        return $address_options;
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
                <th scope="row" class="titledesc <?php echo esc_attr($data['class']); ?>"  colspan="2">
                    <h3><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></h3>
                    <?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>
                </th>
            </tr>
        <?php

        return ob_get_clean();
    }

    public function generate_tab_html($key, $data)
    {
        $field_key = $this->get_field_key( $key );
        $defaults  = array(
          'tabs' => array(),
        );

        $data = wp_parse_args( $data, $defaults );

        ob_start();

        $tabs = $data['tabs'];

        if ($tabs && is_array($tabs)) {
            echo '<div class="sqrip-tabs">';
            foreach ($tabs as $tab) { ?>
                <div class="sqrip-tab <?php echo esc_attr( $tab['class'] ); ?>" data-tab="<?php echo esc_attr( $tab['id'] ); ?>">
                    <h2><?php echo wp_kses_post( $tab['title'] ); ?></h2>
                </div>
                <?php
            }
            echo '</div>';

            echo '<div class="sqrip-tabs-description">';
            foreach ($tabs as $tab) { 
                if (isset($tab['description'])) : ?>
                <div class="sqrip-tab-description" data-tab="<?php echo esc_attr( $tab['id'] ); ?>">
                    <?php echo wp_kses_post( $tab['description'] ); ?>
                </div>
                <?php
                endif;
            }
            echo '</div>';
        }
        ?>
        <?php

        return ob_get_clean();
    }

    public function generate_info_html($key, $data)
    {
        $field_key = $this->get_field_key( $key );
        $defaults  = array(
          'title'             => '',
          'label'             => '',
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
            <tr valign="top">
                <th scope="row" class="titledesc <?php echo esc_attr($data['class']); ?>">
                    <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
                </th>
                <td class="forminp">
                    <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['label'] ); ?></label>
                    <?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>
                </td>
            </tr>
            <?php

        return ob_get_clean();
    }

    /**
     * Check Iban
     */
    public function check_iban_status($post_data)
    {
        $endpoint   = 'iban-status';
        $iban       = $post_data['woocommerce_sqrip_iban'];

        $body = '{
            "iban": "'.$iban.'"
        }';

        $response   = sqrip_remote_request($endpoint, $body, 'POST');  

        if ( isset($response->status) && $response->status== "inactive" ) {

            unset($_POST['woocommerce_sqrip_enabled']);

            $settings = new WC_Admin_Settings();

            $settings->add_error( __( 'The (QR-)IBAN has been changed. Please confirm the new (QR-)IBAN in your sqrip.ch account.', 'sqrip-swiss-qr-invoice' ) );
        }  

    }

    /**
     * Update Iban
     */
    public function update_iban($post_data)
    {
        $endpoint   = 'update-iban';
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
                    $message = __( 'IBAN changes: Active confirmation (see API key in sqrip.ch account).' , 'sqrip-swiss-qr-invoice' );
                    break;
                
                case 'passive':
                    $message = __( 'IBAN changes: Passive confirmation (see API key in sqrip.ch account)' , 'sqrip-swiss-qr-invoice' );
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

        if ( isset($post_data['woocommerce_sqrip_ebics_service']) ) {

            $Sqrip_Payment_Verification = new Sqrip_Payment_Verification();
            $Sqrip_Payment_Verification->refresh_cron();
            
        }


        return parent::process_admin_options();
    }

    public function send_test_email($post_data)
    {
        $endpoint       = 'code';
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

        $response = wp_remote_post(SQRIP_ENDPOINT.$endpoint, $args);

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
            $subject = __('Test E-Mail von sqrip.ch', 'sqrip-swiss-qr-invoice');
            $body = __('Hier das eingestellte Resultat:', 'sqrip-swiss-qr-invoice');
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
                $settings->add_message( __('Test email has been sent!', 'sqrip-swiss-qr-invoice') );
            } else {
                $settings->add_error( __('E-Mail can not be sent, please check WP MAIL SMTP', 'sqrip-swiss-qr-invoice') );
            }
        } else {
            $settings->add_error( 
                sprintf( 
                    __( 'sqrip Error: %s', 'sqrip-swiss-qr-invoice' ), 
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
	            __( "IBAN des Kunden wurde nicht gefunden. Stelle sicher, dass sie im Meta-Feld 'iban_num' hinterlegt ist.", 'sqrip-swiss-qr-invoice' )
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
			    __( 'Error: Es wurde kein API Schlüssel für die Rückerstattungen angegeben. Bitte ergänze dies in den sqrip Plugin Einstellungen.', 'sqrip-swiss-qr-invoice' )
		    );
		    return false;
	    }

	    $endpoint = "code";

        $args = sqrip_prepare_remote_args($body, 'POST', $token);
        $response = wp_remote_post(SQRIP_ENDPOINT.$endpoint, $args);

        $status_code = $response['response']['code'];

        if ($status_code !== 200) {
	        // Transaction was not successful
	        $err_msg = explode(",", $response['body']);
	        $err_msg = trim(strstr($err_msg[0], ':'), ': "');

	        // Add note to the order for your reference
	        $order->add_order_note(
		        sprintf(
			        __( 'Error: %s', 'sqrip-swiss-qr-invoice' ),
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

	        $order->add_order_note( __('sqrip QR-Code für Rückerstattung erstellt.', 'sqrip-swiss-qr-invoice') );

	        $order->update_meta_data('sqrip_refund_qr_attachment_id', $sqrip_qr_png_attachment_id);
	        $order->save(); // without calling save() the meta data is not updated

	        return true;
        } else {
	        // Add note to the order for your reference
	        $order->add_order_note(
		        sprintf(
			        __( 'Error: %s', 'sqrip-swiss-qr-invoice' ),
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
        $endpoint   = 'code';

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
        $response = wp_remote_post(SQRIP_ENDPOINT.$endpoint, $args);

        if (is_wp_error($response)) {
            wc_add_notice( 
                sprintf( 
                    __( 'sqrip Payment Error: %s', 'sqrip-swiss-qr-invoice' ),
                    esc_html( 'Something went wrong!' ) ), 
                'error' 
            );

            return;
        }

        $status_code = $response['response']['code'];

        // var_dump($response);

        if ($status_code !== 200) {
            // Transaction was not succesful
            // Add notice to the cart
            $err_msg = explode(",", $response['body']);
            $err_msg = trim(strstr($err_msg[0], ':'), ': "');

            wc_add_notice( 
                sprintf( 
                    __( 'sqrip Payment Error: %s', 'sqrip-swiss-qr-invoice' ),
                    esc_html( $err_msg ) ), 
                'error' 
            );

            // Add note to the order for your reference
            $order->add_order_note( 
                sprintf( 
                    __( 'sqrip Payment Error: %s', 'sqrip-swiss-qr-invoice' ),
                    esc_html($err_msg) 
                ) 
            );

            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($response_body);

        if (isset($response_body->reference)) {

            $pdf_file_old = get_post_meta($order_id, 'sqrip_pdf_file_url', true);

            if ($pdf_file_old) {

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
            $sqrip_qr_pdf_attachment_id = $this->file_upload($sqrip_pdf, '.pdf');
            // $sqrip_qr_png_attachment_id = $this->file_upload($sqrip_png, '.png');

            $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
            $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);

            // $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
            // $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);

            $order->add_order_note( __('sqrip QR Invoice created.', 'sqrip-swiss-qr-invoice') );

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
                    __( 'Error: %s', 'sqrip-swiss-qr-invoice' ), 
                    esc_html( $response_body->message ) 
                ),
                'error' 
            );

            // Add note to the order for your reference
            $order->add_order_note( 
                sprintf( 
                    __( 'Error: %s', 'sqrip-swiss-qr-invoice' ), 
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

        $prefix = 'sqrip_invoice_';
        $uniq_name = date('dmY') . '' . (int) microtime(true);
        $filename = $prefix . $uniq_name . $type;

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
