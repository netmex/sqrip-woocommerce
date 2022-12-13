<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sqrip_WP_Webhook {

	private static $_instance = null;
    /**
	 * Parent wekbhook
	 * 
	 * @var string
	 */
    private static $webhook = 'sqrip-webhook';
	
	/**
	 * webhook tag
	 * 
	 * @var string
	 */
    private static $webhook_tag = 'payment_comparision';

	/**
	 * ini prefix, leave as it is :)
	 * 
	 * @var string
	 */
    private static $ini_hook_prefix = 'sqrip_';

	/**
	 * Action to be triggered when the url is loaded
	 * 
	 * @var string
	 */
    private static $webhook_action = 'webhook_action';
	
	/**
	 * Construdor :)
	 */
    public function __construct() {
        add_action( 'parse_request', array( $this, 'parse_request' ) );            
        add_action( self::$ini_hook_prefix.self::$webhook_action, array( $this, 'webhook_handler' ) );
    }

	/**
     * Handles the HTTP Request sent to your site's webhook
     */
    public function webhook_handler() {

		$token = sqrip_get_plugin_option('token');

		$webhookIsValid = $this->isValid($token);

		if ( $webhookIsValid && $_SERVER['REQUEST_METHOD'] == "POST" ) {

			$ebics_service = sqrip_get_plugin_option('ebics_service');

			if ($ebics_service == "no") {

				echo json_encode([
					'status' => 201,
					'message' => 'Automatic Comparaison - EBICS disabled'
				]);

			} else {

				$Sqrip_Payment_Verification = new Sqrip_Payment_Verification();

				$response = $Sqrip_Payment_Verification->verify();

				echo json_encode($response);
			}


		} else {
			echo json_encode([
				'status' => 401,
				'message' => 'Unauthorized'
			]);
		}

    }

    public function getWebhook() {
        return self::$webhook.'/'.self::$webhook_tag;
    }

    private function isValid($token){
    	$headers = $this->getAllHeaders();
    	$Authorization = isset($headers['Authorization'])  ? $headers['Authorization'] : '';

    	if (!$Authorization) {
    		global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			get_template_part( 404 ); exit();
    	} 

    	$signature = str_replace('Bearer ', '', $Authorization);

    	return $signature == $token;
    }

    public function parse_request( &$wp ) {
		$ini = self::$ini_hook_prefix;
	
        if( $wp->request ==  $this->getWebhook()) {                
            do_action( $ini.self::$webhook_action );
            die(0);
        }

    }

    /**
     * Retrieves all HTTP headers of a given request
     *
     * @return array
     */
    protected function getAllHeaders(): array
    {
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        } else {
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $headers[str_replace('_', '-', substr($key, 5))] = $value;
                }
            }
        }
        return $headers ?: [];
    }
}

new Sqrip_WP_Webhook();
