<?php

/**
 * SEPA-IBAN validation for the GiroCode (EPC-QR) path.
 *
 * Plugin-local, no server call and no sprain dependency: a GiroCode is generated
 * entirely inside the plugin, so the IBAN it points at is checked here too. The Swiss
 * QR path keeps its own IBAN handling via the sqrip API — this class only guards the
 * SEPA/EUR path.
 *
 * @package sqrip
 * @since 1.13
 */

defined('ABSPATH') || exit;

class Sqrip_Sepa_Iban
{
    /**
     * SEPA participant country => expected IBAN length.
     *
     * @var array<string,int>
     */
    const LENGTHS = array(
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28,
        'CZ' => 24, 'DE' => 22, 'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18,
        'FR' => 27, 'GB' => 22, 'GI' => 23, 'GR' => 27, 'HR' => 21, 'HU' => 28,
        'IE' => 22, 'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20,
        'LV' => 21, 'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28,
        'PT' => 25, 'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
        'VA' => 22,
    );

    /**
     * Strip spaces and upper-case, the way IBANs are compared everywhere.
     *
     * @param string $iban
     * @return string
     */
    public static function normalize($iban)
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $iban));
    }

    /**
     * ISO 7064 mod-97: move the first four characters to the end, turn letters into
     * numbers and check that the whole thing leaves remainder 1. Done iteratively so no
     * bcmath extension is required — the plugin still targets PHP 7.4 without it.
     *
     * @param string $iban
     * @return bool
     */
    public static function mod97_ok($iban)
    {
        $iban       = self::normalize($iban);
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $digits     = '';

        for ($i = 0, $len = strlen($rearranged); $i < $len; $i++) {
            $c = $rearranged[$i];

            if ($c >= '0' && $c <= '9') {
                $digits .= $c;
            } elseif ($c >= 'A' && $c <= 'Z') {
                $digits .= (string) (ord($c) - 55); // A=10 … Z=35
            } else {
                return false;
            }
        }

        $remainder = 0;

        for ($i = 0, $len = strlen($digits); $i < $len; $i++) {
            $remainder = ($remainder * 10 + (int) $digits[$i]) % 97;
        }

        return $remainder === 1;
    }

    /**
     * A Swiss/Liechtenstein QR-IBAN, identified by an IID (positions 5–9) in the reserved
     * range 30000–31999. It only carries a Swiss QR reference and belongs in the Swiss QR
     * path, so it is refused here rather than dressed up as a GiroCode.
     *
     * @param string $iban
     * @return bool
     */
    public static function is_swiss_qr_iban($iban)
    {
        $iban    = self::normalize($iban);
        $country = substr($iban, 0, 2);

        if ($country !== 'CH' && $country !== 'LI') {
            return false;
        }

        $iid = substr($iban, 4, 5);

        if (!ctype_digit($iid)) {
            return false;
        }

        $iid = (int) $iid;

        return $iid >= 30000 && $iid <= 31999;
    }

    /**
     * Full validation for the GiroCode path.
     *
     * @param string $iban
     * @return array {
     *     @type bool   $ok
     *     @type string $reason One of: format, non_sepa_country, wrong_length, checksum,
     *                          qr_iban_use_swiss_path, ok.
     * }
     */
    public static function validate($iban)
    {
        $iban = self::normalize($iban);

        if (!preg_match('/^[A-Z]{2}[0-9A-Z]+$/', $iban)) {
            return array('ok' => false, 'reason' => 'format');
        }

        $country = substr($iban, 0, 2);

        if (!isset(self::LENGTHS[$country])) {
            return array('ok' => false, 'reason' => 'non_sepa_country');
        }

        if (strlen($iban) !== self::LENGTHS[$country]) {
            return array('ok' => false, 'reason' => 'wrong_length');
        }

        if (!self::mod97_ok($iban)) {
            return array('ok' => false, 'reason' => 'checksum');
        }

        if (self::is_swiss_qr_iban($iban)) {
            return array('ok' => false, 'reason' => 'qr_iban_use_swiss_path');
        }

        return array('ok' => true, 'reason' => 'ok');
    }

    /**
     * The GiroCode carries EUR only (EPC restriction).
     *
     * @param string $currency
     * @return bool
     */
    public static function currency_is_eur($currency)
    {
        return strtoupper(trim((string) $currency)) === 'EUR';
    }
}
