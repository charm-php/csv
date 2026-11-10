<?php

declare(strict_types=1);

namespace FrodeBorli\Csv\Tests;

use FrodeBorli\Csv\Dialect;
use FrodeBorli\Csv\Quoting;

/**
 * Deliberately naive, byte-by-byte implementation of the documented lax semantics, used to
 * cross-check the optimised Scanner on random input. Also reports whether strict mode must object.
 */
final class ReferenceParser
{
    /** @return array{0: list<list<string>>, 1: bool} rows, and whether the input violates the strict grammar */
    public static function parse(string $s, Dialect $d): array
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) { $s = substr($s, 3); }
        $D = $d->delimiter; $E = $d->enclosure; $eols = $d->eols();
        $quote = $d->quoting !== Quoting::NONE;
        $n = strlen($s);
        $eolAt = function (int $p) use ($s, $eols): int {
            foreach ($eols as $e) { if (substr($s, $p, strlen($e)) === $e) { return strlen($e); } }
            return 0;
        };
        $rows = []; $bad = false; $p = 0;
        while ($p < $n) {
            if ($l = $eolAt($p)) { $p += $l; continue; }
            $row = [];
            while (true) {
                if ($d->skipInitialSpace) { while ($p < $n && $s[$p] === ' ') { $p++; } }
                $val = '';
                $wasQuoted = false;
                if ($quote && substr($s, $p, strlen($E)) === $E) {
                    $wasQuoted = true;
                    $p += strlen($E);
                    while (true) {
                        if ($p >= $n) { $bad = true; break; }
                        if (substr($s, $p, strlen($E)) === $E) {
                            $p += strlen($E);
                            if (substr($s, $p, strlen($E)) === $E) { $val .= $E; $p += strlen($E); continue; }
                            break;
                        }
                        $val .= $s[$p++];
                    }
                }
                while ($p < $n && !str_starts_with(substr($s, $p, strlen($D)), $D) && !$eolAt($p)) {
                    if ($wasQuoted) { $bad = true; }
                    elseif ($quote && substr($s, $p, strlen($E)) === $E) { $bad = true; }
                    $val .= $s[$p++];
                }
                $row[] = $val;
                if ($p >= $n) { break; }
                if (substr($s, $p, strlen($D)) === $D) { $p += strlen($D); continue; }
                $p += $eolAt($p);
                break;
            }
            $rows[] = $row;
        }
        return [$rows, $bad];
    }
}
