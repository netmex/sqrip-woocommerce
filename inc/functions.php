<?php 

function sqrip_remote_request( $endpoint, $body = '', $method = 'GET' )
{
    $plugin_options     = get_option('woocommerce_sqrip_settings', array());
    $token              = $plugin_options['token'];

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
    	
    	default:
    		// The country/state
		    $store_raw_country = get_option( 'woocommerce_default_country' );

		    // Split the country/state
		    $split_country = explode( ":", $store_raw_country );

		    // Country and state separated:
		    $store_country = $split_country[0];

		    $result = array(
		        'name' => get_bloginfo('name'),
		        'street' => get_option( 'woocommerce_store_address' ),
		        'city' => get_option( 'woocommerce_store_city' ),
		        'postal_code' => get_option( 'woocommerce_store_postcode' ),
		        'country_code' => $store_country,
		    );
    		break;
    }
   
    return $result;            
}

/*
 *  Get user details from sqrip api 
 */
function sqrip_get_user_details()
{
	$endpoint = 'https://api.sqrip.madebycolorelephant.com/api/details';

    $body_decode   = sqrip_remote_request($endpoint);      

    $address = isset($body_decode->user->address) ? $body_decode->user->address : [];

    $result = [];

    if ($address) {
    	$result = array(
    		'city' => $address->city,
    		'country_code' => $address->country_code,
    		'name' => $address->title,
    		'postal_code' => $address->zip,
    		'street' => $address->street,
    	);
    }

    return $result;
}


/*
 *  sqrip validation IBAN
 */
function sqrip_validation_iban($iban, $iban_type)
{
    $endpoint = 'https://api.sqrip.madebycolorelephant.com/api/validate-iban';

    $body = '{
        "iban": {
            "iban": "'.$iban.'",
            "iban_type": "'.$iban_type.'"
        }
    }';

    $body_decode  = sqrip_remote_request($endpoint, $body, $method = 'POST');   
    
    return $body_decode;
}