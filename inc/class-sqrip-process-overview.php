<?php

/**
 * Prozesskonfigurator P0 — "So sieht Ihr Prozess heute aus".
 *
 * Read-only view that derives the current order flow from the existing plugin
 * settings and shows it in plain text. It writes nothing, changes nothing, and
 * touches none of the ~40 process-relevant call sites (that is P4). It is the
 * first, risk-free building block of the process configurator.
 *
 * Golden rule: never describe a step the code does not actually perform.
 *
 * Two layers, deliberately separated:
 *   - Layer A: {@see derive_steps()} — a pure function of an already-resolved
 *     option tuple. No WordPress. Fully unit-testable (tests/process).
 *   - Layer B: {@see build_resolved()} and {@see render()} — WordPress-bound.
 *     Resolves field defaults and the *effective* feature switches (via the
 *     canonical is_enabled() methods, which honour the [HOLD 1.11] parks) and
 *     turns the derived steps into localized HTML.
 *
 * @package sqrip
 * @since   1.12.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sqrip_Process_Overview
{
    /**
     * Placeholder that WooCommerce stores for "no explicit status chosen".
     * Treated as empty when deriving the effective status.
     *
     * @see sqrip-woocommerce.php (thank-you hook, ~1924-1927)
     */
    const STATUS_PLACEHOLDER = 'wc-sqrip-default-status';

    /**
     * When each standard WooCommerce e-mail is sent. This is the core of the
     * "when does the invoice reach the customer" answer: the QR-invoice PDF is
     * attached to the e-mails chosen in `email_attached`, and each of those
     * e-mails fires on its own trigger — almost always an order-status change.
     *
     * Keyed by WC_Email->id. 'trigger' is one of:
     *   'status:<slug>'  fires when the order moves to that status
     *   'on_new_order'   fires when a new order comes in
     *   'on_refund'      fires on a refund
     *   'on_note'        fires when a customer note is added
     *   'manual'         only when the shop sends it by hand from the order menu
     * Unknown ids (custom/third-party e-mails) fall back to 'unknown'.
     *
     * @var array
     */
    const EMAIL_TRIGGERS = array(
        'new_order'                 => array('role' => 'admin',    'trigger' => 'on_new_order'),
        'cancelled_order'           => array('role' => 'admin',    'trigger' => 'status:cancelled'),
        'failed_order'              => array('role' => 'admin',    'trigger' => 'status:failed'),
        'customer_on_hold_order'    => array('role' => 'customer', 'trigger' => 'status:on-hold'),
        'customer_processing_order' => array('role' => 'customer', 'trigger' => 'status:processing'),
        'customer_completed_order'  => array('role' => 'customer', 'trigger' => 'status:completed'),
        'customer_refunded_order'   => array('role' => 'customer', 'trigger' => 'on_refund'),
        'customer_invoice'          => array('role' => 'customer', 'trigger' => 'manual'),
        'customer_note'             => array('role' => 'customer', 'trigger' => 'on_note'),
    );

    /**
     * Wire up the read-only render hook. No menu, no ajax, no form.
     *
     * The view lives inside the existing "Services" settings tab, after the
     * feature switches. The gateway renders it through a pseudo-field of
     * type "process_overview" whose generate_*_html() delegates here.
     */
    public static function init()
    {
        // Nothing to hook for the pure render path — the gateway calls
        // self::render() from generate_process_overview_html(). This method
        // exists so the bootstrap in sqrip-woocommerce.php mirrors the other
        // feature classes and gives us a single place to add hooks later.
    }

    /* ===================================================================
     * Layer A — pure derivation (no WordPress)
     * =================================================================== */

    /**
     * Derive the ordered flow from a fully-resolved option tuple.
     *
     * Every value in $r is already normalized by Layer B: defaults filled in,
     * feature switches reduced to effective booleans (parks honoured), status
     * placeholder collapsed to ''. This function performs no WordPress calls so
     * it can be unit-tested with a plain array.
     *
     * @param array $r Resolved tuple. Expected keys:
     *   bool   suppress            Rechnung bei Bestelleingang unterdrückt
     *   bool   multiple_slips      mehrere QR-Teilrechnungen aktiv
     *   int    number_of_invoices  Anzahl Teilrechnungen (>=2 wenn multiple_slips)
     *   bool   skonto              Skonto EFFEKTIV aktiv (is_enabled, Park beachtet)
     *   float  skonto_percentage   Skonto-Prozent
     *   string status_suppressed   Zielstatus bei Unterdrückung ('' = keiner)
     *   string qr_order_status     Zielstatus nach Rechnungserzeugung
     *   mixed  email_attached      WC-E-Mail-ID(s) oder '' (Rechnung angehängt an)
     *   bool   send_status_emails  qr_order_status_send_emails aktiv
     *   bool   payment_comparison  Zahlungsvergleich EFFEKTIV aktiv
     *   bool   camt                camt-Abgleich EFFEKTIV aktiv
     *   bool   avis                Avis EFFEKTIV aktiv
     *   string status_awaiting     Wartestatus bis Zahlung
     *   string status_completed    Status nach erkannter Zahlung
     *   string delete_invoice_status Status, bei dem die Rechnung entwertet wird ('' = nie)
     *   bool   reminder            Mahnung EFFEKTIV aktiv (is_enabled, Park beachtet)
     *   int    reminder_days       Tage nach Fälligkeit bis Mahnung
     *   string reminder_fee_label  Bezeichnung der Mahngebühr
     *
     * @return array {
     *   @type array  $steps Ordered list. Each: [
     *       'id'     => stable string,
     *       'kind'   => 'sqrip'|'extern'|'derived',
     *       'status' => resulting order-status slug or null,
     *       'flags'  => assoc array of the facts a test/render needs,
     *   ]
     *   @type array  $notes Honesty caveats (strings-by-key) the render must show.
     * }
     */
    public static function derive_steps(array $r)
    {
        $steps = array();
        $notes = array();

        $creates_invoice = empty($r['suppress']);

        // --- 1. Bei Bestelleingang -------------------------------------
        // Effective on-order status. The gateway first sets a transient
        // 'pending' in process_payment; the thank-you hook then applies:
        //   suppress && status_suppressed  -> status_suppressed
        //   otherwise                      -> qr_order_status
        // (status_suppressed placeholder already collapsed to '' by Layer B.)
        if (!empty($r['suppress'])) {
            $on_order_status = ($r['status_suppressed'] !== '')
                ? $r['status_suppressed']
                : $r['qr_order_status'];
        } else {
            $on_order_status = $r['qr_order_status'];
        }

        $steps[] = array(
            'id'     => 'on_order',
            'kind'   => 'sqrip',
            'status' => ($on_order_status !== '') ? $on_order_status : null,
            'flags'  => array(
                'creates_invoice'    => $creates_invoice,
                'invoice_shape'      => (!empty($r['multiple_slips'])) ? 'multiple' : 'single',
                'number_of_invoices' => (!empty($r['multiple_slips'])) ? (int) $r['number_of_invoices'] : 1,
                'skonto'             => !empty($r['skonto']),
                'skonto_percentage'  => !empty($r['skonto']) ? (float) $r['skonto_percentage'] : 0.0,
            ),
        );

        // --- 2. Rechnungsversand: WANN erreicht die Rechnung den Kunden --
        // The central merchant question. Three channels, each with its own
        // timing, all derivable from the settings:
        //   (a) immediate download on the confirmation page (integration_order)
        //   (b) attached to each chosen WC e-mail, which fires on ITS trigger
        //       (EMAIL_TRIGGERS — almost always a status change)
        //   (c) forced at checkout (qr_order_status_send_emails): customer
        //       on-hold + admin new-order, sent directly (gateway:2336).
        // Each e-mail still respects its own enabled state in WooCommerce; that
        // caveat is surfaced in the render, not silently assumed away.
        if ($creates_invoice) {
            $emails = array();
            foreach ((array) $r['email_attached'] as $email_id) {
                if ($email_id === '' || $email_id === null) {
                    continue;
                }
                $map = isset(self::EMAIL_TRIGGERS[$email_id])
                    ? self::EMAIL_TRIGGERS[$email_id]
                    : array('role' => 'unknown', 'trigger' => 'unknown');
                $emails[] = array(
                    'id'      => $email_id,
                    'role'    => $map['role'],
                    'trigger' => $map['trigger'],
                );
            }

            // The forced checkout e-mails carry the invoice only if the customer
            // on-hold e-mail is itself among the chosen attachment e-mails.
            $selected_ids = array_map(function ($e) { return $e['id']; }, $emails);
            $force_carries_invoice = in_array('customer_on_hold_order', $selected_ids, true);

            $steps[] = array(
                'id'     => 'invoice_delivery',
                'kind'   => 'sqrip',
                'status' => null,
                'flags'  => array(
                    'download_on_confirmation' => !empty($r['integration_order']),
                    'emails'                   => $emails,
                    'force_checkout_emails'    => !empty($r['send_status_emails']),
                    'force_carries_invoice'    => (!empty($r['send_status_emails']) && $force_carries_invoice),
                ),
            );
        }

        // --- 3. Zahlungsfeststellung -----------------------------------
        // camt and avis are BOTH sub-features of payment_comparison
        // (camt-admin:40-41, avis:86-87). Model as a tree, not three peers.
        if (!empty($r['camt']) && !empty($r['avis'])) {
            $method = 'camt+avis';
        } elseif (!empty($r['camt'])) {
            $method = 'camt';
        } elseif (!empty($r['avis'])) {
            $method = 'avis';
        } elseif (!empty($r['payment_comparison'])) {
            $method = 'manual';
        } else {
            $method = 'none';
        }

        $steps[] = array(
            'id'     => 'payment_detection',
            'kind'   => 'sqrip',
            'status' => ($r['status_awaiting'] !== '') ? $r['status_awaiting'] : null,
            'flags'  => array('method' => $method),
        );

        // --- 4. Danach --------------------------------------------------
        $steps[] = array(
            'id'     => 'after_payment',
            'kind'   => 'sqrip',
            'status' => ($r['status_completed'] !== '') ? $r['status_completed'] : null,
            'flags'  => array(
                'devalue_status' => ($r['delete_invoice_status'] !== '') ? $r['delete_invoice_status'] : null,
            ),
        );

        // --- 5. Wenn nicht gezahlt (Mahnung) ---------------------------
        // Only when the reminder feature is EFFECTIVELY enabled. While parked
        // ([HOLD 1.11]) Layer B passes reminder=false and this step is omitted —
        // P0 must not claim a reminder the code never sends.
        if (!empty($r['reminder'])) {
            $steps[] = array(
                'id'     => 'reminder',
                'kind'   => 'sqrip',
                'status' => null,
                'flags'  => array(
                    'days'      => (int) $r['reminder_days'],
                    'fee_label' => (string) $r['reminder_fee_label'],
                ),
            );
        }

        // --- Honesty notes ---------------------------------------------
        // Partial-invoice flows (down payment / instalments — archetype D) need
        // the fraction and partial-status fields that P0 does not yet model.
        // Flag it rather than silently drawing an incomplete flow.
        if (!empty($r['multiple_slips'])) {
            $notes['partial'] = 'partial_flow_not_full';
        }

        return array('steps' => $steps, 'notes' => $notes);
    }

    /* ===================================================================
     * Layer B — WordPress-bound resolution + render
     * =================================================================== */

    /**
     * Build the resolved tuple Layer A consumes.
     *
     * Fills field defaults (sqrip_get_plugin_option() returns null for
     * never-saved keys — see inc/functions.php:8) and computes the *effective*
     * feature switches through the canonical is_enabled() methods, which honour
     * the [HOLD 1.11] parks in Skonto/Reminder. Reads only; no side effects.
     *
     * @return array Resolved tuple for derive_steps().
     */
    public static function build_resolved()
    {
        $opt = function ($key, $default = '') {
            $v = sqrip_get_plugin_option($key);
            if ($v === null || $v === '') {
                $v = self::field_default($key, $default);
            }
            return $v;
        };

        $status_suppressed = (string) sqrip_get_plugin_option('status_suppressed');
        if ($status_suppressed === self::STATUS_PLACEHOLDER) {
            $status_suppressed = '';
        }

        return array(
            'suppress'            => sqrip_get_plugin_option('suppress_generation') === 'yes',
            'multiple_slips'      => self::feature_enabled('multiple_qr_slips'),
            'number_of_invoices'  => (int) $opt('number_of_invoices', 1),
            'skonto'              => self::feature_enabled('skonto'),
            'skonto_percentage'   => (float) sqrip_get_plugin_option('skonto_percentage'),
            'status_suppressed'   => $status_suppressed,
            'qr_order_status'     => (string) $opt('qr_order_status'),
            'email_attached'      => self::normalize_email_attached(sqrip_get_plugin_option('email_attached')),
            'integration_order'   => $opt('integration_order', 'yes') === 'yes',
            'send_status_emails'  => sqrip_get_plugin_option('qr_order_status_send_emails') === 'yes',
            'payment_comparison'  => sqrip_get_plugin_option('payment_comparison_enabled') === 'yes',
            'camt'                => self::feature_enabled('camt'),
            'avis'                => self::feature_enabled('avis'),
            'status_awaiting'     => (string) $opt('status_awaiting'),
            'status_completed'    => (string) $opt('status_completed'),
            'delete_invoice_status' => (string) sqrip_get_plugin_option('delete_invoice_status'),
            'reminder'            => self::feature_enabled('reminder'),
            'reminder_days'       => (int) sqrip_get_plugin_option('reminder_days_after_due'),
            'reminder_fee_label'  => (string) sqrip_get_plugin_option('reminder_fee_label'),
        );
    }

    /**
     * Effective feature state via the canonical is_enabled() method.
     *
     * Using the real gate (not the raw option) means parked features
     * ([HOLD 1.11] in Skonto/Reminder) correctly report as off.
     *
     * @param string $feature One of: multiple_qr_slips, skonto, reminder, camt, avis.
     * @return bool
     */
    protected static function feature_enabled($feature)
    {
        switch ($feature) {
            case 'skonto':
                return class_exists('Sqrip_Skonto') && Sqrip_Skonto::is_enabled();
            case 'reminder':
                return class_exists('Sqrip_Reminder') && Sqrip_Reminder::is_enabled();
            case 'camt':
                return class_exists('Sqrip_Camt_Admin') && Sqrip_Camt_Admin::is_enabled();
            case 'avis':
                return class_exists('Sqrip_Avis') && Sqrip_Avis::is_enabled();
            case 'multiple_qr_slips':
                // No dedicated is_enabled(); the raw switch is the gate.
                return sqrip_get_plugin_option('multiple_qr_slips_enabled') === 'yes';
        }
        return false;
    }

    /**
     * Normalize the email_attached option to a plain list of e-mail ids.
     * The option is a multiselect (array), but legacy saves may hold a string.
     *
     * @param mixed $value
     * @return array
     */
    protected static function normalize_email_attached($value)
    {
        if (is_array($value)) {
            return array_values(array_filter($value, function ($v) {
                return $v !== '' && $v !== null;
            }));
        }
        if ($value === '' || $value === null) {
            return array();
        }
        return array($value);
    }

    /**
     * Human title for a WooCommerce e-mail id (e.g. 'customer_on_hold_order'
     * -> "Order on-hold"). Falls back to the id when WooCommerce is not loaded
     * or the id is unknown.
     *
     * @param string $email_id
     * @return string
     */
    protected static function email_title($email_id)
    {
        if (function_exists('sqrip_get_wc_emails')) {
            $emails = sqrip_get_wc_emails();
            if (isset($emails[$email_id])) {
                return $emails[$email_id];
            }
        }
        return $email_id;
    }

    /**
     * Read a field default off the already-registered gateway singleton.
     *
     * Deliberately not `new WC_Sqrip_Payment_Gateway()`: the constructor
     * re-registers the save hook (class-wc-sqrip-payment-gateway.php:96) and
     * runs the ~900-line init_form_fields on every instantiation. The
     * registered instance has done that once already, without side effects.
     *
     * @param string $key      Option key.
     * @param mixed  $fallback Used when the field has no 'default'.
     * @return mixed
     */
    protected static function field_default($key, $fallback = '')
    {
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['sqrip']) && !empty($gateways['sqrip']->form_fields[$key]['default'])) {
                return $gateways['sqrip']->form_fields[$key]['default'];
            }
        }
        return $fallback;
    }

    /**
     * Render the read-only overview as an HTML string for the settings tab.
     *
     * @return string
     */
    public static function render()
    {
        $resolved = self::build_resolved();
        $derived  = self::derive_steps($resolved);

        ob_start();
        ?>
        <tr valign="top" class="sqrip-section">
            <th scope="row" class="titledesc services-tab" colspan="2">
                <h3><?php echo esc_html__('So sieht Ihr Prozess heute aus', 'sqrip-swiss-qr-invoice'); ?></h3>
                <p class="description">
                    <?php echo esc_html__('Aus Ihren aktuellen Einstellungen abgeleitet. Reine Übersicht — hier lässt sich nichts ändern.', 'sqrip-swiss-qr-invoice'); ?>
                </p>
            </th>
        </tr>
        <tr valign="top">
            <td colspan="2" class="forminp">
                <?php echo self::render_steps_html($derived['steps'], $derived['notes'], $resolved); // already escaped ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Turn derived steps into localized, escaped HTML.
     *
     * @param array $steps Steps from derive_steps().
     * @param array $notes Notes from derive_steps().
     * @param array $r     Resolved tuple (for status-label lookups).
     * @return string
     */
    protected static function render_steps_html(array $steps, array $notes, array $r)
    {
        ob_start();
        echo '<table class="widefat striped" style="max-width:900px">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Auslöser', 'sqrip-swiss-qr-invoice') . '</th>';
        echo '<th>' . esc_html__('Was sqrip tut', 'sqrip-swiss-qr-invoice') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($steps as $step) {
            list($trigger, $action) = self::phrase_step($step);
            echo '<tr>';
            echo '<td>' . wp_kses_post($trigger) . '</td>';
            echo '<td>' . wp_kses_post($action) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if (!empty($notes['partial'])) {
            echo '<p class="description" style="margin-top:8px">';
            echo esc_html__('Hinweis: Abläufe mit Teilrechnungen (z. B. Anzahlung) werden in dieser Übersicht noch nicht vollständig dargestellt.', 'sqrip-swiss-qr-invoice');
            echo '</p>';
        }

        return ob_get_clean();
    }

    /**
     * Localized [Auslöser, Aktion] phrasing for one step.
     *
     * @param array $step
     * @return array [string $trigger_html, string $action_html]
     */
    protected static function phrase_step(array $step)
    {
        $status = ($step['status'] !== null) ? self::status_label($step['status']) : '';
        $f = $step['flags'];

        switch ($step['id']) {
            case 'on_order':
                $trigger = esc_html__('Bestellung eingegangen', 'sqrip-swiss-qr-invoice');
                if (empty($f['creates_invoice'])) {
                    $action = esc_html__('Keine QR-Rechnung.', 'sqrip-swiss-qr-invoice');
                } elseif ($f['invoice_shape'] === 'multiple') {
                    $action = sprintf(
                        /* translators: %d = number of partial invoices */
                        esc_html__('QR-Rechnung als %d Teilrechnungen.', 'sqrip-swiss-qr-invoice'),
                        (int) $f['number_of_invoices']
                    );
                } else {
                    $action = esc_html__('QR-Rechnung wird erzeugt.', 'sqrip-swiss-qr-invoice');
                }
                if (!empty($f['skonto'])) {
                    $action .= ' ' . sprintf(
                        /* translators: %s = discount percentage */
                        esc_html__('Zusätzlich Skonto-Rechnung (%s%%).', 'sqrip-swiss-qr-invoice'),
                        esc_html(rtrim(rtrim(number_format((float) $f['skonto_percentage'], 2), '0'), '.'))
                    );
                }
                if ($status !== '') {
                    $action .= ' ' . self::status_sentence($status);
                }
                return array($trigger, $action);

            case 'invoice_delivery':
                $trigger = esc_html__('So erreicht die Rechnung den Kunden', 'sqrip-swiss-qr-invoice');
                $lines = array();

                if (!empty($f['download_on_confirmation'])) {
                    $lines[] = esc_html__('Sofort: als Download auf der Bestellbestätigungsseite.', 'sqrip-swiss-qr-invoice');
                }

                foreach ((array) $f['emails'] as $email) {
                    $title = esc_html(self::email_title($email['id']));
                    $when  = self::trigger_phrase($email['trigger']);
                    if ($email['role'] === 'admin') {
                        $lines[] = sprintf(
                            /* translators: 1: e-mail title, 2: when it is sent */
                            esc_html__('Als PDF an der Admin-E-Mail „%1$s" — %2$s.', 'sqrip-swiss-qr-invoice'),
                            $title, $when
                        );
                    } else {
                        $lines[] = sprintf(
                            /* translators: 1: e-mail title, 2: when it is sent */
                            esc_html__('Als PDF an der Kunden-E-Mail „%1$s" — %2$s.', 'sqrip-swiss-qr-invoice'),
                            $title, $when
                        );
                    }
                }

                if (!empty($f['force_checkout_emails'])) {
                    if (!empty($f['force_carries_invoice'])) {
                        $lines[] = esc_html__('Direkt beim Checkout: die Kundenmail „Bestellung wartet" geht sofort raus — mit der QR-Rechnung im Anhang. (Zusätzlich die Admin-Mail „Neue Bestellung".)', 'sqrip-swiss-qr-invoice');
                    } else {
                        $lines[] = esc_html__('Direkt beim Checkout: Bestätigung an den Kunden („Bestellung wartet") und an den Shop-Admin („Neue Bestellung"). Diese tragen die QR-Rechnung nur, wenn Sie „Bestellung wartet" oben als Anhang-E-Mail wählen.', 'sqrip-swiss-qr-invoice');
                    }
                }

                if (empty($lines)) {
                    $action = esc_html__('Kein automatischer Versand konfiguriert — der Kunde erhält die Rechnung nur, wenn Sie sie von Hand senden.', 'sqrip-swiss-qr-invoice');
                } else {
                    $action = '<ul style="margin:0 0 0 1em;list-style:disc">';
                    foreach ($lines as $line) {
                        $action .= '<li>' . $line . '</li>';
                    }
                    $action .= '</ul>';
                    if (!empty($f['emails'])) {
                        $action .= '<p class="description" style="margin:6px 0 0">'
                            . esc_html__('Jede E-Mail wird nur versendet, wenn sie in WooCommerce aktiviert ist.', 'sqrip-swiss-qr-invoice')
                            . '</p>';
                    }
                }
                return array($trigger, $action);

            case 'payment_detection':
                $trigger = esc_html__('Zahlung erkannt', 'sqrip-swiss-qr-invoice');
                $method_text = self::method_label($f['method']);
                $action = $method_text;
                if ($status !== '') {
                    $action .= ' ' . sprintf(
                        /* translators: %s = order status label */
                        esc_html__('Bis dahin wartet die Bestellung im Status „%s".', 'sqrip-swiss-qr-invoice'),
                        esc_html($status)
                    );
                }
                return array($trigger, $action);

            case 'after_payment':
                $trigger = esc_html__('Nach erkannter Zahlung', 'sqrip-swiss-qr-invoice');
                $action = ($status !== '')
                    ? self::status_sentence($status)
                    : esc_html__('Kein Folgestatus gesetzt.', 'sqrip-swiss-qr-invoice');
                if (!empty($f['devalue_status'])) {
                    $action .= ' ' . sprintf(
                        /* translators: %s = order status label */
                        esc_html__('Beim Status „%s" wird die Rechnung entwertet.', 'sqrip-swiss-qr-invoice'),
                        esc_html(self::status_label($f['devalue_status']))
                    );
                }
                return array($trigger, $action);

            case 'reminder':
                $trigger = sprintf(
                    /* translators: %d = days after due date */
                    esc_html__('%d Tage nach Fälligkeit, wenn offen', 'sqrip-swiss-qr-invoice'),
                    (int) $f['days']
                );
                $label = ($f['fee_label'] !== '') ? $f['fee_label'] : esc_html__('Mahngebühr', 'sqrip-swiss-qr-invoice');
                $action = sprintf(
                    /* translators: %s = fee label */
                    esc_html__('Mahnung mit Gebühr („%s") und neuer QR-Rechnung.', 'sqrip-swiss-qr-invoice'),
                    esc_html($label)
                );
                return array($trigger, $action);
        }

        return array('', '');
    }

    /**
     * "Status wird auf „X" gesetzt." sentence.
     */
    protected static function status_sentence($status_label)
    {
        return sprintf(
            /* translators: %s = order status label */
            esc_html__('Status wird auf „%s" gesetzt.', 'sqrip-swiss-qr-invoice'),
            esc_html($status_label)
        );
    }

    /**
     * Translate an EMAIL_TRIGGERS 'trigger' code into a "when" phrase.
     *
     * @param string $trigger e.g. 'status:on-hold', 'manual', 'on_new_order'.
     * @return string Localized, escaped.
     */
    protected static function trigger_phrase($trigger)
    {
        if (strpos($trigger, 'status:') === 0) {
            $slug = substr($trigger, strlen('status:'));
            if (strpos($slug, 'wc-') !== 0) {
                $slug = 'wc-' . $slug;
            }
            return sprintf(
                /* translators: %s = order status label */
                esc_html__('sobald die Bestellung auf „%s" wechselt', 'sqrip-swiss-qr-invoice'),
                esc_html(self::status_label($slug))
            );
        }
        switch ($trigger) {
            case 'on_new_order':
                return esc_html__('sobald eine neue Bestellung eingeht', 'sqrip-swiss-qr-invoice');
            case 'on_refund':
                return esc_html__('bei einer Rückerstattung', 'sqrip-swiss-qr-invoice');
            case 'on_note':
                return esc_html__('wenn Sie dem Kunden eine Notiz senden', 'sqrip-swiss-qr-invoice');
            case 'manual':
                return esc_html__('nur wenn Sie im Bestellmenü „Rechnung / Bestelldetails an Kunden senden" auslösen', 'sqrip-swiss-qr-invoice');
            case 'unknown':
            default:
                return esc_html__('gemäss den Auslösern dieser E-Mail in WooCommerce', 'sqrip-swiss-qr-invoice');
        }
    }

    /**
     * Human label for a payment-detection method.
     */
    protected static function method_label($method)
    {
        switch ($method) {
            case 'camt+avis':
                return esc_html__('über den camt-Abgleich und die Avis-Erkennung.', 'sqrip-swiss-qr-invoice');
            case 'camt':
                return esc_html__('über den camt-Abgleich (Bankdatei-Upload).', 'sqrip-swiss-qr-invoice');
            case 'avis':
                return esc_html__('über die automatische Avis-Erkennung.', 'sqrip-swiss-qr-invoice');
            case 'manual':
                return esc_html__('von Hand im Zahlungsvergleich bestätigt.', 'sqrip-swiss-qr-invoice');
            case 'none':
            default:
                return esc_html__('von Hand — kein sqrip-Abgleich aktiv.', 'sqrip-swiss-qr-invoice');
        }
    }

    /**
     * Human-readable label for a WooCommerce order-status slug.
     *
     * @param string $slug e.g. 'wc-processing' or 'processing'.
     * @return string
     */
    protected static function status_label($slug)
    {
        if ($slug === '') {
            return '';
        }
        if (function_exists('wc_get_order_status_name')) {
            return wc_get_order_status_name($slug);
        }
        return $slug;
    }
}
