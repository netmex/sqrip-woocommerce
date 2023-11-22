<?php

/**
 * Gets a single option and uses the setting default if the value has not been set yet
 * @param $key
 * @return mixed|string|null
 */
function sqrip_get_plugin_option($key)
{
    $plugin_options = get_option('woocommerce_sqrip_settings', array());

    // option exists in DB
    if ($plugin_options && array_key_exists($key, $plugin_options)) {
        return $plugin_options[$key];
    }

    // $gateway = new WC_Sqrip_Payment_Gateway();
    // $form_fields = $gateway->get_form_fields();

    // // Get option default from form fields if possible.
    // if ( isset( $form_fields[ $key ] ) ) {
    //     return $gateway->get_field_default( $form_fields[ $key ] );
    // }

    return null;
}

/**
 * Gets all the plugin options and uses the setting defaults if values have not been set yet
 * @return false|mixed
 */
function sqrip_get_plugin_options()
{
    $plugin_options = get_option('woocommerce_sqrip_settings', array());
    // $gateway = new WC_Sqrip_Payment_Gateway();
    // $form_fields = $gateway->get_form_fields();
    // $missing_settings = array_diff_key($form_fields, $plugin_options);
    // foreach($missing_settings as $key => $missing_setting) {
    //     $plugin_options[$key] = isset( $form_fields[ $key ] ) ? $gateway->get_field_default( $form_fields[ $key ] ) : null;
    // }
    return $plugin_options;
}

function sqrip_prepare_remote_args($body, $method, $token = null)
{
    $plugin_token = sqrip_get_plugin_option('token');
    $token = $token ? $token : $plugin_token;

    if (!$token || $token == null) {
        return;
    }

    $args = [];
    $args['timeout'] = 60;
    $args['method'] = $method;
    $args['headers'] = [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json'
    ];

    if (!is_string($body)) {
        $body = json_encode($body);
    }

    $args['body'] = $body;
    return $args;
}

function sqrip_remote_request($endpoint, $body = '', $method = 'GET', $token = "")
{
    $args = sqrip_prepare_remote_args($body, $method, $token);

    $response = wp_remote_request(SQRIP_ENDPOINT . $endpoint, $args);

    if (is_wp_error($response)) return;

    $body = wp_remote_retrieve_body($response);

    return json_decode($body);
}


/**
 * Extracts the billing address from a woocommerce order
 *
 * @param WC_Order $order woocommerce order
 *
 * @return array Array with the address correctly formatted for the
 *         payable_to / payable_by fields in the sqrip API
 */
function sqrip_get_billing_address_from_order($order)
{
    $order_data = $order->get_data();
    $company = isset($order_data['billing']['company']) ? $order_data['billing']['company'] : "";

    $billing_address = array(
        'name' => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
        'street' => $order_data['billing']['address_1'] . ($order_data['billing']['address_2'] ? ', ' . $order_data['billing']['address_2'] : ""),
        'postal_code' => $order_data['billing']['postcode'],
        'town' => $order_data['billing']['city'],
        'country_code' => $order_data['billing']['country']
    );

    if (!empty($company)) {
        $billing_address['name'] = $company;
    }

    $plugin_options = sqrip_get_plugin_options();
    $payer = $plugin_options['payer'];
    if ($payer == 'both') {
        $billing_address['name'] = $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'] . "\n" . $company;
    }

    return $billing_address;
}


/**
 * Prepares the request body based on the plugin options
 *
 * @param string $currency_symbol
 * @param float $amount
 * @param string $order_number
 *
 * @return array|false
 */
function sqrip_prepare_qr_code_request_body($currency_symbol, $amount, $order_number)
{
    $plugin_options = sqrip_get_plugin_options();
    $sqrip_due_date = $plugin_options['due_date'];
    $iban = $plugin_options['iban'];
    $token = $plugin_options['token'];
    $initial_digits = $plugin_options['qr_reference_format'];

    $product = $plugin_options['product'];
    $qr_reference = $plugin_options['qr_reference'];
    $address = $plugin_options['address'];
    $lang = $plugin_options['lang'] ? $plugin_options['lang'] : "de";

    $date = date('Y-m-d');
    $due_date_raw = strtotime($date . " + " . $sqrip_due_date . " days");
    $due_date = date('Y-m-d', $due_date_raw);

    $additional_information = $plugin_options['additional_information'];

    if ($additional_information) {
        $additional_information = sqrip_additional_information_shortcodes($additional_information, $lang, $due_date_raw, $order_number);
    }

    if ($iban == '') {
        $err_msg = __('Please add IBAN in the settings of your webshop or on the sqrip dashboard.', 'sqrip-swiss-qr-invoice');
        wc_add_notice($err_msg, 'error');
        return false;
    }

    if ($product == '') {
        $err_msg = __('Please select a product in the settings.', 'sqrip-swiss-qr-invoice');
        wc_add_notice($err_msg, 'error');
        return false;
    }

    $body = [
        "iban" => [
            "iban" => $iban,
        ],
        "payment_information" =>
            [
                "currency_symbol" => $currency_symbol,
                "amount" => $amount,
                "message" => $additional_information
            ],
        "lang" => $lang,
        "product" => $product,
        "source" => "woocommerce"
    ];

    // If the user selects "Order Number" the API request will include param "qr_reference"
    if ($qr_reference == "order_number") {
        $body['payment_information']['qr_reference'] = strval($order_number);
    }

    $iban_type = sqrip_validation_iban($iban, $token);

    if (isset($iban_type->message) && $initial_digits) {
        $body['payment_information']['initial_digits'] = $initial_digits;
    }

    if (!$additional_information) {
        unset($body['payment_information']['message']);
    }

    return $body;
}

/*
 *  Get payable to address
 */
function sqrip_get_payable_to_address($address = 'woocommerce')
{
    if (!$address) {
        return false;
    }

    switch ($address) {
        case 'sqrip':
            $result = sqrip_get_user_details();
            break;

        case 'woocommerce':

            if (empty(get_option('woocommerce_store_address')) || empty(get_option('woocommerce_store_address_2'))) {

                $result = [];

            } else {

                // The country/state
                $store_raw_country = get_option('woocommerce_default_country');

                // Split the country/state
                $split_country = explode(":", $store_raw_country);

                // Country and state separated:
                $store_country = $split_country[0];
                $address = get_option('woocommerce_store_address');
                $address .= get_option('woocommerce_store_address_2') ? ' / ' . get_option('woocommerce_store_address_2') : "";

                $result = array(
                    'name' => get_bloginfo('name'),
                    'street' => $address,
                    'town' => get_option('woocommerce_store_city'),
                    'postal_code' => get_option('woocommerce_store_postcode'),
                    'country_code' => $store_country,
                );

            }
            break;

        case 'individual':
            // sqrip Plugin Options
            $plugin_options = get_option('woocommerce_sqrip_settings', array());

            $result = array(
                'name' => $plugin_options['address_name'],
                'street' => $plugin_options['address_street'],
                'town' => $plugin_options['address_city'],
                'postal_code' => $plugin_options['address_postcode'],
                'country_code' => $plugin_options['address_country'],
            );
            break;
    }

    return $result;
}


function sqrip_get_payable_to_address_txt($address)
{

    $address_arr = sqrip_get_payable_to_address($address);

    if (!$address_arr) {
        return false;
    }

    return $address_txt = $address_arr['name'] . ', ' . $address_arr['street'] . ', ' . $address_arr['town'] . ' ' . $address_arr['postal_code'];
}


/*
 *  Get user details from sqrip api
 */
function sqrip_get_user_details($token = "", $return = "address")
{
    $endpoint = 'details';

    $body_decode = sqrip_remote_request($endpoint, '', 'GET', $token);

    if ($return == "full") {
        return $body_decode;
    }

    $result = [];

    if ($body_decode) {

        $address = isset($body_decode->user->address) ? $body_decode->user->address : [];

        $name = "";

        if (isset($address->title)) {

            $name = $address->title;

        } elseif (isset($body_decode->user)) {

            $name = $body_decode->user->first_name . ' ' . $body_decode->user->last_name;

        }

        if ($address) {
            $result = array(
                'town' => $address->city,
                'country_code' => $address->country_code,
                'name' => $name,
                'postal_code' => $address->zip,
                'street' => $address->street,
            );
        }

    }

    return $result;
}

/*
 *  sqrip validation IBAN
 */
function sqrip_validation_iban($iban, $tokens)
{
    $endpoint = 'validate-iban';

    $body = '{
        "iban": {
            "iban": "' . $iban . '",
            "iban_type": "simple"
        }
    }';

    $res_decode = sqrip_remote_request($endpoint, $body, $method = 'POST', $tokens);

    return $res_decode;
}

/*
 *  sqrip validation Token
 *  @deprecated since v1.0.3 | Use sqrip_get_user_details instead
 */
function sqrip_verify_token($token)
{
    if (!$token) {
        return;
    }

    $endpoint = 'validate-iban';
    $iban = 'CH5604835012345678009';
    $iban_type = 'simple';

    $body = '{
        "iban": {
            "iban": "' . $iban . '",
            "iban_type": "' . $iban_type . '"
        }
    }';

    $res_decode = sqrip_remote_request($endpoint, $body, $method = 'POST', $token);

    return $res_decode;
}

/**
 * Returns the iban number stored in the customer meta
 * @param $user
 *
 * @return mixed
 */
function sqrip_get_customer_iban($user)
{
    // TODO: make the field key customizable in the sqrip options
    return get_user_meta($user->ID, 'iban_num', true);
}

/**
 * Sets the iban number stored in customer meta
 * @param $user WP_User
 * @param $iban string
 * @return bool|int
 */
function sqrip_set_customer_iban($user, $iban)
{
    $args = array(
        'customer' => $user->ID,
        'status' => 'any',
    );
    $order_query = new WC_Order_Query($args);
    $orders = $order_query->get_orders();

    foreach ($orders as $order) {
        $order->update_meta_data('sqrip_refund_iban_num', $iban);
        $order->save();
    }

    // TODO: make the field key customizable in the sqrip options
    return update_user_meta($user->ID, 'iban_num', $iban);
}

function sqrip_get_wc_emails()
{
    $emails = wc()->mailer()->get_emails();
    $options = [];

    if ($emails && is_array($emails)) {
        foreach ($emails as $email) {
            $options[$email->id] = $email->title;
        }
    }

    return $options;
}


function sqrip_additional_information_shortcodes($additional_information, $lang, $due_date, $order_number)
{

    // get current language from WPML
    $current_lang = apply_filters('wpml_current_language', NULL);

    // get current language from plugin options
    if (!$current_lang) {
        $current_lang = sqrip_get_locale_by_lang($lang);
    }
    setlocale(LC_TIME, $current_lang);

    $date_shortcodes = [];
    // finds [due_date format="{format}"]
    preg_match_all('/\[due_date format="(.*)"\]/', $additional_information, $date_shortcodes);
    foreach ($date_shortcodes[0] as $index => $date_shortcode) {
        $format = $date_shortcodes[1][$index];
        $due_date_format = ucwords(\IntlDateFormatter::formatObject(\DateTime::createFromFormat('U', $due_date), $format));
        if (!$due_date_format) {
            continue;
        }
        $additional_information = str_replace($date_shortcode, $due_date_format, $additional_information);
    }

    // replace [order_number] with order number
    $additional_information = str_replace("[order_number]", $order_number, $additional_information);
    return $additional_information;
}

/**
 * Maps the language string used in the sqrip plugin to valid locale values for PHP / Wordpress / WPML
 * @param $lang
 * @return void
 */
function sqrip_get_locale_by_lang($lang)
{
    $locales = [
        'de' => 'de_DE.UTF-8',
        'fr' => 'fr_FR.UTF-8',
        'it' => 'it_IT.UTF-8',
        'en' => 'en_US.UTF-8'
    ];
    $locale = $locales[$lang];
    if (!$locale) {
        $locale = $lang;
    }
    return $locale;
}

function sqrip_file_name($order_id)
{
    $order = wc_get_order($order_id);
    $order_date = '';

    if ($order) {
        $order_date = $order->get_date_created()->date('Ymd');
    } else {
        $order_date = date("Ymd");
    }

    $sqrip_file_name = sqrip_get_plugin_option('file_name');

    // replace [order_number] with order number
    $sqrip_file_name = str_replace("[order_number]", $order_id, $sqrip_file_name);
    // replace [order_date] with order number
    $sqrip_file_name = str_replace("[order_date]", $order_date, $sqrip_file_name);
    // replace [order_number] with order number
    $sqrip_file_name = str_replace("[shop_name]", get_bloginfo('name'), $sqrip_file_name);

    $sqrip_file_name = sqrip_rename_if_duplicates_present($sqrip_file_name);

    if (!preg_match('/^([\w-]+)(?=\.[\w]+$)/', $sqrip_file_name . '.pdf')) {
        $sqrip_file_name = "$order_date" . '-' . get_bloginfo('name') . '-invoice-order-' . "$order_id";
    }

    return $sqrip_file_name;
}

function sqrip_rename_if_duplicates_present($sqrip_file_name)
{
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => -1,
        'post_status' => 'any',
        's' => $sqrip_file_name
    );
    $attachmentsWithSameFileName = count(get_posts($args));
    if ($attachmentsWithSameFileName > 0) {
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'posts_per_page' => -1,
            'post_status' => 'any',
            's' => $sqrip_file_name . '_'
        );
        $attachmentsWithCountFileName = count(get_posts($args)) + 1;

        $sqrip_file_name = $sqrip_file_name . '_' . sqrip_format_number($attachmentsWithCountFileName);
    }

    return $sqrip_file_name;
}

function sqrip_format_number($number)
{
    if ($number >= 0 && $number <= 999) {
        return sprintf("%03d", $number);
    }
    return $number;
}

/**
 * Turn off sqrip if error if auto turn-of enabled
 * since 1.8
 */
function sqrip_auto_turn_off() {
    $plugin_options = get_option('woocommerce_sqrip_settings', array());
    
    if ($plugin_options && isset($plugin_options['turn_off_if_error']) && $plugin_options['turn_off_if_error'] == 'yes') {
        $plugin_options['enabled'] = 'no';
        update_option('woocommerce_sqrip_settings', $plugin_options);
    }
}