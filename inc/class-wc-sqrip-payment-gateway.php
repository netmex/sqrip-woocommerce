<?php

if (!class_exists('WC_Payment_Gateway')) return;

class WC_Sqrip_Payment_Gateway extends WC_Payment_Gateway
{
	/**
	 * Due date for order invoice
	 *
	 * @var string
	 */
    public $due_date;

    /**
	 * IBAN account number
	 *
	 * @var string
	 */
    public $iban;

    /**
	 * sqrip API key token
	 *
	 * @var string
	 */
    public $token;

    /**
	 * Purchased product
	 *
	 * @var string
	 */
    public $product;

    /**
	 * Store owner address
	 *
	 * @var string
	 */
    public $address;

    /**
	 * Enable refunds with sqrip
	 *
	 * @var string
	 */
    public $return_enabled;

    /**
	 * sqrip API key token for refunds
	 *
	 * @var string
	 */
    public $return_token;

    /**
     * Class constructor add payment gateway information
     */
    public function __construct()
    {

        $this->id = 'sqrip'; // payment gateway plugin ID
        $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
        $this->has_fields = true; // in case you need a custom credit card form
        $this->method_title = __('sqrip – Swiss QR-Invoice API', 'sqrip-swiss-qr-invoice');
        $this->method_description = __('sqrip – Modern and clever WooCommerce tools for the most widely used payment method in Switzerland: the bank transfer.', 'sqrip-swiss-qr-invoice'); // will be displayed on the options page

        // gateways can support subscriptions, refunds, saved payment methods,
        // but in this tutorial we begin with simple payments
        $this->supports = array(
            'products'
        );

        // Method with all the options fields
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->due_date = $this->get_option('due_date');
        $this->iban = $this->get_option('iban');
        $this->token = $this->get_option('token');
        $this->product = $this->get_option('product');
        $this->address = $this->get_option('address');
        $this->return_enabled = $this->get_option('return_enabled');
        $this->return_token = $this->get_option('return_token');

        // Add support for refunds if option is set
        if ($this->return_enabled == "yes") {
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


        $address_woocommerce = sqrip_get_payable_to_address_txt('woocommerce');
        $address_sqrip = sqrip_get_payable_to_address_txt('sqrip');

        $address_options = [];

        if ($address_sqrip) {
            $address_options['sqrip'] = __('from sqrip account: ' . esc_attr($address_sqrip), 'sqrip-swiss-qr-invoice');
        }

        if ($address_woocommerce) {
            $address_options['woocommerce'] = __('from WooCommerce: ' . esc_attr($address_woocommerce), 'sqrip-swiss-qr-invoice');
        }

        $address_options['individual'] = __('Third address', 'sqrip-swiss-qr-invoice');

        $description = __('What is the order status that waits for confirmation of made payment to your bank account?', 'sqrip-swiss-qr-invoice');

        $tooltip = sprintf('<span class="sqrip-tooltip"><span>%s</span></span>', __('Do not set this too low! Remove input to disable the setting.', 'sqrip-swiss-qr-invoice'));

        $suppressed_qr_invoice_orders = wc_get_order_statuses();
        $suppressed_qr_invoice_orders = ['wc-sqrip-default-status' => 'Please select an option'] + $suppressed_qr_invoice_orders;
        
        $qr_order_status_options = wc_get_order_statuses();
        if (isset($qr_order_status_options['wc-on-hold'])) {
            $qr_order_status_options['wc-on-hold'] = $qr_order_status_options['wc-on-hold']." (default)";
        } else {
            $qr_order_status_options['on-hold'] = "Sqrip On-hold (default)";
        }

        $this->form_fields = array(
            'tabs' => array(
                'type' => 'tab',
                'tabs' => [
                    [
                        'id' => 'services',
                        'title' => __('Services', 'sqrip-swiss-qr-invoice'),
                        'class' => 'active',
                    ],
                    [
                        'id' => 'qrinvoice',
                        'title' => __('QR-Invoice', 'sqrip-swiss-qr-invoice'),
                        'class' => '',
                    ],
                    [
                        'id' => 'comparison',
                        'title' => __('Payment Comparison', 'sqrip-swiss-qr-invoice'),
                        'description' => '',
                        'class' => '',
                    ],
                    [
                        'id' => 'refunds',
                        'title' => __('Refunds', 'sqrip-swiss-qr-invoice'),
                        'class' => '',
                    ]
                ]
            ),
            'section_connection' => array(
                'title' => __('Connection', 'sqrip-swiss-qr-invoice'),
                'type' => 'section',
                'class' => 'services-tab'
            ),
            'token' => array(
                'title' => __('API key', 'sqrip-swiss-qr-invoice'),
                'type' => 'textarea',
                'description' => __('Open an account at <a href="https://sqrip.ch" target="_blank">https://sqrip.ch</a>, create an API key, copy and paste it here. Done!', 'sqrip-swiss-qr-invoice'),
                'class' => 'services-tab'
            ),
            'section_activation' => array(
                'title' => __('Activation & Status', 'sqrip-swiss-qr-invoice'),
                'type' => 'section',
                'class' => 'services-tab'
            ),
            'enabled' => array(
                'title' => __('Activate sqrip', 'sqrip-swiss-qr-invoice'),
                'label' => __('Enable QR invoices with sqrip API', 'sqrip-swiss-qr-invoice'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
                'class' => 'services-tab'
            ),
            'remaining_credits' => array(
                'title' => __('Remaining Credits', 'sqrip-swiss-qr-invoice'),
                'type' => 'text',
                'description' => '',
                'class' => 'services-tab'
            ), 
            'current_status' => array(
                'title' => __('Current sqrip status', 'sqrip-swiss-qr-invoice'),
                'type' => 'text',
                'description' => '',
                'default' => '',
                'class' => 'services-tab'
            ), 
            'turn_off_if_error' => array(
                'title' => __('Auto turn off', 'sqrip-swiss-qr-invoice'),
                'label' => __('Auto turn off sqrip services if error occurs', 'sqrip-swiss-qr-invoice'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
                'class' => 'services-tab'
            ),
            'section_features' => array(
                'title' => __('Features', 'sqrip-swiss-qr-invoice'),
                'type' => 'section',
                'class' => 'services-tab'
            ),
            'payment_comparison_enabled' => array(
                'title' => __('Activate/Deactivate Payment Comparison', 'sqrip-swiss-qr-invoice'),
                'label' => __('Activate sqrip for Payment Comparison', 'sqrip-swiss-qr-invoice'),
                'type' => 'checkbox',
                'description' => '</br>' . __('If activated, sqrip will add an action for orders with the status specified in setting 1 (Awaiting payment), to change the status to the one specified in setting 2 (Confirmed).', 'sqrip-swiss-qr-invoice'),
                'default' => 'no',
                'class' => 'services-tab'
            ),
            'return_enabled' => array(
                'title' => __('Activate/Deactivate Refunds', 'sqrip-swiss-qr-invoice'),
                'label' => __('Activate sqrip for Refunds', 'sqrip-swiss-qr-invoice'),
                'type' => 'checkbox',
                'description' => __('If activated, sqrip makes refunding easier by creating a QR-code that can be scanned with the banking app to initiate a bank transfer to the client.', 'sqrip-swiss-qr-invoice'),
                'default' => 'no',
                'class' => 'services-tab'
            ),
            'section_display' => array(
                'title' => __('Display', 'sqrip-swiss-qr-invoice'),
                'type' => 'section',
                'class' => 'qrinvoice-tab'
            ),
            'title' => array(
                'title' => __('Payment method name', 'sqrip-swiss-qr-invoice'),
                'type' => 'text',
                'description' => __('Swiss QR invoices with sqrip', 'sqrip-swiss-qr-invoice'),
                'default' => 'QR-Rechnung',
                'class' => 'qrinvoice-tab'
            ),
            'description' => array(
                'title' => __('Description', 'sqrip-swiss-qr-invoice'),
                'type' => 'textarea',
                'description' => __('Description of what the customer can expect from this payment option.', 'sqrip-swiss-qr-invoice'),
                'class' => 'qrinvoice-tab'
            ),

            'section_payment_recevier' => array(
                'title' => __('Payee', 'sqrip-swiss-qr-invoice'),
                'type' => 'section',
                'class' => 'qrinvoice-tab'
            ),
            'iban' => array(
                'title' => __('(QR-)IBAN of Payee', 'sqrip-swiss-qr-invoice'),
                'type' => 'text',
                'description' => __('(QR-)IBAN of the account to which the transfer is to be made', 'sqrip-swiss-qr-invoice'),
                'class' => 'qrinvoice-tab'
            ),
            'address' => array(
                'title' => __('Address', 'sqrip-swiss-qr-invoice'),
                'type' => 'select',
                'description' => __('The address to appear on the QR invoice', 'sqrip-swiss-qr-invoice'),
                'options' => $address_options,
                'class' => 'qrinvoice-tab'
            ),
            'address_name' => array(
                'title' => __('Name*', 'sqrip-swiss-qr-invoice'),
                'type' => 'text',
                'class' => 'sqrip-address-individual',
            ),
            'address_street' => array(
                'title' => __('Street*', 'sqrip-swiss-qr-invoice'),
                'type' => 'text',
                'class' => 'sqrip-address-individual',
            ),
            'address_postcode' => array(
                'title' => __('ZIP*', 'sqrip-swiss-qr-invoice'),
                'type' => 'text',
                'class' => 'sqrip-address-individual',
            ),
            'address_city' => array(
                'title' => __('City*', 'sqrip-swiss-qr-invoice'),
                'type' => 'text',
                'class' => 'sqrip-address-individual',
            ),
            'address_country' => array(
                'title' => __('Country*', 'sqrip-swiss-qr-invoice'),
                'type' => 'select',
                'class' => 'sqrip-address-individual',
                'options' => $countries_list
            ),

            'section_qr_invoice' => array(
                'title' => __('Invoice Details', 'sqrip-swiss-qr-invoice'),
                'type' => 'section',
                'class' => 'qrinvoice-tab'
            ),
            'payer' => array(
                'title' => __('Payer', 'sqrip-swiss-qr-invoice'),
                'type' => 'radio',
                'options' => array(
                    'either' => __('Either Company Name(priority) or First/Last name', 'sqrip-swiss-qr-invoice'),
                    'both' => __('Both Company Name and First/Last name ', 'sqrip-swiss-qr-invoice'),
                ),
                'default' => 'either',
                'class' => 'qrinvoice-tab',
                'description' => __('What information about the client should sqrip use in QR-invoices', 'sqrip-swiss-qr-invoice'),
            ),
            'qr_reference_format' => array(
                'title' => __('Initiate QR-Ref# with these 6 digits', 'sqrip-swiss-qr-invoice'),
                'type' => 'text',
                'default' => '',
                'class' => 'qrinvoice-tab ' . $this->show_qr_reference_format(),
                'custom_attributes' => ['minlength' => 6, 'maxlength' => 6]
            ),
            'qr_reference' => array(
                'title' => __('Basis of the (QR-)Ref#', 'sqrip-swiss-qr-invoice'),
                'type' => 'radio',
                'options' => array(
                    'random' => __('Random number', 'sqrip-swiss-qr-invoice'),
                    'order_number' => __('Order number', 'sqrip-swiss-qr-invoice'),
                ),
                'class' => 'qrinvoice-tab'
            ),
            'due_date' => array(
                'title' => __('Maturity (Today in x days)', 'sqrip-swiss-qr-invoice'),
                'type' => 'number',
                'default' => 30,
                'css' => "width:70px",
                'class' => 'qrinvoice-tab'
            ),
            'additional_information' => array(
                'title' => __('Additional Information', 'sqrip-swiss-qr-invoice'),
                'type' => 'textarea',
                'class' => 'sqrip-additional-information',
                'default' => __("Due date: [due_date format=\"%Y-%m-%d\"]\nOrder: [order_number]\nThank you for your purchase!", "sqrip-swiss-qr-invoice"),
                'description' => __('Will be displayed on the QR invoice in the section “Additional information”. The result shown in invoices cannot exceed 140 symbols and 5 rows.<br>The following short codes are available:<br>[order_number] the order number.<br>[due_date format="%Y-%m-%d"] to insert the due date of the invoice.<br><a href="https://www.php.net/strftime" target="_blank">Supported formats</a> are:<br>%Y-%m-%d -> 2022-04-06<br>%m.%d.%y -> 04.06.22<br>%d. %B %Y -> 06. April 2022<br>%e. %b %Y -> 6. Apr 2022', 'sqrip-swiss-qr-invoice'),
                'class' => 'qrinvoice-tab'
            ),
            'file_name' => array(
                'title' => __('File Name', 'sqrip-swiss-qr-invoice'),
                'type' => 'textarea',
                'class' => 'qrinvoice-tab',
                'maxlength' => 140,
                'default' => __("[order_date]_[shop_name]_invoice-order_[order_number]", "sqrip-swiss-qr-invoice"),
                'description' => __('The only characters allowed are A-Z, dashes (-) and underscores (_). Any spaces will be replaced with underscores (_). You can also use the following variables:<br>[order_number] — your full order number;<br>[order_date] — in yymmdd format, e.g. 230210 for Feb. 10 2023;<br>[shop_name] — can only be used if the shop name conforms with the "characters allowed" rule, otherwise the setting will be highlighted in red.', 'sqrip-swiss-qr-invoice'),
            ),
            'product' => array(
                'title' => __('Format', 'sqrip-swiss-qr-invoice'),
                'type' => 'select',
                'description' => '',
                'options' => array(
                    'Full A4' => __('on a blank A4 PDF', 'sqrip-swiss-qr-invoice'),
                    'Invoice Slip' => __('only the A6 payment part as PDF', 'sqrip-swiss-qr-invoice'),
                ),
                'class' => 'qrinvoice-tab'
            ),
            'lang' => array(
                'title' => __('Language', 'sqrip-swiss-qr-invoice'),
                'type' => 'select',
                'options' => array(
                    'de' => __('German', 'sqrip-swiss-qr-invoice'),
                    'fr' => __('French', 'sqrip-swiss-qr-invoice'),
                    'it' => __('Italian', 'sqrip-swiss-qr-invoice'),
                    'en' => __('English', 'sqrip-swiss-qr-invoice')
                ),
                'default' => 'de',
                'class' => 'qrinvoice-tab'
            ),
            'section_handling' => array(
                'title' => __('Handling', 'sqrip-swiss-qr-invoice'),
                'type' => 'section',
                'class' => 'qrinvoice-tab'
            ),
            'suppress_generation' => array(
                'title' => __('Suppress QR-Invoice generation at checkout', 'sqrip-swiss-qr-invoice'),
                'label' => __('Don\'t generate QR-invoice at checkout but manually', 'sqrip-swiss-qr-invoice'),
                'type' => 'checkbox',
                'description' => __('If you enable this, the order status for orders with sqrip will change to \'Payment pending\', a confirmation e-mail will be sent and the order will wait for you to generate qr-invoices manually. This is helpful if you may need to adjust pricing or quantity after an order has been placed', 'sqrip-swiss-qr-invoice'),
                'default' => 'no',
                'class' => 'qrinvoice-tab'
            ),
            'status_suppressed' => array(
                'title' => __('Define Status when QR-Invoice is suppressed', 'sqrip-swiss-qr-invoice'),
                'type' => 'select',
                'options' => $suppressed_qr_invoice_orders,
                'default' => 'wc-sqrip-default-status',
                'class' => 'qrinvoice-tab'
            ),
            'new_suppressed_status' => array(
                'title' => '',
                'type' => 'text',
                'class' => 'qrinvoice-tab',
                'default' => __('Suppressed status', 'sqrip-swiss-qr-invoice'),
                'description' => __('What is the order status that waits for confirmation of made payment to your bank account?', 'sqrip-swiss-qr-invoice') . '</ br>' . sprintf(__('This selects the status with which new orders are created if the QR-Invoice generation suppression is active. If there is no suitable status available, you can create one right %s', 'sqrip-swiss-qr-invoice'), '<a href="#" class="sqrip-toggle-suppressed-status">' . __('here', 'sqrip-swiss-qr-invoice') . '</a>'),
            ),
            'enabled_new_sustatus' => array(
                'title' => '',
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
                'css' => 'visibility: hidden; position: absolute',
                'class' => 'comparison-tab sqrip-no-height'
            ),
            'first_time_new_sustatus' => array(
                'title' => '',
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
                'css' => 'visibility: hidden; position: absolute',
                'class' => 'comparison-tab sqrip-no-height'
            ),
            'qr_order_status' => array(
                'title' => __('Status of Orders made with payment method \'sqrip\':', 'sqrip-swiss-qr-invoice'),
                'type' => 'select',
                'options' => $qr_order_status_options,
                'class' => 'qrinvoice-tab',
                'default' => 'wc-on-hold',
            ),
            'new_qr_order_status' => array(
                'title' => '',
                'type' => 'text',
                'class' => 'qrinvoice-tab',
                'default' => __('QR order status', 'sqrip-swiss-qr-invoice'),
                'description' => sprintf(__('Set the status of a newly placed order with payment method \'sqrip\' so it matches your shop process. Should no status be suitable, please create one for your own %s.', 'sqrip-swiss-qr-invoice'), '<a href="#" class="sqrip-toggle-qr-order-status">' . __('here', 'sqrip-swiss-qr-invoice') . '</a>'),
            ),
            'enabled_new_qrstatus' => array(
                'title' => '',
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
                'css' => 'visibility: hidden; position: absolute',
                'class' => 'comparison-tab sqrip-no-height'
            ),
            'first_time_new_qrstatus' => array(
                'title' => '',
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
                'css' => 'visibility: hidden; position: absolute',
                'class' => 'comparison-tab sqrip-no-height'
            ),
            'integration_order' => array(
                'title' => __('Show QR-invoice on confirmation page', 'sqrip-swiss-qr-invoice'),
                'label' => __('Offer QR invoice for download', 'sqrip-swiss-qr-invoice'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'yes',
                'class' => 'qrinvoice-tab ' . $this->show_integration_order()
            ),
            'email_attached' => array(
                'title' => __('Attach QR-Invoice to E-Mail template', 'sqrip-swiss-qr-invoice'),
                'description' => __('Select email template to which the QR-invoice is attached', 'sqrip-swiss-qr-invoice'),
                'type' => 'select',
                'options' => sqrip_get_wc_emails(),
                'class' => 'qrinvoice-tab'
            ),
            'delete_invoice_status' => array(
                'title' => __('Delete QR-invoice once status has been changed to', 'sqrip-swiss-qr-invoice'),
                'type' => 'multiselect',
                'default' => 0,
                'options' => wc_get_order_statuses(),
                'class' => 'qrinvoice-tab'
            ),
            'expired_date' => array(
                'title' => __('Delete any generated QR-invoice after ', 'sqrip-swiss-qr-invoice'),
                'label' => sprintf('%s %s', __('days.', 'sqrip-swiss-qr-invoice'), $tooltip),
                'description' => __('Keep the size of your media library small. sqrip deletes all qr-invoice files that are not needed anymore', 'sqrip-swiss-qr-invoice'),
                'type' => 'number',
                'default' => "",
                'css' => "width:70px",
                'class' => 'qrinvoice-tab'
            ),
            'test_email' => array(
                'title' => '',
                'type' => 'checkbox',
                'default' => 'no',
                'css' => 'visibility: hidden'
            ),
            'section_general_settings' => array(
                'title' => __('General Settings', 'sqrip-swiss-qr-invoice'),
                'type' => 'section',
                'class' => 'comparison-tab'
            ),
            'status_awaiting' => array(
                'title' => __('Status of awaiting payment orders', 'sqrip-swiss-qr-invoice'),
                'type' => 'select',
                'options' => wc_get_order_statuses(),
                'default' => 'wc-pending',
                'class' => 'comparison-tab'
            ),
            'new_awaiting_status' => array(
                'title' => '',
                'type' => 'text',
                'class' => 'comparison-tab',
                'default' => __('Awaiting payment', 'sqrip-swiss-qr-invoice'),
                'description' => $description . '</br></br>' . sprintf(__('If there is no suitable status available, you can create one right %s', 'sqrip-swiss-qr-invoice'), '<a href="#" class="sqrip-toggle-awaiting-status">' . __('here', 'sqrip-swiss-qr-invoice') . '</a>'),
            ),
            'enabled_new_awstatus' => array(
                'title' => '',
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
                'css' => 'visibility: hidden; position: absolute',
                'class' => 'comparison-tab sqrip-no-height'
            ),
            'first_time_new_awstatus' => array(
                'title' => '',
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
                'css' => 'visibility: hidden; position: absolute',
                'class' => 'comparison-tab sqrip-no-height'
            ),
            'status_completed' => array(
                'title' => __('Completed Orders Status', 'sqrip-swiss-qr-invoice'),
                'type' => 'select',
                'options' => wc_get_order_statuses(),
                'placeholder' => 'Select Status',
                'default' => 'wc-completed',

                'class' => 'comparison-tab'
            ),
            'new_status' => array(
                'title' => '',
                'type' => 'text',
                'class' => 'comparison-tab',
                'default' => __('Completed, Paid', 'sqrip-swiss-qr-invoice'),
                'description' => __('To what order status should we change your order, once the payment has been confirmed?', 'sqrip-swiss-qr-invoice') . '</br></br>' . sprintf(__('If there is no suitable status available, you can create one right %s', 'sqrip-swiss-qr-invoice'), '<a href="#" class="sqrip-toggle-order-status">' . __('here', 'sqrip-swiss-qr-invoice') . '</a>'),
            ),
            'enabled_new_status' => array(
                'title' => '',
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
                'css' => 'visibility: hidden; position: absolute',
                'class' => 'comparison-tab sqrip-no-height'
            ),
            'first_time_new_status' => array(
                'title' => '',
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
                'css' => 'visibility: hidden; position: absolute',
                'class' => 'comparison-tab sqrip-no-height'
            ),
            'return_token' => array(
                'title' => __('API key for Refunds', 'sqrip-swiss-qr-invoice'),
                'type' => 'textarea',
                'description' => __('For security reasons, a separate API key with <strong>deactived</strong> confirmation is needed for the Refund function.', 'sqrip-swiss-qr-invoice'),
                'class' => 'refunds-tab'
            ),
        );
    }

    public function show_integration_order()
    {
        $suppress_generation = sqrip_get_plugin_option('suppress_generation');

        return $suppress_generation == 'yes' ? 'hide' : '';
    }

    public function show_qr_reference_format()
    {
        $iban = sqrip_get_plugin_option('iban');
        $token = sqrip_get_plugin_option('token');

        $response = sqrip_validation_iban($iban, $token);

        $return = '';

        if (isset($response->message)) {
            $return = $response->message;
        }

        if ($return == 'Valid qr IBAN') {
            return 'qr-iban';
        }

        if ($return == 'Valid simple IBAN') {
            return 'simple-iban';
        }

        return 'hide';
    }

    public function generate_tab_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $defaults = array(
            'tabs' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();

        $tabs = $data['tabs'];

        if ($tabs && is_array($tabs)) {
            echo '<div class="sqrip-tabs">';
            foreach ($tabs as $tab) { ?>
                <div class="sqrip-tab <?php echo esc_attr($tab['class']); ?>"
                     data-tab="<?php echo esc_attr($tab['id']); ?>">
                    <h2><?php echo wp_kses_post($tab['title']); ?></h2>
                </div>
                <?php
            }
            echo '</div>';

            echo '<div class="sqrip-tabs-description">';
            foreach ($tabs as $tab) {
                if (isset($tab['description'])) : ?>
                    <div class="sqrip-tab-description" data-tab="<?php echo esc_attr($tab['id']); ?>">
                        <?php echo wp_kses_post($tab['description']); ?>
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

    public function generate_radio_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $defaults = array(
            'title' => '',
            'disabled' => false,
            'class' => '',
            'css' => '',
            'placeholder' => '',
            'type' => 'text',
            'desc_tip' => false,
            'description' => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args($data, $defaults);
        $value = $this->get_option($key);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?><?php echo $this->get_tooltip_html($data); // WPCS: XSS ok.
                    ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>

                    <?php foreach ((array)$data['options'] as $option_key => $option_value) :
                        $ip_id = $option_key . '_' . $field_key;
                        $checked = (string)$option_key == esc_attr($value) ? "checked" : "";
                        ?>
                        <div class="sqrip-radio-field <?php echo esc_attr($data['class']); ?>">
                            <input id="<?php echo esc_attr($ip_id); ?>" type="radio"
                                   name="<?php echo esc_attr($field_key); ?>"
                                   value="<?php echo esc_attr($option_key); ?>" <?php echo esc_attr($checked); ?>
                                   required="required"/>
                            <label for="<?php echo esc_attr($ip_id); ?>"><?php echo esc_html($option_value); ?></label>
                        </div>
                    <?php endforeach; ?>

                    <?php echo $this->get_description_html($data); // WPCS: XSS ok.
                    ?>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    /**
     * Generate Number Input HTML.
     *
     * @param string $key Field key.
     * @param array $data Field data.
     * @return string
     * @since  1.0.0
     */
    public function generate_number_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $defaults = array(
            'title' => '',
            'label' => '',
            'disabled' => false,
            'class' => '',
            'css' => '',
            'placeholder' => '',
            'type' => 'text',
            'desc_tip' => false,
            'description' => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?><?php echo $this->get_tooltip_html($data); // WPCS: XSS ok.
                    ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <input class="input-text regular-input <?php echo esc_attr($data['class']); ?>"
                           type="<?php echo esc_attr($data['type']); ?>" name="<?php echo esc_attr($field_key); ?>"
                           id="<?php echo esc_attr($field_key); ?>" style="<?php echo esc_attr($data['css']); ?>"
                           value="<?php echo esc_attr($this->get_option($key)); ?>"
                           placeholder="<?php echo esc_attr($data['placeholder']); ?>" <?php disabled($data['disabled'], true); ?> <?php echo $this->get_custom_attribute_html($data); // WPCS: XSS ok.
                    ?> />
                    <?php echo $this->get_description_html($data); // WPCS: XSS ok.
                    ?>
                    <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['label']); ?></label>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    public function generate_section_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $defaults = array(
            'title' => '',
            'disabled' => false,
            'class' => '',
            'css' => '',
            'placeholder' => '',
            'type' => 'text',
            'desc_tip' => false,
            'description' => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top" class="sqrip-section">
            <th scope="row" class="titledesc <?php echo esc_attr($data['class']); ?>" colspan="2">
                <h3><?php echo wp_kses_post($data['title']); ?><?php echo $this->get_tooltip_html($data); // WPCS: XSS ok.
                    ?></h3>
                <?php echo $this->get_description_html($data); // WPCS: XSS ok.
                ?>
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
        $endpoint = 'iban-status';
        $iban = $post_data['woocommerce_sqrip_iban'];

        $body = '{
            "iban": "' . $iban . '"
        }';

        $response = sqrip_remote_request($endpoint, $body, 'POST');

        if (isset($response->status) && $response->status == "inactive") {

            unset($_POST['woocommerce_sqrip_enabled']);

            $settings = new WC_Admin_Settings();

            $settings->add_error(__('The (QR-)IBAN has been changed. Please confirm the new (QR-)IBAN in your sqrip.ch account.', 'sqrip-swiss-qr-invoice'));
        }

    }

    /**
     * Update Iban
     */
    public function update_iban($post_data)
    {
        $endpoint = 'update-iban';
        $iban = $post_data['woocommerce_sqrip_iban'];

        $body = '{
            "iban": {
                "iban": "' . $iban . '"
            }
        }';

        $response = sqrip_remote_request($endpoint, $body, 'POST');

        /**
         * Do something after Update IBAN | Example API Response
         * 'message' => 'IBAN updated.
         * 'type' => 'qr'
         * 'confirmation_type' => 'active'
         */

        if (isset($response->confirmation_type)) {
            $message = '';
            switch ($response->confirmation_type) {
                case 'active':
                    $message = __('IBAN changes: Active confirmation (see API key in sqrip.ch account).', 'sqrip-swiss-qr-invoice');
                    break;

                case 'passive':
                    $message = __('IBAN changes: Passive confirmation (see API key in sqrip.ch account)', 'sqrip-swiss-qr-invoice');
                    break;
            }

            if ($message) {
                $settings = new WC_Admin_Settings();
                $settings->add_message($message);
            }


        }

    }

    public function process_admin_options()
    {
        $post_data = $this->get_post_data();

        $this->check_iban_status($post_data);

        $this->update_iban($post_data);

        if (isset($post_data['woocommerce_sqrip_test_email'])) {

            $this->send_test_email($post_data);

            unset($_POST['woocommerce_sqrip_test_email']);

        }

        if (isset($post_data['woocommerce_sqrip_enabled_new_status']) && !empty($post_data['woocommerce_sqrip_enabled_new_status']) && isset($post_data['woocommerce_sqrip_first_time_new_status'])) {
            $_POST['woocommerce_sqrip_status_completed'] = 'wc-sqrip-paid';
        }

        if (isset($post_data['woocommerce_sqrip_enabled_new_awstatus']) && !empty($post_data['woocommerce_sqrip_enabled_new_awstatus']) && isset($post_data['woocommerce_sqrip_first_time_new_awstatus'])) {
            $_POST['woocommerce_sqrip_status_awaiting'] = 'wc-sqrip-awaiting';
        }

        if (isset($post_data['woocommerce_sqrip_enabled_new_sustatus']) && !empty($post_data['woocommerce_sqrip_enabled_new_sustatus']) && isset($post_data['woocommerce_sqrip_first_time_new_sustatus'])) {
            $_POST['woocommerce_sqrip_status_suppressed'] = 'wc-sqrip-suppressed';
        }

        if (isset($post_data['woocommerce_sqrip_enabled_new_qrstatus']) && !empty($post_data['woocommerce_sqrip_enabled_new_qrstatus']) && isset($post_data['woocommerce_sqrip_first_time_new_qrstatus'])) {
            $_POST['woocommerce_sqrip_qr_order_status'] = 'wc-sqrip-qrstatus';
        }

        return parent::process_admin_options();
    }

    public function send_test_email($post_data)
    {
        $endpoint = 'code';
        $token = $post_data['woocommerce_sqrip_token'];
        $iban = $post_data['woocommerce_sqrip_iban'];
        $product = $post_data['woocommerce_sqrip_product'];
        $sqrip_due_date = $post_data['woocommerce_sqrip_due_date'];
        $address = $post_data['woocommerce_sqrip_address'];
        $qr_reference = $post_data['woocommerce_sqrip_qr_reference'];
        $initial_digits = $post_data['woocommerce_sqrip_qr_reference_format'];
        $payer = $post_data['woocommerce_sqrip_payer'];
        $lang = isset($post_data['woocommerce_sqrip_lang']) ? $post_data['woocommerce_sqrip_lang'] : "de";
        $order_id = "11111";

        $plugin_options = sqrip_get_plugin_options();
        $additional_information = $plugin_options['additional_information'];

        // Integration By default is attachment.
        $integration = 'attachment';

        $date = date('Y-m-d');
        $due_date = date('Y-m-d', strtotime($date . " + " . $sqrip_due_date . " days"));
        $due_date_raw = strtotime($date . " + " . $sqrip_due_date . " days");


        if ($additional_information) {
            $additional_information = sqrip_additional_information_shortcodes($additional_information, $lang, $due_date_raw, $order_id);
        }

        $body = [
            "iban" => [
                "iban" => $iban,
            ],
            "payable_by" =>
                [
                    "name" => "netmex digital gmbh",
                    "street" => "Laurenzenvorstadt 11",
                    "postal_code" => 5000,
                    "town" => "Aarau",
                    "country_code" => "CH"
                ],
            "payment_information" =>
                [
                    "currency_symbol" => 'CHF',
                    "amount" => 107.77,
                    "message" => $additional_information
                ],
            "lang" => $lang,
            "product" => $product,
            'file_type' => 'pdf',
            "source" => "woocommerce"
        ];

        if ($payer == 'both') {
            $body['payable_by']['name'] = "Sophie Mustermann\nnetmex digital gmbh";
        }

        if ($qr_reference == "order_number") {
            $body['payment_information']['qr_reference'] = $order_id;
        }

        $iban_type = sqrip_validation_iban($iban, $token);

        if (isset($iban_type->message) && $initial_digits) {
            $body['payment_information']['initial_digits'] = $initial_digits;
        }

        if ($address == "individual") {

            $body['payable_to'] = array(
                'name' => $post_data['woocommerce_sqrip_address_name'],
                'street' => $post_data['woocommerce_sqrip_address_street'],
                'town' => $post_data['woocommerce_sqrip_address_city'],
                'postal_code' => $post_data['woocommerce_sqrip_address_postcode'],
                'country_code' => $post_data['woocommerce_sqrip_address_country'],
            );

        } else {

            $body['payable_to'] = sqrip_get_payable_to_address($address);

        }

        if (!$additional_information) {
            unset($body['payment_information']['message']);
        }

        $body = wp_json_encode($body);

        $args = [
            'method' => 'POST',
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ],
            'body' => $body,
            'data_format' => 'body',
        ];

        $response = wp_remote_post(SQRIP_ENDPOINT . $endpoint, $args);

        $response_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($response_body);

        $settings = new WC_Admin_Settings();

        if (isset($response_body->reference)) {
            $sqrip_pdf = $response_body->pdf_file;
            // $sqrip_png       =    $response_body->png_file;
            $sqrip_reference = $response_body->reference;

            // TODO: replace with attachment ID and store this in meta instead of actual file
            $sqrip_qr_pdf_attachment_id = $this->file_upload($sqrip_pdf, '.pdf', $token, $order_id);
            // $sqrip_qr_png_attachment_id = $this->file_upload($sqrip_png, '.png', $token);

            $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
            $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);


            // $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
            // $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);

            $to = get_option('admin_email');
            $subject = 'Test E-Mail von sqrip.ch';
            $body = 'Hier das eingestellte Resultat:';
            $attachments = [];

            $headers[] = 'From: sqrip Test-Mail <' . $to . '>';
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
            
            if (isset($response_body->total_codes_left) && $response_body->total_codes_left <= 0) {
                // turn off sqrip if auto turn-off enabled
                sqrip_auto_turn_off();
            }

            $wp_mail = wp_mail($to, $subject, $body, $headers, $attachments);

            if ($wp_mail) {
                $settings->add_message(__('<span id="test-email-status">Test email has been sent! <a href="' . $sqrip_qr_pdf_url . '" target="_blank">Click here</a> to view the invoice.</span>', 'sqrip-swiss-qr-invoice'));
            } else {
                $settings->add_error(__('E-Mail can not be sent, please check WP MAIL SMTP', 'sqrip-swiss-qr-invoice'));
            }
        } else {
            // turn off sqrip if auto turn-off enabled
            sqrip_auto_turn_off();

            $message = isset($response_body->message) ? $response_body->message : 'Connection error!';

            $settings->add_error(
                sprintf(
                    __('sqrip Error: %s', 'sqrip-swiss-qr-invoice'),
                    esc_html($message)
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
            $this->description = trim($this->description);
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
    public function process_refund($order_id, $amount = null, $reason = "")
    {

        global $woocommerce;

        $order = wc_get_order($order_id);
        $order_data = $order->get_data(); // order data

        $currency_symbol = $order_data['currency'];

        $body = sqrip_prepare_qr_code_request_body($currency_symbol, $amount, strval($order_id));

        // change product to Credit to just get QR Code
        $body['product'] = 'Credit';

        // replace sqrip IBAN with IBAN of customer
        $user = $order->get_user();
        $iban = sqrip_get_customer_iban($user);

        $order->update_meta_data('sqrip_refund_iban_num', $iban);
        $order->save();

        if (!$iban) {
            // Add note to the order for your reference
            $order->add_order_note(
                __("IBAN des Kunden wurde nicht gefunden. Stelle sicher, dass sie im Meta-Feld 'iban_num' hinterlegt ist.", 'sqrip-swiss-qr-invoice')
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
        // $payable_by['town'] = $payable_by['city'];
        // unset($payable_by['city']);

        // $payable_to['city'] = $payable_to['town'];
        // unset($payable_to['town']);

        $body['payable_by'] = $payable_by;
        $body['payable_to'] = $payable_to;

        $token = sqrip_get_plugin_option('return_token');
        if (!$token) {
            $order->add_order_note(
                __('Error: Es wurde kein API Schlüssel für die Rückerstattungen angegeben. Bitte ergänze dies in den sqrip Plugin Einstellungen.', 'sqrip-swiss-qr-invoice')
            );
            return false;
        }

        $endpoint = "code";

        $args = sqrip_prepare_remote_args($body, 'POST', $token);
        $response = wp_remote_post(SQRIP_ENDPOINT . $endpoint, $args);

        $status_code = $response['response']['code'];

        if ($status_code !== 200) {
            // Transaction was not successful
            $err_msg = explode(",", $response['body']);
            $err_msg = trim(strstr($err_msg[0], ':'), ': "');

            // Add note to the order for your reference
            $order->add_order_note(
                sprintf(
                    __('Error: %s', 'sqrip-swiss-qr-invoice'),
                    esc_html($err_msg)
                )
            );

            // turn off sqrip if auto turn-off enabled
            sqrip_auto_turn_off();

            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($response_body);

        if (isset($response_body->reference)) {
            $sqrip_png = $response_body->png_file;
            $sqrip_qr_png_attachment_id = $this->file_upload($sqrip_png, '.png', '', $order_id);

            $order->add_order_note(__('sqrip QR-Code für Rückerstattung erstellt.', 'sqrip-swiss-qr-invoice'));

            $order->update_meta_data('sqrip_refund_qr_attachment_id', $sqrip_qr_png_attachment_id);
            $order->save(); // without calling save() the meta data is not updated

            if (isset($response_body->total_codes_left) && $response_body->total_codes_left <= 0) {
                // turn off sqrip if auto turn-off enabled
                sqrip_auto_turn_off();
            }

            return true;
        } else {
            // Add note to the order for your reference
            $order->add_order_note(
                sprintf(
                    __('Error: %s', 'sqrip-swiss-qr-invoice'),
                    esc_html($response_body->message)
                )
            );

            // turn off sqrip if auto turn-off enabled
            sqrip_auto_turn_off();

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
        $endpoint = 'code';

        // we need it to get any order details
        $order = wc_get_order($order_id);
        $order_data = $order->get_data(); // order data

        $currency_symbol = $order_data['currency'];
        $amount = floatval($order_data['total']);

        $address = sqrip_get_plugin_option('address');

        $suppress_generation = sqrip_get_plugin_option('suppress_generation');

        if ($suppress_generation == "yes") {
            $order->update_status('pending');

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        $body = sqrip_prepare_qr_code_request_body($currency_symbol, $amount, strval($order_id));
        $body["payable_by"] = sqrip_get_billing_address_from_order($order);
        $body['payable_to'] = sqrip_get_payable_to_address($address);

        $args = sqrip_prepare_remote_args($body, 'POST');
        $response = wp_remote_post(SQRIP_ENDPOINT . $endpoint, $args);

        if (is_wp_error($response)) {
            wc_add_notice(
                sprintf(
                    __('sqrip Payment Error: %s', 'sqrip-swiss-qr-invoice'),
                    esc_html('Something went wrong!')),
                'error'
            );

            return;
        }

        $status_code = $response['response']['code'];

        if ($status_code !== 200) {
            // Transaction was not succesful
            // Add notice to the cart
            $err_msg = explode(",", $response['body']);
            $err_msg = trim(strstr($err_msg[0], ':'), ': "');
            $has_purchase = stripos($err_msg, "purchase");
            $has_request = stripos($err_msg, "complete request");
            $sqrip_link = $has_purchase ? 
                " here <a href='https://www.sqrip.ch/#pricing' target='_blank'>https://www.sqrip.ch/#pricing</a>" 
                : ($has_request ? " And we don't yet know why. Please contact our <a href='mailto:support@sqrip.ch'>support</a>" : "");
            $customer_msg = "It seems we couldn't provide you with a QR-invoice at this time. Please try later, contact the shop or use a different payment method.";
            // <a href="mailto:someone@example.com">Send email</a>
            wc_add_notice(
                sprintf(
                    __('sqrip Payment Error: %s', 'sqrip-swiss-qr-invoice'),
                    esc_html($customer_msg)),
                'error'
            );

            // Add note to the order for your reference
            $order->add_order_note(
                sprintf(
                    __('sqrip Payment Error: %s', 'sqrip-swiss-qr-invoice'),
                    esc_html($err_msg) . $sqrip_link
                )
            );

            // turn off sqrip if auto turn-off enabled
            sqrip_auto_turn_off();

            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($response_body);

        if (isset($response_body->reference)) {

            $pdf_file_old = get_post_meta($order_id, 'sqrip_pdf_file_url', true);

            if ($pdf_file_old) {

                $pdf_file_old_id = attachment_url_to_postid($pdf_file_old);

                if ($pdf_file_old_id) {

                    require_once(ABSPATH . 'wp-settings.php');

                    wp_delete_attachment($pdf_file_old_id, true);

                }

            }

            $sqrip_pdf = $response_body->pdf_file;
            // $sqrip_png       =    $response_body->png_file;
            $sqrip_reference = $response_body->reference;

            // TODO: replace with attachment ID and store this in meta instead of actual file
            $sqrip_qr_pdf_attachment_id = $this->file_upload($sqrip_pdf, '.pdf', '', $order_id);
            // $sqrip_qr_png_attachment_id = $this->file_upload($sqrip_png, '.png');

            $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
            $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);

            // $sqrip_qr_png_url = wp_get_attachment_url($sqrip_qr_png_attachment_id);
            // $sqrip_qr_png_path = get_attached_file($sqrip_qr_png_attachment_id);

            $order->add_order_note(__('sqrip QR Invoice created.', 'sqrip-swiss-qr-invoice'));

            $order->update_meta_data('sqrip_reference_id', $sqrip_reference);
            $order->update_meta_data('sqrip_qr_pdf_attachment_id', $sqrip_qr_pdf_attachment_id);
            $order->update_meta_data('sqrip_pdf_file_url', $sqrip_qr_pdf_url);
            $order->update_meta_data('sqrip_pdf_file_path', $sqrip_qr_pdf_path);
            $order->update_meta_data('sqrip_refund_iban_num', get_user_meta($order->get_user_id(), 'iban_num', true));

            // $order->update_meta_data('sqrip_png_file_url', $sqrip_qr_png_url);
            // $order->update_meta_data('sqrip_png_file_path', $sqrip_qr_png_path);

            // Empty the cart (Very important step)
            $woocommerce->cart->empty_cart();
            $order->save();

            if (isset($response_body->total_codes_left) && $response_body->total_codes_left <= 0) {
                // turn off sqrip if auto turn-off enabled
                sqrip_auto_turn_off();
            }

            // Redirect to thank you page
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        } else {

            $customer_msg = "It seems we couldn't provide you with a QR-invoice at this time. Please try later, contact the shop or use a different payment method.";
            wc_add_notice(
                sprintf(
                    __('Error: %s', 'sqrip-swiss-qr-invoice'),
                    esc_html($customer_msg)
                ),
                'error'
            );

            // Add note to the order for your reference
            $order->add_order_note(
                sprintf(
                    __('Error: %s', 'sqrip-swiss-qr-invoice'),
                    esc_html($response_body->message)
                )
            );

            // turn off sqrip if auto turn-off enabled
            sqrip_auto_turn_off();

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
    public function file_upload($fileurl, $type, $token = "", $order_id = "")
    {
        include_once(ABSPATH . 'wp-admin/includes/image.php');

        $sqrip_name = sqrip_file_name($order_id);
        $filename = $sqrip_name . $type;
        $file_path = sanitize_title($sqrip_name) . $type;

        // Get the path to the upload directory.
        $uploaddir = wp_upload_dir();
        $uploadfile = $uploaddir['path'] . '/' . $file_path;

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
            'post_status' => 'inherit',
            'meta_input' => array(
                'sqrip_invoice' => true
            )
        );
        // Insert the attachment.
        $attach_id = wp_insert_attachment($attachment, $uploadfile);

        // Generate the metadata for the attachment, and update the database record.
        $attach_data = wp_generate_attachment_metadata($attach_id, $uploadfile);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }
}
