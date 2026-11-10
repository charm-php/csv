<?php

declare(strict_types=1);

namespace Charm\Csv\Tests;

use Charm\Csv\Decoder;
use Charm\Csv\Format;
use Charm\Csv\Mode;

/** Test helpers: decode a whole string, in one piece or cut into chunks. */
final class Support
{
    /**
     * @param int $chunk bytes per decode() call; 0 = the whole string at once
     * @return list<array<int|string, ?string>>
     */
    public static function decode(string $csv, ?Format $format = null, bool|array $header = false, Mode $mode = Mode::Lax, int $chunk = 0): array
    {
        $d = new Decoder($format ?? new Format(), $header, $mode);
        $rows = [];
        $chunks = $chunk > 0 ? str_split($csv, $chunk) : [$csv];
        foreach ($chunks as $c) {
            array_push($rows, ...$d->decode($c));
        }
        array_push($rows, ...$d->finish());
        return $rows;
    }

    /** Decode with the given cut points (strictly increasing byte offsets). */
    public static function decodeCuts(string $csv, array $cuts, ?Format $format = null, bool|array $header = false, Mode $mode = Mode::Lax): array
    {
        $d = new Decoder($format ?? new Format(), $header, $mode);
        $rows = [];
        $prev = 0;
        foreach ([...$cuts, strlen($csv)] as $cut) {
            array_push($rows, ...$d->decode(substr($csv, $prev, $cut - $prev)));
            $prev = $cut;
        }
        array_push($rows, ...$d->finish());
        return $rows;
    }

    /** Encode rows into one string. */
    public static function encode(array $rows, ?Format $format = null, bool|array $header = false): string
    {
        $e = new \Charm\Csv\Encoder($format ?? new Format(), $header);
        $out = '';
        foreach ($rows as $row) {
            $out .= $e->encode($row);
        }
        return $out;
    }
}
