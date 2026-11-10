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
    private bool $headerDone = false;
    /** No BOM and no header: encode() is just record(). */
    private bool $bare;
    /** @var list<int|string>|null */
    private ?array $names;

    private string $special;
    /** @var list<string> multibyte tokens that must not occur in an unquoted field */
    private array $multi = [];
    /** @var list<string> */
    private array $tailBad = [];
    private bool $extra;
    private bool $never;
    private bool $lean;
    private string $E;
    /** special without the delimiter, which the line-first check counts instead */
    private string $mask;
    private string $D;
    private string $eol;
    private bool $all;
    private bool $nonNumeric;

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
        $this->bare = !$f->bom && $header === false;
        $this->lean = $this->special !== '' && !$this->extra && $f->null === null && $f->quoting === Quoting::Minimal;
        $this->mask = str_replace($f->delimiter, '', $this->special);
        if ($this->mask === '') { $this->lean = false; }
        [$this->E, $this->D, $this->eol] = [$f->enclosure, $f->delimiter, $f->eolSequence];
        $this->never = $f->quoting === Quoting::Never;
        $this->all = $f->quoting === Quoting::All;
        $this->nonNumeric = $f->quoting === Quoting::NonNumeric;
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
        $pre = $this->bare ? '' : $this->prepare($row);
        if ($this->lean && $this->started) {
            // Hot path: Minimal quoting, nothing special about the format, only strings and ints.
            $n = count($row);
            foreach ($row as $v) { // implode() would convert floats, bools and null wrongly
                if (!is_string($v) && !is_int($v)) { return $pre . $this->general($row); }
            }
            // Line first: join once, and if no field contains the delimiter, enclosure or a line break, that is the record.
            $line = implode($this->D, $row);
            if ($n > 1 && strcspn($line, $this->mask) === strlen($line) && substr_count($line, $this->D) === $n - 1) {
                return $pre === '' ? $line . $this->eol : $pre . $line . $this->eol;
            }
            $single = $n === 1;
            $special = $this->special;
            $E = $this->E;
            foreach ($row as $i => $v) {
                if (is_string($v)) {
                    if ($v === '' ? !$single : strcspn($v, $special) === strlen($v)) { continue; }
                    $row[$i] = $v === '' ? $E . $E : (str_contains($v, $E) ? $E . str_replace($E, $E . $E, $v) . $E : $E . $v . $E);
                } // ints are joined as they are: they never need enclosing
            }
            return $pre === '' ? implode($this->D, $row) . $this->eol : $pre . implode($this->D, $row) . $this->eol;
        }
        return $pre . $this->general($row);
    }

    /** Everything the hot path in encode() does not cover: other types, other quoting modes, the null text, the first row. */
    private function general(array $row): string
    {
        $n = count($row);
        $single = $n === 1;
        $special = $this->special;
        $E = $this->E;
        $bom0 = !$this->started && $n > 0; // a leading BOM would be swallowed by the decoder
        if ($bom0) { $this->started = true; }
        // $row is a by-value copy, so converted and enclosed fields are written back into it and joined in C.
        foreach ($row as $i => $v) {
            if (is_string($v)) {
                $num = $conv = $isNull = false;
            } elseif (is_int($v)) {
                $bom0 = false;
                if ($this->all) { $row[$i] = $E . $v . $E; }
                continue;
            } else {
                $num = $isNull = false;
                $conv = true;
                $nt = $this->format->null;
                if ($v instanceof \BackedEnum) { $v = $v->value; }
                if (is_string($v)) { /* a string-backed enum */ }
                elseif (is_int($v)) { $num = true; $v = (string) $v; }
                elseif (is_float($v)) { $num = true; $v = var_export($v, true); }
                elseif ($v === null) { $v = $nt ?? ''; $isNull = true; }
                elseif (is_bool($v)) { $v = $v ? '1' : '0'; }
                else { throw new \TypeError('CSV field must be string, int, float, bool, null or a backed enum, ' . get_debug_type($v) . ' given'); }
            }
            $nt = $this->format->null;
            $all = $this->all;
            $need = $v === ''
                ? $single
                : (($special !== '' && strcspn($v, $special) !== strlen($v)) || ($this->extra && $this->needsMore($v))); // (an empty mask stops at NUL before PHP 8.4; see Scanner)
            if ($bom0) {
                $bom0 = false;
                $need = $need || str_starts_with($v, "\xEF\xBB\xBF");
            }
            // A string equal to the null text must be enclosed, or it would decode as null (an enclosed field never does).
            if (!$need && $nt !== null && !$isNull && $v === $nt) { $need = true; }
            if ($need && $this->never) {
                throw new \UnexpectedValueException('Field needs quoting but the format has Quoting::Never: ' . ($v === '' ? '(single empty field)' : var_export($v, true)));
            }
            if ($need || $all || ($this->nonNumeric && !$num)) {
                $row[$i] = str_contains($v, $E) ? $E . str_replace($E, $E . $E, $v) . $E : $E . $v . $E;
            } elseif ($conv) {
                $row[$i] = $v;
            }
        }
        return implode($this->D, $row) . $this->eol;
    }

    /** BOM, header record and column order: everything that depends on state between calls. */
    private function prepare(array &$row): string
    {
        $out = '';
        if (!$this->bomSent) {
            $this->bomSent = true;
            if ($this->format->bom) { $out = "\xEF\xBB\xBF"; }
        }
        if ($this->header === true && $this->names === null) {
            if (array_is_list($row)) {
                throw new \ValueError('header: true takes the column names from the first row, which must be associative');
            }
            $this->names = array_keys($row);
        }
        if ($this->header !== false && !$this->headerDone) {
            $this->headerDone = true;
            $out .= $this->encode($this->names);
        }
        if ($this->names !== null && !array_is_list($row) && array_keys($row) !== $this->names) {
            if ($extra = array_diff_key($row, array_flip($this->names))) {
                throw new \ValueError('Row has keys that are not in the header: ' . implode(', ', array_keys($extra)));
            }
            $ordered = [];
            foreach ($this->names as $name) { $ordered[] = $row[$name] ?? null; }
            $row = $ordered;
        }
        return $out;
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
