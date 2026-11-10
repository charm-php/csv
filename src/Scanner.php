<?php

declare(strict_types=1);

namespace FrodeBorli\Csv;

/**
 * The parsing engine. Not public API; use Reader.
 *
 * scan() parses as many complete records as the buffer holds. It never loops per character:
 * strcspn() finds the end of a plain field, strpos() the next enclosure of a quoted one.
 * Single-byte delimiters/terminators go through strcspn; multibyte tokens through a cached
 * strpos (UTF-8 is self-synchronising, so byte search is safe).
 *
 * Chunking is handled here, not by guessing quote parity: a record that is not
 * unambiguously finished inside a non-final buffer is left unconsumed and re-scanned once more
 * data has arrived. "Unambiguous" means at least $peek bytes follow the point of decision
 * (a lone "\r" may be half of "\r\n", a closing enclosure may be half of a doubled one, ...).
 */
final class Scanner
{
    /** Bytes of the buffer consumed by the last scan() (always at a record boundary). */
    public int $consumed = 0;
    /** Line breaks / records consumed so far, for error positions. */
    public int $lines = 0;
    public int $records = 0;
    /** Required field count; null = taken from the first record (only if $check). */
    public ?int $expect = null;

    private string $D;
    private string $E;
    private string $X;
    private int $Ld;
    private int $Le;
    private int $Lx;
    private bool $std;
    private bool $quote;
    private bool $strict;
    private bool $skip;
    private string $stops = '';
    /** @var list<string> multibyte delimiter / terminator */
    private array $multi = [];
    private int $peek;

    public function __construct(Dialect $d, private bool $check = false)
    {
        $this->D = $d->delimiter;
        $this->E = $d->enclosure;
        $this->X = $d->lineTerminator;
        $this->Ld = strlen($this->D);
        $this->Le = strlen($this->E);
        $this->Lx = strlen($this->X);
        $this->std = $d->universalEol();
        $this->quote = $d->quoting !== Quoting::NONE;
        $this->strict = $d->strict;
        $this->skip = $d->skipInitialSpace;
        if ($this->Ld === 1) { $this->stops .= $this->D; } else { $this->multi[] = $this->D; }
        if ($this->std) { $this->stops .= "\r\n"; }
        elseif ($this->Lx === 1) { $this->stops .= $this->X; }
        else { $this->multi[] = $this->X; }
        $this->peek = max($this->Ld, $this->Le, $this->std ? 2 : $this->Lx);
    }

    /** @return list<list<string>> */
    public function scan(string $s, bool $final): array
    {
        [$D, $E, $Ld, $Le, $d0] = [$this->D, $this->E, $this->Ld, $this->Le, $this->D[0]];
        $std = $this->std;
        $x0 = $this->X[0];
        $e0 = $this->quote ? $this->E[0] : false; // false never equals a byte, so NONE needs no extra test
        [$stops, $multi, $quote, $strict, $skip] = [$this->stops, $this->multi, $this->quote, $this->strict, $this->skip];
        $n = strlen($s);
        $p = 0;
        $rows = [];
        $next = array_fill(0, count($multi), -1); // cached position of the next multibyte token
        // A decision at $p needs $peek bytes of lookahead unless the buffer is final.
        $lines = !$skip;
        $eolStops = $std ? "\r\n" : ($this->Lx === 1 ? $this->X : '');
        $xm = !$std && $this->Lx > 1;
        $X = $this->X;
        $Lx = $this->Lx;
        $nq = $quote ? -1 : PHP_INT_MAX; // cached position of the next enclosure
        $nx = -1; // ... and of the next multibyte terminator
        $lim = $final ? $n - 1 : $n - $this->peek;

        while ($p < $n) {
            if ($p > $lim) { goto done; }
            $c = $s[$p];
            if ($std ? ($c === "\n" || $c === "\r") : $c === $x0) { // blank line?
                if ($l = $this->eolAt($s, $p)) { $p += $l; continue; }
            }
            $rs = $p;
            if ($lines) {
                // Fast path: a line without any enclosure holds no quoted field, so explode() splits it in one C call.
                $le = $eolStops === '' ? $n : $p + strcspn($s, $eolStops, $p); // (strcspn with an empty mask stops at NUL before PHP 8.4)
                if ($xm) {
                    if ($nx < $p) { $f = strpos($s, $X, $p); $nx = $f === false ? PHP_INT_MAX : $f; }
                    if ($nx < $le) { $le = $nx; }
                }
                if ($quote && $nq < $p) { $f = strpos($s, $E, $p); $nq = $f === false ? PHP_INT_MAX : $f; }
                if ($nq > $le) {
                    if ($le > $lim) {
                        if (!$final) { $p = $rs; goto done; }
                        $row = explode($D, substr($s, $p, $le - $p));
                        $p = $n;
                    } else {
                        $row = explode($D, substr($s, $p, $le - $p));
                        $p = $le + ($std ? ($s[$le] === "\r" && ($s[$le + 1] ?? '') === "\n" ? 2 : 1) : $Lx);
                    }
                    goto record;
                }
            }
            $row = [];
            while (true) {
                if ($skip && ($p += strspn($s, ' ', $p)) >= $n) { // only spaces left
                    if (!$final) { $p = $rs; goto done; }
                    $row[] = '';
                    break;
                }
                if ($s[$p] === $e0 && ($Le === 1 || substr_compare($s, $E, $p, $Le) === 0)) {
                    $start = $p;
                    $p += $Le;
                    $val = '';
                    while (true) {
                        $q = strpos($s, $E, $p);
                        if ($q === false) {
                            if (!$final) { $p = $rs; goto done; }
                            if ($strict) { $this->fail('unterminated quoted field', $s, $start, count($rows)); }
                            $val .= substr($s, $p); // lax: the rest of the input is the field
                            $p = $n;
                            break;
                        }
                        $val .= substr($s, $p, $q - $p);
                        $p = $q + $Le;
                        if (!$final && $p > $lim) { $p = $rs; goto done; } // cannot tell "" from a closing quote yet
                        if ($Le === 1 ? ($s[$p] ?? '') === $E : substr_compare($s, $E, $p, $Le) === 0) {
                            $val .= $E;
                            $p += $Le;
                            continue;
                        }
                        break;
                    }
                    // Stray text between the closing enclosure and the next token.
                    $end = $multi ? $this->stop($s, $p, $next) : $p + strcspn($s, $stops, $p);
                    if ($end > $p) {
                        if ($strict) { $this->fail('unexpected text after closing quote', $s, $p, count($rows)); }
                        $val .= substr($s, $p, $end - $p);
                        $p = $end;
                    }
                } else {
                    $end = $multi ? $this->stop($s, $p, $next) : $p + strcspn($s, $stops, $p);
                    $val = substr($s, $p, $end - $p);
                    if ($strict && $quote && ($at = strpos($val, $E)) !== false) {
                        $this->fail('unexpected quote in unquoted field', $s, $p + $at, count($rows));
                    }
                    $p = $end;
                }
                if ($p > $lim) {
                    if (!$final) { $p = $rs; goto done; }
                    $row[] = $val; // end of input ends the record
                    break;
                }
                $row[] = $val;
                if ($s[$p] === $d0 && ($Ld === 1 || substr_compare($s, $D, $p, $Ld) === 0)) {
                    $p += $Ld;
                    if ($p < $n) { continue; }
                    if (!$final) { $p = $rs; goto done; }
                    $row[] = ''; // input ends right after a delimiter: one more, empty field
                    break;
                }
                // Not a delimiter, so (the scan stopped there) a terminator.
                $p += $std ? ($s[$p] === "\r" && ($s[$p + 1] ?? '') === "\n" ? 2 : 1) : $this->Lx;
                break;
            }
            record:
            if ($this->check) {
                if ($this->expect === null) { $this->expect = count($row); }
                elseif (count($row) !== $this->expect) {
                    $this->fail('expected ' . $this->expect . ' fields, found ' . count($row), $s, $rs, count($rows));
                }
            }
            $rows[] = $row;
        }

        done:
        $this->consumed = $p;
        $this->lines += $this->countLines($s, $p);
        $this->records += count($rows);
        return $rows;
    }

    /** End of a plain field starting at $p when a multibyte delimiter/terminator is in play. */
    private function stop(string $s, int $p, array &$next): int
    {
        $end = $this->stops === '' ? strlen($s) : $p + strcspn($s, $this->stops, $p);
        foreach ($this->multi as $i => $t) {
            if ($next[$i] < $p) {
                $f = strpos($s, $t, $p);
                $next[$i] = $f === false ? PHP_INT_MAX : $f;
            }
            if ($next[$i] < $end) { $end = $next[$i]; }
        }
        return $end;
    }

    /** Length of the terminator starting at $p, or 0. */
    private function eolAt(string $s, int $p): int
    {
        if ($this->std) {
            $c = $s[$p];
            return $c === "\n" ? 1 : ($c === "\r" ? (($s[$p + 1] ?? '') === "\n" ? 2 : 1) : 0);
        }
        return $s[$p] === $this->X[0] && ($this->Lx === 1 || substr_compare($s, $this->X, $p, $this->Lx) === 0) ? $this->Lx : 0;
    }

    private function countLines(string $s, int $len): int
    {
        if ($len === 0) { return 0; }
        if ($this->std) {
            return substr_count($s, "\n", 0, $len) + substr_count($s, "\r", 0, $len) - substr_count($s, "\r\n", 0, $len);
        }
        return substr_count($s, $this->X, 0, $len);
    }

    private function fail(string $reason, string $s, int $pos, int $rowsInScan): never
    {
        $before = substr($s, 0, $pos);
        if ($this->std) {
            $nl = strrpos($before, "\n");
            $cr = strrpos($before, "\r");
            $ls = max($nl === false ? -1 : $nl, $cr === false ? -1 : $cr) + 1;
        } else {
            $i = strrpos($before, $this->X);
            $ls = $i === false ? 0 : $i + $this->Lx;
        }
        $tail = substr($before, $ls);
        $col = strlen($tail) - preg_match_all('/[\x80-\xBF]/', $tail) + 1; // UTF-8 characters
        throw new ParseException($reason, $this->lines + $this->countLines($s, $pos) + 1, $col, $this->records + $rowsInScan + 1);
    }
}
