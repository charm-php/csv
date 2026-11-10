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

final class DecoderTest extends TestCase
{
    private static function lax(string $csv, ?Format $f = null): array
    {
        return Support::decode($csv, $f);
    }

    #[DataProvider('rfc4180')]
    public function testRfc4180Cases(string $csv, array $expected): void
    {
        $this->assertSame($expected, Support::decode($csv, null, false, Mode::Lax));
        $this->assertSame($expected, Support::decode($csv, null, false, Mode::Strict));
    }

    public static function rfc4180(): iterable
    {
        yield 'simple' => ["a,b,c\r\n1,2,3\r\n", [['a', 'b', 'c'], ['1', '2', '3']]];
        yield 'no trailing newline' => ["a,b\r\n1,2", [['a', 'b'], ['1', '2']]];
        yield 'LF' => ["a,b\n1,2\n", [['a', 'b'], ['1', '2']]];
        yield 'CR only' => ["a,b\r1,2\r", [['a', 'b'], ['1', '2']]];
        yield 'mixed endings' => ["a\r\nb\nc\rd", [['a'], ['b'], ['c'], ['d']]];
        yield 'empty input' => ['', []];
        yield 'only a newline' => ["\r\n", []];
        yield 'blank lines are skipped' => ["a\n\n\nb\n\n", [['a'], ['b']]];
        yield 'empty fields' => [",,\n,x,", [['', '', ''], ['', 'x', '']]];
        yield 'trailing delimiter at EOF' => ['a,', [['a', '']]];
        yield 'single empty field must be quoted' => ["\"\"\n\"\"\n", [[''], ['']]];
        yield 'quoted' => ['"a","b"' . "\n", [['a', 'b']]];
        yield 'quoted delimiter' => ['"a,b",c', [['a,b', 'c']]];
        yield 'quoted newline' => ["\"a\r\nb\",c\n", [["a\r\nb", 'c']]];
        yield 'quoted CR and LF kept' => ["\"a\nb\rc\"", [["a\nb\rc"]]];
        yield 'doubled quotes' => ['"say ""hi""",x', [['say "hi"', 'x']]];
        yield 'quote only' => ['""""', [['"']]];
        yield 'two doubled quotes' => ['"""",""""""', [['"', '""']]];
        yield 'empty quoted fields' => ['"","",""', [['', '', '']]];
        yield 'quoted field at EOF' => ['a,"b"', [['a', 'b']]];
        yield 'whitespace is data' => [" a , b \n", [[' a ', ' b ']]];
        yield 'space before quote is data' => ['a, b', [['a', ' b']]];
        yield 'multibyte data' => ["é,日本語,😀\n", [['é', '日本語', '😀']]];
        yield 'NUL bytes' => ["a\0b,c", [["a\0b", 'c']]];
        yield 'backslash is data' => ['a\\b,"c\\"', [['a\\b', 'c\\']]];
    }

    /** Regression: before PHP 8.4, strcspn() with an empty mask stops at the first NUL byte. */
    public function testNulBytesWithMultibyteDelimiterAndEol(): void
    {
        $f = new Format('::', '"', '<>');
        $this->assertSame([["a\0b", "c\0"], ["\0", "<"]], self::lax("a\0b::c\0<>\0::<", $f));
        $this->assertSame([["<\0"]], self::lax("<\0", new Format(',', '"', '<>')));
        $this->assertSame([["a\0b", 'c']], self::lax("a\0b::\"c\"", $f));
    }

    public function testBomIsStripped(): void
    {
        $this->assertSame([['a', 'b']], self::lax("\xEF\xBB\xBFa,b\n"));
        $this->assertSame([['a']], self::lax("\xEF\xBB\xBF\"a\""));
        $this->assertSame([['a'], ["\xEF\xBB\xBFb"]], self::lax("a\n\xEF\xBB\xBFb"), 'only at the very start');
    }

    public function testBomArrivingInPieces(): void
    {
        $d = new Decoder();
        $this->assertSame([], $d->decode("\xEF"));
        $this->assertSame([], $d->decode("\xBB"));
        $this->assertSame([['a']], $d->decode("\xBFa\n"));
        // a lone partial BOM at the end of the input is data
        $d = new Decoder();
        $this->assertSame([], $d->decode("\xEF\xBB"));
        $this->assertSame([["\xEF\xBB"]], $d->finish());
        // and only once per input
        $d = new Decoder();
        $d->decode("\xEF\xBB\xBF");
        $this->assertSame([["\xEF\xBB\xBFa"]], [...$d->decode("\xEF\xBB\xBFa"), ...$d->finish()]);
    }

    public function testSkipInitialSpace(): void
    {
        $f = new Format(skipInitialSpace: true);
        $this->assertSame([['a', 'b', 'c d ', 'e,f']], self::lax('a,   b,c d ,  "e,f"', $f));
        $this->assertSame([['a', ''], ['x', 'y']], self::lax("a,   \n  x, y", $f));
        $this->assertSame([['a', '']], self::lax('a,   ', $f));
    }

    public function testCustomFormats(): void
    {
        $this->assertSame([['a', 'b;c', 'd']], self::lax("a;'b;c';d\n", new Format(';', "'")));
        $this->assertSame([['a', 'b', 'c'], ['d', 'e']], self::lax("a\tb\tc\r\nd\te", Format::tsv()));
        $this->assertSame([['a', 'b'], ['c']], self::lax("a\x1eb|c", new Format("\x1e", '"', '|')));
    }

    public function testCustomEolIsMatchedExactly(): void
    {
        $f = new Format(',', '"', '|');
        $this->assertSame([['a', "b\nc"], ['d']], self::lax("a,b\nc|d|", $f));
        $f = new Format(',', '"', '<>');
        $this->assertSame([['a', 'b|c'], ['d', "e\n"]], self::lax("a,b|c<>d,\"e\n\"<>", $f));
    }

    public function testNeverQuotingReadsQuotesLiterally(): void
    {
        $this->assertSame([['5" pipe', '"x'], ['a', 'b']], self::lax("5\" pipe\t\"x\na\tb", Format::tsv()));
        $this->assertSame([['"a"', 'b']], self::lax('"a",b', new Format(quoting: Quoting::Never)));
        $this->assertSame([['"a"', 'b']], Support::decode('"a",b', new Format(quoting: Quoting::Never), false, Mode::Strict));
    }

    public function testMultibyteTokens(): void
    {
        $f = new Format('→', '«', '¶');
        $this->assertSame([['a', 'b→c', '«'], ['é', '日本']], self::lax('a→«b→c«→««««¶é→日本', $f));
        $f = new Format('::', '[]', "\n");
        $this->assertSame([['a', 'b::c', 'x[]y'], ['d', '']], self::lax("a::[]b::c[]::[]x[][]y[]\nd::", $f));
        // leftmost match wins: "a|||b" is "a" + "||" + "|b"
        $this->assertSame([['a', '|b']], self::lax('a|||b', new Format('||')));
    }

    // ---- the incremental protocol ------------------------------------------------------------

    public function testDecodeReturnsRecordsCompletedByTheChunk(): void
    {
        $d = new Decoder();
        $this->assertSame([], $d->decode('a,b'));
        $this->assertSame([['a', 'b1']], $d->decode("1\r\nc"));
        $this->assertSame([['c', 'd']], $d->decode(",d\n"));
        $this->assertSame([], $d->decode(''));
        $this->assertSame([], $d->finish());
    }

    public function testARecordIsReleasedAsSoonAsItIsKnownToBeComplete(): void
    {
        $d = new Decoder(mode: Mode::Lax);
        $this->assertSame([["aaa"]], $d->decode("aaa\n")); // (the first three bytes are held back to look for a BOM)
        $this->assertSame([['b', 'c']], $d->decode("b,\"c\"\n"));
        $this->assertSame([], $d->decode("d\r"), 'a lone CR may be the first half of CRLF');
        $this->assertSame([['d']], $d->decode("\n"));
        $this->assertSame([], $d->decode('e'));
        $this->assertSame([['e']], $d->finish());
    }

    public function testFinishReturnsTheLastRecordAndResets(): void
    {
        $d = new Decoder(header: true);
        $this->assertSame([['id' => '1']], $d->decode("id\n1\n2"));
        $this->assertSame([['id' => '2']], $d->finish());
        // reuse: a fresh header, fresh line counting
        $this->assertSame([['x' => '9']], [...$d->decode("x\n9\n"), ...$d->finish()]);
        $this->assertSame([], $d->finish());
    }

    public function testFinishResetsPositionsAndBom(): void
    {
        $d = new Decoder();
        $d->decode("a\nb\n");
        $d->finish();
        $this->assertSame([['a']], [...$d->decode("\xEF\xBB\xBFa\n"), ...$d->finish()]);
        try {
            $d->decode("x\"y");
            $d->finish();
            $this->fail('expected ParseException');
        } catch (ParseException $e) {
            $this->assertSame([1, 2, 1], [$e->lineNumber, $e->column, $e->record]);
        }
    }

    public function testFinishWithoutInput(): void
    {
        $this->assertSame([], (new Decoder())->finish());
        $this->assertSame([], (new Decoder(header: true))->finish());
    }

    // ---- Strict errors, with positions -------------------------------------------------------

    #[DataProvider('errors')]
    public function testStrictErrors(string $csv, string $message, int $line, int $column, int $record): void
    {
        foreach ([0, 1, 3] as $chunk) { // the position must not depend on chunking
            try {
                Support::decode($csv, null, false, Mode::Strict, $chunk);
                $this->fail('expected ParseException');
            } catch (ParseException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
                $this->assertSame([$line, $column, $record], [$e->lineNumber, $e->column, $e->record], "chunk $chunk");
                $this->assertStringContainsString("line $line, column $column", $e->getMessage());
            }
        }
    }

    public static function errors(): iterable
    {
        yield 'unterminated' => ["a,b\n\"abc", 'unterminated quoted field', 2, 1, 2];
        yield 'unterminated after fields' => ["a,\"b\nc\nd", 'unterminated quoted field', 1, 3, 1];
        yield 'text after closing quote' => ["a,b\nc,\"d\"x,e", 'unexpected text after closing quote', 2, 6, 2];
        yield 'text after quote, line 4' => ["a\nb\n\"c\nd\"e", 'unexpected text after closing quote', 4, 3, 3];
        yield 'quote in unquoted field' => ["a,b\nab\"c,d", 'unexpected quote in unquoted field', 2, 3, 2];
        yield 'space before quote' => ['a, "b"', 'unexpected quote in unquoted field', 1, 4, 1];
        yield 'CR line numbering' => ["a\rb\rc\"d", 'unexpected quote', 3, 2, 3];
        yield 'CRLF line numbering' => ["a\r\nb\r\nc\"d", 'unexpected quote', 3, 2, 3];
        yield 'multibyte column counts characters' => ["éé,\"x\"y", 'unexpected text', 1, 7, 1];
        yield 'blank lines count as lines but not records' => ["a\n\n\nb\"", 'unexpected quote', 4, 2, 2];
        yield 'too few fields' => ["a,b\n1,2\n3\n", 'expected 2 fields, found 1', 3, 1, 3];
        yield 'too many fields' => ["a,b\n1,2,3\n", 'expected 2 fields, found 3', 2, 1, 2];
        yield 'field count after a multiline record' => ["a,b\n\"x\ny\",2\n3\n", 'expected 2 fields, found 1', 4, 1, 3];
    }

    public function testStrictErrorPositionWithCustomEol(): void
    {
        $f = new Format(',', '"', '<>');
        try {
            Support::decode("a<>b<>c\"d", $f, false, Mode::Strict, 2);
            $this->fail('expected ParseException');
        } catch (ParseException $e) {
            $this->assertSame([1 + 2, 2, 3], [$e->lineNumber, $e->column, $e->record]);
        }
    }

    public function testStrictErrorFromFinishAndFromDecode(): void
    {
        $d = new Decoder();
        $d->decode("a\n\"b");
        try {
            $d->finish();
            $this->fail('expected ParseException');
        } catch (ParseException $e) {
            $this->assertSame([2, 1, 2], [$e->lineNumber, $e->column, $e->record]);
        }
        $d = new Decoder();
        $this->expectException(ParseException::class);
        $d->decode("a\nb\"c\n");
    }

    public function testStrictIsTheDefault(): void
    {
        $this->expectException(ParseException::class);
        (new Decoder())->decode("a\"b\n");
    }

    // ---- Lax recovery ------------------------------------------------------------------------

    public function testLaxRecovery(): void
    {
        // unterminated quote: the rest of the input is the field
        $this->assertSame([['a', "b,c\nd"]], self::lax("a,\"b,c\nd"));
        $this->assertSame([['a', 'b"']], self::lax('a,"b"""'));
        // text after a closing quote is appended up to the next delimiter / EOL, quotes included literally
        $this->assertSame([['abcd', 'e'], ['f']], self::lax("\"ab\"cd,e\nf"));
        $this->assertSame([['ab"xy']], self::lax('"ab""x"y'));
        $this->assertSame([['abc"d"', 'e']], self::lax('"ab"c"d",e'));
        // quotes inside an unquoted field are literal
        $this->assertSame([['5" pipe', 'x"y"z']], self::lax('5" pipe,x"y"z'));
        $this->assertSame([['a', ' "b"']], self::lax('a, "b"'));
        // ragged records are fine
        $this->assertSame([['a', 'b', 'c'], ['1'], ['1', '2', '3', '4']], self::lax("a,b,c\n1\n1,2,3,4"));
    }

    // ---- header ------------------------------------------------------------------------------

    public function testHeaderTrue(): void
    {
        $rows = Support::decode("id,name\n1,Ann\n2,\"Bob, Jr.\"\n", null, true, Mode::Strict);
        $this->assertSame([['id' => '1', 'name' => 'Ann'], ['id' => '2', 'name' => 'Bob, Jr.']], $rows);
    }

    public function testHeaderFalseGivesLists(): void
    {
        $this->assertSame([['x', 'y']], Support::decode("x,y\n", null, false));
    }

    public function testGivenNames(): void
    {
        $rows = Support::decode("1,Ann\n2,Bob\n", null, ['id', 'name'], Mode::Strict);
        $this->assertSame([['id' => '1', 'name' => 'Ann'], ['id' => '2', 'name' => 'Bob']], $rows);
    }

    public function testGivenNamesAreReindexed(): void
    {
        $this->assertSame([['a' => '1', 'b' => '2']], Support::decode('1,2', null, [5 => 'a', 9 => 'b']));
    }

    public function testGivenNamesMustBeUniqueAndNonEmpty(): void
    {
        foreach ([['a', 'a'], ['a', ''], []] as $names) {
            try {
                new Decoder(header: $names);
                $this->fail('expected ValueError for ' . json_encode($names));
            } catch (\ValueError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHeaderOnlyAndEmpty(): void
    {
        $this->assertSame([], Support::decode("a,b\n", null, true));
        $this->assertSame([], Support::decode("a,b", null, true));
        $this->assertSame([], Support::decode('', null, true));
    }

    public function testHeaderAcrossChunks(): void
    {
        $d = new Decoder(header: true);
        $this->assertSame([], $d->decode('a,'));
        $this->assertSame([], $d->decode("b\n"));
        $this->assertSame([['a' => '1', 'b' => '2']], $d->decode("1,2\n"));
    }

    public function testNumericHeaderNamesBecomeIntegerKeys(): void
    {
        $this->assertSame([[1 => 'x', 2 => 'y']], Support::decode("1,2\nx,y", null, true));
    }

    public function testLaxPadsShortAndKeepsExtraFieldsUnderIntegerKeys(): void
    {
        $rows = Support::decode("a,b,c\n1\n1,2,3,4,5\n", null, true, Mode::Lax);
        $this->assertSame([
            ['a' => '1', 'b' => null, 'c' => null],
            ['a' => '1', 'b' => '2', 'c' => '3', 3 => '4', 4 => '5'],
        ], $rows);
    }

    public function testStrictRejectsFieldCountMismatchWithHeader(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('expected 3 fields, found 1 at line 2, column 1 (record 2)');
        Support::decode("a,b,c\n1\n", null, true, Mode::Strict);
    }

    public function testStrictChecksGivenNamesAgainstTheFirstRecord(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('expected 2 fields, found 3 at line 1');
        Support::decode("1,2,3\n", null, ['a', 'b'], Mode::Strict);
    }

    public function testDuplicateAndEmptyHeaderNames(): void
    {
        $rows = Support::decode("a,,a,a,b\n1,2,3,4,5\n", null, true, Mode::Lax);
        $this->assertSame([['a' => '1', 'column_2' => '2', 'a_2' => '3', 'a_3' => '4', 'b' => '5']], $rows);
        foreach (["a,a\n1,2", "a,\n1,2"] as $csv) {
            try {
                Support::decode($csv, null, true, Mode::Strict);
                $this->fail('expected ParseException');
            } catch (ParseException $e) {
                $this->assertSame([1, 1, 1], [$e->lineNumber, $e->column, $e->record]);
            }
        }
    }

    public function testHeaderMatchesManualArrayCombine(): void
    {
        $csv = "a,b,c\n1,2,3\n4,5,6\n";
        $lists = Support::decode($csv);
        $names = array_shift($lists);
        $this->assertSame(array_map(fn($r) => array_combine($names, $r), $lists), Support::decode($csv, null, true));
    }

    // ---- null representation -----------------------------------------------------------------

    public function testNullRepresentation(): void
    {
        $f = new Format(null: '\N');
        $this->assertSame([['a', null, ''], [null]], Support::decode("a,\\N,\n\\N\n", $f));
        $this->assertSame([['x' => null, 'y' => 'z']], Support::decode("x,y\n\\N,z\n", $f, true));
    }

    public function testEmptyStringAsNullRepresentation(): void
    {
        $this->assertSame([[null, 'a', null]], Support::decode(',a,', new Format(null: '')));
    }

    // ---- misc --------------------------------------------------------------------------------

    public function testLargeInputInOneChunk(): void
    {
        $rows = [];
        for ($i = 0; $i < 5000; $i++) { $rows[] = [(string) $i, "q\"$i", "m\n$i"]; }
        $csv = Support::encode($rows);
        $this->assertSame($rows, Support::decode($csv, null, false, Mode::Strict));
    }

    public function testRecordsLargerThanTheChunk(): void
    {
        $big = str_repeat('x,"y', 1500) . '",z';
        $csv = "a,b\n\"$big\",c\n$big\nlast\n";
        $expected = Support::decode($csv);
        $this->assertSame(['last'], $expected[3] ?? null);
        foreach ([7, 64, 1000] as $chunk) {
            $this->assertSame($expected, Support::decode($csv, null, false, Mode::Lax, $chunk));
        }
    }

    public function testStreamLoop(): void
    {
        $h = fopen('php://memory', 'w+');
        fwrite($h, "a,b\n1,2\n3,\"4\n5\"");
        rewind($h);
        $decoder = new Decoder(header: true);
        $rows = [];
        while (!feof($h)) {
            foreach ($decoder->decode(fread($h, 4)) as $row) { $rows[] = $row; }
        }
        foreach ($decoder->finish() as $row) { $rows[] = $row; }
        $this->assertSame([['a' => '1', 'b' => '2'], ['a' => '3', 'b' => "4\n5"]], $rows);
    }
}
