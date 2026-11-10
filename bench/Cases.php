<?php

declare(strict_types=1);

namespace Charm\Csv\Bench;

use Charm\Csv\Decoder;
use Charm\Csv\Encoder;
use Charm\Csv\Mode;

/**
 * The code being timed. It lives in an included file on purpose: the tracing JIT only compiles
 * code from files that opcache has cached, and the main script is never cached.
 * "collect" keeps every row (the result array is part of the cost), "stream" counts and discards.
 */
final class Cases
{
    private const CHUNK = 65536;

    public static function fgetcsvCollect(string $file): array
    {
        $h = fopen($file, 'rb');
        $o = [];
        while (($x = fgetcsv($h, null, ',', '"', '')) !== false) { $o[] = $x; }
        fclose($h);
        return $o;
    }

    public static function fgetcsvStream(string $file): int
    {
        $h = fopen($file, 'rb');
        $n = 0;
        while (($x = fgetcsv($h, null, ',', '"', '')) !== false) { $n++; }
        fclose($h);
        return $n;
    }

    /** Only valid for data without line breaks inside fields. */
    public static function strGetcsvCollect(string $file): array
    {
        $h = fopen($file, 'rb');
        $o = [];
        while (($l = fgets($h)) !== false) { $o[] = str_getcsv($l, ',', '"', ''); }
        fclose($h);
        return $o;
    }

    public static function decoderCollect(string $file, Mode $mode = Mode::Lax): array
    {
        $d = new Decoder(mode: $mode);
        $h = fopen($file, 'rb');
        $o = [];
        while (!feof($h)) {
            foreach ($d->decode(fread($h, self::CHUNK)) as $row) { $o[] = $row; }
        }
        foreach ($d->finish() as $row) { $o[] = $row; }
        fclose($h);
        return $o;
    }

    public static function decoderStream(string $file, Mode $mode = Mode::Lax, bool|array $header = false): int
    {
        $d = new Decoder(header: $header, mode: $mode);
        $h = fopen($file, 'rb');
        $n = 0;
        while (!feof($h)) {
            foreach ($d->decode(fread($h, self::CHUNK)) as $row) { $n++; }
        }
        foreach ($d->finish() as $row) { $n++; }
        fclose($h);
        return $n;
    }

    /** @param list<list<string>> $rows */
    public static function fputcsv(array $rows): int
    {
        $h = fopen('php://memory', 'w+b');
        foreach ($rows as $row) { fputcsv($h, $row, ',', '"', '', "\r\n"); }
        return ftell($h);
    }

    /** @param list<list<string>> $rows */
    public static function encoder(array $rows): int
    {
        $h = fopen('php://memory', 'w+b');
        $e = new Encoder();
        foreach ($rows as $row) { fwrite($h, $e->encode($row)); }
        return ftell($h);
    }
}
