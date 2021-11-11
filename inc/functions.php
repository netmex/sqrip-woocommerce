<?php 

function sqrip_remote_request( $endpoint, $body = '', $method = 'GET', $token = "" )
{
    $plugin_options     = get_option('woocommerce_sqrip_settings', array());
    $token              = $token ? $token : $plugin_options['token'];

    $args = [];
    $args['method'] = $method;
    $args['headers'] = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer '.$token,
        'Accept'        => 'application/json'
    ];
    $args['body'] = $body;

    $response = wp_remote_request($endpoint, $args);

    if ( is_wp_error($response) ) return;

    $body = wp_remote_retrieve_body($response);

    return json_decode($body);
}

/*
 *  Get payable to address
 */
function sqrip_get_payable_to_address($address)
{
    if ( !$address ) return;

    switch ($address) {
    	case 'sqrip':
    		$result = sqrip_get_user_details();
    		break;
    	
    	case 'woocommerce':
    		// The country/state
		    $store_raw_country = get_option( 'woocommerce_default_country' );

		    // Split the country/state
		    $split_country = explode( ":", $store_raw_country );

		    // Country and state separated:
		    $store_country = $split_country[0];
            $address = get_option( 'woocommerce_store_address' );
            $address .= get_option( 'woocommerce_store_address_2' ) ? ', '.get_option( 'woocommerce_store_address_2' ) : "";
            
		    $result = array(
		        'name' => get_bloginfo('name'),
		        'street' => $address,
		        'city' => get_option( 'woocommerce_store_city' ),
		        'postal_code' => get_option( 'woocommerce_store_postcode' ),
		        'country_code' => $store_country,
		    );
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
        return __('Keine Adresse gefunden!', 'sqrip');
    }

    return $address_txt = $address_arr['name'].', '.$address_arr['street'].', '.$address_arr['city'].', '.$address_arr['postal_code'].' '.$address_arr['city'];
}


/*
 *  Get user details from sqrip api 
 */
function sqrip_get_user_details($token = "")
{
	$endpoint = 'https://api.sqrip.ch/api/details';

    $body_decode   = sqrip_remote_request($endpoint, '', 'GET', $token);  

    $address = isset($body_decode->user->address) ? $body_decode->user->address : [];

    $result = [];

    if ($address) {
    	$result = array(
    		'city' => $address->city,
    		'country_code' => $address->country_code,
    		'name' => $body_decode->user->first_name.' '.$body_decode->user->last_name,
    		'postal_code' => $address->zip,
    		'street' => $address->street,
    	);
    }

    return $result;
}

/*
 *  sqrip validation IBAN
 */
function sqrip_validation_iban($iban, $tokens)
{
    $endpoint = 'https://api.sqrip.ch/api/validate-iban';

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
 *  @deprecated since v1.0.3 | User sqrip_get_user_details instead
 */
function sqrip_verify_token($token)
{
    if (!$token) {
        return;
    }

    $endpoint = 'https://api.sqrip.ch/api/validate-iban';
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