<?php

/**
 * Reader for ISO 20022 bank statements (camt.053) and credit notifications (camt.054).
 *
 * Deliberately order-driven. The caller hands over the payment references of its open
 * orders and gets back only the credit entries carrying one of them. Everything else in
 * the file — and a monthly statement holds the shop's entire banking business — is
 * counted and dropped on the spot: not returned, not stored, not logged. The parser
 * never reads the payer's name, address or account at all.
 *
 * The file is streamed one entry at a time, so a large statement is never held in
 * memory as a whole.
 *
 * @package sqrip
 * @since 1.11
 */

defined('ABSPATH') || exit;

class Sqrip_Camt_Parser
{
    /**
     * Refuse anything larger. A yearly statement of a busy shop stays well below this.
     */
    const MAX_FILE_SIZE = 20971520; // 20 MB

    /**
     * Error codes collected during the last run. Translated by the caller, so this
     * class stays free of WordPress and testable on its own.
     *
     * @var array
     */
    private $errors = array();

    /**
     * @return array List of arrays with 'code' and optional 'detail'.
     */
    public function get_errors()
    {
        return $this->errors;
    }

    /**
     * Strip a reference down to what can be compared.
     *
     * QR references are 27 digits, Creditor References start with RF. Banks and humans
     * both like to group them with spaces, so remove everything that is not a letter or
     * a digit and compare in upper case.
     *
     * @param string $reference
     * @return string
     */
    public static function normalize_reference($reference)
    {
        $reference = strtoupper((string) $reference);

        return (string) preg_replace('/[^A-Z0-9]/', '', $reference);
    }

    /**
     * Find the payments belonging to a known set of references.
     *
     * @param string   $file       Absolute path to the XML file.
     * @param string[] $references Payment references of the still open orders.
     *
     * @return array|false {
     *     @type array $matches       Normalised reference => list of payments found for it.
     *                                A payment holds reference, amount, currency,
     *                                value_date and booking_date. Nothing else.
     *     @type int   $other_credits Credits in the file belonging to no open order.
     *                                A number only, so the shop can tell whether it
     *                                picked the right file, without this code ever
     *                                touching unrelated business.
     *     @type int   $credits_total Credits seen in total.
     * }
     *         False when the file could not be read; see get_errors().
     */
    public function collect_matches($file, array $references)
    {
        $this->errors = array();

        if (!$this->validate_file($file)) {
            return false;
        }

        $wanted = array();

        foreach ($references as $reference) {
            $normalized = self::normalize_reference($reference);

            if ($normalized !== '') {
                $wanted[$normalized] = true;
            }
        }

        $result = array(
            'matches'       => array(),
            'other_credits' => 0,
            'credits_total' => 0,
        );

        $collect = function ($payment) use (&$result, $wanted) {
            $result['credits_total']++;

            $reference = $payment['reference'];

            // Not one of ours. Count it and forget it — this is the whole point of
            // going through the open orders instead of through the statement.
            if ($reference === '' || !isset($wanted[$reference])) {
                $result['other_credits']++;

                return;
            }

            $result['matches'][$reference][] = $payment;
        };

        if (!$this->stream_entries($file, $collect)) {
            return false;
        }

        return $result;
    }

    /**
     * Is this a file we are willing to open at all?
     *
     * @param string $file
     * @return bool
     */
    private function validate_file($file)
    {
        if (!is_string($file) || $file === '' || !is_readable($file) || !is_file($file)) {
            $this->errors[] = array('code' => 'unreadable');

            return false;
        }

        $size = filesize($file);

        if ($size === false || $size === 0) {
            $this->errors[] = array('code' => 'empty');

            return false;
        }

        if ($size > self::MAX_FILE_SIZE) {
            $this->errors[] = array('code' => 'too_large');

            return false;
        }

        return true;
    }

    /**
     * Walk the credit entries of the file and report every single payment in them.
     *
     * @param string   $file
     * @param callable $on_payment Receives one payment array at a time.
     * @return bool
     */
    private function stream_entries($file, callable $on_payment)
    {
        $previous_state = libxml_use_internal_errors(true);
        $this->block_external_entities();

        $reader = new XMLReader();

        // LIBXML_NONET forbids fetching anything over the network. LIBXML_NOENT is
        // deliberately NOT set, so entities are never substituted. A bank statement is
        // a foreign file and is treated like one.
        $opened = @$reader->open($file, null, LIBXML_NONET | LIBXML_NOCDATA);

        if (!$opened) {
            $this->errors[] = array('code' => 'not_xml');
            $this->restore_libxml($previous_state);

            return false;
        }

        $document = new DOMDocument();
        $entries_seen = 0;

        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Ntry') {
                continue;
            }

            $entry = @$reader->expand($document);

            if ($entry instanceof DOMNode) {
                $entries_seen++;
                $this->handle_entry($entry, $on_payment);
            }

            // The children of this entry are dealt with; jump to the next sibling.
            @$reader->next();
        }

        $reader->close();

        $malformed = $this->has_fatal_libxml_error();
        $this->restore_libxml($previous_state);

        if ($malformed) {
            $this->errors[] = array('code' => 'malformed_xml');

            return false;
        }

        if ($entries_seen === 0) {
            $this->errors[] = array('code' => 'no_entries');

            return false;
        }

        return true;
    }

    /**
     * Turn one <Ntry> into zero or more payments.
     *
     * A single entry can be one payment or a batch ("Sammelbuchung") holding many, in
     * which case the entry amount is the total and the real payments sit in TxDtls.
     *
     * @param DOMNode  $entry
     * @param callable $on_payment
     * @return void
     */
    private function handle_entry(DOMNode $entry, callable $on_payment)
    {
        $xpath = new DOMXPath($entry->ownerDocument);

        // Money leaving the account is none of our business.
        if ($this->child_value($xpath, $entry, 'CdtDbtInd') !== 'CRDT') {
            return;
        }

        // A cancelled or returned booking must not confirm an order.
        $reversal = strtoupper($this->child_value($xpath, $entry, 'RvslInd'));

        if ($reversal === 'TRUE' || $reversal === '1') {
            return;
        }

        $entry_amount   = $this->child_value($xpath, $entry, 'Amt');
        $entry_currency = $this->child_attribute($xpath, $entry, 'Amt', 'Ccy');
        $value_date     = $this->date_value($xpath, $entry, 'ValDt');
        $booking_date   = $this->date_value($xpath, $entry, 'BookgDt');

        $transactions = $xpath->query(
            './*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]',
            $entry
        );

        // No breakdown: the entry itself is the payment.
        if (!$transactions || $transactions->length === 0) {
            $reference = $this->reference_from($xpath, $entry);

            call_user_func($on_payment, array(
                'reference'    => $reference,
                'amount'       => $this->to_amount($entry_amount),
                'currency'     => $entry_currency,
                'value_date'   => $value_date,
                'booking_date' => $booking_date,
            ));

            return;
        }

        foreach ($transactions as $transaction) {
            // Inside a batch an individual item can still be a debit.
            $indicator = $this->child_value($xpath, $transaction, 'CdtDbtInd');

            if ($indicator !== '' && $indicator !== 'CRDT') {
                continue;
            }

            $amount   = $this->child_value($xpath, $transaction, 'Amt');
            $currency = $this->child_attribute($xpath, $transaction, 'Amt', 'Ccy');

            $transaction_value_date   = $this->date_value($xpath, $transaction, 'ValDt');
            $transaction_booking_date = $this->date_value($xpath, $transaction, 'BookgDt');

            call_user_func($on_payment, array(
                'reference'    => $this->reference_from($xpath, $transaction),
                'amount'       => $this->to_amount($amount !== '' ? $amount : $entry_amount),
                'currency'     => $currency !== '' ? $currency : $entry_currency,
                'value_date'   => $transaction_value_date !== '' ? $transaction_value_date : $value_date,
                'booking_date' => $transaction_booking_date !== '' ? $transaction_booking_date : $booking_date,
            ));
        }
    }

    /**
     * The payment reference of an entry or transaction.
     *
     * Structured creditor reference first — that is the QR reference or the RF
     * reference printed on the QR bill. EndToEndId is the fallback some banks use.
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $context
     * @return string Normalised, empty when there is none.
     */
    private function reference_from(DOMXPath $xpath, DOMNode $context)
    {
        $reference = $this->deep_value($xpath, $context, 'CdtrRefInf', 'Ref');

        if ($reference === '') {
            $reference = $this->deep_value($xpath, $context, 'Refs', 'EndToEndId');
        }

        $reference = self::normalize_reference($reference);

        // Banks write this when the payer supplied nothing.
        if ($reference === 'NOTPROVIDED') {
            return '';
        }

        return $reference;
    }

    /**
     * Value of a direct child element.
     */
    private function child_value(DOMXPath $xpath, DOMNode $context, $name)
    {
        $nodes = $xpath->query('./*[local-name()="' . $name . '"]', $context);

        if (!$nodes || $nodes->length === 0) {
            return '';
        }

        return trim($nodes->item(0)->textContent);
    }

    /**
     * Attribute of a direct child element, such as the currency on <Amt Ccy="CHF">.
     */
    private function child_attribute(DOMXPath $xpath, DOMNode $context, $name, $attribute)
    {
        $nodes = $xpath->query('./*[local-name()="' . $name . '"]', $context);

        if (!$nodes || $nodes->length === 0 || !($nodes->item(0) instanceof DOMElement)) {
            return '';
        }

        return trim($nodes->item(0)->getAttribute($attribute));
    }

    /**
     * Value of a nested element, addressed by parent and child name.
     */
    private function deep_value(DOMXPath $xpath, DOMNode $context, $parent, $child)
    {
        $query = './/*[local-name()="' . $parent . '"]/*[local-name()="' . $child . '"]';
        $nodes = $xpath->query($query, $context);

        if (!$nodes || $nodes->length === 0) {
            return '';
        }

        return trim($nodes->item(0)->textContent);
    }

    /**
     * A date wrapper such as <ValDt><Dt>2026-07-24</Dt></ValDt>, which may also carry
     * a full timestamp in <DtTm>.
     *
     * @return string ISO date, empty when absent.
     */
    private function date_value(DOMXPath $xpath, DOMNode $context, $name)
    {
        $nodes = $xpath->query('./*[local-name()="' . $name . '"]', $context);

        if (!$nodes || $nodes->length === 0) {
            return '';
        }

        $wrapper = $nodes->item(0);

        foreach (array('Dt', 'DtTm') as $child) {
            $value = $this->child_value($xpath, $wrapper, $child);

            if ($value !== '') {
                return substr($value, 0, 10);
            }
        }

        return '';
    }

    /**
     * Amounts arrive as strings and are compared against order totals later.
     *
     * @return float
     */
    private function to_amount($value)
    {
        return round((float) str_replace(',', '.', trim((string) $value)), 2);
    }

    /**
     * Refuse to resolve external entities while we parse.
     *
     * PHP 8 does not load them by default any more, but the plugin still supports
     * PHP 7.4, and being explicit costs nothing.
     *
     * @return void
     */
    private function block_external_entities()
    {
        if (function_exists('libxml_set_external_entity_loader')) {
            libxml_set_external_entity_loader(null);
        }
    }

    /**
     * @return bool Whether libxml reported something worse than a warning.
     */
    private function has_fatal_libxml_error()
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $previous_state
     * @return void
     */
    private function restore_libxml($previous_state)
    {
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);
    }
}
