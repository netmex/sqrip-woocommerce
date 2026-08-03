<?php

/**
 * Standalone tests for the GiroCode classes (no WordPress needed).
 *
 * Run:  php tests/girocode/run-girocode-tests.php
 */

define('ABSPATH', __DIR__ . '/'); // satisfy the `defined('ABSPATH') || exit;` guards

$root = dirname(__DIR__, 2);

if (file_exists($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}
require $root . '/inc/class-sqrip-sepa-iban.php';
require $root . '/inc/class-sqrip-girocode.php';

$ok = true;
function check($label, $cond)
{
    global $ok;
    $ok = $ok && $cond;
    printf("[%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
}

// ── RF reference (ISO 11649) ────────────────────────────────────────
check('RF known vector 539007547034 -> RF18539007547034',
      Sqrip_GiroCode::rf_reference('539007547034') === 'RF18539007547034');

foreach (array('1042', 'WC-20260803-778', 'A1') as $b) {
    check("RF valid for base '$b' (" . Sqrip_GiroCode::rf_reference($b) . ')',
          Sqrip_GiroCode::rf_is_valid(Sqrip_GiroCode::rf_reference($b)));
}

// ── EPC payload ─────────────────────────────────────────────────────
$rf      = Sqrip_GiroCode::rf_reference('1042');
$payload = Sqrip_GiroCode::payload(array(
    'name'      => 'Muster Verein e.V.',
    'iban'      => 'DE72500105170648489890',
    'amount'    => 12.5,
    'reference' => $rf,
));
check('payload starts BCD/002/1/SCT', strpos($payload, "BCD\n002\n1\nSCT\n") === 0);
check('payload has EUR12.50', strpos($payload, 'EUR12.50') !== false);
check('payload carries the RF reference', strpos($payload, $rf) !== false);

// ── SEPA IBAN validation ────────────────────────────────────────────
foreach (array('DE89370400440532013000', 'AT611904300234573201', 'NL91ABNA0417164300') as $v) {
    check("IBAN accepted: $v", Sqrip_Sepa_Iban::validate($v)['ok'] === true);
}
check('bad checksum rejected', Sqrip_Sepa_Iban::validate('DE89370400440532013001')['ok'] === false);
check('non-SEPA rejected', Sqrip_Sepa_Iban::validate('US64SVBKUS6S3300958879')['reason'] === 'non_sepa_country');
check('CH QR-IBAN routed to Swiss path',
      Sqrip_Sepa_Iban::validate('CH4431999123000889012')['reason'] === 'qr_iban_use_swiss_path');
check('EUR guard: CHF rejected', Sqrip_Sepa_Iban::currency_is_eur('CHF') === false);

// ── Rendering via chillerlan ────────────────────────────────────────
$svg = Sqrip_GiroCode::render_svg($payload);
check('SVG rendered (non-empty <svg>)', is_string($svg) && strpos($svg, '<svg') !== false);
file_put_contents(__DIR__ . '/girocode-plugin.svg', $svg);

if (extension_loaded('gd')) {
    $png = Sqrip_GiroCode::render_png($payload);
    $is_png = is_string($png) && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n";
    check('PNG rendered (valid PNG signature)', $is_png);
    file_put_contents(__DIR__ . '/girocode-plugin.png', $png);
} else {
    echo "[SKIP] PNG (GD extension not loaded)\n";
}

// ── High-level generate() ───────────────────────────────────────────
$g = Sqrip_GiroCode::generate(array(
    'name'           => 'Muster Verein e.V.',
    'iban'           => 'DE72500105170648489890',
    'amount'         => 12.5,
    'reference_base' => '1042',
));
check('generate() returns valid RF + svg',
      Sqrip_GiroCode::rf_is_valid($g['reference']) && strpos($g['svg'], '<svg') !== false);

echo "\n" . ($ok ? "ALL GREEN\n" : "!!! FAILURES !!!\n");
exit($ok ? 0 : 1);
