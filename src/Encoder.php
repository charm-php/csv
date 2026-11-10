<?php

declare(strict_types=1);

namespace Charm\Csv;

/**
 * CSV encoder: turns one row into one CSV record. It does no I/O, so it works with any sink:
 *
 *     $encoder = new Encoder(header: true);
 *     $h = fopen('out.csv', 'wb');
 *     foreach ($rows as $row) { fwrite($h, $encoder->encode($row)); }
 *
 * Field values:
 *  - string, int and float (written round-trippably and independent of the locale);
 *  - bool: true is "1", false is "0";
 *  - null: "" (or Format::$null when set);
 *  - backed enums: their value.
 * Anything else (arrays, resources, pure enums, objects, including Stringable ones) throws TypeError.
 *
 * A field is enclosed if it contains the delimiter, the enclosure or a line break, if it ends with a
 * fragment of a multibyte token that could form a token with its neighbour, or if it is the only (empty)
 * field of a row (otherwise the record would read back as a blank line). With skipInitialSpace, fields
 * starting with a space are enclosed too. So is a very first field starting with a UTF-8 BOM, which a
 * Decoder would otherwise strip. An empty row (no fields) is written as a blank line, which decoders skip.
 *
 * Header: with `true`, the first encode() call also emits a header record made of the first row's keys
 * (so that row must be associative). With a list of names, that header is emitted first. Either way, later
 * associative rows are written in the header's column order, by key: missing keys become null, keys that
 * are not in the header throw ValueError. List rows are written as they are. Without a header, rows are
 * written as they are, whatever their keys.
 */
final class Encoder
{
    private bool $started = false;
    private bool $bomSent = false;
    /** @var list<int|string>|null */
    private ?array $names;

    private string $special;
    /** @var list<string> multibyte tokens that must not occur in an unquoted field */
    private array $multi = [];
    /** @var list<string> */
    private array $tailBad = [];
    private bool $extra;
    private bool $never;
    private bool $all;
    private bool $nonNumeric;
    private bool $plain;
    private string $mask;

    /** @param bool|list<int|string> $header true: header from the first row's keys; list: write this header first */
    public function __construct(private Format $format = new Format(), private bool|array $header = false)
    {
        $f = $format;
        $this->names = is_array($header) ? array_values($header) : null;
        $tokens = [$f->delimiter, ...$f->eols()];
        if ($f->quoting !== Quoting::Never) { // with Never the enclosure is not special, so it never forces quoting
            $tokens[] = $f->enclosure;
        }
        $this->special = '';
        foreach ($tokens as $t) {
            if (strlen($t) === 1) { $this->special .= $t; }
            elseif ($t !== "\r\n") { $this->multi[] = $t; }
        }
        // A field ending in a proper prefix of a multibyte token could form that token together with the
        // delimiter or EOL that follows ("a|" + "||" reads as "a" + "||" + "|"). A fragment at the
        // start of a field is harmless: decoders match tokens left to right, from a known position.
        foreach ($this->multi as $t) {
            for ($k = 1; $k < strlen($t); $k++) {
                $this->tailBad[] = substr($t, 0, $k);
            }
        }
        $this->extra = $this->multi || $f->skipInitialSpace;
        $this->never = $f->quoting === Quoting::Never;
        $this->all = $f->quoting === Quoting::All;
        $this->nonNumeric = $f->quoting === Quoting::NonNumeric;
        // The fast path in record() needs: minimal quoting, single-byte tokens only, nothing special about spaces.
        $this->plain = $f->quoting === Quoting::Minimal && !$this->extra && strlen($f->delimiter) === 1 && strlen($f->enclosure) === 1
            && $this->special !== '';
        $this->mask = str_replace($f->delimiter, '', $this->special); // the delimiter is counted separately
        if ($this->mask === '') { $this->plain = false; }
    }

    /**
     * Encode one record, including the EOL sequence. The first call may also return the BOM and the header record.
     *
     * @param array<int|string, mixed> $row
     * @throws \TypeError for a field value of an unsupported type
     * @throws \ValueError for a row whose keys do not fit the header
     * @throws \UnexpectedValueException if Quoting::Never is set and a field would need quoting
     */
    public function encode(array $row): string
    {
        $out = '';
        if (!$this->bomSent && $this->format->bom) {
            $out = "\xEF\xBB\xBF";
        }
        $this->bomSent = true;
        if ($this->header === true && $this->names === null) {
            if (array_is_list($row)) {
                throw new \ValueError('header: true takes the column names from the first row, which must be associative');
            }
            $this->names = array_keys($row);
        }
        if ($this->header !== false && !$this->started) {
            $out .= $this->record($this->names);
        }
        if ($this->names !== null && !array_is_list($row)) {
            if (array_keys($row) !== $this->names) {
                if ($extra = array_diff_key($row, array_flip($this->names))) {
                    throw new \ValueError('Row has keys that are not in the header: ' . implode(', ', array_keys($extra)));
                }
                $ordered = [];
                foreach ($this->names as $name) { $ordered[] = $row[$name] ?? null; }
                $row = $ordered;
            }
        }
        return $out . $this->record($row);
    }

    private function record(array $row): string
    {
        $f = $this->format;
        $n = count($row);
        $checkBom = !$this->started && $n > 0; // a leading BOM would be swallowed by the decoder
        if ($checkBom) { $this->started = true; }
        if ($this->plain && !$checkBom && $n > 1) {
            // Fast path for the common case: only strings, and none of them needs enclosing. The delimiter
            // count of the joined row tells whether a field contains one; one strcspn() covers the rest.
            foreach ($row as $v) {
                if (!is_string($v)) { goto slow; }
            }
            $s = implode($f->delimiter, $row);
            if (strcspn($s, $this->mask) === strlen($s) && substr_count($s, $f->delimiter) === $n - 1) {
                return $s . $f->eolSequence;
            }
            $E = $f->enclosure;
            $out = [];
            foreach ($row as $v) {
                $out[] = strcspn($v, $this->special) === strlen($v) ? $v : $E . str_replace($E, $E . $E, $v) . $E;
            }
            return implode($f->delimiter, $out) . $f->eolSequence;
        }
        slow:
        $E = $f->enclosure;
        $single = $n === 1;
        $out = [];
        foreach ($row as $v) {
            if (is_string($v)) {
                $num = false;
            } else {
                if ($v instanceof \BackedEnum) { $v = $v->value; }
                $num = false;
                if (is_string($v)) { /* a string-backed enum */ }
                elseif (is_int($v)) { $num = true; $v = (string) $v; }
                elseif (is_float($v)) { $num = true; $v = var_export($v, true); }
                elseif ($v === null) { $v = $f->null ?? ''; }
                elseif (is_bool($v)) { $v = $v ? '1' : '0'; }
                else { throw new \TypeError('CSV field must be string, int, float, bool, null or a backed enum, ' . get_debug_type($v) . ' given'); }
            }
            $need = $v === ''
                ? $single
                : (($this->special !== '' && strcspn($v, $this->special) !== strlen($v)) || ($this->extra && $this->needsMore($v))); // (an empty mask stops at NUL before PHP 8.4; see Scanner)
            if ($checkBom) {
                $checkBom = false;
                $need = $need || str_starts_with($v, "\xEF\xBB\xBF");
            }
            if ($need && $this->never) {
                throw new \UnexpectedValueException('Field needs quoting but the format has Quoting::Never: ' . ($v === '' ? '(single empty field)' : var_export($v, true)));
            }
            if ($need || $this->all || ($this->nonNumeric && !$num)) {
                $v = $E . str_replace($E, $E . $E, $v) . $E;
            }
            $out[] = $v;
        }
        return implode($f->delimiter, $out) . $f->eolSequence;
    }

    /** The less common reasons to enclose a field: multibyte tokens, a dangling token prefix, leading space. */
    private function needsMore(string $v): bool
    {
        foreach ($this->multi as $t) {
            if (str_contains($v, $t)) { return true; }
        }
        foreach ($this->tailBad as $t) {
            if (str_ends_with($v, $t)) { return true; }
        }
        return $this->format->skipInitialSpace && $v[0] === ' ';
    }
}
