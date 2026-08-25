<?php

/**
 * Plugin side of the sqrip Auskunftsdienst (email payment notification service).
 *
 * The shop never talks to the bank. A separate service reads the bank's credit-
 * notification emails and holds the parsed credits. This class:
 *   - hands the service the shop's open QR references (order-driven: nothing else
 *     leaves the shop, and the service returns only credits that match one of them),
 *   - feeds those matches into the same Sqrip_Camt_Reconciler::match() used by the
 *     camt reconciliation, and acts on the outcome in one place (process()),
 *   - exposes a REST callback the service nudges when a credit arrives (no cron),
 *   - proxies the onboarding (verification-code) calls for the setup assistant.
 *
 * Payments above the shop's release limit are not booked automatically — the admin
 * releases them from a signed one-time e-mail. Warnings (under-/overpayment, low
 * confidence, missing reference, batch mismatch) are held or flagged and never booked
 * blindly. Each match and warning is delivered by the service exactly once, so process()
 * acts on and logs each on first sight.
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

    // Rolling log of recently recognised payments (shown under "Reconcile") and the set
    // of already-processed items. /v2/claim delivers each match and warning exactly once
    // and then drops it, so both the action and the log entry must be written on first
    // sight; the processed set guards against a second, racing claim.
    const LOG_OPTION = 'sqrip_avis_log';
    const SEEN_OPTION = 'sqrip_avis_processed';
    const LOG_MAX = 25;

    // Empty release limit means "book everything automatically" — a limit no real payment
    // reaches. An explicit 0, by contrast, means "confirm every payment by hand".
    const AUTO_THRESHOLD = 1000000.0;

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
        self::maybe_migrate_threshold();

        add_action('rest_api_init', array(__CLASS__, 'register_routes'));

        if (is_admin()) {
            add_action('wp_ajax_sqrip_avis_reconcile', array(__CLASS__, 'ajax_reconcile'));
            add_action('wp_ajax_sqrip_avis_onboard', array(__CLASS__, 'ajax_onboard'));
            add_action('wp_ajax_sqrip_avis_status', array(__CLASS__, 'ajax_status'));
            add_action('woocommerce_update_options_payment_gateways_sqrip', array(__CLASS__, 'maybe_register'));
            add_action('admin_notices', array(__CLASS__, 'maybe_notice'));

            // The signed confirm/reject links from the suggestion e-mail land on
            // admin-post.php, which the shop admin may open without being logged in.
            add_action('admin_post_sqrip_avis_suggestion', array(__CLASS__, 'handle_suggestion_action'));
            add_action('admin_post_nopriv_sqrip_avis_suggestion', array(__CLASS__, 'handle_suggestion_action'));
        }
    }

    /**
     * One-time: keep the release limit meaningful across the semantics change. It used to
     * mean "book everything automatically" at 0; now 0 means "confirm every payment by
     * hand", and a blank/high value books all. A shop that saved the old default (0) meant
     * "book all", so lift that one 0 to the high auto-limit — no shop has yet chosen 0
     * under the new meaning. Fresh shops get the high default from the field itself.
     *
     * @return void
     */
    private static function maybe_migrate_threshold()
    {
        if (get_option('sqrip_avis_threshold_migrated')) {
            return;
        }

        $opts = get_option('woocommerce_sqrip_settings', array());

        if (is_array($opts) && isset($opts['avis_threshold'])
            && ($opts['avis_threshold'] === '0' || $opts['avis_threshold'] === 0 || $opts['avis_threshold'] === 0.0)) {
            $opts['avis_threshold'] = '1000000';
            update_option('woocommerce_sqrip_settings', $opts);
        }

        update_option('sqrip_avis_threshold_migrated', 1, false);
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
     * The release limit. A payment strictly above it is not booked automatically — the
     * shop admin releases it from an e-mail. An unset/blank value means "book everything
     * automatically" (a limit no payment reaches); an explicit 0 means "confirm every
     * payment by hand".
     *
     * @return float
     */
    private static function threshold()
    {
        $raw = sqrip_get_plugin_option('avis_threshold');

        if ($raw === null || $raw === '' || $raw === false) {
            return self::AUTO_THRESHOLD;
        }

        return (float) $raw;
    }

    /**
     * What to do when a customer pays more than the order total: 'hold' (default, wait
     * for the admin) or 'pay' (book it, notify the admin).
     *
     * @return string 'hold' or 'pay'.
     */
    private static function overpayment_mode()
    {
        return sqrip_get_plugin_option('avis_overpayment') === 'pay' ? 'pay' : 'hold';
    }

    /**
     * Normalise a reference the same way the service and the camt reconciler do:
     * uppercase, keep only A–Z and 0–9.
     *
     * @param string $reference
     * @return string
     */
    private static function normalize($reference)
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $reference));
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

        $summary = self::process($report, false);

        return new WP_REST_Response(array(
            'applied' => count($summary['applied']),
            'held'    => count($summary['held']),
        ), 200);
    }

    // --- the reconciliation ------------------------------------------------

    /**
     * Ask the service for the matches to the shop's open references and sort them
     * into the reconciler's categories.
     *
     * @param bool $fresh When true (manual "Reconcile now"), ask the service to read the
     *                    mailbox synchronously before matching, so a just-arrived e-mail is
     *                    seen at once instead of waiting for the periodic poll.
     * @return array|string Report, or a message on failure.
     */
    public static function run($fresh = false)
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

        $body = array('token' => self::token(), 'orders' => $payload);

        // Manual "Reconcile now" asks the service to read the mailbox synchronously first,
        // so a just-arrived e-mail is seen immediately instead of waiting for the periodic
        // (5-minute) poll. The nudge and the periodic path leave this off — they don't need
        // it, and it keeps the on-demand mailbox read to the rare manual click.
        if ($fresh) {
            $body['fresh'] = true;
        }

        $claim = self::post('/v2/claim', $body);

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
        $report['expectations']      = $expectations;

        // No side effects here. All booking, holding, e-mailing and logging happens in
        // process() — the single place that acts on a claim, so the nudge path and the
        // manual "Reconcile now" behave identically and each match/warning is acted on
        // exactly once (the service delivers each only once, then drops it).
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
     * Act on one claim response: book the safe matches, hold or e-mail the rest, and
     * record every recognised payment in the log. This is the ONLY place that acts on a
     * claim, so the nudge and the manual button behave the same; each match and warning
     * is handled at most once (the service delivers each only once, then drops it).
     *
     * @param array $report From run().
     * @param bool  $manual True when a human pressed "Reconcile now": the release limit
     *                      does not apply and over-limit matches are booked directly.
     * @return array {applied: order_number[], held: order_number[]}
     */
    public static function process(array $report, $manual = false)
    {
        $expectations = isset($report['expectations']) && is_array($report['expectations']) ? $report['expectations'] : array();
        $warnings     = isset($report['warnings']) && is_array($report['warnings']) ? $report['warnings'] : array();
        $orders       = isset($report['orders']) && is_array($report['orders']) ? $report['orders'] : array();

        // Overpayment carries both a match and a warning; checksum flags a whole batch.
        // Read them first so the booking step can react.
        $overpaid       = array();
        $has_checksum   = false;
        $checksum_total = 0.0;

        foreach ($warnings as $w) {
            $type = isset($w['type']) ? $w['type'] : '';
            if ($type === 'overpayment' && isset($w['reference'])) {
                $overpaid[self::normalize($w['reference'])] = $w;
            } elseif ($type === 'checksum') {
                $has_checksum    = true;
                $checksum_total += isset($w['batch_total']) ? (float) $w['batch_total'] : 0.0;
            }
        }

        $threshold      = self::threshold();
        $overpay_mode   = self::overpayment_mode();
        $applied        = array();
        $held           = array();
        $checksum_held  = array(); // one aggregated e-mail lists every affected order

        foreach ($orders as $entry) {
            $is_paid = ($entry['category'] === Sqrip_Camt_Reconciler::PAID)
                || ($entry['category'] === Sqrip_Camt_Reconciler::PARTLY_PAID && !empty($entry['applicable_slips']));

            if (!$is_paid) {
                continue;
            }

            $paid_slip = self::paid_slip($entry);

            if (!$paid_slip) {
                continue;
            }

            $payment  = isset($paid_slip['payments'][0]) ? $paid_slip['payments'][0] : array();
            $ref_norm = self::normalize($paid_slip['reference']);
            $amount   = isset($payment['amount']) ? (float) $payment['amount'] : (float) $entry['total'];
            $currency = isset($payment['currency']) ? (string) $payment['currency'] : (string) $entry['currency'];
            $score    = isset($payment['score']) ? (int) $payment['score'] : null;
            $aspects  = isset($payment['matched_aspects']) && is_array($payment['matched_aspects']) ? $payment['matched_aspects'] : array();

            $dedup = self::dedup_key(array('match', $ref_norm, round($amount, 2),
                isset($payment['value_date']) ? $payment['value_date'] : '',
                isset($payment['booking_date']) ? $payment['booking_date'] : ''));

            if (self::already_processed($dedup)) {
                continue;
            }

            $order = wc_get_order($entry['order_id']);

            if (!$order || !sqrip_order_in_avis_scope($order)) {
                continue;
            }

            // Decide what happens to this clean match.
            $is_overpaid = isset($overpaid[$ref_norm]);
            $decision    = self::decide($amount, $threshold, $manual, $is_overpaid, $overpay_mode, $has_checksum);

            self::mark_processed($dedup);

            $expected = ($entry['total'] !== null && $entry['total'] !== '') ? (float) $entry['total'] : null;

            if ($decision === 'book') {
                self::book_order($order, $entry, $paid_slip);
                $applied[] = $entry['order_number'];

                if ($is_overpaid) {
                    // Booked despite the overpayment (shop setting "pay") — inform the admin.
                    self::send_overpayment_paid_email($order, $paid_slip['reference'], $expected, $overpaid[$ref_norm], $currency);
                    self::log_add($ref_norm, $paid_slip['reference'], $amount, $currency, $score, $aspects,
                        __('Booked as paid (overpaid).', 'sqrip-swiss-qr-invoice'));
                } else {
                    self::log_add($ref_norm, $paid_slip['reference'], $amount, $currency, $score, $aspects,
                        __('Booked as paid.', 'sqrip-swiss-qr-invoice'));
                }
            } elseif ($decision === 'hold_approval') {
                $reason = $is_overpaid ? 'overpayment' : 'over_threshold';
                self::send_approval_email($order, $entry, $paid_slip, $amount, $expected, $currency, $reason);
                self::add_order_note($order, self::hold_note($paid_slip, $reason));
                $held[] = $entry['order_number'];
                $consequence = ($reason === 'overpayment')
                    ? __('Held — overpaid, e-mailed for your release.', 'sqrip-swiss-qr-invoice')
                    : __('Held — above your limit, e-mailed for your release.', 'sqrip-swiss-qr-invoice');
                self::log_add($ref_norm, $paid_slip['reference'], $amount, $currency, $score, $aspects, $consequence);
            } else { // hold_notify (checksum) — collect for one aggregated e-mail
                self::add_order_note($order, self::hold_note($paid_slip, 'checksum'));
                $held[] = $entry['order_number'];
                $checksum_held[] = array(
                    'order'     => $order,
                    'reference' => $paid_slip['reference'],
                    'expected'  => $expected,
                    'currency'  => $currency,
                );
                self::log_add($ref_norm, $paid_slip['reference'], $amount, $currency, $score, $aspects,
                    __('Held — batch total mismatch, please check.', 'sqrip-swiss-qr-invoice'));
            }
        }

        // One e-mail per batch mismatch, listing every affected order.
        if ($checksum_held) {
            self::send_checksum_email($checksum_held, $checksum_total);
        }

        // Warnings not tied to a booked match: underpayment / low_confidence (notify),
        // and no_reference / bulk_payment (candidate e-mail).
        self::process_warnings($warnings, $expectations);

        return array('applied' => $applied, 'held' => $held);
    }

    /**
     * Pure decision for one clean, fully-paid match. Extracted so the money path is
     * unit-tested without WordPress.
     *
     * Order of precedence: a batch mismatch holds for a look; then an overpayment the
     * shop wants to confirm; then the release limit (only in the automatic path). A
     * manual "Reconcile now" ignores the limit but still respects overpayment/checksum.
     *
     * @param float  $amount
     * @param float  $threshold
     * @param bool   $manual
     * @param bool   $is_overpaid
     * @param string $overpay_mode 'hold' | 'pay'
     * @param bool   $has_checksum
     * @return string 'book' | 'hold_approval' | 'hold_notify'
     */
    public static function decide($amount, $threshold, $manual, $is_overpaid, $overpay_mode, $has_checksum)
    {
        if ($has_checksum) {
            return 'hold_notify';
        }

        if ($is_overpaid && $overpay_mode === 'hold') {
            return 'hold_approval';
        }

        if (!$manual && (float) $amount > (float) $threshold) {
            return 'hold_approval';
        }

        return 'book';
    }

    /**
     * Handle the warnings that are not resolved together with a match.
     *
     * @param array $warnings
     * @param array $expectations
     * @return void
     */
    private static function process_warnings(array $warnings, array $expectations)
    {
        $by_ref     = self::orders_by_ref($expectations);
        $by_num     = self::orders_by_num($expectations);
        $candidates = array();

        foreach ($warnings as $w) {
            $type = isset($w['type']) ? $w['type'] : '';

            // Overpayment and checksum are handled next to the match in process().
            if ($type === 'overpayment' || $type === 'checksum' || $type === '') {
                continue;
            }

            $dedup = self::dedup_key(array('warn', $type, isset($w['reference']) ? self::normalize($w['reference']) : '', $w));

            if (self::already_processed($dedup)) {
                continue;
            }

            self::mark_processed($dedup);

            if ($type === 'no_reference' || $type === 'bulk_payment') {
                $candidates[] = $w; // candidate suggestion e-mail, sent together below

                $cref = isset($w['candidate_references'][0]) ? (string) $w['candidate_references'][0] : '';
                self::log_add(
                    self::normalize($cref), $cref,
                    isset($w['amount']) ? (float) $w['amount'] : null,
                    isset($w['currency']) ? (string) $w['currency'] : '',
                    null, array(), self::warning_consequence($type)
                );

                continue;
            }

            self::handle_simple_warning($w, $by_ref, $by_num);
        }

        if ($candidates) {
            self::notify_suggestions($candidates, $expectations);
        }
    }

    /**
     * A warning that points at (at most) one order: e-mail the admin, add an order note
     * where a reference resolves, and log it. Never books anything.
     *
     * @param array $w      One warning from the service.
     * @param array $by_ref normalised reference => order info.
     * @param array $by_num order number => order info (for warnings that carry only the
     *                      order number, e.g. no_reference_key on a non-sqrip order).
     * @return void
     */
    private static function handle_simple_warning(array $w, array $by_ref, array $by_num = array())
    {
        $type     = $w['type'];
        $ref_norm = isset($w['reference']) ? self::normalize($w['reference']) : '';
        $info     = ($ref_norm !== '' && isset($by_ref[$ref_norm])) ? $by_ref[$ref_norm] : null;

        // Fall back to the order number when the warning carries no resolvable reference
        // (a non-sqrip / bank-transfer order matched by its order number only).
        if (!$info && isset($w['order_number']) && isset($by_num[(string) $w['order_number']])) {
            $info = $by_num[(string) $w['order_number']];
        }

        $order = $info ? wc_get_order($info['order_id']) : null;

        if (!$order || !sqrip_order_in_avis_scope($order)) {
            $order = null;
        }

        $ref_display = $info ? $info['reference_display'] : $ref_norm;
        $currency    = $info ? (string) $info['currency'] : (isset($w['currency']) ? (string) $w['currency'] : '');
        $expected    = $info ? $info['expected'] : (isset($w['expected']) ? $w['expected'] : null);

        // Order note (plain) + a detailed e-mail (HTML with the order link).
        if ($order) {
            self::add_order_note($order, self::warning_message($w));
        }

        if ($type === 'underpayment' && $order) {
            self::send_underpayment_email($order, $ref_display, $expected, $w, $currency);
        } elseif ($type === 'low_confidence' && $order) {
            self::send_lowconfidence_email($order, $ref_display, $w, $currency);
        } elseif ($type === 'no_reference_key' && $order) {
            self::send_no_reference_key_email($order);
        } else {
            self::send_fallback_email($order, $w, $currency);
        }

        $amount  = isset($w['received']) ? (float) $w['received'] : (isset($w['amount']) ? (float) $w['amount'] : null);
        $score   = isset($w['score']) ? (int) $w['score'] : null;
        $aspects = isset($w['matched_aspects']) && is_array($w['matched_aspects']) ? $w['matched_aspects'] : array();

        self::log_add($ref_norm, $ref_display, $amount, $currency, $score, $aspects, self::warning_consequence($type));
    }

    /**
     * Add an order note. For an order that is NOT one of sqrip's own QR orders — reconciled
     * only because the shop opted its status into the reconciliation — the note says so
     * explicitly. That matters when another plugin also manages the same order (e.g. adds a
     * GiroCode or sets the status), so it stays clear who did what.
     *
     * @param \WC_Order $order
     * @param string    $text
     * @return void
     */
    private static function add_order_note($order, $text)
    {
        if ($order && is_a($order, 'WC_Order') && $order->get_payment_method() !== 'sqrip') {
            $text .= ' ' . __('Note: this order does not use the sqrip QR payment method — the sqrip payment notification service matched it via the order number read from the bank document.', 'sqrip-swiss-qr-invoice');
        }

        $order->add_order_note($text);
    }

    /**
     * Book one order as paid: order note, void sibling invoices (Skonto/reminder), and
     * move it to the shop's "completed" status.
     *
     * @param \WC_Order $order
     * @param array     $entry
     * @param array     $paid_slip
     * @return void
     */
    private static function book_order($order, $entry, $paid_slip)
    {
        self::add_order_note($order, self::note($paid_slip));

        if (!empty($entry['alternatives']) && !empty($entry['paid_alternative'])) {
            sqrip_void_other_invoices($order, $entry['paid_alternative']);
        }

        $status_completed = sqrip_get_plugin_option('status_completed');

        if ($status_completed) {
            $order->update_status($status_completed, '');
        } else {
            $order->save();
        }
    }

    /**
     * @param array $entry
     * @return array|null The first fully-paid slip of the entry.
     */
    private static function paid_slip($entry)
    {
        foreach ($entry['slips'] as $slip) {
            if ($slip['category'] === Sqrip_Camt_Reconciler::PAID) {
                return $slip;
            }
        }

        return null;
    }

    /**
     * normalised reference => {order_id, order_number, currency, expected, reference_display}
     * for every open slip, so a warning's reference can be resolved to an order.
     *
     * @param array $expectations
     * @return array
     */
    private static function orders_by_ref(array $expectations)
    {
        $by_ref = array();

        foreach ($expectations as $order) {
            foreach ($order['slips'] as $slip) {
                $key = self::normalize($slip['reference']);

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

        return $by_ref;
    }

    /**
     * order number => {order_id, order_number, currency, expected, reference_display} for
     * every open order, so a warning that carries only the order number (a non-sqrip order
     * matched by its number) can still be resolved to its order.
     *
     * @param array $expectations
     * @return array
     */
    private static function orders_by_num(array $expectations)
    {
        $by_num = array();

        foreach ($expectations as $order) {
            $num = (string) $order['order_number'];

            if ($num === '') {
                continue;
            }

            $slip = isset($order['slips'][0]) ? $order['slips'][0] : array();

            $by_num[$num] = array(
                'order_id'          => $order['order_id'],
                'order_number'      => $order['order_number'],
                'currency'          => $order['currency'],
                'expected'          => isset($slip['expected']) ? $slip['expected'] : (isset($order['total']) ? $order['total'] : null),
                'reference_display' => isset($slip['reference']) ? (string) $slip['reference'] : '',
            );
        }

        return $by_num;
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

    /**
     * The order note added when a matched payment is held instead of booked.
     *
     * @param array  $slip
     * @param string $reason 'overpayment' | 'checksum' | 'over_threshold'
     * @return string
     */
    private static function hold_note($slip, $reason)
    {
        switch ($reason) {
            case 'overpayment':
                $lead = __('Payment received but held: the amount is higher than the order. Waiting for your release.', 'sqrip-swiss-qr-invoice');
                break;
            case 'checksum':
                $lead = __('Payment received but held: a batch total did not add up. Please check.', 'sqrip-swiss-qr-invoice');
                break;
            default:
                $lead = __('Payment received but held: above your release limit. Waiting for your release.', 'sqrip-swiss-qr-invoice');
        }

        return $lead . ' ' . sprintf(
            /* translators: %s: reference */
            __('Reference %s. sqrip payment notification service.', 'sqrip-swiss-qr-invoice'),
            $slip['reference']
        );
    }

    /**
     * A short plain-text sentence for one warning — used for the order note. The e-mail
     * bodies are built separately (richer, with the order link).
     *
     * @param array $w
     * @return string
     */
    private static function warning_message(array $w)
    {
        $type = isset($w['type']) ? $w['type'] : '';
        $ref  = isset($w['reference']) ? $w['reference'] : '';

        switch ($type) {
            case 'underpayment':
                return sprintf(
                    /* translators: 1: received amount, 2: expected amount, 3: reference */
                    __('Underpaid: received %1$s, expected %2$s (reference %3$s). The order is held.', 'sqrip-swiss-qr-invoice'),
                    self::money($w, 'received'), self::money($w, 'expected'), $ref
                );
            case 'overpayment':
                return sprintf(
                    /* translators: 1: received amount, 2: expected amount, 3: reference */
                    __('Overpaid: received %1$s, expected %2$s (reference %3$s).', 'sqrip-swiss-qr-invoice'),
                    self::money($w, 'received'), self::money($w, 'expected'), $ref
                );
            case 'low_confidence':
                $base = sprintf(
                    /* translators: 1: score (0-10), 2: reference */
                    __('Assigned with low confidence (score %1$d of 10, reference %2$s). Please check.', 'sqrip-swiss-qr-invoice'),
                    isset($w['score']) ? (int) $w['score'] : 0, $ref
                );

                if (!empty($w['currency_mismatch'])) {
                    $base .= ' ' . __('The payment currency does not match the order.', 'sqrip-swiss-qr-invoice');
                }

                return $base;
            case 'no_reference_key':
                return sprintf(
                    /* translators: %s: order number */
                    __('A payment came in for order %s, but the order has no payment reference — matched by the order number only. Please check and assign it by hand.', 'sqrip-swiss-qr-invoice'),
                    isset($w['order_number']) ? $w['order_number'] : ''
                );
        }

        return isset($w['message']) ? (string) $w['message'] : __('A payment needs your attention.', 'sqrip-swiss-qr-invoice');
    }

    /**
     * Short label for the log's "Consequence" column.
     *
     * @param string $type
     * @return string
     */
    private static function warning_consequence($type)
    {
        switch ($type) {
            case 'underpayment':
                return __('Held — underpaid.', 'sqrip-swiss-qr-invoice');
            case 'low_confidence':
                return __('Flagged — please check.', 'sqrip-swiss-qr-invoice');
            case 'no_reference_key':
                return __('Flagged — no payment reference.', 'sqrip-swiss-qr-invoice');
            case 'no_reference':
            case 'bulk_payment':
                return __('E-mailed for your assignment.', 'sqrip-swiss-qr-invoice');
        }

        return __('Flagged.', 'sqrip-swiss-qr-invoice');
    }

    /**
     * @param array  $w
     * @param string $key
     * @return string Currency + formatted amount (currency omitted if unknown).
     */
    private static function money(array $w, $key)
    {
        if (!isset($w[$key])) {
            return '';
        }

        $ccy = isset($w['currency']) && $w['currency'] !== '' ? trim((string) $w['currency']) . ' ' : '';

        return $ccy . number_format((float) $w[$key], 2, '.', '');
    }

    // --- warning e-mails to the shop admin ---------------------------------

    /**
     * @param string $subject
     * @param string $body_html
     * @return void
     */
    private static function mail_admin($subject, $body_html)
    {
        $to = apply_filters('sqrip_avis_notify_recipient', get_option('admin_email'));
        wp_mail($to, $subject, $body_html, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * @return string Subject of the "please check" e-mails.
     */
    private static function subject_check()
    {
        return __('sqrip: action needed — please check a payment', 'sqrip-swiss-qr-invoice');
    }

    /**
     * @param string     $currency
     * @param float|null $amount
     * @return string Currency + amount, or '' when unknown.
     */
    private static function amount_str($currency, $amount)
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        $ccy = ($currency !== null && $currency !== '') ? trim((string) $currency) . ' ' : '';

        return $ccy . number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param \WC_Order $order
     * @return string Escaped "#number" link to the order.
     */
    private static function order_link_html($order)
    {
        return '<a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a>';
    }

    /**
     * @param \WC_Order $order
     * @return string The order's current status label.
     */
    private static function status_name($order)
    {
        return function_exists('wc_get_order_status_name')
            ? wc_get_order_status_name($order->get_status())
            : $order->get_status();
    }

    /**
     * "Order #123, outstanding amount CHF 50.00, reference RF…." (escaped markup).
     *
     * @param \WC_Order  $order
     * @param string     $reference
     * @param float|null $expected
     * @param string     $currency
     * @return string
     */
    private static function order_ref_line($order, $reference, $expected, $currency)
    {
        $amt = self::amount_str($currency, $expected);

        return sprintf(
            /* translators: 1: order link like #123, 2: outstanding amount, 3: reference */
            esc_html__('Order %1$s, outstanding amount %2$s, reference %3$s.', 'sqrip-swiss-qr-invoice'),
            self::order_link_html($order),
            $amt !== '' ? esc_html($amt) : '&mdash;',
            esc_html((string) $reference)
        );
    }

    /**
     * @param \WC_Order $order
     * @return string "Please check the order: #123" (escaped markup).
     */
    private static function check_order_link($order)
    {
        return sprintf(
            /* translators: %s: link to the order */
            esc_html__('Please check the order: %s', 'sqrip-swiss-qr-invoice'),
            self::order_link_html($order)
        );
    }

    /**
     * Customer paid too little: order stays open, admin informed.
     *
     * @return void
     */
    private static function send_underpayment_email($order, $reference, $expected, array $w, $currency)
    {
        $received = self::amount_str($currency, isset($w['received']) ? $w['received'] : null);

        $body =
            '<p style="font-family:sans-serif;font-size:14px;">'
            . self::order_ref_line($order, $reference, $expected, $currency) . ' '
            . sprintf(
                /* translators: %s: received amount */
                esc_html__('The customer paid too little (%s).', 'sqrip-swiss-qr-invoice'),
                $received !== '' ? esc_html($received) : '&mdash;'
            )
            . '</p>'
            . '<p style="font-family:sans-serif;font-size:14px;">&rarr; '
            . sprintf(
                /* translators: %s: order status */
                esc_html__('The order stays on "%s".', 'sqrip-swiss-qr-invoice'),
                esc_html(self::status_name($order))
            )
            . '<br>&rarr; ' . self::check_order_link($order)
            . '</p>';

        self::mail_admin(self::subject_check(), $body);
    }

    /**
     * Customer paid too much and the shop books it anyway ("pay" setting): admin informed.
     * Read the status AFTER booking, so it shows the status the order actually landed on.
     *
     * @return void
     */
    private static function send_overpayment_paid_email($order, $reference, $expected, array $w, $currency)
    {
        $received = self::amount_str($currency, isset($w['received']) ? $w['received'] : null);

        $body =
            '<p style="font-family:sans-serif;font-size:14px;">'
            . self::order_ref_line($order, $reference, $expected, $currency) . ' '
            . sprintf(
                /* translators: %s: received amount */
                esc_html__('The customer paid too much (%s).', 'sqrip-swiss-qr-invoice'),
                $received !== '' ? esc_html($received) : '&mdash;'
            )
            . '</p>'
            . '<p style="font-family:sans-serif;font-size:14px;">&rarr; '
            . sprintf(
                /* translators: %s: new order status */
                esc_html__('The order was set to "%s".', 'sqrip-swiss-qr-invoice'),
                esc_html(self::status_name($order))
            )
            . '<br>&rarr; ' . self::check_order_link($order)
            . '</p>';

        self::mail_admin(self::subject_check(), $body);
    }

    /**
     * A payment was tied to one order but only with low confidence.
     *
     * @return void
     */
    private static function send_lowconfidence_email($order, $reference, array $w, $currency)
    {
        $score = isset($w['score']) ? (int) $w['score'] : 0;

        $body =
            '<p style="font-family:sans-serif;font-size:14px;">'
            . sprintf(
                /* translators: 1: order link, 2: reference, 3: score 0-10 */
                esc_html__('A payment was assigned to order %1$s (reference %2$s), but only with low confidence (score %3$d/10).', 'sqrip-swiss-qr-invoice'),
                self::order_link_html($order), esc_html((string) $reference), $score
            )
            . '</p>';

        if (!empty($w['currency_mismatch'])) {
            // The service now sends the credit's own currency; if present, name both.
            $pay_ccy  = isset($w['payment_currency']) ? (string) $w['payment_currency'] : '';
            $mismatch = ($pay_ccy !== '')
                ? sprintf(
                    /* translators: 1: payment currency, 2: order currency */
                    esc_html__('The payment currency (%1$s) does not match the order\'s currency (%2$s).', 'sqrip-swiss-qr-invoice'),
                    esc_html($pay_ccy), esc_html($currency))
                : sprintf(
                    /* translators: %s: the order's currency */
                    esc_html__('The payment currency does not match the order\'s currency (%s).', 'sqrip-swiss-qr-invoice'),
                    esc_html($currency));

            $body .= '<p style="font-family:sans-serif;font-size:14px;">' . $mismatch . '</p>';
        }

        $body .= '<p style="font-family:sans-serif;font-size:14px;">&rarr; ' . self::check_order_link($order) . '</p>';

        self::mail_admin(self::subject_check(), $body);
    }

    /**
     * An unclassified warning — best effort with whatever the service sent.
     *
     * @param \WC_Order|null $order
     * @return void
     */
    private static function send_fallback_email($order, array $w, $currency)
    {
        $amount = isset($w['amount']) ? $w['amount']
            : (isset($w['received']) ? $w['received']
            : (isset($w['payment_amount']) ? $w['payment_amount'] : null));
        $ccy    = ($currency !== '' && $currency !== null) ? $currency
            : (isset($w['payment_currency']) ? (string) $w['payment_currency'] : '');
        $desc   = self::amount_str($ccy, $amount);

        $body = '<p style="font-family:sans-serif;font-size:14px;">'
            . ($desc !== ''
                ? sprintf(
                    /* translators: %s: amount */
                    esc_html__('A payment (%s) needs your attention.', 'sqrip-swiss-qr-invoice'),
                    esc_html($desc))
                : esc_html__('A payment needs your attention.', 'sqrip-swiss-qr-invoice'))
            . '</p>';

        if ($order && is_a($order, 'WC_Order')) {
            $body .= '<p style="font-family:sans-serif;font-size:14px;">&rarr; ' . self::check_order_link($order) . '</p>';
        }

        self::mail_admin(self::subject_check(), $body);
    }

    /**
     * A payment matched an order by its number, but the order carries no payment reference
     * (a non-sqrip / bank-transfer order). Ask the admin to check and assign it by hand.
     *
     * @param \WC_Order $order
     * @return void
     */
    private static function send_no_reference_key_email($order)
    {
        $body = '<p style="font-family:sans-serif;font-size:14px;">'
            . sprintf(
                /* translators: %s: order number (as a link) */
                esc_html__('A payment came in for order %s, but the order has no payment reference — matched by the order number only. Please check and assign it by hand.', 'sqrip-swiss-qr-invoice'),
                self::order_link_html($order))
            . '</p>'
            . '<p style="font-family:sans-serif;font-size:14px;">&rarr; ' . self::check_order_link($order) . '</p>';

        self::mail_admin(self::subject_check(), $body);
    }

    /**
     * One e-mail for a batch payment whose total does not add up: lists every affected
     * order, states whether the batch is short or over, and that all are held.
     *
     * @param array      $held        [ {order, reference, expected, currency}, … ]
     * @param float|null $batch_total
     * @return void
     */
    private static function send_checksum_email(array $held, $batch_total)
    {
        $sum      = 0.0;
        $currency = '';
        $items    = '';

        foreach ($held as $h) {
            if ($currency === '') {
                $currency = (string) $h['currency'];
            }
            if ($h['expected'] !== null) {
                $sum += (float) $h['expected'];
            }
            $items .= '<li style="margin:2px 0;">' . self::order_ref_line($h['order'], $h['reference'], $h['expected'], $h['currency']) . '</li>';
        }

        $cmp = ((float) $batch_total < $sum)
            ? sprintf(
                /* translators: 1: batch total, 2: sum of outstanding amounts */
                esc_html__('The batch total (%1$s) is smaller than the sum of the outstanding amounts (%2$s).', 'sqrip-swiss-qr-invoice'),
                esc_html(self::amount_str($currency, $batch_total)), esc_html(self::amount_str($currency, $sum)))
            : sprintf(
                /* translators: 1: batch total, 2: sum of outstanding amounts */
                esc_html__('The batch total (%1$s) is larger than the sum of the outstanding amounts (%2$s).', 'sqrip-swiss-qr-invoice'),
                esc_html(self::amount_str($currency, $batch_total)), esc_html(self::amount_str($currency, $sum)));

        $body =
            '<p style="font-family:sans-serif;font-size:14px;">' . esc_html__('This concerns:', 'sqrip-swiss-qr-invoice') . '</p>'
            . '<ul style="font-family:sans-serif;font-size:14px;">' . $items . '</ul>'
            . '<p style="font-family:sans-serif;font-size:14px;">' . $cmp . '</p>'
            . '<p style="font-family:sans-serif;font-size:14px;">&rarr; ' . esc_html__('All orders stay on their current status.', 'sqrip-swiss-qr-invoice')
            . '<br>&rarr; ' . esc_html__('Please check the orders.', 'sqrip-swiss-qr-invoice') . '</p>';

        self::mail_admin(self::subject_check(), $body);
    }

    /**
     * E-mail the admin a one-click release for a clean match that was held (above the
     * release limit, or an overpayment the shop chose to confirm by hand). Reuses the
     * signed confirm/reject/open links of the suggestion e-mail with a single candidate,
     * and leads with the concrete order details.
     *
     * @param \WC_Order $order
     * @param array     $entry
     * @param array     $paid_slip
     * @param float     $amount    Received amount.
     * @param float|null $expected Order's outstanding amount.
     * @param string    $currency
     * @param string    $reason    'over_threshold' | 'overpayment'
     * @return void
     */
    private static function send_approval_email($order, $entry, $paid_slip, $amount, $expected, $currency, $reason)
    {
        $ref_norm = self::normalize($paid_slip['reference']);

        $candidates = array($ref_norm => array(
            'order_id'          => $entry['order_id'],
            'order_number'      => $entry['order_number'],
            'currency'          => $entry['currency'],
            'expected'          => isset($entry['total']) ? $entry['total'] : null,
            'reference_display' => (string) $paid_slip['reference'],
        ));

        $line   = self::order_ref_line($order, $paid_slip['reference'], $expected, $currency);
        $status = esc_html(self::status_name($order));
        /* translators: %s: order status */
        $stays  = sprintf(esc_html__('The order stays on "%s" until you release it.', 'sqrip-swiss-qr-invoice'), $status);

        if ($reason === 'overpayment') {
            /* translators: %s: received amount */
            $detail = $line . ' ' . sprintf(esc_html__('The customer paid too much (%s).', 'sqrip-swiss-qr-invoice'), esc_html(self::amount_str($currency, $amount))) . ' ' . $stays;
        } else {
            /* translators: %s: received amount */
            $detail = $line . ' ' . sprintf(esc_html__('The amount %s is above your release limit.', 'sqrip-swiss-qr-invoice'), esc_html(self::amount_str($currency, $amount))) . ' ' . $stays;
        }

        self::send_suggestion_email(array('amount' => $amount, 'currency' => $currency), $candidates, $reason, $detail);
    }

    // --- recognised-payments log (shown under "Reconcile") -----------------

    /**
     * Append one recognised payment to the rolling log. The timestamp is stamped now, in
     * the server's own time (not the notification's UTC field), and rendered in the
     * site's timezone — so the "Date / time" column is never off by the UTC offset.
     *
     * @return void
     */
    private static function log_add($ref_norm, $ref_display, $amount, $currency, $score, $aspects, $consequence)
    {
        $log = get_option(self::LOG_OPTION, array());

        if (!is_array($log)) {
            $log = array();
        }

        array_unshift($log, array(
            'ts'          => time(),
            'reference'   => (string) ($ref_display !== '' ? $ref_display : $ref_norm),
            'amount'      => ($amount === null || $amount === '') ? null : (float) $amount,
            'currency'    => (string) $currency,
            'score'       => ($score === null) ? null : (int) $score,
            'aspects'     => is_array($aspects) ? array_values($aspects) : array(),
            'consequence' => (string) $consequence,
        ));

        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_MAX), false);
    }

    /**
     * The recognised-payments table, newest first. Escaped markup, safe to echo.
     *
     * @return string
     */
    public static function render_log()
    {
        $log = get_option(self::LOG_OPTION, array());

        if (!is_array($log) || !$log) {
            return '';
        }

        ob_start();
        ?>
        <p style="margin-top:16px;"><strong><?php esc_html_e('Recently recognised payments', 'sqrip-swiss-qr-invoice'); ?></strong></p>
        <table class="widefat striped" style="margin-top:4px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date / time', 'sqrip-swiss-qr-invoice'); ?></th>
                    <th><?php esc_html_e('QR reference / SCOR', 'sqrip-swiss-qr-invoice'); ?></th>
                    <th><?php esc_html_e('Amount', 'sqrip-swiss-qr-invoice'); ?></th>
                    <th><?php esc_html_e('Score', 'sqrip-swiss-qr-invoice'); ?></th>
                    <th><?php esc_html_e('Findings', 'sqrip-swiss-qr-invoice'); ?></th>
                    <th><?php esc_html_e('Consequence', 'sqrip-swiss-qr-invoice'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log as $row) : ?>
                    <tr>
                        <td><?php echo esc_html(self::log_time($row)); ?></td>
                        <td><code><?php echo esc_html(isset($row['reference']) ? $row['reference'] : ''); ?></code></td>
                        <td><?php echo esc_html(self::log_amount($row)); ?></td>
                        <td><?php echo esc_html(self::log_score($row)); ?></td>
                        <td><?php echo esc_html(self::aspects_label(isset($row['aspects']) ? $row['aspects'] : array())); ?></td>
                        <td><?php echo esc_html(isset($row['consequence']) ? $row['consequence'] : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        return ob_get_clean();
    }

    /**
     * @param array $row
     * @return string Local date and time of a log row.
     */
    private static function log_time($row)
    {
        $ts = isset($row['ts']) ? (int) $row['ts'] : 0;

        if (!$ts) {
            return '';
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts);
    }

    /**
     * @param array $row
     * @return string
     */
    private static function log_amount($row)
    {
        if (!isset($row['amount']) || $row['amount'] === null) {
            return '—';
        }

        return trim((string) (isset($row['currency']) ? $row['currency'] : '') . ' ' . number_format((float) $row['amount'], 2, '.', ''));
    }

    /**
     * @param array $row
     * @return string Score out of 10, or a dash when the row carries none.
     */
    private static function log_score($row)
    {
        if (!isset($row['score']) || $row['score'] === null) {
            return '—';
        }

        /* translators: %d: score from 0 to 10 */
        return sprintf(__('%d/10', 'sqrip-swiss-qr-invoice'), (int) $row['score']);
    }

    /**
     * Turn the service's matched aspects into readable findings.
     *
     * @param array $aspects
     * @return string
     */
    private static function aspects_label($aspects)
    {
        if (!is_array($aspects) || !$aspects) {
            return '—';
        }

        $labels = array(
            'reference'    => __('Reference', 'sqrip-swiss-qr-invoice'),
            'order_number' => __('Order number', 'sqrip-swiss-qr-invoice'),
            'payer'        => __('Payer', 'sqrip-swiss-qr-invoice'),
            'amount'       => __('Amount', 'sqrip-swiss-qr-invoice'),
        );

        $out = array();

        foreach ($aspects as $a) {
            $out[] = isset($labels[$a]) ? $labels[$a] : (string) $a;
        }

        return implode(', ', $out);
    }

    // --- one-time processing guard (single delivery) ----------------------

    /**
     * @param array $parts
     * @return string A stable key for one match or warning.
     */
    private static function dedup_key(array $parts)
    {
        return md5((string) wp_json_encode($parts));
    }

    /**
     * @param string $key
     * @return bool
     */
    private static function already_processed($key)
    {
        $seen = get_option(self::SEEN_OPTION, array());

        return is_array($seen) && isset($seen[$key]);
    }

    /**
     * @param string $key
     * @return void
     */
    private static function mark_processed($key)
    {
        $seen = get_option(self::SEEN_OPTION, array());

        if (!is_array($seen)) {
            $seen = array();
        }

        $seen[$key] = time();

        // Keep the set bounded; drop the oldest keys well above any single claim's size.
        if (count($seen) > 500) {
            asort($seen);
            $seen = array_slice($seen, -500, null, true);
        }

        update_option(self::SEEN_OPTION, $seen, false);
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
    private static function send_suggestion_email(array $sug, array $candidates, $reason = 'candidate', $detail = '')
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

        // Approval e-mails (over_threshold / overpayment) lead with the concrete order
        // details ($detail, already safe HTML with the order link); the candidate e-mail
        // uses a plain intro. Both are emitted without further escaping below.
        if ($detail !== '') {
            $intro_html = $detail . ' ' . esc_html__('The links are valid for 24 hours and work once.', 'sqrip-swiss-qr-invoice');
        } else {
            $intro_html = esc_html__('The sqrip payment notification service found a probable payment that could not be assigned automatically. Please check it against the order, then confirm, reject, or open the order for details. The links are valid for 24 hours and work once.', 'sqrip-swiss-qr-invoice');
        }

        // Two clearly labelled sources: what the bank reported (via the notification
        // service) vs. what your shop holds. Sender / value date only exist on some
        // sources — omitted if empty.
        $parts = array_filter(array(trim($currency . ' ' . $amount), $sender, $value), 'strlen');

        $lbl = 'font-family:sans-serif;font-size:13px;color:#555;text-transform:uppercase;letter-spacing:.04em;margin:0 0 3px;';

        $body = '<p style="font-family:sans-serif;font-size:14px;">' . $intro_html . '</p>'
            . '<p style="' . $lbl . '">' . esc_html__('Received via the bank notification', 'sqrip-swiss-qr-invoice') . '</p>'
            . '<p style="font-family:sans-serif;font-size:15px;margin:0 0 18px;"><strong>' . esc_html(implode('  ·  ', $parts)) . '</strong></p>'
            . '<p style="' . $lbl . '">' . esc_html__('From your shop', 'sqrip-swiss-qr-invoice') . '</p>'
            . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-family:sans-serif;font-size:14px;">'
            . '<thead>' . $head . '</thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';

        switch ($reason) {
            case 'over_threshold':
                $subject = __('sqrip: payment above your limit — please release', 'sqrip-swiss-qr-invoice');
                break;
            case 'overpayment':
                $subject = __('sqrip: payment above the amount due — please release', 'sqrip-swiss-qr-invoice');
                break;
            default:
                $subject = __('sqrip: probable payment — please check', 'sqrip-swiss-qr-invoice');
        }

        $to = apply_filters('sqrip_avis_suggestion_recipient', get_option('admin_email'));

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

        if (!$order || !sqrip_order_in_avis_scope($order)) {
            self::suggestion_page(__('The order could not be found, so nothing was changed.', 'sqrip-swiss-qr-invoice'));
        }

        self::add_order_note($order, sprintf(
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

    // --- live status (settings page) --------------------------------------

    /**
     * Report whether the reconciliation really operates for this shop: the service must
     * be reachable AND the shop registered (which also runs the v2 account gate). Only
     * then does the service actually poll this shop's mailbox every minute.
     *
     * @return void
     */
    public static function ajax_status()
    {
        check_ajax_referer(self::NONCE, 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'sqrip-swiss-qr-invoice')), 403);
        }

        if (!self::is_enabled()) {
            wp_send_json_success(array('state' => 'off'));
        }

        $health = wp_remote_get(self::service_url() . '/', array('timeout' => 8));

        if (is_wp_error($health) || (int) wp_remote_retrieve_response_code($health) !== 200) {
            wp_send_json_success(array('state' => 'unreachable'));
        }

        // register_with_service() is idempotent and also runs the v2 account gate.
        if (self::register_with_service()) {
            wp_send_json_success(array('state' => 'running'));
        }

        wp_send_json_success(array('state' => 'problem', 'message' => self::$last_error));
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

        // A human pressed the button: read the mailbox fresh, and the release limit does
        // not apply here.
        $report = self::run(true);

        if (!is_array($report)) {
            wp_send_json_error(array('message' => $report));
        }

        self::process($report, true);

        wp_send_json_success(array('html' => self::render_check($report)));
    }

    /**
     * The table shown after "check now": every order waiting for payment and whether
     * a payment has come in for it. Doubles as the confirmation that notifications are
     * arriving and being recognised.
     *
     * @param array $report
     * @return string
     */
    private static function render_check(array $report)
    {
        $orders = $report['orders'];

        ob_start();
        ?>
        <p style="margin-top:12px;"><strong><?php esc_html_e('Orders still waiting for payment', 'sqrip-swiss-qr-invoice'); ?></strong></p>

        <?php if (!$orders) : ?>
            <p class="description"><?php esc_html_e('There are no orders waiting for payment right now.', 'sqrip-swiss-qr-invoice'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="margin-top:4px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Order number', 'sqrip-swiss-qr-invoice'); ?></th>
                        <th><?php esc_html_e('Amount', 'sqrip-swiss-qr-invoice'); ?></th>
                        <th><?php esc_html_e('QR reference / SCOR', 'sqrip-swiss-qr-invoice'); ?></th>
                        <th><?php esc_html_e('Name', 'sqrip-swiss-qr-invoice'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $entry) :
                        $refs = array();
                        foreach ($entry['slips'] as $slip) {
                            if (!empty($slip['reference'])) {
                                $refs[] = $slip['reference'];
                            }
                        }
                        $order = wc_get_order($entry['order_id']);
                        ?>
                        <tr>
                            <td><?php echo self::order_link($entry['order_id'], $entry['order_number']); ?></td>
                            <td><?php echo esc_html($entry['currency'] . ' ' . number_format((float) $entry['total'], 2, '.', '')); ?></td>
                            <td><code><?php echo esc_html(implode(', ', $refs)); ?></code></td>
                            <td><?php echo esc_html(self::contact_label($order)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:6px;">
                <a class="button-secondary" download="sqrip-unpaid-orders-<?php echo esc_attr(wp_date('Y-m-d')); ?>.csv" href="<?php echo esc_attr(self::orders_csv_href($orders)); ?>">
                    <?php esc_html_e('Download CSV', 'sqrip-swiss-qr-invoice'); ?>
                </a>
            </p>
        <?php endif; ?>

        <?php
        foreach ($report['warnings'] as $warning) :
            if (!is_array($warning)) {
                continue;
            }
            ?>
            <p class="description"><?php echo esc_html(self::warning_message($warning)); ?></p>
        <?php endforeach; ?>

        <?php echo self::render_log(); // escaped markup ?>
        <?php

        return ob_get_clean();
    }

    /**
     * The contact for the order's "Name" column: person, company, or both. If the order
     * carries a billing company it is shown, together with the person's name when present.
     *
     * @param \WC_Order|null $order
     * @return string
     */
    private static function contact_label($order)
    {
        if (!$order) {
            return '';
        }

        $company = trim((string) $order->get_billing_company());
        $person  = trim((string) $order->get_formatted_billing_full_name());

        if ($company !== '' && $person !== '') {
            return $company . ' (' . $person . ')';
        }

        return $company !== '' ? $company : $person;
    }

    /**
     * The order's payment deadline: its creation date plus the shop's due-date days. The
     * exact deadline is not stored per order — it is derived the same way the QR bill
     * computes it, which matches the normal case (bill generated at checkout).
     *
     * @param \WC_Order|null $order
     * @return string Y-m-d, or '' when unknown.
     */
    private static function due_date_for($order)
    {
        if (!$order || !$order->get_date_created()) {
            return '';
        }

        $days = sqrip_get_plugin_option('due_date');
        $days = is_numeric($days) ? (int) $days : 30;

        $due = clone $order->get_date_created();
        $due->modify('+' . $days . ' days');

        return $due->date('Y-m-d');
    }

    /**
     * A downloadable CSV of the waiting orders, with the order date and payment deadline
     * added. Returned as a base64 data: URI so the download needs no extra endpoint. The
     * UTF-8 BOM lets spreadsheets read the umlauts; the delimiter is a semicolon (what
     * European spreadsheets expect).
     *
     * @param array $orders
     * @return string
     */
    private static function orders_csv_href(array $orders)
    {
        $rows = array(array(
            __('Order number', 'sqrip-swiss-qr-invoice'),
            __('Order date', 'sqrip-swiss-qr-invoice'),
            __('Due date', 'sqrip-swiss-qr-invoice'),
            __('Currency', 'sqrip-swiss-qr-invoice'),
            __('Amount', 'sqrip-swiss-qr-invoice'),
            __('QR reference / SCOR', 'sqrip-swiss-qr-invoice'),
            __('Name', 'sqrip-swiss-qr-invoice'),
        ));

        foreach ($orders as $entry) {
            $order = wc_get_order($entry['order_id']);

            $refs = array();
            foreach ($entry['slips'] as $slip) {
                if (!empty($slip['reference'])) {
                    $refs[] = $slip['reference'];
                }
            }

            $created = ($order && $order->get_date_created()) ? $order->get_date_created()->date('Y-m-d') : '';

            $rows[] = array(
                $entry['order_number'],
                $created,
                self::due_date_for($order),
                $entry['currency'],
                number_format((float) $entry['total'], 2, '.', ''),
                implode(', ', $refs),
                self::contact_label($order),
            );
        }

        return 'data:text/csv;charset=utf-8;base64,' . base64_encode("\xEF\xBB\xBF" . self::to_csv($rows));
    }

    /**
     * @param array $rows Rows of cells.
     * @return string Semicolon-separated CSV, fields quoted where needed, CRLF line ends.
     */
    private static function to_csv(array $rows)
    {
        $lines = array();

        foreach ($rows as $row) {
            $cells = array();

            foreach ($row as $cell) {
                $cell = (string) $cell;

                if (preg_match('/[";\r\n]/', $cell)) {
                    $cell = '"' . str_replace('"', '""', $cell) . '"';
                }

                $cells[] = $cell;
            }

            $lines[] = implode(';', $cells);
        }

        return implode("\r\n", $lines) . "\r\n";
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
