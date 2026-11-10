<?php

declare(strict_types=1);

namespace Charm\Csv\Tests;

use Charm\Csv\Decoder;
use Charm\Csv\Encoder;
use Charm\Csv\Format;
use Charm\Csv\Mode;
use Charm\Csv\Quoting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** decode(encode(rows)) === rows, for any rows of strings with at least one field. */
final class RoundTripTest extends TestCase
{
    public static function formats(): iterable
    {
        $bom = "\xEF\xBB\xBF";
        $text = ['a', 'b', 'é', ' ', '  ', "\0", $bom, '0', '1.5'];
        yield 'rfc4180' => [new Format(), [...$text, ',', '"', '""', "\r\n", "\n", "\r", ',"', "\r", "\n\n"]];
        yield 'rfc4180, All' => [new Format(quoting: Quoting::All), [...$text, ',', '"', "\r\n", "\n"]];
        yield 'rfc4180, NonNumeric' => [new Format(quoting: Quoting::NonNumeric), [...$text, ',', '"', "\n"]];
        yield 'excel' => [Format::excel(), [...$text, ',', '"', "\r\n"]];
        yield 'unix' => [Format::unix(), [...$text, ',', '"', "\r\n", "\n"]];
        yield 'semicolon' => [new Format(';', "'", "\n"), [...$text, ';', "'", '"', ',', "\n", "\r"]];
        yield 'skipInitialSpace' => [new Format(skipInitialSpace: true), [...$text, ',', '"', ' a', "\n"]];
        yield 'tsv (Never)' => [Format::tsv(), [...$text, ',', '"', "'", '\\']];
        yield 'multibyte' => [new Format('→', '«', '¶'), [...$text, '→', '«', '¶', "\n", "\xE2", "\x86", "\xC2", "\xB6", '→→']];
        yield 'two-byte delimiter' => [new Format('::', '[]', "\n"), [...$text, '::', ':', '[]', '[', ']', "\n", "\r\n"]];
        yield 'self-overlapping delimiter' => [new Format('||', "'", "\r\n"), [...$text, '||', '|', '|||', "'", "''", "\r\n", "\n"]];
        yield 'custom EOL' => [new Format(',', '"', '<>'), [...$text, ',', '"', '<>', '<', '>', "\n", '<<>>']];
        yield 'multibyte delimiter and EOL, Never' => [new Format('::', '"', '<>', Quoting::Never), [...$text, ':', '<', '>', '::', '<>']];
        yield 'CR EOL' => [new Format(',', '"', "\r"), [...$text, ',', '"', "\r", "\n", "\r\n"]];
        yield 'NUL delimiter' => [new Format("\0", '"', "\n"), [...$text, "\0", '"', "\n"]];
        yield 'null text' => [new Format(null: '\N'), [...$text, ',', '"', '\\', 'N', "\n"]];
    }

    #[DataProvider('formats')]
    public function testRandomRowsSurviveAnEncodeDecodeCycle(Format $f, array $alphabet): void
    {
        mt_srand(20261110);
        for ($i = 0; $i < 150; $i++) {
            $rows = [];
            for ($r = mt_rand(1, 12); $r > 0; $r--) {
                $row = [];
                for ($c = mt_rand(1, 5); $c > 0; $c--) {
                    $v = '';
                    for ($k = mt_rand(0, 4); $k > 0; $k--) { $v .= $alphabet[mt_rand(0, count($alphabet) - 1)]; }
                    if ($f->null !== null && $v === $f->null) { $v .= 'x'; } // documented: such a string reads back as null
                    $row[] = $v;
                }
                $rows[] = $row;
            }
            try {
                $csv = Support::encode($rows, $f);
            } catch (\UnexpectedValueException) { // Quoting::Never can only represent fields free of delimiters and line breaks
                $this->assertSame(Quoting::Never, $f->quoting);
                continue;
            }
            $label = json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE);
            $chunk = mt_rand(1, 12);
            $this->assertSame($rows, Support::decode($csv, $f), $label);
            $this->assertSame($rows, Support::decode($csv, $f, false, Mode::Lax, $chunk), "chunk $chunk, $label");
            // The Encoder's output is always valid for the strict Decoder, but ragged rows are not, so pad them.
            $w = max(array_map('count', $rows));
            $square = array_map(fn($r) => array_pad($r, $w, 'p'), $rows);
            $this->assertSame($square, Support::decode(Support::encode($square, $f), $f, false, Mode::Strict, $chunk), "strict, $label");
        }
    }

    public function testNeverFormatRoundTripsWhenFieldsAreClean(): void
    {
        $rows = [['a', 'b"c', "'x'"], ['é', "\0", 'z'], ['y', 'y', 'y']];
        $f = Format::tsv();
        $this->assertSame($rows, Support::decode(Support::encode($rows, $f), $f, false, Mode::Strict));
    }

    public function testMixedTypesComeBackAsStringsAndNullsWithANullText(): void
    {
        $f = new Format(null: '\N');
        $csv = Support::encode([[1, 2.5, true, false, null, '', 'x']], $f);
        $this->assertSame("1,2.5,1,0,\\N,,x\r\n", $csv);
        $this->assertSame([['1', '2.5', '1', '0', null, '', 'x']], Support::decode($csv, $f, false, Mode::Strict));
    }

    public function testHeaderedRoundTrip(): void
    {
        mt_srand(99);
        $rows = [];
        for ($i = 0; $i < 300; $i++) {
            $rows[] = ['id' => (string) $i, 'note' => str_repeat('x,"', mt_rand(0, 3)) . "\n" . mt_rand(0, 9), 'empty' => ''];
        }
        $csv = Support::encode($rows, null, true);
        foreach ([0, 1, 7, 100] as $chunk) {
            $this->assertSame($rows, Support::decode($csv, null, true, Mode::Strict, $chunk), "chunk $chunk");
        }
    }

    public function testStreamRoundTripWithSmallChunks(): void
    {
        mt_srand(7);
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = [(string) $i, str_repeat('x', mt_rand(0, 30)) . ($i % 7 ? '' : "\n,\""), $i % 3 ? 'plain' : 'with "quotes"'];
        }
        $h = fopen('php://memory', 'w+');
        $encoder = new Encoder();
        foreach ($rows as $row) { fwrite($h, $encoder->encode($row)); }
        rewind($h);
        $decoder = new Decoder();
        $out = [];
        while (!feof($h)) {
            foreach ($decoder->decode(fread($h, 13)) as $row) { $out[] = $row; }
        }
        array_push($out, ...$decoder->finish());
        $this->assertSame($rows, $out);
    }
}
