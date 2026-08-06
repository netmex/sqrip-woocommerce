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
    const LOCALPART_OPTION = 'sqrip_avis_localpart';

    // The service is the same for every shop, so no one has to type it. A stored
    // 'avis_service_url' option still overrides it if one is ever set by hand.
    const DEFAULT_SERVICE_URL = 'https://avis-service-ajeqivb4ra-oa.a.run.app';

    /** @var string The last transport failure, appended to the "unreachable" message. */
    private static $last_error = '';

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

            // The signed confirm/reject links from the suggestion e-mail land on
            // admin-post.php, which the shop admin may open without being logged in.
            add_action('admin_post_sqrip_avis_suggestion', array(__CLASS__, 'handle_suggestion_action'));
            add_action('admin_post_nopriv_sqrip_avis_suggestion', array(__CLASS__, 'handle_suggestion_action'));
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
        // Fixed for every shop. Not read from any option so a stale value from an
        // earlier version can never point it somewhere wrong.
        return rtrim(self::DEFAULT_SERVICE_URL, '/');
    }

    /**
     * The shop's own name at the service, e.g. "timber" for timber@avis.sqrip.ch.
     *
     * @return string
     */
    private static function customer()
    {
        return self::localpart();
    }

    /**
     * The mailbox name before @avis.sqrip.ch — auto-assigned once, never chosen by
     * hand. A readable slug of the shop name plus a short unique suffix, so it is
     * guaranteed unique across shops and stays stable (the e-banking address must
     * never change once entered).
     *
     * @return string
     */
    public static function localpart()
    {
        $stored = sanitize_key((string) get_option(self::LOCALPART_OPTION, ''));
        if ($stored !== '') {
            return $stored;
        }

        // Keep an address a beta tester set by hand in the old text field.
        $legacy = sanitize_key((string) sqrip_get_plugin_option('avis_localpart'));
        if ($legacy !== '') {
            update_option(self::LOCALPART_OPTION, $legacy, false);
            return $legacy;
        }

        $slug = preg_replace('/[^a-z0-9]/', '', strtolower((string) sanitize_title(get_bloginfo('name'))));
        $slug = ($slug === '') ? 'shop' : substr($slug, 0, 20);
        $suffix = substr(strtolower(wp_generate_password(8, false)), 0, 5);
        $localpart = sanitize_key($slug . '-' . $suffix);

        update_option(self::LOCALPART_OPTION, $localpart, false);

        return $localpart;
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
     * The service authenticates with the shared token. The push (src/notify.py) sends
     * it in the JSON body ({"token","pending"}), not in a header — so we read the body
     * parameter here. (The header form was never sent by the service; the nudge used to
     * fail authorisation and only the manual "Check now" pull worked.)
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public static function rest_authorised($request)
    {
        if (!self::is_enabled()) {
            return false;
        }

        $sent = (string) $request->get_param('token');

        return $sent !== '' && hash_equals(self::token(), $sent);
    }

    /**
     * Nudge received: reconcile now and book what is safe to book automatically.
     *
     * The service tells us how many notifications are waiting via `pending`; if it is
     * explicitly zero there is nothing to fetch and we skip the round-trip.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rest_reconcile($request)
    {
        $pending = $request->get_param('pending');

        if ($pending !== null && (int) $pending <= 0) {
            return new WP_REST_Response(array('applied' => 0, 'held' => 0, 'pending' => 0), 200);
        }

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

        // Register (v2) also gates on the sqrip account. Surface "no credits"/"inactive"
        // instead of asking the service for a claim it would reject anyway.
        if (!self::register_with_service() && self::$last_error !== '') {
            return self::$last_error;
        }

        $reconciler = new Sqrip_Camt_Reconciler();
        $orders     = $reconciler->collect_open_orders();

        if ($orders === false) {
            return __('No order status for "waiting for payment" is configured yet.', 'sqrip-swiss-qr-invoice');
        }

        $expectations = $reconciler->build_expectations($orders['orders']);
        $payload      = self::orders_payload($expectations);

        $claim = self::post('/v2/claim', array('token' => self::token(), 'orders' => $payload));

        if (!is_array($claim)) {
            return self::unreachable_message();
        }

        if (isset($claim['error'])) {
            return self::gate_message((string) $claim['error']);
        }

        $matches  = isset($claim['matches']) && is_array($claim['matches']) ? $claim['matches'] : array();
        $warnings = isset($claim['warnings']) && is_array($claim['warnings']) ? $claim['warnings'] : array();

        $report = $reconciler->match($expectations, $matches);

        $report['orders_scanned']   = $orders['scanned'];
        $report['orders_truncated'] = $orders['truncated'];
        $report['credits_total']    = count($matches);
        $report['unmatched_credits'] = isset($claim['dropped']) ? (int) $claim['dropped'] : 0;
        $report['warnings']          = $warnings;
        $report['last_seen']         = isset($claim['last_seen']) && is_array($claim['last_seen']) ? $claim['last_seen'] : null;
        $report['pending_charges']   = isset($claim['pending_charges']) ? (int) $claim['pending_charges'] : 0;

        // Stufe 3 (probable): v2 carries these in `warnings` (types no_reference and
        // bulk_payment carry candidate_references) — never booked automatically. Each
        // gets e-mailed to the shop admin, who confirms or rejects it by hand.
        $probable = array();

        foreach ($warnings as $warning) {
            if (isset($warning['candidate_references'])
                && is_array($warning['candidate_references'])
                && $warning['candidate_references']) {
                $probable[] = $warning;
            }
        }

        $report['suggestions'] = count($probable);

        if ($probable) {
            self::notify_suggestions($probable, $expectations);
        }

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

    // --- Stufe 3: probable payments, confirmed by the admin via signed links ----

    /**
     * E-mail each probable (Stufe 3) payment to the shop admin. Never books anything
     * on its own — the admin decides with the confirm/reject links.
     *
     * @param array $suggestions From the service: {amount,currency,sender,value_date,candidate_references}.
     * @param array $expectations The open orders, to resolve a candidate reference to an order.
     * @return void
     */
    private static function notify_suggestions(array $suggestions, array $expectations)
    {
        $by_ref = array();

        foreach ($expectations as $order) {
            foreach ($order['slips'] as $slip) {
                $key = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $slip['reference']));

                if ($key !== '') {
                    $by_ref[$key] = array(
                        'order_id'          => $order['order_id'],
                        'order_number'      => $order['order_number'],
                        'currency'          => $order['currency'],
                        'expected'          => $slip['expected'],
                        'reference_display' => (string) $slip['reference'],
                    );
                }
            }
        }

        foreach ($suggestions as $sug) {
            $refs = isset($sug['candidate_references']) && is_array($sug['candidate_references'])
                ? $sug['candidate_references'] : array();

            $candidates = array();

            foreach ($refs as $ref) {
                $key = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $ref));

                if (isset($by_ref[$key])) {
                    $candidates[$key] = $by_ref[$key];
                }
            }

            if (!$candidates) {
                continue; // no open order to point at — nothing to confirm
            }

            // One e-mail per probable payment, even if the service repeats it.
            $dedup = md5(wp_json_encode(array(
                isset($sug['amount']) ? $sug['amount'] : '',
                isset($sug['currency']) ? $sug['currency'] : '',
                isset($sug['sender']) ? $sug['sender'] : '',
                isset($sug['value_date']) ? $sug['value_date'] : '',
                array_keys($candidates),
            )));

            if (get_transient('sqrip_avis_seen_' . $dedup)) {
                continue;
            }

            set_transient('sqrip_avis_seen_' . $dedup, 1, 2 * DAY_IN_SECONDS);

            self::send_suggestion_email($sug, $candidates);
        }
    }

    /**
     * @param array $sug        One suggestion from the service.
     * @param array $candidates normalized reference => {order_id, order_number, currency, expected}.
     * @return void
     */
    private static function send_suggestion_email(array $sug, array $candidates)
    {
        $amount   = isset($sug['amount']) ? number_format((float) $sug['amount'], 2, '.', '') : '';
        $currency = isset($sug['currency']) ? (string) $sug['currency'] : '';
        $sender   = isset($sug['sender']) ? (string) $sug['sender'] : '';
        $value    = isset($sug['value_date']) ? (string) $sug['value_date'] : '';

        // One signed, single-use token per candidate; acting on any one resolves the
        // whole payment, so each token also carries its sibling ids to clear together.
        $tokens = array();

        foreach ($candidates as $key => $cand) {
            $tokens[$key] = wp_generate_password(20, false);
        }

        $all_ids = array_values($tokens);
        $rows    = '';

        foreach ($candidates as $key => $cand) {
            $id       = $tokens[$key];
            $siblings = array_values(array_diff($all_ids, array($id)));

            set_transient('sqrip_avis_sug_' . $id, array(
                'order_id'     => $cand['order_id'],
                'order_number' => $cand['order_number'],
                'amount'       => $amount,
                'currency'     => $currency,
                'reference'    => $key,
                'siblings'     => $siblings,
            ), DAY_IN_SECONDS);

            $order     = wc_get_order($cand['order_id']);
            $open_url  = $order ? $order->get_edit_order_url() : '';
            $name      = $order ? $order->get_formatted_billing_full_name() : '';
            $ref_disp  = (isset($cand['reference_display']) && $cand['reference_display'] !== '')
                ? $cand['reference_display'] : $key;
            $order_amt = ($cand['expected'] === null || $cand['expected'] === '')
                ? '—'
                : trim($cand['currency'] . ' ' . number_format((float) $cand['expected'], 2, '.', ''));

            $actions = '<a href="' . esc_url(self::action_link($id, 'confirm')) . '">' . esc_html__('Confirm', 'sqrip-swiss-qr-invoice') . '</a> &nbsp;|&nbsp; '
                . '<a href="' . esc_url(self::action_link($id, 'reject')) . '">' . esc_html__('Reject', 'sqrip-swiss-qr-invoice') . '</a>'
                . ($open_url ? ' &nbsp;|&nbsp; <a href="' . esc_url($open_url) . '">' . esc_html__('Open order', 'sqrip-swiss-qr-invoice') . '</a>' : '');

            $rows .= '<tr>'
                . '<td style="padding:8px 14px;border-bottom:1px solid #eee;">' . esc_html($cand['order_number']) . '</td>'
                . '<td style="padding:8px 14px;border-bottom:1px solid #eee;">' . esc_html($order_amt) . '</td>'
                . '<td style="padding:8px 14px;border-bottom:1px solid #eee;"><code>' . esc_html($ref_disp) . '</code></td>'
                . '<td style="padding:8px 14px;border-bottom:1px solid #eee;">' . esc_html($name) . '</td>'
                . '<td style="padding:8px 14px;border-bottom:1px solid #eee;">' . $actions . '</td>'
                . '</tr>';
        }

        $head = '<tr>'
            . '<th style="text-align:left;padding:8px 14px;border-bottom:2px solid #333;">' . esc_html__('Order number', 'sqrip-swiss-qr-invoice') . '</th>'
            . '<th style="text-align:left;padding:8px 14px;border-bottom:2px solid #333;">' . esc_html__('Amount', 'sqrip-swiss-qr-invoice') . '</th>'
            . '<th style="text-align:left;padding:8px 14px;border-bottom:2px solid #333;">' . esc_html__('QR reference / SCOR', 'sqrip-swiss-qr-invoice') . '</th>'
            . '<th style="text-align:left;padding:8px 14px;border-bottom:2px solid #333;">' . esc_html__('Name', 'sqrip-swiss-qr-invoice') . '</th>'
            . '<th style="text-align:left;padding:8px 14px;border-bottom:2px solid #333;">' . esc_html__('Action', 'sqrip-swiss-qr-invoice') . '</th>'
            . '</tr>';

        $intro = __('The sqrip payment notification service found a probable payment that could not be assigned automatically. Please check it against the order, then confirm, reject, or open the order for details. The links are valid for 24 hours and work once.', 'sqrip-swiss-qr-invoice');

        // The incoming payment shown once above the candidate orders it might belong to.
        // Sender / value date are only present on some (v1) sources — omit them if empty.
        $parts = array_filter(array(trim($currency . ' ' . $amount), $sender, $value), 'strlen');

        $paid_line = '<p style="font-family:sans-serif;font-size:14px;"><strong>'
            . esc_html__('Incoming payment', 'sqrip-swiss-qr-invoice') . ':</strong> '
            . esc_html(implode('  ·  ', $parts))
            . '</p>';

        $body = '<p style="font-family:sans-serif;font-size:14px;">' . esc_html($intro) . '</p>'
            . $paid_line
            . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-family:sans-serif;font-size:14px;">'
            . '<thead>' . $head . '</thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';

        $subject = __('sqrip: probable payment — please check', 'sqrip-swiss-qr-invoice');
        $to      = apply_filters('sqrip_avis_suggestion_recipient', get_option('admin_email'));

        wp_mail($to, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * A signed, single-use link to admin-post.php for a confirm/reject action.
     *
     * @param string $id
     * @param string $do 'confirm' or 'reject'.
     * @return string
     */
    private static function action_link($id, $do)
    {
        $sig = hash_hmac('sha256', $id . '|' . $do, self::token());

        return add_query_arg(array(
            'action' => 'sqrip_avis_suggestion',
            'id'     => rawurlencode($id),
            'do'     => $do,
            'sig'    => $sig,
        ), admin_url('admin-post.php'));
    }

    /**
     * The confirm/reject link was clicked. Verify the signature, act exactly once, and
     * show the admin a short result page. Reachable without a login (nopriv), so the
     * signature + single-use transient are the only guard.
     *
     * @return void
     */
    public static function handle_suggestion_action()
    {
        $id  = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
        $do  = isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : '';
        $sig = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

        if ($id === '' || ($do !== 'confirm' && $do !== 'reject')) {
            self::suggestion_page(__('This link is not valid.', 'sqrip-swiss-qr-invoice'));
        }

        $expected = hash_hmac('sha256', $id . '|' . $do, self::token());

        if (!hash_equals($expected, $sig)) {
            self::suggestion_page(__('This link is not valid.', 'sqrip-swiss-qr-invoice'));
        }

        $data = get_transient('sqrip_avis_sug_' . $id);

        if (!is_array($data)) {
            self::suggestion_page(__('This link has expired or was already used.', 'sqrip-swiss-qr-invoice'));
        }

        // Single use, and resolve the whole payment: clear this token and its siblings.
        delete_transient('sqrip_avis_sug_' . $id);

        if (!empty($data['siblings']) && is_array($data['siblings'])) {
            foreach ($data['siblings'] as $sibling) {
                delete_transient('sqrip_avis_sug_' . $sibling);
            }
        }

        if ($do === 'reject') {
            self::suggestion_page(__('The suggested payment was rejected. Nothing was changed.', 'sqrip-swiss-qr-invoice'));
        }

        $order = wc_get_order($data['order_id']);

        if (!$order || $order->get_payment_method() !== 'sqrip') {
            self::suggestion_page(__('The order could not be found, so nothing was changed.', 'sqrip-swiss-qr-invoice'));
        }

        $order->add_order_note(sprintf(
            /* translators: 1: currency, 2: amount, 3: reference */
            __('Payment confirmed by hand from an e-mail suggestion: %1$s %2$s, reference %3$s (probable match from the sqrip payment notification service).', 'sqrip-swiss-qr-invoice'),
            $data['currency'],
            $data['amount'],
            $data['reference']
        ));

        $status_completed = sqrip_get_plugin_option('status_completed');

        if ($status_completed) {
            $order->update_status($status_completed, '');
        } else {
            $order->save();
        }

        self::suggestion_page(sprintf(
            /* translators: %s: order number */
            __('Order %s was marked as paid.', 'sqrip-swiss-qr-invoice'),
            $data['order_number']
        ));
    }

    /**
     * Short human-facing result page for a clicked link. Ends the request.
     *
     * @param string $message
     * @return void
     */
    private static function suggestion_page($message)
    {
        wp_die(
            esc_html($message),
            esc_html__('sqrip payment notification', 'sqrip-swiss-qr-invoice'),
            array('response' => 200, 'back_link' => true)
        );
    }

    // --- admin trigger ("check now") --------------------------------------

    /**
     * @return void
     */
    public static function ajax_reconcile()
    {
        check_ajax_referer(self::NONCE, 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'sqrip-swiss-qr-invoice')), 403);
        }

        if (!self::is_enabled()) {
            wp_send_json_error(array('message' => __('Please switch on the automatic payment reconciliation first.', 'sqrip-swiss-qr-invoice')), 403);
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

        <?php
        if (!empty($report['last_seen'])) :
            $ls  = $report['last_seen'];
            $ref = isset($ls['reference']) ? (string) $ls['reference'] : '';

            $link = '';
            foreach ($orders as $entry) {
                foreach ($entry['slips'] as $slip) {
                    if ($slip['reference'] === $ref) {
                        $link = ' ' . self::order_link($entry['order_id'], $entry['order_number']);
                        break 2;
                    }
                }
            }

            $when = isset($ls['seen_at']) ? strtotime((string) $ls['seen_at']) : 0;
            $when = $when ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $when) : '';
            ?>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: currency, 2: amount, 3: reference, 4: date/time */
                    esc_html__('Last recognised: %1$s %2$s, reference %3$s, on %4$s', 'sqrip-swiss-qr-invoice'),
                    esc_html(isset($ls['currency']) ? $ls['currency'] : ''),
                    esc_html(isset($ls['amount']) ? number_format((float) $ls['amount'], 2, '.', '') : ''),
                    esc_html($ref),
                    esc_html($when)
                );
                echo $link; // already-escaped markup from order_link()
                ?>
            </p>
        <?php endif; ?>

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
        $map  = array('start' => '/v2/onboarding/start', 'code' => '/v2/onboarding/code', 'complete' => '/v2/onboarding/complete');

        if (!isset($map[$step])) {
            wp_send_json_error(array('message' => __('Unknown step.', 'sqrip-swiss-qr-invoice')));
        }

        if (self::customer() === '') {
            wp_send_json_error(array('message' => __('Please set the mailbox name and save the settings first.', 'sqrip-swiss-qr-invoice')));
        }

        // Register on demand; v2 also gates on the sqrip account (credits/active).
        if (!self::register_with_service() && self::$last_error !== '') {
            wp_send_json_error(array('message' => self::$last_error));
        }

        $result = self::post($map[$step], array('token' => self::token()));

        if (!is_array($result)) {
            wp_send_json_error(array('message' => self::unreachable_message()));
        }

        if (isset($result['error'])) {
            wp_send_json_error(array('message' => self::gate_message((string) $result['error'])));
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

        self::$last_error = '';

        // v2 register also carries the shop's real sqrip API token: the service checks
        // that account (active? credits?) and books each match to it, so every shop pays
        // on its own account.
        $response = wp_remote_post(self::service_url() . '/v2/register', array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'    => wp_json_encode(array(
                'token'        => self::token(),
                'customer'     => self::customer(),
                'callback_url' => rest_url('sqrip/v1/reconcile'),
                // Clean the API key before forwarding: the field is a textarea and may
                // carry a trailing newline. WordPress strips it from its own request
                // headers, but the service uses Python urllib, which rejects a newline in
                // a header value — turning a valid account into a bogus 403 "inactive".
                'sqrip_token'  => sanitize_text_field((string) sqrip_get_plugin_option('token')),
            )),
        ));

        if (is_wp_error($response)) {
            self::$last_error = $response->get_error_message();

            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        // The service rejects a mailbox name that already belongs to another shop.
        if ($code === 409) {
            set_transient('sqrip_avis_name_taken', 1, 60);

            return false;
        }

        if ($code >= 200 && $code < 300) {
            return true;
        }

        // Gate failure (402 no credits / 403 account inactive) — remember why, so the
        // caller can show it instead of a generic "unreachable".
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $err  = (is_array($data) && isset($data['error'])) ? (string) $data['error'] : '';
        self::$last_error = self::gate_message($err);

        return false;
    }

    /**
     * Turn a v2 gate error code into a message the shop admin can act on.
     *
     * @param string $error
     * @return string
     */
    private static function gate_message($error)
    {
        switch ($error) {
            case 'keine_credits':
                return __('No sqrip credits left — please top up your sqrip account to use the payment reconciliation.', 'sqrip-swiss-qr-invoice');
            case 'sqrip_konto_inaktiv':
                return __('Your sqrip account is not active. Please confirm it at sqrip.ch before using the payment reconciliation.', 'sqrip-swiss-qr-invoice');
            case 'kein_v2_konto':
            case 'unbekannter Token':
                return __('Please save the sqrip settings again to register this shop for the service.', 'sqrip-swiss-qr-invoice');
        }

        return self::unreachable_message();
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
            self::$last_error = $response->get_error_message();

            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        // The service answers gate failures (402/403/400) with a JSON {"error": ...} body.
        // Return it so the caller can react; only a non-JSON body counts as unreachable.
        if (!is_array($data)) {
            self::$last_error = 'HTTP ' . $code;

            return null;
        }

        return $data;
    }

    /**
     * The "could not be reached" message, with the transport detail when we have one
     * (e.g. a cURL error or an HTTP status) so the cause is visible.
     *
     * @return string
     */
    private static function unreachable_message()
    {
        $msg = __('The payment notification service could not be reached.', 'sqrip-swiss-qr-invoice');

        return self::$last_error !== '' ? $msg . ' (' . self::$last_error . ')' : $msg;
    }
}
