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

/**
 * Countries a QR invoice may be created for.
 *
 * @since 1.10.4
 * @return string[] Upper case ISO 3166-1 alpha-2 codes, empty if nothing is configured.
 */
function sqrip_get_allowed_invoice_countries()
{
    $countries = sqrip_get_plugin_option('allowed_invoice_countries');

    if (!is_array($countries)) {
        return array();
    }

    $countries = array_map(function ($country) {
        return strtoupper(trim((string) $country));
    }, $countries);

    return array_values(array_filter($countries));
}

/**
 * Is the country restriction switched on and usable?
 *
 * This is the single off-switch for the whole feature. It reads the stored option, and
 * sqrip_get_plugin_option() deliberately ignores the form-field defaults — so on a shop
 * that simply updates and changes nothing, the option is absent and this returns false.
 * Nothing about such a shop's checkout changes.
 *
 * A switched-on but empty country list would block every single order, which is never
 * what the shop meant. That counts as switched off as well.
 *
 * @since 1.10.4
 * @return bool
 */
function sqrip_country_gatekeeper_active()
{
    if (sqrip_get_plugin_option('country_restriction_enabled') !== 'yes') {
        return false;
    }

    return !empty(sqrip_get_allowed_invoice_countries());
}

/**
 * May a QR invoice be created for this invoice country?
 *
 * A QR invoice is always in Swiss francs. Sending one to a customer abroad causes
 * confusion and bank charges, so the shop can limit it to the countries it wants.
 * (NET2-2329)
 *
 * @since 1.10.4
 * @param string $country Two letter country code of the billing address.
 * @return bool
 */
function sqrip_is_invoice_country_allowed($country)
{
    if (!sqrip_country_gatekeeper_active()) {
        return true;
    }

    $country = strtoupper(trim((string) $country));

    // Nothing to judge by. Let it pass rather than block an order over missing data.
    if ($country === '') {
        return true;
    }

    return in_array($country, sqrip_get_allowed_invoice_countries(), true);
}

/**
 * May a QR invoice be created for this order?
 *
 * Judged by the invoice (billing) address, because that is the address printed on the
 * QR bill as the debtor and the one the customer pays from.
 *
 * @since 1.10.4
 * @param WC_Order $order
 * @return bool
 */
function sqrip_order_allows_qr_invoice($order)
{
    if (!is_a($order, 'WC_Order')) {
        return true;
    }

    return sqrip_is_invoice_country_allowed($order->get_billing_country());
}

/**
 * Record on the order why no QR invoice was created, so the shop is not left guessing.
 *
 * @since 1.10.4
 * @param WC_Order $order
 * @return void
 */
function sqrip_add_country_blocked_order_note($order)
{
    if (!is_a($order, 'WC_Order')) {
        return;
    }

    $order->add_order_note(
        sprintf(
            /* translators: %s: two letter country code of the invoice address */
            __('No sqrip QR invoice was created: the invoice address is in %s, which is not among the countries selected in the sqrip settings.', 'sqrip-swiss-qr-invoice'),
            $order->get_billing_country()
        )
    );
}

/**
 * Whether a stored file reference can actually be used as a link target.
 *
 * The clean-up writes the literal string 'deleted' into sqrip_pdf_file_url and friends.
 * That string is truthy, so a plain `if ($url)` produced a download button pointing at
 * href="deleted", which the browser resolved to "http://deleted".
 *
 * @param mixed $value Stored meta value.
 * @return bool
 */
function sqrip_is_usable_file_url($value)
{
    if (!is_string($value) || $value === '' || $value === 'deleted') {
        return false;
    }

    return (bool) filter_var($value, FILTER_VALIDATE_URL);
}

/**
 * Order statuses in which the customer may still pay.
 *
 * WooCommerce's own pre-payment statuses plus whatever this shop configured — including
 * the partial-payment statuses, because a partially paid order is not settled yet. Used
 * both for hiding the download block and for protecting files from the clean-up, so the
 * two can never drift apart.
 *
 * @return array Status slugs without the 'wc-' prefix.
 */
function sqrip_awaiting_payment_statuses()
{
    $statuses = array('pending', 'on-hold', 'failed', 'checkout-draft');

    $option_keys = array(
        'status_awaiting',
        'qr_order_status',
        'status_suppressed',
        'partial_invoice_1_status',
        'partial_invoice_2_status',
        'partial_invoice_3_status',
    );

    foreach ($option_keys as $key) {
        $status = sqrip_get_plugin_option($key);

        if ($status) {
            $statuses[] = str_replace('wc-', '', (string) $status);
        }
    }

    return array_values(array_unique(array_filter($statuses)));
}

/**
 * Whether the order is still waiting for a payment.
 *
 * @param WC_Order $order
 * @return bool
 */
function sqrip_order_awaits_payment($order)
{
    if (!is_object($order) || !method_exists($order, 'has_status')) {
        return false;
    }

    if (method_exists($order, 'needs_payment') && $order->needs_payment()) {
        return true;
    }

    return (bool) $order->has_status(sqrip_awaiting_payment_statuses());
}

/**
 * Customer-facing text in the form of address the shop configured.
 *
 * Only German has an informal variant here: the informal wording is a fixed German
 * sentence, so for any other site language we deliberately fall back to the regular
 * translated text instead of leaking German onto a French or Italian shop.
 *
 * @param string $key Text identifier.
 * @return string
 */
function sqrip_frontend_text($key)
{
    $informal = sqrip_get_plugin_option('frontend_anrede') === 'du';
    $is_german = strpos((string) get_locale(), 'de') === 0;

    switch ($key) {
        case 'pay_with_qr_invoice':
            if ($informal && $is_german) {
                return 'Verwende die untenstehende QR-Rechnung, um den ausstehenden Betrag zu bezahlen.';
            }

            return __('Use the QR invoice below to pay the outstanding balance.', 'sqrip-swiss-qr-invoice');

        case 'qr_invoice_failed':
            if ($informal && $is_german) {
                return 'Die QR-Rechnung konnte im Moment leider nicht erstellt werden. Bitte versuche es später erneut, wende dich an den Shop oder wähle eine andere Zahlungsart.';
            }

            return __("It seems we couldn't provide you with a QR-invoice at this time. Please try later, contact the shop or use a different payment method.", 'sqrip-swiss-qr-invoice');

        case 'country_blocked':
            if ($informal && $is_german) {
                return 'Die QR-Rechnung steht für deine Rechnungsadresse nicht zur Verfügung. Bitte wähle eine andere Zahlungsart.';
            }

            return __('The QR invoice is not available for your invoice address. Please choose a different payment method.', 'sqrip-swiss-qr-invoice');
    }

    return '';
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
    $building_number_meta = $order->get_meta('_billing_sqrip_building_num', true);

    $billing_address = array(
        'name' => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
        'street' => $order_data['billing']['address_1'],
        'building_number' => ($order_data['billing']['address_2'] ? $order_data['billing']['address_2'] :
            ( !empty($building_number_meta) ? $building_number_meta : "")),
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

    // Read every setting defensively. Keys that were introduced by a later release are
    // simply absent until the merchant saves the settings screen again, so these direct
    // reads produced a batch of PHP 8 "Undefined array key" warnings on every single
    // checkout — and with display_errors on, those warnings corrupt the checkout AJAX
    // response and the order can no longer be placed.
    $sqrip_due_date = isset($plugin_options['due_date']) ? $plugin_options['due_date'] : '';
    $iban = isset($plugin_options['iban']) ? $plugin_options['iban'] : '';
    $token = isset($plugin_options['token']) ? $plugin_options['token'] : '';
    $initial_digits = isset($plugin_options['qr_reference_format']) ? $plugin_options['qr_reference_format'] : '';

    $product = isset($plugin_options['product']) ? $plugin_options['product'] : '';
    $qr_reference = isset($plugin_options['qr_reference']) ? $plugin_options['qr_reference'] : '';
    $address = isset($plugin_options['address']) ? $plugin_options['address'] : '';
    $lang = !empty($plugin_options['lang']) ? $plugin_options['lang'] : "de";
    $add_to_pdf_invoice = sqrip_get_plugin_option('pdf_invoice_integration');

    $date = date('Y-m-d');

    // A missing or non-numeric due date made strtotime() return false, and date('Y-m-d',
    // false) silently yields 1970-01-01 — printing a 1970 payment deadline on a real QR
    // bill with nothing in any log. Fall back to the field default instead.
    if (!is_numeric($sqrip_due_date)) {
        $sqrip_due_date = 30;
    }

    $due_date_raw = strtotime($date . " + " . (int) $sqrip_due_date . " days");
    $due_date = date('Y-m-d', $due_date_raw);

    $additional_information = isset($plugin_options['additional_information']) ? $plugin_options['additional_information'] : '';
    $partial_invoice_information = isset($plugin_options['partial_invoice_information']) ? $plugin_options['partial_invoice_information'] : '';
    $multiple_qr_slips_enabled = isset($plugin_options['multiple_qr_slips_enabled']) ? $plugin_options['multiple_qr_slips_enabled'] : 'no';
    $number_of_invoices = isset($plugin_options['number_of_invoices']) ? $plugin_options['number_of_invoices'] : 0;

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

    if ($add_to_pdf_invoice == 'yes') {
        $product = 'Invoice Slip';
    }

    if ($multiple_qr_slips_enabled && $partial_invoice_information) {
        $partial_invoice_information = str_replace("[max_invoice]", $number_of_invoices, $partial_invoice_information);
            
    } else {
        $partial_invoice_information = "";
    }

    $body = [
        "iban" => [
            "iban" => $iban,
        ],
        "payment_information" =>
            [
                "currency_symbol" => $currency_symbol,
                "amount" => $amount,
                "message" => $additional_information,
                "partial_invoice_message" => $partial_invoice_information
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

            if (empty(get_option('woocommerce_store_address')) && empty(get_option('woocommerce_store_address_2'))) {

                $result = [];

            } else {

                // The country/state
                $store_raw_country = get_option('woocommerce_default_country');

                // Split the country/state
                $split_country = explode(":", $store_raw_country);

                // Country and state separated:
                $store_country = $split_country[0];
                $address = get_option('woocommerce_store_address');
                $building_number = get_option('woocommerce_store_address_2') ? get_option('woocommerce_store_address_2') : "";

                $result = array(
                    'name' => get_bloginfo('name'),
                    'street' => $address,
                    'building_number' => ($building_number ? $building_number : ""),
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
                'building_number' => ($plugin_options['address_building_number'] ? $plugin_options['address_building_number'] : ""),
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
                // The sqrip account keeps street and building number apart, exactly as the
                // structured addresses require, and GET /details returns both. This branch
                // used to drop the number, so shops set to "from the sqrip account" printed
                // a payee address without it — while the other two address sources, and the
                // payer address, have always passed it on.
                'building_number' => isset($address->building_number) ? $address->building_number : "",
            );
        }

    }

    return $result;
}

/*
 *  sqrip validation IBAN
 *
 *  The validation result for a given IBAN + token is deterministic and rarely
 *  changes, but this function is reached on the checkout / QR-generation path.
 *  Without caching, every such request makes a blocking ~0.25s call to
 *  api.sqrip.ch (validate-iban). We cache the result in a transient so only the
 *  first request per (iban, token) pair hits the API. The admin "Check" button
 *  passes $force_refresh = true to always re-query and refresh the cache.
 *
 *  @param string $iban
 *  @param string $tokens
 *  @param bool   $force_refresh  Bypass the cache and re-query the API.
 */
function sqrip_validation_iban($iban, $tokens, $force_refresh = false)
{
    $endpoint = 'validate-iban';

    $cache_key = 'sqrip_ibanval_' . md5($iban . '|' . $tokens);

    if (!$force_refresh) {
        $cached = get_transient($cache_key);
        // Stored values are always objects, so a strict !== false means "cached".
        if ($cached !== false) {
            return $cached;
        }
    }

    $body = '{
        "iban": {
            "iban": "' . $iban . '",
            "iban_type": "simple"
        }
    }';

    $res_decode = sqrip_remote_request($endpoint, $body, $method = 'POST', $tokens);

    // Only cache a well-formed API response; never persist transient/network errors.
    if (is_object($res_decode) && isset($res_decode->message)) {
        set_transient($cache_key, $res_decode, 12 * HOUR_IN_SECONDS);
    }

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
    // $options = ["sqrip-do-not-attach" => __('Do not attach to any e-mail', 'sqrip-swiss-qr-invoice'),];

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
    // $current_lang = apply_filters('wpml_current_language', NULL);
    $current_lang = get_locale();

    // get current language from plugin options
    if (!$current_lang) {
        $current_lang = sqrip_get_locale_by_lang($lang);
    }
    setlocale(LC_TIME, $current_lang);

    $date_shortcodes = [];
    // finds [due_date format="{format}"] — non-greedy, so two shortcodes in one message
    // do not swallow everything between the first format=" and the last "].
    preg_match_all('/\[due_date format="(.*?)"\]/', $additional_information, $date_shortcodes);

    // IntlDateFormatter needs the intl extension, which is not guaranteed on shared
    // hosting. Without this guard a due-date shortcode was a fatal "class not found" on
    // every sqrip checkout: the order was created, no QR invoice existed and the
    // customer saw a white screen. Fall back to WordPress' own date formatting.
    $has_intl = class_exists('IntlDateFormatter');

    foreach ($date_shortcodes[0] as $index => $date_shortcode) {
        $format = $date_shortcodes[1][$index];

        if ($has_intl) {
            $formatter = new IntlDateFormatter(
                $current_lang,
                IntlDateFormatter::FULL,
                IntlDateFormatter::NONE,
                null,
                null,
                $format
            );
            $due_date_format = $formatter->format($due_date);
        } else {
            // Best-effort mapping of the documented ICU patterns to PHP date() ones.
            $php_format = str_replace(
                array('yyyy', 'y', 'MMMM', 'MMM', 'MM', 'dd', 'd'),
                array('Y', 'Y', 'F', 'M', 'm', 'd', 'j'),
                $format
            );
            $due_date_format = date_i18n($php_format, $due_date);
        }
        // $due_date_format = strftime($format, $due_date);

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

function sqrip_file_name($order_id, $is_refund=false)
{
    $order = wc_get_order($order_id);
    $order_date = '';

    if ($order && !$is_refund) {
        $order_date = $order->get_date_created()->date('YmdHis');
    } else {
        $order_date = date("YmdHis");
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

/**
 * Is the message the API sent back too generic to put in front of a shop manager?
 *
 * When the Swiss QR-bill generator refuses the invoice data, the API answers HTTP 200
 * with message "Server Error" and no reference. Copied into an order note verbatim that
 * reads like an outage on our side, while it is really the shop's own data — wrong IBAN,
 * a reference the format does not allow, an address field too long. (NET2-2339)
 *
 * @since 1.10.5
 * @param string $message
 * @return bool
 */
function sqrip_api_message_is_generic($message)
{
    $message = trim((string) $message);

    if ($message === '') {
        return true;
    }

    return strcasecmp($message, 'Server Error') === 0;
}

/**
 * What to tell the shop when the API refused to create a QR invoice.
 *
 * Keeps the API's own wording whenever it says something useful, and replaces it with a
 * plain sentence when it does not. The raw text is still appended, so support can see
 * what really came back.
 *
 * @since 1.10.5
 * @param object|null $response_body Decoded API response.
 * @return string
 */
function sqrip_api_error_summary($response_body)
{
    $raw = (is_object($response_body) && isset($response_body->message))
        ? (string) $response_body->message
        : '';

    if (!sqrip_api_message_is_generic($raw)) {
        return $raw;
    }

    $summary = __('The QR invoice could not be created because the payment data was not accepted. Please check the IBAN, the reference format and the addresses.', 'sqrip-swiss-qr-invoice');

    if (trim($raw) === '') {
        return $summary;
    }

    return sprintf(
        /* translators: 1: plain explanation, 2: verbatim message from the sqrip API */
        __('%1$s (technical message: %2$s)', 'sqrip-swiss-qr-invoice'),
        $summary,
        $raw
    );
}

/**
 * The field-level complaints from the API, turned into something readable.
 *
 * Both error paths of the manual QR creation use this, so a shop sees the same
 * explanation whether the API answered 200-without-reference or a 4xx.
 *
 * @since 1.10.5
 * @param object|null $response_body Decoded API response.
 * @return string Empty when the API named no fields.
 */
function sqrip_api_error_details($response_body)
{
    if (!is_object($response_body) || !isset($response_body->errors)) {
        return '';
    }

    $errors = (array) $response_body->errors;

    if (!$errors) {
        return '';
    }

    $keys = array_map('strtolower', array_keys($errors));

    $mentions = function ($needles) use ($keys) {
        foreach ($keys as $key) {
            foreach ((array) $needles as $needle) {
                if (strpos($key, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    };

    $hints = array();

    // Unchanged from before: the one case the plugin already explained in plain words.
    if (isset($errors['payable_by.name']) || isset($errors['payable_by.company'])) {
        $hints[] = __('Please submit at least either Name / Last name or Company name to generate the qr-invoice', 'sqrip-swiss-qr-invoice');
    }

    if ($mentions('iban')) {
        $hints[] = __('The IBAN was not accepted. Check it under Payee in the sqrip settings — note that a QR-IBAN always needs a QR reference.', 'sqrip-swiss-qr-invoice');
    }

    if ($mentions('reference')) {
        $hints[] = __('The reference number was not accepted. Check the reference settings of the payment method.', 'sqrip-swiss-qr-invoice');
    }

    if ($mentions(array('street', 'city', 'postal', 'zip', 'address', 'building', 'country'))) {
        $hints[] = __('An address was not accepted — a field is empty, too long, or contains characters a QR bill does not allow.', 'sqrip-swiss-qr-invoice');
    }

    // List what the API actually named as well, so nothing is hidden behind the hints.
    $fields = array();

    foreach ($errors as $field => $messages) {
        $messages = is_array($messages) ? $messages : array($messages);
        $fields[] = $field . ': ' . implode(' ', array_map('strval', $messages));
    }

    return trim(implode(' ', $hints) . ' ' . implode(' | ', $fields));
}

/**
 * Returns the meta value for an order that matches the meta_key
 * Checks if the WooCommerce HPOS is enabled
 * @param $order
 * @param $meta_key
 *
 * @return mixed
 * since 1.9
 */
function sqrip_get_order_meta_value($order, $meta_key)
{
    $isHPOS = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
    $meta_value = $isHPOS ? 
        $order->get_meta($meta_key, true) : 
        get_post_meta($order_id, $meta_key, true);

    return $meta_value;
}

/**
 * Returns the formatted value for an invoice reference id
 * @param $reference_id
 *
 * @return mixed
 * since 1.9
 */
function sqrip_format_reference_id($reference_id, $order_id)
{
    $qr_basis = sqrip_get_plugin_option("qr_reference");
    $reference_id_formatted = esc_html($reference_id);
    $order_id = (string) $order_id;

    switch ($qr_basis) {
        case 'order_number':
            if (strpos(strtolower($reference_id_formatted), 'rf') !== false) {
                if (endsWith($reference_id_formatted, $order_id)) {
                    $reference_id_formatted = sqrip_reference_id_format_with_order_id ($reference_id_formatted, $order_id, 4);
                } else {
                    $reference_id_formatted = sqrip_default_reference_id_formatting($reference_id_formatted);
                }
            } else {
                $control_digit = substr($reference_id_formatted, -1);
                $reference_without_control_digit = substr($reference_id_formatted, 0, -1);
                $ref_ends_with_order_id = endsWith($reference_without_control_digit, $order_id);

                if ($ref_ends_with_order_id) {
                    $reference_id_formatted = sqrip_reference_id_format_with_order_id ($reference_id_formatted, $order_id, 2)." ".$control_digit;
                } else {
                    $reference_id_formatted = sqrip_default_reference_id_formatting($reference_id_formatted);
                }

            }

            break;
        case 'random':
            $reference_id_formatted = sqrip_default_reference_id_formatting($reference_id_formatted);
            break;
        
        default:
            $reference_id_formatted = sqrip_default_reference_id_formatting($reference_id_formatted);
            break;
    }

    return $reference_id_formatted;
}

function sqrip_default_reference_id_formatting ($reference_id_formatted) {
    if (strpos(strtolower($reference_id_formatted), 'rf') !== false) {
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 4, 0);
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 9, 0);
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 14, 0);
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 19, 0);
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 24, 0);
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 29, 0);
    } else {
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 2, 0);
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 8, 0);
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 14, 0);
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 20, 0);
        $reference_id_formatted = substr_replace($reference_id_formatted, " ", 26, 0);
    }
    return $reference_id_formatted;
}

function endsWith( $haystack, $needle ) {
    $length = strlen( $needle );
    if( !$length ) {
        return true;
    }
    return substr( $haystack, -$length ) === $needle;
}

function sqrip_reference_id_format_with_order_id ($reference_id_formatted, $order_id, $start_length) {
    $start_str = substr($reference_id_formatted, 0, $start_length);
    $reference_id_formatted = substr($reference_id_formatted, $start_length);
    $reference_id_formatted = substr($reference_id_formatted, 0, -strlen($order_id));

    while (strlen($reference_id_formatted) > 5) {
        $start_str .= " ".substr($reference_id_formatted, 0, 5);
        $reference_id_formatted = substr($reference_id_formatted, 5);
    }

    return $start_str." ".$reference_id_formatted." <b>".$order_id."</b>";
}


/*
*  sqrip QR Code PDF  Download in medialibrary and set
*  static function version
*/
function file_upload_stt($fileurl, $type, $token = "", $order_id = "", $is_refund = false)
{
    // Validate the URL BEFORE file_get_contents(): on PHP 8 an empty or null path throws
    // a ValueError, i.e. a fatal on the checkout request, not a warning as on 7.4.
    if (!is_string($fileurl) || $fileurl === '') {
        return 0;
    }

    include_once(ABSPATH . 'wp-admin/includes/image.php');

    $sqrip_name = sqrip_file_name($order_id, $is_refund);
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

    // Same failure handling as WC_Sqrip_Payment_Gateway::file_upload(): an unchecked
    // download produced 0-byte "invoices", and fwrite() on a failed fopen() is a fatal
    // TypeError on PHP 8. Return 0 so callers can report a real error.
    $contents = file_get_contents($fileurl, false, $context);

    if ($contents === false || $contents === '') {
        return 0;
    }

    $savefile = fopen($uploadfile, 'w');

    if (!$savefile) {
        return 0;
    }

    $written = fwrite($savefile, $contents);
    fclose($savefile);

    if ($written === false || !file_exists($uploadfile) || filesize($uploadfile) === 0) {
        return 0;
    }

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

    if (is_wp_error($attach_id) || !$attach_id) {
        return 0;
    }

    // Generate the metadata for the attachment, and update the database record.
    $attach_data = wp_generate_attachment_metadata($attach_id, $uploadfile);
    wp_update_attachment_metadata($attach_id, $attach_data);

    return $attach_id;
}

/**
 * from abstract-wc-payment-gateway.php - static function version
 * Get the return url (thank you page).
 *
 * @param WC_Order|null $order Order object.
 * @return string
 */
function get_return_url_stt( $order = null ) {
    if ( $order ) {
        $return_url = $order->get_checkout_order_received_url();
    } else {
        $return_url = wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );
    }

    /**
     * Filter the return url.
     *
     * @since 2.4.0
     * @param string $return_url Return URL.
     * @param WC_Order|null $order Order object.
     * @return string
     */
    return apply_filters( 'woocommerce_get_return_url', $return_url, $order );
}


/*
 *  Processing payment - static function version
 */
function process_payment_stt($order_id)
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
    
    $add_to_pdf_invoice = sqrip_get_plugin_option('pdf_invoice_integration');

    if ($suppress_generation == "yes") {
        $order->update_status('pending');

        return array(
            'result' => 'success',
            'redirect' => get_return_url_stt($order),
        );
    }

    $body = sqrip_prepare_qr_code_request_body($currency_symbol, $amount, strval($order_id));
    $body["payable_by"] = sqrip_get_billing_address_from_order($order);
    $body['payable_to'] = sqrip_get_payable_to_address($address);

    $multiple_invoices_enabled = sqrip_get_plugin_option('multiple_qr_slips_enabled') == 'yes';
    
    $number_of_invoices = sqrip_get_plugin_option('number_of_invoices');
    $is_multiple_invoices = $multiple_invoices_enabled && ($number_of_invoices && $number_of_invoices > 1);
    if ($is_multiple_invoices) {
        $body["invoice_fractions"] = [];
        for ($i=1; $i <= $number_of_invoices; $i++) {
            $invoice_fraction = sqrip_get_plugin_option('invoice_fraction_'.$i);
            if ($invoice_fraction) {
                $body["invoice_fractions"][$i] = $invoice_fraction;
            }
        }
    }

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
        $customer_msg = sqrip_frontend_text('qr_invoice_failed');
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
    // error_log($response_body);
    $response_body = json_decode($response_body);
    // count() on a non-array is a fatal TypeError on PHP 8: an HTTP 200 whose body is not
    // valid JSON (proxy interstitial, truncated or gzip-mangled response) makes
    // json_decode() return null, which used to take down the checkout request *after* the
    // sqrip credit had already been spent. On PHP 7.4 the same line only warned.
    $response_reference = $is_multiple_invoices
        ? (isset($response_body[0]->reference) ? $response_body[0]->reference : null)
        : (isset($response_body->reference) ? $response_body->reference : null);

    if (isset($response_reference)) {

        if ($is_multiple_invoices) {
            $num = 1;
            foreach ($response_body as $key => $invoice) {

                $sqrip_pdf = $invoice->pdf_file;
                $sqrip_reference = $invoice->reference;
                $sqrip_partial_amount = $invoice->amount;
                $partial_invoice_fraction = sqrip_get_plugin_option('invoice_fraction_'.$num);
    
                $sqrip_qr_pdf_attachment_id = file_upload_stt($sqrip_pdf, '.pdf', '', $order_id."-".$num);
    
                $sqrip_qr_pdf_url = wp_get_attachment_url($sqrip_qr_pdf_attachment_id);
                $sqrip_qr_pdf_path = get_attached_file($sqrip_qr_pdf_attachment_id);

                $order->update_meta_data('sqrip_reference_id_'.$num, $sqrip_reference);
                $order->update_meta_data('sqrip_qr_pdf_attachment_id_'.$num, $sqrip_qr_pdf_attachment_id);
                $order->update_meta_data('sqrip_pdf_file_url_'.$num, $sqrip_qr_pdf_url);
                $order->update_meta_data('sqrip_pdf_file_path_'.$num, $sqrip_qr_pdf_path);
                $order->update_meta_data('sqrip_partial_invoice_amount_'.$num, $sqrip_partial_amount);
                $order->update_meta_data('sqrip_partial_invoice_fraction_'.$num, $partial_invoice_fraction);
                $num++;
            }

            $order->add_order_note(__('sqrip QR Invoices created.', 'sqrip-swiss-qr-invoice'));
            $order->update_meta_data('sqrip_multiple_invoice_count', $number_of_invoices);
            $order->update_meta_data('sqrip_paid_invoice_number', '0');
        }
        else {
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
            $sqrip_qr_pdf_attachment_id = file_upload_stt($sqrip_pdf, '.pdf', '', $order_id);
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

            if ($add_to_pdf_invoice == 'yes') {
                $sqrip_png = $response_body->png_file;
                $order->update_meta_data('sqrip_png_file_url', $sqrip_png);
            }
        }
        
        $order->update_meta_data('sqrip_refund_iban_num', get_user_meta($order->get_user_id(), 'iban_num', true));

        // $order->update_meta_data('sqrip_png_file_url', $sqrip_qr_png_url);
        // $order->update_meta_data('sqrip_png_file_path', $sqrip_qr_png_path);

        // Empty the cart (Very important step)
        $woocommerce->cart->empty_cart();
        $order->save();

        // Array access on an object is a fatal Error, so only index when it really is an
        // array (the API returns an object for a single invoice).
        $invoice_info = ($is_multiple_invoices && isset($response_body[0])) ? $response_body[0] : $response_body;
        if (isset($invoice_info->total_codes_left) && $invoice_info->total_codes_left <= 0) {
            // turn off sqrip if auto turn-off enabled
            sqrip_auto_turn_off();
        }
        // Redirect to thank you page
        return array(
            'result' => 'success',
            'redirect' => get_return_url_stt($order),
        );
    } else {

        $customer_msg = sqrip_frontend_text('qr_invoice_failed');
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

/**
 * Update a single sqrip plugin option
 * since 1.9
 * 
 * @param string $key
 * @param mixed $value
 *
 * @return void
 */
function update_sqrip_options($key, $value) {
    $plugin_options = get_option('woocommerce_sqrip_settings', array());
    $plugin_options[$key] = $value;
    update_option('woocommerce_sqrip_settings', $plugin_options);
}