<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Sqrip Payments Blocks integration
 *
 * @since 1.0.3
 */
final class WC_Gateway_Sqrip_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_Sqrip
	 */
	private $gateway;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = 'sqrip';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_sqrip_settings', [] );
		$gateways       = WC()->payment_gateways->payment_gateways();
		// Without the isset() guard this was an undefined index whenever another plugin
		// filters sqrip out of woocommerce_payment_gateways, leaving $this->gateway null
		// and turning is_active() into a fatal during woocommerce_blocks_loaded — which
		// renders the block checkout with no payment methods at all.
		$this->gateway  = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		if ( ! $this->gateway ) {
			return false;
		}

		// Ask the gateway itself instead of only reading the 'enabled' setting: every
		// availability rule (such as the invoice-country restriction) lives in
		// is_available(), and reading 'enabled' bypassed all of them in the block
		// checkout while classic checkout honoured them.
		if ( is_callable( array( $this->gateway, 'is_available' ) ) ) {
			return (bool) $this->gateway->is_available();
		}

		return $this->gateway->enabled === 'yes';
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_path       = '../../js/frontend/blocks.js';
		// $script_asset_path = ( __DIR__ ) . '/../../js/frontend/blocks.asset.php';//works
		$script_asset_path = plugin_dir_path( __FILE__ ) . '../../js/frontend/blocks.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: array(
				'dependencies' => array(),
				'version'      => '1.2.0'
			);
		$script_url        = plugin_dir_url( __FILE__ ) . $script_path;

		wp_register_script(
			'wc-sqrip-payments-blocks',
			$script_url,
			$script_asset[ 'dependencies' ],
			$script_asset[ 'version' ],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			// The domain was 'woocommerce-gateway-sqrip' and the path pointed at
			// inc/blocks/languages/, which does not exist — so block-checkout strings were
			// never translated. Use the plugin's real domain and its languages folder.
			wp_set_script_translations( 'wc-sqrip-payments-blocks', 'sqrip-swiss-qr-invoice', trailingslashit( plugin_dir_path( __FILE__ ) ) . '../../languages/' );
		}

		return [ 'wc-sqrip-payments-blocks' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return [
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] )
		];
	}
}