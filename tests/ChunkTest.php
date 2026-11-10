<?php

declare(strict_types=1);

namespace FrodeBorli\Csv\Tests;

use FrodeBorli\Csv\Dialect;
use FrodeBorli\Csv\ParseException;
use FrodeBorli\Csv\Quoting;
use FrodeBorli\Csv\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The result must never depend on where the input is cut into chunks. */
final class ChunkTest extends TestCase
{
    /** @return iterable<string, array{Dialect, string}> */
    public static function cases(): iterable
    {
        $rfc = new Dialect();
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
        ];
        foreach ($docs as $name => $doc) {
            yield "rfc4180: $name" => [$rfc, $doc];
            yield "skipInitialSpace: $name" => [new Dialect(skipInitialSpace: true), str_replace(',', ', ', $doc)];
        }
        $mb = new Dialect('→', '«', '¶');
        yield 'multibyte tokens' => [$mb, "a→«b→c«→««««¶é→日本¶«x¶y«→z→¶¶"];
        yield 'multibyte tokens, lax junk' => [$mb, "a→«b«c→d«e→«f"];
        yield 'two-byte delimiter, LF' => [new Dialect('::', '[]', "\n"), "a::[]b::c[]::[]x[][]y[]\nd::\n\n[]\n"];
        yield 'self-overlapping delimiter' => [new Dialect('||', "'", "\r\n"), "a|||b||'c||d'||||\r\n||\r\nx"];
        yield 'custom terminator' => [new Dialect(',', '"', "\x1e"), "a,b\x1ec,\"d\x1ee\"\x1e\x1ef"];
        yield 'multibyte terminator' => [new Dialect(',', '"', '<>'), "a,b<>c,\"d<>e\"<><>f,<"];
        yield 'tsv' => [Dialect::tsv(), "a\tb\r\n\"c\td\n"];
        yield 'strict-valid, strict' => [new Dialect(strict: true), "a,b\n\"c\"\"\",\"d\ne\"\n"];
    }

    #[DataProvider('cases')]
    public function testAnyChunkSizeGivesTheSameRows(Dialect $d, string $doc): void
    {
        $expected = $this->outcome($doc, $d, 1 << 20);
        for ($chunk = 1; $chunk <= 17; $chunk++) {
            $this->assertSame($expected, $this->outcome($doc, $d, $chunk), "chunk size $chunk");
            // and through a real stream
            $h = fopen('php://memory', 'w+');
            fwrite($h, $doc);
            rewind($h);
            $this->assertSame($expected, $this->outcome($h, $d, $chunk), "stream, chunk size $chunk");
        }
    }

    /** Rows, or the exception position, so that failures must be reproduced exactly too. */
    private function outcome(mixed $src, Dialect $d, int $chunk): array
    {
        $r = is_string($src) ? Reader::fromString($src, $d) : Reader::fromStream($src, $d);
        try {
            return [$r->withChunkSize($chunk)->toArray()];
        } catch (ParseException $e) {
            return [$e->getMessage()];
        }
    }

    public function testRecordsLargerThanTheChunk(): void
    {
        $big = str_repeat('x,"y', 5000) . '",z';
        $csv = "a,b\n\"$big\",c\n$big\nlast\n";
        $expected = Reader::fromString($csv)->toArray();
        $this->assertSame(['last'], $expected[3] ?? null);
        foreach ([1, 7, 64, 1000] as $chunk) {
            $this->assertSame($expected, Reader::fromString($csv)->withChunkSize($chunk)->toArray());
        }
    }

    public function testMultibyteTokensSplitAtEveryByte(): void
    {
        $d = new Dialect('→', '«', '¶');
        $csv = "a→«b→c«→««««¶é→日本¶";
        $expected = [['a', 'b→c', '«'], ['é', '日本']];
        // chunk sizes 1 and 2 split every three-byte "→" and every two-byte "«" at every possible offset
        foreach ([1, 2, 3, 4, 5] as $chunk) {
            $this->assertSame($expected, Reader::fromString($csv, $d)->withChunkSize($chunk)->toArray());
        }
    }

    // ---- cross-check against the naive reference parser on random input -----------------------

    public static function fuzzDialects(): iterable
    {
        yield 'rfc4180' => [new Dialect(), ['a', 'b', ',', ',', '"', '"', "\n", "\r\n", "\r", ' ', 'é']];
        yield 'skipInitialSpace' => [new Dialect(skipInitialSpace: true), ['a', ',', '"', '"', "\n", ' ', ' ']];
        yield 'semicolon/single quote' => [new Dialect(';', "'", "\n"), ['a', ';', ';', "'", "'", "\n", '"', ',']];
        yield 'multibyte' => [new Dialect('→', '«', '¶'), ['a', '→', '→', '«', '«', '¶', "\n", 'é', "\xE2", "\x86"]];
        yield 'two-byte delimiter' => [new Dialect('::', '[]', "\n"), ['a', '::', ':', '[]', '[', ']', "\n", ',']];
        yield 'self-overlapping delimiter' => [new Dialect('||', "'", "\r\n"), ['a', '||', '|', "'", "'", "\r\n", "\n", "\r"]];
        yield 'custom terminator' => [new Dialect(',', '"', '<>'), ['a', ',', '"', '"', '<>', '<', '>', "\n"]];
        yield 'no quoting' => [new Dialect("\t", '"', "\n", Quoting::NONE), ['a', "\t", '"', '"', "\n", "\r"]];
        yield 'tab, CR' => [new Dialect("\t", '"', "\r"), ['a', "\t", '"', '"', "\r", "\n"]];
    }

    #[DataProvider('fuzzDialects')]
    public function testMatchesReferenceParserOnRandomInput(Dialect $d, array $alphabet): void
    {
        mt_srand(4180);
        $strict = $d->with(strict: true);
        for ($i = 0; $i < 600; $i++) {
            $len = mt_rand(0, 40);
            $doc = '';
            for ($j = 0; $j < $len; $j++) { $doc .= $alphabet[mt_rand(0, count($alphabet) - 1)]; }
            [$expected, $violation] = ReferenceParser::parse($doc, $d);
            $label = 'input ' . json_encode($doc, JSON_INVALID_UTF8_SUBSTITUTE);
            $this->assertSame($expected, Reader::fromString($doc, $d)->toArray(), "lax, $label");
            $chunk = mt_rand(1, 9);
            $this->assertSame($expected, Reader::fromString($doc, $d)->withChunkSize($chunk)->toArray(), "lax, chunk $chunk, $label");
            try {
                $rows = Reader::fromString($doc, $strict)->withChunkSize($chunk)->toArray();
                $this->assertFalse($violation, "strict must reject, $label");
                $this->assertSame($expected, $rows);
            } catch (ParseException) {
                $this->assertTrue($violation, "strict must accept, $label");
            }
        }
    }
}
