<?php

/**
 * Plugin side of the sqrip Auskunftsdienst (email payment notification service).
 *
 * The shop never talks to the bank. A separate service reads the bank's credit-
 * notification emails and holds the parsed credits. This class:
 *   - hands the service the shop's open QR references (order-driven: nothing else
 *     leaves the shop, and the service returns only credits that match one of them),
 *   - feeds those matches into the same Sqrip_Camt_Reconciler::match() used by the
 *     camt reconciliation, and applies the outcome,
 *   - exposes a REST callback the service nudges when a credit arrives (no cron),
 *   - proxies the onboarding (verification-code) calls for the setup assistant.
 *
 * Amounts at or above the shop's threshold are never booked automatically — they
 * are left for the shop to confirm by hand.
 *
 * @package sqrip
 * @since 1.11
 */

defined('ABSPATH') || exit;

class Sqrip_Avis
{
    const NONCE = 'sqrip-avis';
    const TOKEN_OPTION = 'sqrip_avis_token';

    // The service is the same for every shop, so no one has to type it. A stored
    // 'avis_service_url' option still overrides it if one is ever set by hand.
    const DEFAULT_SERVICE_URL = 'https://avis-service-ajeqivb4ra-oa.a.run.app';

    /**
     * @return void
     */
    public static function init()
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));

        if (is_admin()) {
            add_action('wp_ajax_sqrip_avis_reconcile', array(__CLASS__, 'ajax_reconcile'));
            add_action('wp_ajax_sqrip_avis_onboard', array(__CLASS__, 'ajax_onboard'));
            add_action('woocommerce_update_options_payment_gateways_sqrip', array(__CLASS__, 'maybe_register'));
            add_action('admin_notices', array(__CLASS__, 'maybe_notice'));
        }
    }

    /**
     * Show a notice when the chosen mailbox name is already taken by another shop.
     *
     * @return void
     */
    public static function maybe_notice()
    {
        if (get_transient('sqrip_avis_name_taken')) {
            delete_transient('sqrip_avis_name_taken');
            echo '<div class="notice notice-error"><p>'
                . esc_html__('That mailbox name is already in use by another shop. Please choose a different one.', 'sqrip-swiss-qr-invoice')
                . '</p></div>';
        }
    }

    /**
     * Re-announce the shop to the service whenever the settings are saved, so the
     * service always has the current callback URL and name.
     *
     * @return void
     */
    public static function maybe_register()
    {
        if (self::is_enabled()) {
            self::register_with_service();
        }
    }

    /**
     * A sub-feature of the payment comparison, like the camt reconciliation.
     *
     * @return bool
     */
    public static function is_enabled()
    {
        return sqrip_get_plugin_option('payment_comparison_enabled') === 'yes'
            && sqrip_get_plugin_option('avis_enabled') === 'yes';
    }

    // --- configuration -----------------------------------------------------

    /**
     * @return string Service base URL without a trailing slash.
     */
    private static function service_url()
    {
        $url = (string) sqrip_get_plugin_option('avis_service_url');

        return rtrim($url !== '' ? $url : self::DEFAULT_SERVICE_URL, '/');
    }

    /**
     * The shop's own name at the service, e.g. "timber" for timber@avis.sqrip.ch.
     *
     * @return string
     */
    private static function customer()
    {
        return sanitize_key((string) sqrip_get_plugin_option('avis_localpart'));
    }

    /**
     * Amounts of this size or larger are never booked without a human.
     *
     * @return float 0 means every match may be booked automatically.
     */
    private static function threshold()
    {
        return (float) sqrip_get_plugin_option('avis_threshold');
    }

    /**
     * Shared secret between shop and service, minted once and kept.
     *
     * @return string
     */
    public static function token()
    {
        $token = get_option(self::TOKEN_OPTION);

        if (!$token) {
            $token = wp_generate_password(40, false);
            update_option(self::TOKEN_OPTION, $token, false);
        }

        return $token;
    }

    // --- REST callback (the service nudges the shop) -----------------------

    /**
     * @return void
     */
    public static function register_routes()
    {
        register_rest_route('sqrip/v1', '/reconcile', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_reconcile'),
            'permission_callback' => array(__CLASS__, 'rest_authorised'),
        ));
    }

    /**
     * The service authenticates with the shared token in a header.
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public static function rest_authorised($request)
    {
        if (!self::is_enabled()) {
            return false;
        }

        $sent = (string) $request->get_header('x_sqrip_token');

        return $sent !== '' && hash_equals(self::token(), $sent);
    }

    /**
     * Nudge received: reconcile now and book what is safe to book automatically.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rest_reconcile($request)
    {
        $report = self::run();

        if (!is_array($report)) {
            return new WP_REST_Response(array('error' => $report), 200);
        }

        $applied = self::apply($report, false);

        return new WP_REST_Response(array(
            'applied' => count($applied),
            'held'    => count(self::orders_over_threshold($report)),
        ), 200);
    }

    // --- the reconciliation ------------------------------------------------

    /**
     * Ask the service for the matches to the shop's open references and sort them
     * into the reconciler's categories.
     *
     * @return array|string Report, or a message on failure.
     */
    public static function run()
    {
        if (!self::is_enabled()) {
            return __('The payment notification service is switched off.', 'sqrip-swiss-qr-invoice');
        }

        if (self::service_url() === '' || self::customer() === '') {
            return __('Please set the mailbox name under "camt Reconciliation" and save first.', 'sqrip-swiss-qr-invoice');
        }

        // Make sure the service knows this shop before we ask it anything.
        self::register_with_service();

        $reconciler = new Sqrip_Camt_Reconciler();
        $orders     = $reconciler->collect_open_orders();

        if ($orders === false) {
            return __('No order status for "waiting for payment" is configured yet.', 'sqrip-swiss-qr-invoice');
        }

        $expectations = $reconciler->build_expectations($orders['orders']);
        $payload      = self::orders_payload($expectations);

        $claim = self::post('/v1/claim', array('token' => self::token(), 'orders' => $payload));

        if (!is_array($claim)) {
            return __('The payment notification service could not be reached.', 'sqrip-swiss-qr-invoice');
        }

        $matches = isset($claim['matches']) && is_array($claim['matches']) ? $claim['matches'] : array();

        $report = $reconciler->match($expectations, $matches);

        $report['orders_scanned']   = $orders['scanned'];
        $report['orders_truncated'] = $orders['truncated'];
        $report['credits_total']    = count($matches);
        $report['unmatched_credits'] = isset($claim['dropped']) ? (int) $claim['dropped'] : 0;
        $report['warnings']         = isset($claim['warnings']) && is_array($claim['warnings']) ? $claim['warnings'] : array();

        return $report;
    }

    /**
     * One entry per QR slip: reference, order number, expected amount, currency.
     * This is everything the service needs to match by reference (secure), by order
     * number (foreign payments), or — as a plausibility hint only — by amount.
     *
     * @param array $expectations
     * @return array
     */
    private static function orders_payload(array $expectations)
    {
        $payload = array();

        foreach ($expectations as $order) {
            foreach ($order['slips'] as $slip) {
                $payload[] = array(
                    'reference'    => $slip['reference'],
                    'order_number' => (string) $order['order_number'],
                    'amount'       => $slip['expected'] === null ? 0 : (float) $slip['expected'],
                    'currency'     => $order['currency'],
                );
            }
        }

        return $payload;
    }

    /**
     * Book the payments that need no judgement. In the automatic path, matches at or
     * above the threshold are held back for the shop to confirm.
     *
     * @param array $report
     * @param bool  $confirmed True when a human triggered this (threshold ignored).
     * @return array Order ids that were changed.
     */
    public static function apply(array $report, $confirmed = false)
    {
        $status_completed = sqrip_get_plugin_option('status_completed');
        $threshold        = self::threshold();
        $applied          = array();

        foreach ($report['orders'] as $entry) {
            if ($entry['category'] !== Sqrip_Camt_Reconciler::PAID
                && !($entry['category'] === Sqrip_Camt_Reconciler::PARTLY_PAID && !empty($entry['applicable_slips']))) {
                continue;
            }

            // The threshold guard: large amounts wait for a human.
            if (!$confirmed && $threshold > 0 && (float) $entry['total'] >= $threshold) {
                continue;
            }

            $order = wc_get_order($entry['order_id']);

            if (!$order || $order->get_payment_method() !== 'sqrip') {
                continue;
            }

            $paid_slip = null;

            foreach ($entry['slips'] as $slip) {
                if ($slip['category'] === Sqrip_Camt_Reconciler::PAID) {
                    $paid_slip = $slip;
                    break;
                }
            }

            if (!$paid_slip) {
                continue;
            }

            $order->add_order_note(self::note($paid_slip));

            // Skonto/reminder: whichever invoice was paid, the others stop being payable.
            if (!empty($entry['alternatives']) && !empty($entry['paid_alternative'])) {
                sqrip_void_other_invoices($order, $entry['paid_alternative']);
            }

            if ($status_completed) {
                $order->update_status($status_completed, '');
            } else {
                $order->save();
            }

            $applied[$entry['order_id']] = $entry['order_number'];
        }

        return $applied;
    }

    /**
     * Orders that matched but were held back because of the threshold.
     *
     * @param array $report
     * @return array
     */
    private static function orders_over_threshold(array $report)
    {
        $threshold = self::threshold();

        if ($threshold <= 0) {
            return array();
        }

        $held = array();

        foreach ($report['orders'] as $entry) {
            if ($entry['category'] === Sqrip_Camt_Reconciler::PAID
                && (float) $entry['total'] >= $threshold) {
                $held[] = $entry;
            }
        }

        return $held;
    }

    /**
     * @param array $slip
     * @return string
     */
    private static function note($slip)
    {
        $payment  = isset($slip['payments'][0]) ? $slip['payments'][0] : array();
        $amount   = isset($payment['amount']) ? number_format((float) $payment['amount'], 2, '.', '') : '';
        $currency = isset($payment['currency']) ? $payment['currency'] : '';

        return sprintf(
            /* translators: 1: currency, 2: amount, 3: reference */
            __('Payment received: %1$s %2$s, reference %3$s. Confirmed by the sqrip payment notification service.', 'sqrip-swiss-qr-invoice'),
            $currency,
            $amount,
            $slip['reference']
        );
    }

    // --- admin trigger ("check now") --------------------------------------

    /**
     * @return void
     */
    public static function ajax_reconcile()
    {
        check_ajax_referer(self::NONCE, 'security');

        if (!current_user_can('manage_woocommerce') || !self::is_enabled()) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'sqrip-swiss-qr-invoice')), 403);
        }

        $report = self::run();

        if (!is_array($report)) {
            wp_send_json_error(array('message' => $report));
        }

        // A human pressed the button, so the threshold guard does not apply here.
        $applied = self::apply($report, true);

        wp_send_json_success(array('html' => self::render_check($report, $applied)));
    }

    /**
     * The table shown after "check now": every order waiting for payment and whether
     * a payment has come in for it. Doubles as the confirmation that notifications are
     * arriving and being recognised.
     *
     * @param array $report
     * @param array $applied order_id => order_number that were booked just now
     * @return string
     */
    private static function render_check(array $report, array $applied)
    {
        $orders = $report['orders'];

        ob_start();
        ?>
        <p>
            <?php
            printf(
                esc_html(_n(
                    '%d order waiting for payment checked.',
                    '%d orders waiting for payment checked.',
                    (int) $report['orders_scanned'],
                    'sqrip-swiss-qr-invoice'
                )),
                (int) $report['orders_scanned']
            );
            ?>
        </p>

        <?php if (!$orders) : ?>
            <p><?php esc_html_e('There are no orders waiting for payment right now.', 'sqrip-swiss-qr-invoice'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Order', 'sqrip-swiss-qr-invoice'); ?></th>
                        <th><?php esc_html_e('Amount', 'sqrip-swiss-qr-invoice'); ?></th>
                        <th><?php esc_html_e('Status', 'sqrip-swiss-qr-invoice'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $entry) : ?>
                        <tr>
                            <td><?php echo self::order_link($entry['order_id'], $entry['order_number']); ?></td>
                            <td><?php echo esc_html($entry['currency'] . ' ' . number_format((float) $entry['total'], 2, '.', '')); ?></td>
                            <td><?php echo esc_html(self::status_label($entry, isset($applied[$entry['order_id']]))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php foreach ($report['warnings'] as $warning) : ?>
            <p class="description"><?php echo esc_html($warning); ?></p>
        <?php endforeach; ?>
        <?php

        return ob_get_clean();
    }

    /**
     * @param array $entry
     * @param bool  $booked
     * @return string
     */
    private static function status_label($entry, $booked)
    {
        switch ($entry['category']) {
            case Sqrip_Camt_Reconciler::PAID:
                return $booked
                    ? __('Paid — status updated', 'sqrip-swiss-qr-invoice')
                    : __('Paid — waiting for your confirmation', 'sqrip-swiss-qr-invoice');
            case Sqrip_Camt_Reconciler::PARTLY_PAID:
                return __('Partly paid', 'sqrip-swiss-qr-invoice');
            case Sqrip_Camt_Reconciler::AMOUNT_MISMATCH:
                return __('Amount differs — please check', 'sqrip-swiss-qr-invoice');
            case Sqrip_Camt_Reconciler::DUPLICATE:
                return __('Paid more than once — please check', 'sqrip-swiss-qr-invoice');
            case Sqrip_Camt_Reconciler::OUT_OF_SEQUENCE:
                return __('An earlier instalment is still unpaid', 'sqrip-swiss-qr-invoice');
        }

        return __('Still waiting for payment', 'sqrip-swiss-qr-invoice');
    }

    /**
     * @param int    $order_id
     * @param string $order_number
     * @return string Escaped markup.
     */
    private static function order_link($order_id, $order_number)
    {
        $url = admin_url('post.php?post=' . (int) $order_id . '&action=edit');

        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $url = admin_url('admin.php?page=wc-orders&action=edit&id=' . (int) $order_id);
        }

        return '<a href="' . esc_url($url) . '" target="_blank">#' . esc_html($order_number) . '</a>';
    }

    // --- onboarding proxy (verification code) ------------------------------

    /**
     * Thin proxy so the setup assistant can drive the service's onboarding without
     * exposing the service token to the browser.
     *
     * @return void
     */
    public static function ajax_onboard()
    {
        check_ajax_referer(self::NONCE, 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'sqrip-swiss-qr-invoice')), 403);
        }

        $step = isset($_POST['step']) ? sanitize_key(wp_unslash($_POST['step'])) : '';
        $map  = array('start' => '/v1/onboarding/start', 'code' => '/v1/onboarding/code', 'complete' => '/v1/onboarding/complete');

        if (!isset($map[$step])) {
            wp_send_json_error(array('message' => __('Unknown step.', 'sqrip-swiss-qr-invoice')));
        }

        if (self::customer() === '') {
            wp_send_json_error(array('message' => __('Please set the mailbox name and save the settings first.', 'sqrip-swiss-qr-invoice')));
        }

        // Register on demand so the service always knows this shop's token/callback.
        self::register_with_service();

        $result = self::post($map[$step], array('token' => self::token()));

        if (!is_array($result)) {
            wp_send_json_error(array('message' => __('The payment notification service could not be reached.', 'sqrip-swiss-qr-invoice')));
        }

        wp_send_json_success($result);
    }

    /**
     * Tell the service this shop's callback URL and name. Called when the settings
     * are saved (see the gateway) so the service can nudge us.
     *
     * @return bool
     */
    public static function register_with_service()
    {
        if (self::service_url() === '' || self::customer() === '') {
            return false;
        }

        $response = wp_remote_post(self::service_url() . '/v1/register', array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'    => wp_json_encode(array(
                'token'        => self::token(),
                'customer'     => self::customer(),
                'callback_url' => rest_url('sqrip/v1/reconcile'),
            )),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        // The service rejects a mailbox name that already belongs to another shop.
        if ($code === 409) {
            set_transient('sqrip_avis_name_taken', 1, 60);

            return false;
        }

        return $code >= 200 && $code < 300;
    }

    // --- service client ----------------------------------------------------

    /**
     * @param string $path
     * @param array  $body
     * @return array|null Decoded response, or null on any failure.
     */
    private static function post($path, array $body)
    {
        $response = wp_remote_post(self::service_url() . $path, array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }
}
