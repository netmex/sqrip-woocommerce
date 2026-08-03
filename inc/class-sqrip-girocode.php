<?php

/**
 * GiroCode (EPC-QR) generator — plugin-local, no sqrip API involved.
 *
 * A GiroCode is a commodity: the payload is a fixed BCD text block and the reference is
 * an ISO 11649 creditor reference, both computed here in plain PHP. Only the final QR
 * rendering uses a bundled library (chillerlan/php-qrcode), SVG by default so no GD
 * extension is required on the shop's server.
 *
 * The reference is written to the same order meta as the Swiss QR reference
 * (sqrip_reference_id), so the existing camt reconciler matches SEPA payments unchanged.
 *
 * @package sqrip
 * @since 1.13
 */

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

defined('ABSPATH') || exit;

class Sqrip_GiroCode
{
    /** EPC version 002 lets the BIC be omitted (SEPA-internal). */
    const VERSION = '002';

    /**
     * A valid RF creditor reference (ISO 11649) from a base string, e.g. an order number.
     *
     * @param string $base
     * @return string
     */
    public static function rf_reference($base)
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $base));

        // Check digits: (base + "RF" + "00") converted to numbers, 98 - (mod 97).
        $rearranged = self::alnum_to_digits($base . 'RF' . '00');
        $check      = 98 - self::mod97($rearranged);

        return 'RF' . str_pad((string) $check, 2, '0', STR_PAD_LEFT) . $base;
    }

    /**
     * Whether an RF reference carries a valid check (rearranged mod 97 must be 1).
     *
     * @param string $rf
     * @return bool
     */
    public static function rf_is_valid($rf)
    {
        $rf = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $rf));

        if (strpos($rf, 'RF') !== 0) {
            return false;
        }

        return self::mod97(self::alnum_to_digits(substr($rf, 4) . substr($rf, 0, 4))) === 1;
    }

    /**
     * Build the EPC-069 payload string carried by the GiroCode.
     *
     * Exactly one of a structured reference (RF) or an unstructured message is used.
     *
     * @param array $args {
     *     @type string $name         Beneficiary, max 70.
     *     @type string $iban         Beneficiary IBAN (SEPA).
     *     @type float  $amount       Amount in EUR.
     *     @type string $reference    RF creditor reference (structured). Optional.
     *     @type string $unstructured Free-text remittance, max 140. Used only when there
     *                                is no structured reference.
     *     @type string $bic          Optional.
     *     @type string $purpose      Optional 4-letter purpose code.
     * }
     * @return string
     */
    public static function payload(array $args)
    {
        $name = isset($args['name']) ? trim(preg_replace('/\s+/', ' ', (string) $args['name'])) : '';

        if (function_exists('mb_substr') && mb_strlen($name) > 70) {
            $name = mb_substr($name, 0, 70);
        }

        $iban   = strtoupper(preg_replace('/\s+/', '', isset($args['iban']) ? (string) $args['iban'] : ''));
        $amount = 'EUR' . number_format((float) (isset($args['amount']) ? $args['amount'] : 0), 2, '.', '');

        $reference    = isset($args['reference']) ? (string) $args['reference'] : '';
        $unstructured = isset($args['unstructured']) ? (string) $args['unstructured'] : '';

        // Structured reference wins; the two fields are mutually exclusive.
        if ($reference !== '') {
            $unstructured = '';
        } elseif (function_exists('mb_substr') && mb_strlen($unstructured) > 140) {
            $unstructured = mb_substr($unstructured, 0, 140);
        }

        $fields = array(
            'BCD',                                              // 1  service tag
            self::VERSION,                                      // 2  version
            '1',                                                // 3  UTF-8
            'SCT',                                              // 4  SEPA credit transfer
            isset($args['bic']) ? (string) $args['bic'] : '',   // 5  BIC (optional)
            $name,                                              // 6  beneficiary
            $iban,                                              // 7  IBAN
            $amount,                                            // 8  amount
            isset($args['purpose']) ? (string) $args['purpose'] : '', // 9 purpose
            $reference,                                         // 10 structured (RF) …
            $unstructured,                                      // 11 … or unstructured
            '',                                                 // 12 beneficiary-to-originator
        );

        // Trailing empty fields may be dropped.
        while (count($fields) > 0 && end($fields) === '') {
            array_pop($fields);
        }

        return implode("\n", $fields);
    }

    /**
     * Render a payload as an SVG string (no GD extension needed).
     *
     * @param string $payload
     * @return string
     */
    public static function render_svg($payload)
    {
        self::assert_library();

        return self::render($payload, QRCode::OUTPUT_MARKUP_SVG, false);
    }

    /**
     * Render a payload as raw PNG bytes. Requires the GD extension.
     *
     * @param string $payload
     * @return string
     */
    public static function render_png($payload)
    {
        self::assert_library();

        return self::render($payload, QRCode::OUTPUT_IMAGE_PNG, false);
    }

    /**
     * One-stop helper: reference + payload + SVG for an order.
     *
     * @param array $args As for payload(), but 'reference' is derived from 'reference_base'
     *                    when not given.
     * @return array { @type string $reference, @type string $payload, @type string $svg }
     */
    public static function generate(array $args)
    {
        if (empty($args['reference']) && !empty($args['reference_base'])) {
            $args['reference'] = self::rf_reference($args['reference_base']);
        }

        $payload = self::payload($args);

        return array(
            'reference' => isset($args['reference']) ? $args['reference'] : '',
            'payload'   => $payload,
            'svg'       => self::render_svg($payload),
        );
    }

    /**
     * @param string $payload
     * @param int    $output_type
     * @param bool   $base64
     * @return string
     */
    private static function render($payload, $output_type, $base64)
    {
        $options = new QROptions(array(
            'outputType'  => $output_type,
            'eccLevel'    => QRCode::ECC_M,
            'imageBase64' => $base64,
            'scale'       => 6,
        ));

        return (new QRCode($options))->render($payload);
    }

    /**
     * @throws RuntimeException when the bundled QR library is not autoloaded.
     * @return void
     */
    private static function assert_library()
    {
        if (!class_exists('chillerlan\QRCode\QRCode')) {
            throw new RuntimeException('sqrip: chillerlan/php-qrcode is not available — run "composer install" in the plugin directory.');
        }
    }

    /** Letters to numbers (A=10 … Z=35), digits kept. */
    private static function alnum_to_digits($s)
    {
        $out = '';
        $s   = strtoupper($s);

        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $c = $s[$i];

            if ($c >= '0' && $c <= '9') {
                $out .= $c;
            } elseif ($c >= 'A' && $c <= 'Z') {
                $out .= (string) (ord($c) - 55);
            }
        }

        return $out;
    }

    /** Mod 97 over a long digit string, iterative (no bcmath needed). */
    private static function mod97($digits)
    {
        $remainder = 0;

        for ($i = 0, $len = strlen($digits); $i < $len; $i++) {
            $remainder = ($remainder * 10 + (int) $digits[$i]) % 97;
        }

        return $remainder;
    }
}
