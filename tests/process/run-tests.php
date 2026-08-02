<?php
/**
 * Test bench for Sqrip_Process_Overview::derive_steps() — the pure Layer A of
 * the Prozesskonfigurator P0.
 *
 * Plain PHP, no WordPress and no test framework:
 *
 *     php tests/process/run-tests.php
 *
 * Only the pure derivation is exercised here. The WordPress-bound Layer B
 * (default resolution + effective is_enabled() feature switches, which honour
 * the [HOLD 1.11] parks) is not unit-tested — its inputs arrive here as a
 * ready-made tuple, exactly as build_resolved() would hand them over.
 *
 * @package sqrip
 * @since 1.12
 */

define('ABSPATH', __DIR__);

require dirname(__DIR__, 2) . '/inc/class-sqrip-process-overview.php';

$passed = 0;
$failed = 0;

function check($label, $got, $expected)
{
    global $passed, $failed;

    $ok = ($got === $expected);
    $ok ? $passed++ : $failed++;

    echo ($ok ? "  OK   " : "  FAIL ") . $label . "\n";

    if (!$ok) {
        echo "         expected: " . var_export($expected, true) . "\n";
        echo "         got:      " . var_export($got, true) . "\n";
    }
}

/** A fully-resolved tuple with everything off — the neutral baseline. */
function base_tuple()
{
    return array(
        'suppress'              => false,
        'multiple_slips'        => false,
        'number_of_invoices'    => 1,
        'skonto'                => false,
        'skonto_percentage'     => 0.0,
        'status_suppressed'     => '',
        'qr_order_status'       => 'wc-on-hold',
        'email_attached'        => array(),
        'email_enabled'         => array(),
        'integration_order'     => true,
        'send_status_emails'    => false,
        'payment_comparison'    => false,
        'camt'                  => false,
        'avis'                  => false,
        'status_awaiting'       => 'wc-on-hold',
        'status_completed'      => 'wc-completed',
        'delete_invoice_status' => '',
        'reminder'              => false,
        'reminder_days'         => 0,
        'reminder_fee_label'    => '',
    );
}

/** Return the ordered list of step ids. */
function step_ids(array $derived)
{
    return array_map(function ($s) { return $s['id']; }, $derived['steps']);
}

/** Find one step by id (or null). */
function step(array $derived, $id)
{
    foreach ($derived['steps'] as $s) {
        if ($s['id'] === $id) {
            return $s;
        }
    }
    return null;
}

echo "\n--- Ablauf A — Rechnung zuerst ---\n";
// Rechnung sofort, Zahlung von Hand bestätigt, Standard-Folgestatus.
$a = base_tuple();
$a['payment_comparison'] = true;             // manuell bestätigt
$a['email_attached']     = array('customer_on_hold_order');
$da = Sqrip_Process_Overview::derive_steps($a);
check('A: Schrittfolge', step_ids($da), array('on_order', 'invoice_delivery', 'payment_detection', 'after_payment'));
check('A: erzeugt Rechnung', step($da, 'on_order')['flags']['creates_invoice'], true);
check('A: Status nach Erzeugung', step($da, 'on_order')['status'], 'wc-on-hold');
check('A: Rechnungsart einzeln', step($da, 'on_order')['flags']['invoice_shape'], 'single');
check('A: Download-Kanal an', step($da, 'invoice_delivery')['flags']['download_on_confirmation'], true);
check('A: E-Mail geht BEIM BESTELLEINGANG raus (Status = on-hold gesetzt)', step($da, 'invoice_delivery')['flags']['emails'][0], array('id' => 'customer_on_hold_order', 'role' => 'customer', 'moment' => 'on_order', 'enabled' => true));
check('A: Zahlung manuell', step($da, 'payment_detection')['flags']['method'], 'manual');
check('A: Wartestatus', step($da, 'payment_detection')['status'], 'wc-on-hold');
check('A: Endstatus', step($da, 'after_payment')['status'], 'wc-completed');
check('A: keine Teilrechnungs-Notiz', isset($da['notes']['partial']), false);

echo "\n--- Ablauf B — Prüfen, anpassen, dann Rechnung ---\n";
// Keine Rechnung bei Eingang; expliziter Prüfstatus.
$b = base_tuple();
$b['suppress']          = true;
$b['status_suppressed'] = 'wc-pending';
$b['payment_comparison'] = true;
$db = Sqrip_Process_Overview::derive_steps($b);
check('B: kein Versand-Schritt (keine Rechnung)', step_ids($db), array('on_order', 'payment_detection', 'after_payment'));
check('B: erzeugt keine Rechnung', step($db, 'on_order')['flags']['creates_invoice'], false);
check('B: Prüfstatus', step($db, 'on_order')['status'], 'wc-pending');

echo "\n--- Ablauf B' — Unterdrückung ohne gesetzten Prüfstatus ---\n";
// Der echte Code fällt hier auf qr_order_status zurück (thank-you hook).
$b2 = base_tuple();
$b2['suppress']          = true;
$b2['status_suppressed'] = '';           // Platzhalter/leer -> Fallback
$b2['qr_order_status']   = 'wc-processing';
$db2 = Sqrip_Process_Overview::derive_steps($b2);
check("B': Fallback auf qr_order_status", step($db2, 'on_order')['status'], 'wc-processing');

echo "\n--- Ablauf C — Ware zuerst, mit Mahnung ---\n";
// Rechnung erzeugt, camt-Abgleich, Mahnung nach 10 Tagen (Layer A traut dem
// effektiven Boolean; der [HOLD 1.11]-Park ist Layer Bs Aufgabe).
$c = base_tuple();
$c['payment_comparison'] = true;
$c['camt']               = true;
$c['email_attached']     = array('customer_completed_order');
$c['reminder']           = true;
$c['reminder_days']      = 10;
$c['reminder_fee_label'] = 'Mahngebühr';
$dc = Sqrip_Process_Overview::derive_steps($c);
check('C: Schrittfolge mit Mahnung', step_ids($dc), array('on_order', 'invoice_delivery', 'payment_detection', 'after_payment', 'reminder'));
check('C: Zahlung via camt', step($dc, 'payment_detection')['flags']['method'], 'camt');
check('C: Rechnung geht NACH ZAHLUNG raus (completed-Mail)', step($dc, 'invoice_delivery')['flags']['emails'][0]['moment'], 'after_payment');
check('C: Mahnfrist', step($dc, 'reminder')['flags']['days'], 10);

echo "\n--- Geparkte Features: reminder=false blendet den Schritt aus ---\n";
$park = base_tuple();
$park['reminder'] = false;               // wie Layer B es bei [HOLD 1.11] liefert
$dpark = Sqrip_Process_Overview::derive_steps($park);
check('Park: kein Mahn-Schritt', step($dpark, 'reminder'), null);

echo "\n--- Zahlungs-Methodenbaum (camt/avis unter payment_comparison) ---\n";
$m = base_tuple();
$m['payment_comparison'] = true;
$m['camt'] = true; $m['avis'] = true;
check('camt+avis', step(Sqrip_Process_Overview::derive_steps($m), 'payment_detection')['flags']['method'], 'camt+avis');
$m['avis'] = false;
check('nur camt', step(Sqrip_Process_Overview::derive_steps($m), 'payment_detection')['flags']['method'], 'camt');
$m['camt'] = false; $m['avis'] = true;
check('nur avis', step(Sqrip_Process_Overview::derive_steps($m), 'payment_detection')['flags']['method'], 'avis');
$m['avis'] = false;
check('nur manuell', step(Sqrip_Process_Overview::derive_steps($m), 'payment_detection')['flags']['method'], 'manual');
$m['payment_comparison'] = false;
check('kein Abgleich', step(Sqrip_Process_Overview::derive_steps($m), 'payment_detection')['flags']['method'], 'none');

echo "\n--- E-Mail-Timing: WANN welche Rechnung rausgeht ---\n";
$e = base_tuple();
// on_order-Status = wc-on-hold, completed = wc-completed. Ein Auslöser-Status,
// den der Ablauf NICHT setzt (processing), bleibt als Status-Moment stehen.
$e['email_attached'] = array('customer_processing_order', 'customer_invoice', 'new_order', 'some_custom_plugin_email');
$de = step(Sqrip_Process_Overview::derive_steps($e), 'invoice_delivery');
check('processing: Status, den der Ablauf nicht setzt', $de['flags']['emails'][0], array('id' => 'customer_processing_order', 'role' => 'customer', 'moment' => 'status:wc-processing', 'enabled' => true));
check('customer_invoice → manuell', $de['flags']['emails'][1]['moment'], 'manual');
check('new_order → Admin, beim Bestelleingang', $de['flags']['emails'][2], array('id' => 'new_order', 'role' => 'admin', 'moment' => 'on_order', 'enabled' => true));
check('unbekannte E-Mail → unknown', $de['flags']['emails'][3]['moment'], 'unknown');

// Echter enabled-Status aus WooCommerce: deaktivierte Mail wird als Fakt gemeldet.
$edis = base_tuple();
$edis['email_attached'] = array('customer_on_hold_order');
$edis['email_enabled']  = array('customer_on_hold_order' => false);
$ddis = step(Sqrip_Process_Overview::derive_steps($edis), 'invoice_delivery');
check('deaktivierte Mail → enabled=false (Fakt, keine Kondition)', $ddis['flags']['emails'][0]['enabled'], false);

$e2 = base_tuple();
$e2['integration_order'] = false;     // Download-Kanal aus
$e2['email_attached']    = array();   // keine E-Mail gewählt
$e2['send_status_emails'] = false;
$de2 = step(Sqrip_Process_Overview::derive_steps($e2), 'invoice_delivery');
check('kein Kanal: leere E-Mail-Liste', $de2['flags']['emails'], array());
check('kein Kanal: Download aus', $de2['flags']['download_on_confirmation'], false);

$e3 = base_tuple();
$e3['send_status_emails'] = true;     // Checkout-Zwang, aber On-Hold-Mail nicht als Anhang
$de3 = step(Sqrip_Process_Overview::derive_steps($e3), 'invoice_delivery');
check('Checkout-Zwang erkannt', $de3['flags']['force_checkout_emails'], true);
check('Zwang trägt Rechnung NICHT (On-Hold nicht gewählt)', $de3['flags']['force_carries_invoice'], false);

$e4 = base_tuple();
$e4['send_status_emails'] = true;
$e4['email_attached'] = array('customer_on_hold_order');  // On-Hold als Anhang -> Rechnung reitet mit
$de4 = step(Sqrip_Process_Overview::derive_steps($e4), 'invoice_delivery');
check('Zwang trägt Rechnung (On-Hold gewählt)', $de4['flags']['force_carries_invoice'], true);

echo "\n--- Ablauf D — Teilrechnungen: ehrlich zurückgestellt ---\n";
// P0 stellt Anzahlungs-/Ratenabläufe noch nicht vollständig dar. Statt einen
// unvollständigen Ablauf zu behaupten, setzt die Engine eine Ehrlichkeits-Notiz.
$d = base_tuple();
$d['multiple_slips']     = true;
$d['number_of_invoices'] = 2;
$dd = Sqrip_Process_Overview::derive_steps($d);
check('D: Rechnungsart mehrfach', step($dd, 'on_order')['flags']['invoice_shape'], 'multiple');
check('D: Anzahl Teilrechnungen', step($dd, 'on_order')['flags']['number_of_invoices'], 2);
check('D: Ehrlichkeits-Notiz gesetzt', $dd['notes']['partial'], 'partial_flow_not_full');

echo "\n--- Frische Installation (nur Defaults, alles aus) ---\n";
// Muss einen sauberen Standard-Ablauf ergeben, nie leer/null-Kette.
$fresh = base_tuple();
$dfresh = Sqrip_Process_Overview::derive_steps($fresh);
check('Fresh: Schrittfolge', step_ids($dfresh), array('on_order', 'invoice_delivery', 'payment_detection', 'after_payment'));
check('Fresh: Endstatus vorhanden', step($dfresh, 'after_payment')['status'], 'wc-completed');
check('Fresh: kein Abgleich aktiv', step($dfresh, 'payment_detection')['flags']['method'], 'none');

echo "\n=====================================\n";
echo "  bestanden: $passed   fehlgeschlagen: $failed\n";
echo "=====================================\n";

exit($failed === 0 ? 0 : 1);
