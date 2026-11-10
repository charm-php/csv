<?php

declare(strict_types=1);

namespace FrodeBorli\Csv\Tests;

use FrodeBorli\Csv\Dialect;
use FrodeBorli\Csv\ParseException;
use FrodeBorli\Csv\Quoting;
use FrodeBorli\Csv\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    private static function read(string $csv, ?Dialect $d = null): array
    {
        return Reader::fromString($csv, $d)->toArray();
    }

    #[DataProvider('rfc4180')]
    public function testRfc4180Cases(string $csv, array $expected): void
    {
        $this->assertSame($expected, self::read($csv));
        $this->assertSame($expected, self::read($csv, new Dialect(strict: true)));
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
        yield 'ragged rows' => ["a,b,c\n1\n1,2,3,4", [['a', 'b', 'c'], ['1'], ['1', '2', '3', '4']]];
    }

    public function testSpaceBeforeQuoteIsOnlyLaxlyAccepted(): void
    {
        $this->expectException(ParseException::class);
        self::read('a, "b"', new Dialect(strict: true));
    }

    public function testBomIsStripped(): void
    {
        $this->assertSame([['a', 'b']], self::read("\xEF\xBB\xBFa,b\n"));
        $this->assertSame([['a']], self::read("\xEF\xBB\xBF\"a\""));
    }

    public function testBomOnlyAtTheStart(): void
    {
        $this->assertSame([['a'], ["\xEF\xBB\xBFb"]], self::read("a\n\xEF\xBB\xBFb"));
    }

    public function testSkipInitialSpace(): void
    {
        $d = new Dialect(skipInitialSpace: true);
        $this->assertSame([['a', 'b', 'c d ', 'e,f']], self::read('a,   b,c d ,  "e,f"', $d));
        $this->assertSame([['a', ''], ['x', 'y']], self::read("a,   \n  x, y", $d));
        $this->assertSame([['a', '']], self::read('a,   ', $d));
    }

    public function testCustomDialects(): void
    {
        $this->assertSame([['a', 'b;c', 'd']], self::read("a;'b;c';d\n", new Dialect(';', "'")));
        $this->assertSame([['a', 'b', 'c'], ['d', 'e']], self::read("a\tb\tc\r\nd\te", Dialect::tsv()));
        $this->assertSame([['a', 'b'], ['c']], self::read("a\x1eb|c", new Dialect("\x1e", '"', '|')));
    }

    public function testCustomTerminatorIsMatchedExactly(): void
    {
        $d = new Dialect(',', '"', '|');
        $this->assertSame([['a', "b\nc"], ['d']], self::read("a,b\nc|d|", $d));
        $d = new Dialect(',', '"', '<>');
        $this->assertSame([['a', 'b|c'], ['d', "e\n"]], self::read("a,b|c<>d,\"e\n\"<>", $d));
    }

    public function testTsvTreatsQuotesAsLiteral(): void
    {
        $this->assertSame([['5" pipe', '"x'], ['a', 'b']], self::read("5\" pipe\t\"x\na\tb", Dialect::tsv()));
    }

    public function testMultibyteTokens(): void
    {
        $d = new Dialect('→', '«', '¶');
        $this->assertSame([['a', 'b→c', '«'], ['é', '日本']], self::read('a→«b→c«→««««¶é→日本', $d));
    }

    public function testMultibyteDelimiterAndEnclosure(): void
    {
        $d = new Dialect('::', '[]', "\n");
        $this->assertSame([['a', 'b::c', 'x[]y'], ['d', '']], self::read("a::[]b::c[]::[]x[][]y[]\nd::", $d));
    }

    public function testMultibyteSelfOverlappingDelimiter(): void
    {
        // leftmost match wins: "a|||b" is "a|" + "|b"? No: it is "a" + "||" + "|b".
        $this->assertSame([['a', '|b']], self::read('a|||b', new Dialect('||')));
    }

    // ---- strict-mode errors, with positions --------------------------------------------------

    #[DataProvider('errors')]
    public function testStrictErrors(string $csv, string $message, int $line, int $column, int $record): void
    {
        try {
            self::read($csv, new Dialect(strict: true));
            $this->fail('expected ParseException');
        } catch (ParseException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame([$line, $column, $record], [$e->csvLine, $e->column, $e->record]);
        }
    }

    public static function errors(): iterable
    {
        yield 'unterminated' => ["a,b\n\"abc", 'unterminated quoted field', 2, 1, 2];
        yield 'unterminated after fields' => ["a,\"b\nc\nd", 'unterminated quoted field', 1, 3, 1];
        yield 'text after closing quote' => ["a,b\nc,\"d\"x,e", 'unexpected text after closing quote', 2, 6, 2];
        yield 'text after quote, line 4' => ["a\nb\n\"c\nd\"e", 'unexpected text after closing quote', 4, 3, 3];
        yield 'quote in unquoted field' => ["a,b\nab\"c,d", 'unexpected quote in unquoted field', 2, 3, 2];
        yield 'CR line numbering' => ["a\rb\rc\"d", 'unexpected quote', 3, 2, 3];
        yield 'CRLF line numbering' => ["a\r\nb\r\nc\"d", 'unexpected quote', 3, 2, 3];
        yield 'multibyte column counts characters' => ["éé,\"x\"y", 'unexpected text', 1, 7, 1];
        yield 'blank lines count as lines but not records' => ["a\n\n\nb\"", 'unexpected quote', 4, 2, 2];
    }

    public function testStrictErrorPositionAcrossChunks(): void
    {
        $csv = "a,b\n1,2\n3,4\n5,\"6\"x\n";
        foreach ([1, 2, 3, 5, 7, 100] as $chunk) {
            try {
                Reader::fromString($csv, new Dialect(strict: true))->withChunkSize($chunk)->toArray();
                $this->fail('expected ParseException');
            } catch (ParseException $e) {
                $this->assertSame([4, 6, 4], [$e->csvLine, $e->column, $e->record], "chunk $chunk");
            }
        }
    }

    public function testRequireConsistentFields(): void
    {
        $r = Reader::fromString("a,b\n1,2\n3\n");
        $this->assertCount(3, $r->toArray());
        try {
            $r->requireConsistentFields()->toArray();
            $this->fail('expected ParseException');
        } catch (ParseException $e) {
            $this->assertSame([3, 1, 3], [$e->csvLine, $e->column, $e->record]);
            $this->assertStringContainsString('expected 2 fields, found 1', $e->getMessage());
        }
    }

    // ---- lax recovery ------------------------------------------------------------------------

    public function testLaxRecovery(): void
    {
        // unterminated quote: the rest of the input is the field
        $this->assertSame([['a', "b,c\nd"]], self::read("a,\"b,c\nd"));
        $this->assertSame([['a', 'b"']], self::read('a,"b"""'));
        // text after a closing quote is appended up to the next delimiter / terminator, quotes included literally
        $this->assertSame([['abcd', 'e'], ['f']], self::read("\"ab\"cd,e\nf"));
        $this->assertSame([['ab"xy']], self::read('"ab""x"y'));
        $this->assertSame([['abc"d"', 'e']], self::read('"ab"c"d",e'));
        // quotes inside an unquoted field are literal
        $this->assertSame([['5" pipe', 'x"y"z']], self::read('5" pipe,x"y"z'));
    }

    // ---- shapes ------------------------------------------------------------------------------

    public function testAssociativeRows(): void
    {
        $r = Reader::fromString("id,name\n1,Ann\n2,\"Bob, Jr.\"\n")->withHeader();
        $this->assertSame([['id' => '1', 'name' => 'Ann'], ['id' => '2', 'name' => 'Bob, Jr.']], $r->toArray());
        $this->assertSame([[0 => 'x', 1 => 'y']], Reader::fromString("x,y\n")->withHeader(false)->toArray());
    }

    public function testGivenNames(): void
    {
        $r = Reader::fromString("1,Ann\n2,Bob\n")->withHeader(['id', 'name']);
        $this->assertSame([['id' => '1', 'name' => 'Ann'], ['id' => '2', 'name' => 'Bob']], $r->toArray());
        $this->assertSame(['id' => ['1', '2'], 'name' => ['Ann', 'Bob']], $r->columns());
    }

    public function testHeaderOnlyAndEmpty(): void
    {
        $this->assertSame([], Reader::fromString("a,b\n")->withHeader()->toArray());
        $this->assertSame(['a' => [], 'b' => []], Reader::fromString("a,b\n")->withHeader()->columns());
        $this->assertSame([], Reader::fromString('')->withHeader()->toArray());
        $this->assertSame([], Reader::fromString('')->withHeader()->columns());
        $this->assertSame([], Reader::fromString('')->columns());
    }

    public function testRaggedRowsWithHeaderLaxPadsAndTruncates(): void
    {
        $rows = Reader::fromString("a,b,c\n1\n1,2,3,4\n")->withHeader()->toArray();
        $this->assertSame([['a' => '1', 'b' => null, 'c' => null], ['a' => '1', 'b' => '2', 'c' => '3']], $rows);
    }

    public function testRaggedRowsWithHeaderStrictThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('expected 3 fields, found 1 at line 2, column 1 (record 2)');
        Reader::fromString("a,b,c\n1\n", new Dialect(strict: true))->withHeader()->toArray();
    }

    public function testDuplicateAndEmptyHeaders(): void
    {
        $r = Reader::fromString("a,,a,a,b\n1,2,3,4,5\n")->withHeader();
        $this->assertSame([['a' => '1', 'column_2' => '2', 'a_2' => '3', 'a_3' => '4', 'b' => '5']], $r->toArray());
        foreach (["a,a\n1,2", "a,\n1,2"] as $csv) {
            try {
                Reader::fromString($csv, new Dialect(strict: true))->withHeader()->toArray();
                $this->fail('expected ParseException');
            } catch (ParseException $e) {
                $this->assertSame(1, $e->csvLine);
            }
        }
    }

    public function testGivenNamesMustBeUnique(): void
    {
        $this->expectException(\ValueError::class);
        Reader::fromString('1,2')->withHeader(['a', 'a'])->toArray();
    }

    public function testColumnMajor(): void
    {
        $csv = "id,name\n1,Ann\n2,Bob\n3,Cy\n";
        $this->assertSame(['id' => ['1', '2', '3'], 'name' => ['Ann', 'Bob', 'Cy']], Reader::fromString($csv)->withHeader()->columns());
        $this->assertSame([['id', '1', '2', '3'], ['name', 'Ann', 'Bob', 'Cy']], Reader::fromString($csv)->columns());
        foreach ([1, 3, 8] as $chunk) { // pieces from several chunks are concatenated
            $this->assertSame(['id' => ['1', '2', '3'], 'name' => ['Ann', 'Bob', 'Cy']], Reader::fromString($csv)->withChunkSize($chunk)->withHeader()->columns());
        }
        $c = Reader::fromString($csv)->withHeader()->columns();
        $this->assertTrue(array_is_list($c['id']));
    }

    public function testColumnMajorLaxAndStrictWidths(): void
    {
        $this->assertSame([['a', '1', '1'], ['b', null, '2']], Reader::fromString("a,b\n1\n1,2,3")->columns());
        $this->expectException(ParseException::class);
        Reader::fromString("a,b\n1\n", new Dialect(strict: true))->columns();
    }

    public function testCasts(): void
    {
        $csv = "id,price,note\n1,2.5,x\n2,,y\n";
        $casts = ['id' => intval(...), 'price' => floatval(...)];
        $this->assertSame([['id' => 1, 'price' => 2.5, 'note' => 'x'], ['id' => 2, 'price' => 0.0, 'note' => 'y']],
            Reader::fromString($csv)->withHeader()->withCasts($casts)->toArray());
        $this->assertSame(['id' => [1, 2], 'price' => [2.5, 0.0], 'note' => ['x', 'y']],
            Reader::fromString($csv)->withHeader()->withCasts($casts)->columns());
        $this->assertSame([[1, 2], ['a', 'b']], Reader::fromString("1,a\n2,b")->withCasts([0 => intval(...)])->columns());
        $this->assertSame([[1, 'a']], Reader::fromString("1,a")->withCasts([0 => intval(...)])->toArray());
    }

    public function testCastOnUnknownColumnThrows(): void
    {
        $this->expectException(\ValueError::class);
        Reader::fromString("a\n1")->withHeader()->withCasts(['nope' => intval(...)])->toArray();
    }

    public function testNamedCastWithoutHeaderThrows(): void
    {
        $this->expectException(\ValueError::class);
        Reader::fromString("a\n1")->withCasts(['a' => intval(...)])->toArray();
    }

    // ---- sources -----------------------------------------------------------------------------

    public function testFileAndStreamSources(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "a,b\n1,2\n");
        try {
            $this->assertSame([['a', 'b'], ['1', '2']], Reader::fromFile($path)->toArray());
            $r = Reader::fromFile($path); // re-iterable
            $this->assertSame($r->toArray(), $r->toArray());
            $h = fopen($path, 'rb');
            $this->assertSame([['a', 'b'], ['1', '2']], Reader::fromStream($h)->toArray());
            $this->assertTrue(is_resource($h), 'a caller-owned stream is not closed');
            fclose($h);
        } finally {
            unlink($path);
        }
    }

    public function testStreamIsReadFromItsCurrentPosition(): void
    {
        $h = fopen('php://memory', 'w+');
        fwrite($h, "skip me\na,b\n");
        rewind($h);
        fgets($h);
        $this->assertSame([['a', 'b']], Reader::fromStream($h)->toArray());
    }

    public function testMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Reader::fromFile('/nonexistent/file.csv')->toArray();
    }

    public function testIsLazy(): void
    {
        $h = fopen('php://memory', 'w+');
        fwrite($h, str_repeat("a,b\n", 1000));
        rewind($h);
        $it = Reader::fromStream($h)->withChunkSize(16)->getIterator();
        $this->assertSame(['a', 'b'], $it->current());
        $this->assertLessThan(200, ftell($h), 'only a few chunks may have been read');
    }

    public function testFileHandleIsClosedWhenIterationIsAbandoned(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, str_repeat("a,b\n", 100));
        try {
            $it = Reader::fromFile($path)->withChunkSize(8)->getIterator();
            $it->current();
            unset($it); // generator destroyed: finally block closes the file
            $this->addToAssertionCount(1);
        } finally {
            unlink($path);
        }
    }

    public function testInvalidArguments(): void
    {
        $this->expectException(\ValueError::class);
        Reader::fromString('a')->withChunkSize(0);
    }

    public function testRowsAreYieldedWithSequentialKeys(): void
    {
        $keys = [];
        foreach (Reader::fromString("a\nb\nc\nd\n")->withChunkSize(2) as $k => $_) { $keys[] = $k; }
        $this->assertSame([0, 1, 2, 3], $keys);
    }

    public function testQuotingNoneReadsQuotesLiterally(): void
    {
        $this->assertSame([['"a"', 'b']], self::read('"a",b', new Dialect(quoting: Quoting::NONE)));
    }
}
