<?php

declare(strict_types=1);

namespace Charm\Csv\Tests;

use Charm\Csv\Decoder;
use Charm\Csv\Format;
use Charm\Csv\Mode;
use Charm\Csv\ParseException;
use Charm\Csv\Quoting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The result must never depend on where the input is cut into chunks. */
final class ChunkTest extends TestCase
{
    /** @return iterable<string, array{Format, string}> */
    public static function cases(): iterable
    {
        $rfc = new Format();
        $docs = [
            'plain' => "a,b,c\r\n1,2,3\r\n4,5,6",
            'quoted' => "\"a\",\"b,c\",\"d\"\"e\"\n\"multi\r\nline\",x,\"\"\n\"\",\"\"\"\",\"\"\"\"\"\"\n",
            'CR only' => "a,b\rc,d\r\"e\rf\",g\r",
            'blank lines' => "a\n\n\r\n\rb\n",
            'bom' => "\xEF\xBB\xBFa,b\n\"c\",d\n",
            'ending in delimiter' => "a,b,",
            'trailing quote' => "a,\"b\"",
            'lax junk' => "x\"y,\"ab\"cd,\"unterminated\n1,2",
            'multibyte data' => "é,日本語,😀\n\"ü\nñ\",ø\n",
            'ragged' => "a,b\n1\n1,2,3\n",
        ];
        foreach ($docs as $name => $doc) {
            yield "rfc4180: $name" => [$rfc, $doc];
            yield "skipInitialSpace: $name" => [new Format(skipInitialSpace: true), str_replace(',', ', ', $doc)];
        }
        $mb = new Format('→', '«', '¶');
        yield 'multibyte tokens' => [$mb, "a→«b→c«→««««¶é→日本¶«x¶y«→z→¶¶"];
        yield 'multibyte tokens, junk' => [$mb, "a→«b«c→d«e→«f"];
        yield 'two-byte delimiter, LF' => [new Format('::', '[]', "\n"), "a::[]b::c[]::[]x[][]y[]\nd::\n\n[]\n"];
        yield 'self-overlapping delimiter' => [new Format('||', "'", "\r\n"), "a|||b||'c||d'||||\r\n||\r\nx"];
        yield 'custom EOL' => [new Format(',', '"', "\x1e"), "a,b\x1ec,\"d\x1ee\"\x1e\x1ef"];
        yield 'multibyte EOL' => [new Format(',', '"', '<>'), "a,b<>c,\"d<>e\"<><>f,<"];
        yield 'tsv' => [Format::tsv(), "a\tb\r\n\"c\td\n"];
        yield 'valid for strict' => [new Format(), "a,b\n\"c\"\"\",\"d\ne\"\n"];
        yield 'null text' => [new Format(null: '\N'), "a,\\N\n\\N,\"\\N\"\n"];
    }

    #[DataProvider('cases')]
    public function testEverySingleSplitGivesTheSameRows(Format $f, string $doc): void
    {
        foreach ([Mode::Lax, Mode::Strict] as $mode) {
            foreach ([false, true] as $header) {
                if ($header && $mode === Mode::Strict) {
                    continue; // an invalid header and a later error in one scan: which one is thrown first depends on the cut
                }
                $expected = $this->outcome($doc, [], $f, $header, $mode);
                for ($cut = 0; $cut <= strlen($doc); $cut++) {
                    $this->assertSame($expected, $this->outcome($doc, [$cut], $f, $header, $mode), "cut at $cut, {$mode->name}");
                }
            }
        }
    }

    #[DataProvider('cases')]
    public function testEveryChunkSizeGivesTheSameRows(Format $f, string $doc): void
    {
        foreach ([Mode::Lax, Mode::Strict] as $mode) {
            $expected = $this->outcome($doc, [], $f, false, $mode);
            for ($size = 1; $size <= 17; $size++) {
                $cuts = [];
                for ($p = $size; $p < strlen($doc); $p += $size) { $cuts[] = $p; }
                $this->assertSame($expected, $this->outcome($doc, $cuts, $f, false, $mode), "chunk size $size, {$mode->name}");
            }
        }
    }

    #[DataProvider('cases')]
    public function testRandomChunkingsGiveTheSameRows(Format $f, string $doc): void
    {
        mt_srand(1110);
        foreach ([Mode::Lax, Mode::Strict] as $mode) {
            $header = $mode === Mode::Lax; // see above for why not in Strict
            $expected = $this->outcome($doc, [], $f, $header, $mode);
            for ($i = 0; $i < 60; $i++) {
                $cuts = [];
                for ($p = mt_rand(0, 6); $p < strlen($doc); $p += mt_rand(1, 6)) { $cuts[] = $p; }
                $cuts = array_values(array_unique($cuts));
                $this->assertSame($expected, $this->outcome($doc, $cuts, $f, $header, $mode), 'cuts ' . implode(',', $cuts) . ", {$mode->name}");
            }
        }
    }

    /** Rows, or the exception (message, so that positions must be reproduced exactly too). */
    private function outcome(string $doc, array $cuts, Format $f, bool $header, Mode $mode): array
    {
        try {
            return [Support::decodeCuts($doc, $cuts, $f, $header, $mode)];
        } catch (ParseException $e) {
            return [$e->getMessage()];
        }
    }

    public function testMultibyteTokensSplitAtEveryByte(): void
    {
        $f = new Format('→', '«', '¶');
        $csv = "a→«b→c«→««««¶é→日本¶";
        $expected = [['a', 'b→c', '«'], ['é', '日本']];
        // chunk sizes 1 and 2 split every three-byte "→" and every two-byte "«" at every possible offset
        foreach ([1, 2, 3, 4, 5] as $size) {
            $this->assertSame($expected, Support::decode($csv, $f, false, Mode::Lax, $size), "chunk $size");
        }
    }

    public function testAThreeWaySplitAtEveryPair(): void
    {
        $f = new Format('→', '«', '¶');
        $doc = "a→«b→c«→««««¶é";
        $expected = Support::decode($doc, $f);
        $n = strlen($doc);
        for ($i = 0; $i <= $n; $i++) {
            for ($j = $i; $j <= $n; $j++) {
                $this->assertSame($expected, Support::decodeCuts($doc, [$i, $j], $f), "cuts $i, $j");
            }
        }
    }

    public function testEmptyChunksAreHarmless(): void
    {
        $d = new Decoder(header: true);
        $rows = [];
        foreach (['', 'a,b', '', "\n", '', '1,2', '', "\n", ''] as $c) {
            array_push($rows, ...$d->decode($c));
        }
        array_push($rows, ...$d->finish());
        $this->assertSame([['a' => '1', 'b' => '2']], $rows);
    }

    // ---- cross-check against the naive reference parser on random input ----------------------

    public static function fuzzFormats(): iterable
    {
        yield 'rfc4180' => [new Format(), ['a', 'b', ',', ',', '"', '"', "\n", "\r\n", "\r", ' ', 'é']];
        yield 'skipInitialSpace' => [new Format(skipInitialSpace: true), ['a', ',', '"', '"', "\n", ' ', ' ']];
        yield 'semicolon/single quote' => [new Format(';', "'", "\n"), ['a', ';', ';', "'", "'", "\n", '"', ',']];
        yield 'multibyte' => [new Format('→', '«', '¶'), ['a', '→', '→', '«', '«', '¶', "\n", 'é', "\xE2", "\x86"]];
        yield 'two-byte delimiter' => [new Format('::', '[]', "\n"), ['a', '::', ':', '[]', '[', ']', "\n", ',']];
        yield 'self-overlapping delimiter' => [new Format('||', "'", "\r\n"), ['a', '||', '|', "'", "'", "\r\n", "\n", "\r"]];
        yield 'custom EOL' => [new Format(',', '"', '<>'), ['a', ',', '"', '"', '<>', '<', '>', "\n"]];
        yield 'never quoting' => [new Format("\t", '"', "\n", Quoting::Never), ['a', "\t", '"', '"', "\n", "\r"]];
        yield 'tab, CR' => [new Format("\t", '"', "\r"), ['a', "\t", '"', '"', "\r", "\n"]];
    }

    #[DataProvider('fuzzFormats')]
    public function testMatchesReferenceParserOnRandomInput(Format $f, array $alphabet): void
    {
        mt_srand(4180);
        for ($i = 0; $i < 600; $i++) {
            $len = mt_rand(0, 40);
            $doc = '';
            for ($j = 0; $j < $len; $j++) { $doc .= $alphabet[mt_rand(0, count($alphabet) - 1)]; }
            [$expected, $violation] = ReferenceParser::parse($doc, $f);
            $ragged = $expected && count(array_unique(array_map('count', $expected))) > 1;
            $label = 'input ' . json_encode($doc, JSON_INVALID_UTF8_SUBSTITUTE);
            $this->assertSame($expected, Support::decode($doc, $f), "lax, $label");
            $chunk = mt_rand(1, 9);
            $this->assertSame($expected, Support::decode($doc, $f, false, Mode::Lax, $chunk), "lax, chunk $chunk, $label");
            try {
                $rows = Support::decode($doc, $f, false, Mode::Strict, $chunk);
                $this->assertFalse($violation || $ragged, "strict must reject, $label");
                $this->assertSame($expected, $rows);
            } catch (ParseException) {
                $this->assertTrue($violation || $ragged, "strict must accept, $label");
            }
        }
    }
}
