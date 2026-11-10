<?php

declare(strict_types=1);

namespace FrodeBorli\Csv\Tests;

use FrodeBorli\Csv\Dialect;
use FrodeBorli\Csv\Quoting;
use FrodeBorli\Csv\Reader;
use FrodeBorli\Csv\Writer;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase
{
    public function testMinimalQuoting(): void
    {
        $row = ['a', 'b,c', 'd"e', "f\ng", "h\ri", "j\r\nk", '', ' x'];
        $this->assertSame("a,\"b,c\",\"d\"\"e\",\"f\ng\",\"h\ri\",\"j\r\nk\",, x\r\n", Writer::rowToString($row));
    }

    public function testWhitespaceNeedsNoQuotingByDefault(): void
    {
        $this->assertSame(" x , y \r\n", Writer::rowToString([' x ', ' y ']));
        $this->assertSame("\" x\",y\r\n", Writer::rowToString([' x', 'y'], new Dialect(skipInitialSpace: true)));
    }

    public function testSingleEmptyFieldIsQuoted(): void
    {
        $this->assertSame("\"\"\r\n", Writer::rowToString(['']));
        $this->assertSame("a,\r\n", Writer::rowToString(['a', '']));
        $this->assertSame(",\r\n", Writer::rowToString(['', '']));
    }

    public function testEmptyRowIsABlankLine(): void
    {
        $this->assertSame("\r\n", Writer::rowToString([]));
    }

    public function testLineTerminatorAndDelimiter(): void
    {
        $this->assertSame("a;b\n", Writer::rowToString(['a', 'b'], new Dialect(';', '"', "\n")));
        $this->assertSame("a\tb\tc\n", Writer::rowToString(['a', 'b', 'c'], Dialect::tsv()));
        $this->assertSame("'a;b';c|", Writer::rowToString(['a;b', 'c'], new Dialect(';', "'", '|')));
        $this->assertSame("a,\"b|c\"|", Writer::rowToString(['a', 'b|c'], new Dialect(',', '"', '|')));
    }

    public function testQuotingAll(): void
    {
        $d = new Dialect(quoting: Quoting::ALL);
        $this->assertSame("\"a\",\"1\",\"\",\"\"\r\n", Writer::rowToString(['a', 1, '', null], $d));
    }

    public function testQuotingNonNumeric(): void
    {
        $d = new Dialect(quoting: Quoting::NONNUMERIC);
        $this->assertSame("\"a\",1,2.5,\"3\",\"\"\r\n", Writer::rowToString(['a', 1, 2.5, '3', null], $d));
    }

    public function testQuotingNoneThrowsWhenQuotingWouldBeNeeded(): void
    {
        $d = new Dialect(quoting: Quoting::NONE);
        $this->assertSame("a,b\"c,d e\r\n", Writer::rowToString(['a', 'b"c', 'd e'], $d)); // the enclosure is not special
        foreach ([['a,b'], ["a\nb"], ["a\rb"], [''], ['x', "a\r\nb"]] as $row) {
            try {
                Writer::rowToString($row, $d);
                $this->fail('expected UnexpectedValueException for ' . json_encode($row));
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\UnexpectedValueException::class);
        Writer::rowToString(["a\tb"], Dialect::tsv());
    }

    public function testNulInFieldsWithEmptyTokenMask(): void
    {
        $d = new Dialect('::', '"', '<>', Quoting::NONE); // no single-byte token at all
        $this->assertSame("a\0b::c<>", Writer::rowToString(["a\0b", 'c'], $d));
    }

    public function testFieldTypes(): void
    {
        $s = new class implements \Stringable { public function __toString(): string { return 'str,able'; } };
        $this->assertSame("1,-2,1.5,0.30000000000000004,1.0,1,,,\"str,able\"\r\n", Writer::rowToString([1, -2, 1.5, 0.1 + 0.2, 1.0, true, false, null, $s]));
    }

    public function testFloatFormattingIsLocaleIndependent(): void
    {
        $old = setlocale(LC_NUMERIC, null);
        if (!setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'nb_NO.UTF-8')) {
            $this->markTestSkipped('no comma-decimal locale installed');
        }
        try {
            $this->assertSame("1.5\r\n", Writer::rowToString([1.5]));
        } finally {
            setlocale(LC_NUMERIC, $old);
        }
    }

    public function testUnsupportedTypesThrow(): void
    {
        $this->expectException(\TypeError::class);
        Writer::rowToString([[1]]);
    }

    public function testBom(): void
    {
        $this->assertSame("\xEF\xBB\xBFa,b\r\n", Writer::rowsToString([['a', 'b']], Dialect::excel()));
        $this->assertSame("a,b\r\n", Writer::rowsToString([['a', 'b']]));
        $this->assertSame([['a', 'b']], Reader::fromString(Writer::rowsToString([['a', 'b']], Dialect::excel()))->toArray());
    }

    public function testFirstFieldStartingWithABomSurvivesRoundTrip(): void
    {
        $rows = [["\xEF\xBB\xBFx", 'y']];
        $this->assertSame($rows, Reader::fromString(Writer::rowsToString($rows))->toArray());
    }

    public function testRowsToStringAcceptsGeneratorsAndIterableRows(): void
    {
        $gen = (function () { yield [1, 2]; yield new \ArrayIterator([3, 4]); })();
        $this->assertSame("1,2\r\n3,4\r\n", Writer::rowsToString($gen));
    }

    // ---- associative rows / headers ----------------------------------------------------------

    public function testAssociativeRowsDeriveTheHeaderFromTheFirstRow(): void
    {
        $rows = [['id' => 1, 'name' => 'Ann'], ['name' => 'Bob', 'id' => 2], ['id' => 3]];
        $this->assertSame("id,name\r\n1,Ann\r\n2,Bob\r\n3,\r\n", Writer::rowsToString($rows));
    }

    public function testExplicitHeader(): void
    {
        $out = Writer::rowsToString([['b' => 2, 'a' => 1], [3, 4]], null, ['a', 'b']);
        $this->assertSame("a,b\r\n1,2\r\n3,4\r\n", $out);
    }

    public function testUnknownKeysThrow(): void
    {
        $this->expectException(\ValueError::class);
        Writer::rowsToString([['a' => 1], ['a' => 1, 'zzz' => 2]]);
    }

    public function testRowToStringNeverWritesAHeader(): void
    {
        $this->assertSame("1,2\r\n", Writer::rowToString(['x' => 1, 'y' => 2]));
    }

    public function testHeaderWithQuotedNames(): void
    {
        $this->assertSame("\"a,b\",c\r\n1,2\r\n", Writer::rowsToString([['a,b' => 1, 'c' => 2]]));
    }

    // ---- streams -----------------------------------------------------------------------------

    public function testStreamsToMemoryInLargeWrites(): void
    {
        $h = fopen('php://memory', 'w+');
        $w = Writer::toStream($h, null, 100);
        $this->assertSame(0, ftell($h));
        $w->writeRow(['a', 'b']);
        $this->assertSame(0, ftell($h), 'a small row stays in the buffer');
        $w->writeRows((function () { for ($i = 0; $i < 100; $i++) { yield [$i, 'row']; } })());
        $this->assertGreaterThan(0, ftell($h), 'flushed when the buffer filled up');
        $w->close();
        $this->assertTrue(is_resource($h), 'a caller-owned stream stays open');
        rewind($h);
        $csv = stream_get_contents($h);
        $this->assertSame(Writer::rowsToString(array_merge([['a', 'b']], array_map(fn($i) => [$i, 'row'], range(0, 99)))), $csv);
    }

    public function testWritesToPhpOutput(): void
    {
        ob_start();
        $w = Writer::toStream(fopen('php://output', 'w'));
        $w->writeRows([['a', 'b'], ['c', "d\ne"]]);
        $w->close();
        $this->assertSame("a,b\r\nc,\"d\ne\"\r\n", ob_get_clean());
    }

    public function testToFileAndAutomaticClose(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        try {
            $w = Writer::toFile($path);
            $w->writeRow(['a', 'b']);
            unset($w); // destructor flushes
            $this->assertSame("a,b\r\n", file_get_contents($path));
            $w = Writer::toFile($path);
            $w->writeRows([[1]]);
            $w->close();
            $w->close();
            $this->assertSame("1\r\n", file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testUnwritableFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Writer::toFile('/nonexistent-dir/x.csv');
    }

    public function testContentsOnlyForMemoryWriters(): void
    {
        $this->expectException(\LogicException::class);
        Writer::toStream(fopen('php://memory', 'w'))->contents();
    }

    // ---- multibyte dialects: tokens that could form across field boundaries -------------------

    public function testMultibyteDialectEnclosesFieldsThatCouldFormTokens(): void
    {
        $d = new Dialect('||', "'", "\r\n");
        // "a|" followed by the delimiter "||" would read as "a" + "||" + "|"
        $this->assertSame("'a|'||b\r\n", Writer::rowToString(['a|', 'b'], $d));
        $this->assertSame("|a||b\r\n", Writer::rowToString(['|a', 'b'], $d)); // a leading fragment is harmless
        $this->assertSame("a||b\r\n", Writer::rowToString(['a', 'b'], $d));
        $this->assertSame("'x||y'||'it''s'\r\n", Writer::rowToString(['x||y', "it's"], $d));
        $d = new Dialect('→', '«', '¶');
        $this->assertSame("é→«x→y«¶", Writer::rowToString(['é', 'x→y'], $d));
    }
}
