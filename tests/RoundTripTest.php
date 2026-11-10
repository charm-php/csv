<?php

declare(strict_types=1);

namespace FrodeBorli\Csv\Tests;

use FrodeBorli\Csv\Dialect;
use FrodeBorli\Csv\Quoting;
use FrodeBorli\Csv\Reader;
use FrodeBorli\Csv\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** read(write(rows)) === rows, for any rows of strings with at least one field. */
final class RoundTripTest extends TestCase
{
    public static function dialects(): iterable
    {
        $bom = "\xEF\xBB\xBF";
        $text = ['a', 'b', 'é', ' ', '  ', "\0", $bom, '0', '1.5'];
        yield 'rfc4180' => [new Dialect(), [...$text, ',', '"', '""', "\r\n", "\n", "\r", ',"', "\r", "\n\n"]];
        yield 'rfc4180, ALL' => [new Dialect(quoting: Quoting::ALL), [...$text, ',', '"', "\r\n", "\n"]];
        yield 'rfc4180, NONNUMERIC' => [new Dialect(quoting: Quoting::NONNUMERIC), [...$text, ',', '"', "\n"]];
        yield 'excel' => [Dialect::excel(), [...$text, ',', '"', "\r\n"]];
        yield 'unix' => [Dialect::unix(), [...$text, ',', '"', "\r\n", "\n"]];
        yield 'semicolon' => [new Dialect(';', "'", "\n"), [...$text, ';', "'", '"', ',', "\n", "\r"]];
        yield 'skipInitialSpace' => [new Dialect(skipInitialSpace: true), [...$text, ',', '"', ' a', "\n"]];
        yield 'tsv (NONE)' => [Dialect::tsv(), [...$text, ',', '"', "'", '\\']];
        yield 'multibyte' => [new Dialect('→', '«', '¶'), [...$text, '→', '«', '¶', "\n", "\xE2", "\x86", "\xC2", "\xB6", '→→']];
        yield 'two-byte delimiter' => [new Dialect('::', '[]', "\n"), [...$text, '::', ':', '[]', '[', ']', "\n", "\r\n"]];
        yield 'self-overlapping delimiter' => [new Dialect('||', "'", "\r\n"), [...$text, '||', '|', '|||', "'", "''", "\r\n", "\n"]];
        yield 'custom terminator' => [new Dialect(',', '"', '<>'), [...$text, ',', '"', '<>', '<', '>', "\n", '<<>>']];
        yield 'CR terminator' => [new Dialect(',', '"', "\r"), [...$text, ',', '"', "\r", "\n", "\r\n"]];
        yield 'NUL delimiter' => [new Dialect("\0", '"', "\n"), [...$text, "\0", '"', "\n"]];
    }

    #[DataProvider('dialects')]
    public function testRandomRowsSurviveAWriteReadCycle(Dialect $d, array $alphabet): void
    {
        mt_srand(20261110);
        for ($i = 0; $i < 150; $i++) {
            $rows = [];
            for ($r = mt_rand(1, 12); $r > 0; $r--) {
                $row = [];
                for ($f = mt_rand(1, 5); $f > 0; $f--) {
                    $v = '';
                    for ($k = mt_rand(0, 4); $k > 0; $k--) { $v .= $alphabet[mt_rand(0, count($alphabet) - 1)]; }
                    $row[] = $v;
                }
                $rows[] = $row;
            }
            // Quoting::NONE can only represent fields that are free of delimiters and line breaks.
            $csv = null;
            try {
                $csv = Writer::rowsToString($rows, $d);
            } catch (\UnexpectedValueException) {
                $this->assertSame(Quoting::NONE, $d->quoting);
                continue;
            }
            $label = json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE);
            $chunk = mt_rand(1, 12);
            $this->assertSame($rows, Reader::fromString($csv, $d)->toArray(), $label);
            $this->assertSame($rows, Reader::fromString($csv, $d)->withChunkSize($chunk)->toArray(), "chunk $chunk, $label");
            // Writer output is always valid for the strict reader (NONE has no quoting to violate).
            $this->assertSame($rows, Reader::fromString($csv, $d->with(strict: true))->withChunkSize($chunk)->toArray(), "strict, $label");
        }
    }

    public function testNoneDialectRoundTripsWhenFieldsAreClean(): void
    {
        $rows = [['a', 'b"c', "'x'"], ['', ''], ['é', "\0"]];
        $d = Dialect::tsv();
        $this->assertSame($rows, Reader::fromString(Writer::rowsToString($rows, $d), $d)->toArray());
    }

    public function testStreamRoundTripWithSmallBuffersAndChunks(): void
    {
        mt_srand(7);
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = [(string) $i, str_repeat('x', mt_rand(0, 30)) . ($i % 7 ? '' : "\n,\""), $i % 3 ? 'plain' : 'with "quotes"'];
        }
        $h = fopen('php://memory', 'w+');
        $w = Writer::toStream($h, null, 64);
        $w->writeRows($rows);
        $w->close();
        rewind($h);
        $this->assertSame($rows, Reader::fromStream($h)->withChunkSize(13)->toArray());
    }
}
