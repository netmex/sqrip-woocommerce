<?php
/**
 * Test bench for Sqrip_Camt_Parser.
 *
 * Plain PHP, no WordPress and no test framework needed:
 *
 *     php tests/camt/run-tests.php
 *
 * Point it at a real bank file to check the parser against it. The file is only read,
 * never copied or written anywhere:
 *
 *     php tests/camt/run-tests.php /path/to/camt054.xml
 *
 * @package sqrip
 * @since 1.11
 */

define('ABSPATH', __DIR__);

require dirname(__DIR__, 2) . '/inc/class-sqrip-camt-parser.php';

$fixtures = __DIR__ . '/fixtures';
$scratch  = sys_get_temp_dir() . '/sqrip-camt-tests-' . getmypid();

@mkdir($scratch, 0700, true);

register_shutdown_function(function () use ($scratch) {
    foreach ((array) glob($scratch . '/*') as $file) {
        @unlink($file);
    }
    @rmdir($scratch);
});

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

/**
 * References of the open orders the shop is asking about.
 */
$open_orders = array(
    '21 00000 00003 13947 14300 09017',   // 149.50, paid
    'RF18539007547034',                   // 80.00
    '210000000003139471430009024',        // 200.00, inside a batch booking
    '210000000003139471430009031',        // 100.25, only an EndToEndId
    '210000000003139471430009048',        // appears as a debit only
    '210000000003139471430009055',        // appears as a reversal only
    '210000000003139471430009062',        // no payment at all
);

echo "\n=== Reference normalisation ===\n";

check('QR reference with spaces',
    Sqrip_Camt_Parser::normalize_reference('21 00000 00003 13947 14300 09017'),
    '210000000003139471430009017');
check('RF reference, lower case and spaced',
    Sqrip_Camt_Parser::normalize_reference('rf18 5390 0754 7034'),
    'RF18539007547034');
check('empty stays empty', Sqrip_Camt_Parser::normalize_reference(''), '');

echo "\n=== camt.054 with batch booking, debit, reversal, foreign credit ===\n";

$parser = new Sqrip_Camt_Parser();
$result = $parser->collect_matches($fixtures . '/camt054-mixed.xml', $open_orders);

if ($result === false) {
    echo "  FAIL file unreadable: " . var_export($parser->get_errors(), true) . "\n";
    $failed++;
} else {
    $matches = $result['matches'];

    check('number of references hit', count($matches), 4);
    check('foreign credits counted, not kept', $result['other_credits'], 1);

    check('QR payment found, amount',
        $matches['210000000003139471430009017'][0]['amount'], 149.50);
    check('QR payment value date',
        $matches['210000000003139471430009017'][0]['value_date'], '2026-07-24');
    check('duplicate payment: same reference twice',
        count($matches['210000000003139471430009017']), 2);

    check('RF reference found', $matches['RF18539007547034'][0]['amount'], 80.00);

    check('batch booking: single amount, not the total',
        $matches['210000000003139471430009024'][0]['amount'], 200.00);
    check('batch booking: payment via EndToEndId',
        $matches['210000000003139471430009031'][0]['amount'], 100.25);

    check('debit inside a batch ignored',
        isset($matches['210000000003139471430009048']), false);
    check('reversal ignored',
        isset($matches['210000000003139471430009055']), false);
    check('order without payment stays open',
        isset($matches['210000000003139471430009062']), false);

    $fields = array();

    foreach ($matches as $payments) {
        foreach ($payments as $payment) {
            $fields = array_merge($fields, array_keys($payment));
        }
    }

    check('these fields and no others are kept',
        array_values(array_unique($fields)),
        array('reference', 'amount', 'currency', 'value_date', 'booking_date'));
}

echo "\n=== camt.053 statement ===\n";

$parser = new Sqrip_Camt_Parser();
$result = $parser->collect_matches($fixtures . '/camt053-statement.xml', $open_orders);

if ($result === false) {
    echo "  FAIL file unreadable: " . var_export($parser->get_errors(), true) . "\n";
    $failed++;
} else {
    check('entry without TxDtls is read',
        $result['matches']['210000000003139471430009017'][0]['amount'], 149.50);
    check('value date also taken from DtTm',
        $result['matches']['210000000003139471430009017'][0]['value_date'], '2026-07-24');
    check('underpayment reported as a hit, amount checked later',
        $result['matches']['RF18539007547034'][0]['amount'], 70.00);
    check('NOTPROVIDED counts as foreign', $result['other_credits'], 1);
}

echo "\n=== Security: external entities (XXE) ===\n";

$secret = $scratch . '/secret.txt';
file_put_contents($secret, 'TOP-SECRET-STATEMENT');

$xxe = $scratch . '/xxe.xml';
file_put_contents($xxe, '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE Document [ <!ENTITY leak SYSTEM "file://' . $secret . '"> ]>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.08">
  <BkToCstmrDbtCdtNtfctn><Ntfctn><Ntry>
    <Amt Ccy="CHF">1.00</Amt>
    <CdtDbtInd>CRDT</CdtDbtInd>
    <ValDt><Dt>2026-07-24</Dt></ValDt>
    <NtryDtls><TxDtls>
      <Amt Ccy="CHF">1.00</Amt>
      <RmtInf><Strd><CdtrRefInf><Ref>&leak;</Ref></CdtrRefInf></Strd></RmtInf>
    </TxDtls></NtryDtls>
  </Ntry></Ntfctn></BkToCstmrDbtCdtNtfctn>
</Document>');

// Control: prove the probe would actually leak through an unhardened parser,
// otherwise the check below would pass for the wrong reason.
libxml_use_internal_errors(true);
$control = new DOMDocument();
$control->loadXML(file_get_contents($xxe), LIBXML_NOENT);
$control_xpath = new DOMXPath($control);
$control_nodes = $control_xpath->query('//*[local-name()="Ref"]');
$control_value = $control_nodes->length ? trim($control_nodes->item(0)->textContent) : '';
libxml_clear_errors();

check('control: an unhardened parser does leak the file',
    strpos($control_value, 'TOP-SECRET') !== false, true);

$parser = new Sqrip_Camt_Parser();
$result = $parser->collect_matches($xxe, array('TOP-SECRET-STATEMENT', 'x'));
$leaked = is_array($result) && strpos(var_export($result, true), 'TOP-SECRET') !== false;

check('the parser does NOT read the external file', $leaked, false);

echo "\n=== Error handling ===\n";

$parser = new Sqrip_Camt_Parser();
check('missing file', $parser->collect_matches($fixtures . '/nope.xml', $open_orders), false);
$errors = $parser->get_errors();
check('  error code', $errors[0]['code'], 'unreadable');

$truncated = $scratch . '/truncated.xml';
file_put_contents($truncated, '<Document><Ntry><Amt>1.00</Amt>');
$parser = new Sqrip_Camt_Parser();
check('truncated XML', $parser->collect_matches($truncated, $open_orders), false);
$errors = $parser->get_errors();
check('  error code', $errors[0]['code'], 'malformed_xml');

$no_entries = $scratch . '/empty.xml';
file_put_contents($no_entries, '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.08"></Document>');
$parser = new Sqrip_Camt_Parser();
check('XML without bookings', $parser->collect_matches($no_entries, $open_orders), false);
$errors = $parser->get_errors();
check('  error code', $errors[0]['code'], 'no_entries');

/**
 * Optional: read a real bank file handed in on the command line.
 *
 * Prints structure only — how many credits, how many references could be read — and
 * never the references themselves, so a real statement can be checked without its
 * contents ending up on screen or in a shell history.
 */
if (isset($argv[1])) {
    echo "\n=== Real file: " . basename($argv[1]) . " ===\n";

    $parser = new Sqrip_Camt_Parser();
    $probe  = $parser->collect_matches($argv[1], array());

    if ($probe === false) {
        echo "  not readable: " . var_export($parser->get_errors(), true) . "\n";
        $failed++;
    } else {
        echo "  credits found:          " . $probe['credits_total'] . "\n";
        echo "  none of them matched:   " . $probe['other_credits'] . "\n";
        echo "  (matching needs the references of your open orders)\n";

        check('every credit was reachable',
            $probe['credits_total'] === $probe['other_credits'], true);
        check('at least one credit found', $probe['credits_total'] > 0, true);
    }
}

echo "\n----------------------------------------\n";
echo "  $passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);
