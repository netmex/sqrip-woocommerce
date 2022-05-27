<?php

function sqrip_get_plugin_option($key) {
	$plugin_options     = get_option('woocommerce_sqrip_settings', array());
	if($plugin_options && array_key_exists($key, $plugin_options)) {
		return $plugin_options[$key];
	}
	return null;
}

function sqrip_prepare_remote_args($body, $method, $token = null) {
	$plugin_options     = get_option('woocommerce_sqrip_settings', array());
	$token              = $token ? $token : $plugin_options['token'];

	$args = [];
	$args['method'] = $method;
	$args['headers'] = [
		'Content-Type'  => 'application/json',
		'Authorization' => 'Bearer '.$token,
		'Accept'        => 'application/json'
	];

	if(!is_string($body)) {
		$body = json_encode($body);
	}

	$args['body'] = $body;
	return $args;
}

function sqrip_remote_request( $endpoint, $body = '', $method = 'GET', $token = "" )
{
    $args = sqrip_prepare_remote_args($body, $method, $token);

    $response = wp_remote_request(SQRIP_ENDPOINT.$endpoint, $args);

    if ( is_wp_error($response) ) return;

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
function sqrip_get_billing_address_from_order($order) {
	$order_data = $order->get_data();
    $company = isset($order_data['billing']['company']) ? $order_data['billing']['company'] : "";

	$billing_address = array(
		'name'            => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
		'street'          => $order_data['billing']['address_1'] . ($order_data['billing']['address_2'] ? ', ' . $order_data['billing']['address_2'] : ""),
		'postal_code'     => intval($order_data['billing']['postcode']),
		'town' => $order_data['billing']['city'],
		'country_code'    => $order_data['billing']['country']
	);

    if ( !empty($company) ) {
        $billing_address['company'] = $company;
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
function sqrip_prepare_qr_code_request_body($currency_symbol, $amount, $order_number) {
	$plugin_options         = get_option('woocommerce_sqrip_settings', array());
	$sqrip_due_date         = $plugin_options['due_date'];
	$iban                   = $plugin_options['iban'];

	$product                = $plugin_options['product'];
	$qr_reference           = $plugin_options['qr_reference'];
	$address                = $plugin_options['address'];
	$lang                   = $plugin_options['lang'] ? $plugin_options['lang'] : "de";

	$date                   = date('Y-m-d');
	$due_date_raw           = strtotime($date . " + ".$sqrip_due_date." days");
    $due_date               = date('Y-m-d', $due_date_raw);

    $additional_information = $plugin_options['additional_information'];

    if($additional_information) {
        $additional_information = sqrip_additional_information_shortcodes($additional_information, $lang, $due_date_raw, $order_number);
    }

	if ($iban == '') {
		$err_msg = __( 'Please add IBAN in the settings of your webshop or on the sqrip dashboard.', 'sqrip-swiss-qr-invoice' );
		wc_add_notice($err_msg, 'error');
		return false;
	}

	if ($product == '') {
		$err_msg = __( 'Please select a product in the settings.', 'sqrip-swiss-qr-invoice' );
		wc_add_notice($err_msg, 'error');
		return false;
	}

	$body = [
		"iban" => [
			"iban"  => $iban,
		],
		"payment_information" =>
			[
				"currency_symbol"   => $currency_symbol,
				"amount"            => $amount,
                "message"           => $additional_information
			],
		"lang"      => $lang,
		"product"   => $product,
		"source"    => "woocommerce"
	];

	// If the user selects "Order Number" the API request will include param "qr_reference"
	if ( $qr_reference == "order_number" ) {
		$body['payment_information']['qr_reference'] = strval($order_number);
	}

	return $body;
}

/*
 *  Get payable to address
 */
function sqrip_get_payable_to_address($address = 'woocommerce')
{
	if(!$address) {
		return false;
	}

    switch ($address) {
    	case 'sqrip':
    		$result = sqrip_get_user_details();
    		break;
    	
    	case 'woocommerce':

            if ( empty(get_option( 'woocommerce_store_address' )) || empty(get_option( 'woocommerce_store_address_2' )) ) {

                $result = [];
                
            } else {

        		// The country/state
    		    $store_raw_country = get_option( 'woocommerce_default_country' );

    		    // Split the country/state
    		    $split_country = explode( ":", $store_raw_country );

    		    // Country and state separated:
    		    $store_country = $split_country[0];
                $address = get_option( 'woocommerce_store_address' );
                $address .= get_option( 'woocommerce_store_address_2' ) ? ' / '.get_option( 'woocommerce_store_address_2' ) : "";
                
    		    $result = array(
    		        'name' => get_bloginfo('name'),
    		        'street' => $address,
    		        'city' => get_option( 'woocommerce_store_city' ),
    		        'postal_code' => get_option( 'woocommerce_store_postcode' ),
    		        'country_code' => $store_country,
    		    );

            }
    		break;

        case 'individual':
            // sqrip Plugin Options 
            $plugin_options     = get_option('woocommerce_sqrip_settings', array());

            $result = array(
                'name' => $plugin_options['address_name'],
                'street' => $plugin_options['address_street'],
                'city' => $plugin_options['address_city'],
                'postal_code' => $plugin_options['address_postcode'],
                'country_code' => $plugin_options['address_country'],
            );
            break;
    }
   
    return $result;            
}


function sqrip_get_payable_to_address_txt($address){

    $address_arr = sqrip_get_payable_to_address($address);

    if ( !$address_arr ) {
        return false;
    }

    return $address_txt = $address_arr['name'].', '.$address_arr['street'].', '.$address_arr['city'].' '.$address_arr['postal_code'];
}


/*
 *  Get user details from sqrip api 
 */
function sqrip_get_user_details($token = "")
{
	$endpoint = 'details';

    $body_decode   = sqrip_remote_request($endpoint, '', 'GET', $token); 

    $result = []; 

    if ($body_decode) {

        $address = isset($body_decode->user->address) ? $body_decode->user->address : [];

        $name = "";

        if (isset($address->title)) {

            $name = $address->title;

        } elseif (isset($body_decode->user)) {

            $name = $body_decode->user->first_name.' '.$body_decode->user->last_name;

        }
        
        if ($address) {
            $result = array(
                'city' => $address->city,
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
            "iban": "'.$iban.'",
            "iban_type": "simple"
        }
    }';

    $res_decode  = sqrip_remote_request($endpoint, $body, $method = 'POST', $tokens);   
    
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
            "iban": "'.$iban.'",
            "iban_type": "'.$iban_type.'"
        }
    }';

    $res_decode  = sqrip_remote_request($endpoint, $body, $method = 'POST', $token);   
    
    return $res_decode;
}

/**
 * Returns the iban number stored in the customer meta
 * @param $user
 *
 * @return mixed
 */
function sqrip_get_customer_iban($user) {
	// TODO: make the field key customizable in the sqrip options
	return get_user_meta($user->ID, 'iban_num', true);
}

/**
 * Sets the iban number stored in customer meta
 * @param $user WP_User
 * @param $iban string
 * @return bool|int
 */
function sqrip_set_customer_iban($user, $iban) {
    // TODO: make the field key customizable in the sqrip options
    return update_user_meta($user->ID, 'iban_num', $iban);
}

function sqrip_get_wc_emails(){
    $emails = wc()->mailer()->get_emails();
    $options = [];

    if ($emails && is_array($emails)) {
        foreach ($emails as $email) {
            $options[$email->id] =  $email->title;
        }
    }

    return $options;
}


function sqrip_additional_information_shortcodes($additional_information, $lang, $due_date, $order_number) {

    // get current language from WPML
    $current_lang = apply_filters( 'wpml_current_language', NULL );

    // get current language from plugin options
    if(!$current_lang) {
        $current_lang = sqrip_get_locale_by_lang($lang);
    }
    setlocale(LC_TIME, $current_lang);

    $date_shortcodes = [];
    // finds [due_date format="{format}"]
    preg_match_all('/\[due_date format="(.*)"\]/', $additional_information, $date_shortcodes);
    foreach($date_shortcodes[0] as $index=>$date_shortcode) {
        $format = $date_shortcodes[1][$index];
        $due_date_format = strftime($format, $due_date);
        if(!$due_date_format) {
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
function sqrip_get_locale_by_lang($lang) {
    $locales = [
        'de' => 'de_DE',
        'fr' => 'fr_FR',
        'it' => 'it_IT',
        'en' => 'en_US'
    ];
    $locale = $locales[$lang];
    if(!$locale) {
        $locale = $lang;
    }
    return $locale;
}