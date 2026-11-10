<?php

declare(strict_types=1);

namespace Charm\Csv\Tests;

use Charm\Csv\Encoder;
use Charm\Csv\Format;
use Charm\Csv\Mode;
use Charm\Csv\Quoting;
use PHPUnit\Framework\TestCase;

enum Suit: string { case Hearts = 'H'; case Spades = 'S,'; }
enum Level: int { case Low = 1; case High = 10; }
enum Pure { case A; }

final class EncoderTest extends TestCase
{
    private static function row(array $row, ?Format $f = null): string
    {
        return (new Encoder($f ?? new Format()))->encode($row);
    }

    public function testMinimalQuoting(): void
    {
        $row = ['a', 'b,c', 'd"e', "f\ng", "h\ri", "j\r\nk", '', ' x'];
        $this->assertSame("a,\"b,c\",\"d\"\"e\",\"f\ng\",\"h\ri\",\"j\r\nk\",, x\r\n", self::row($row));
    }

    public function testWhitespaceNeedsNoQuotingByDefault(): void
    {
        $this->assertSame(" x , y \r\n", self::row([' x ', ' y ']));
        $this->assertSame("\" x\",y\r\n", self::row([' x', 'y'], new Format(skipInitialSpace: true)));
    }

    public function testSingleEmptyFieldIsQuoted(): void
    {
        $this->assertSame("\"\"\r\n", self::row(['']));
        $this->assertSame("a,\r\n", self::row(['a', '']));
        $this->assertSame(",\r\n", self::row(['', '']));
        $this->assertSame("\"\"\r\n", self::row([null]));
    }

    public function testEmptyRowIsABlankLine(): void
    {
        $this->assertSame("\r\n", self::row([]));
    }

    public function testEolAndDelimiter(): void
    {
        $this->assertSame("a;b\n", self::row(['a', 'b'], new Format(';', '"', "\n")));
        $this->assertSame("a\tb\tc\n", self::row(['a', 'b', 'c'], Format::tsv()));
        $this->assertSame("'a;b';c|", self::row(['a;b', 'c'], new Format(';', "'", '|')));
        $this->assertSame("a,\"b|c\"|", self::row(['a', 'b|c'], new Format(',', '"', '|')));
        $this->assertSame("a\nb\n", (function () { $e = new Encoder(Format::unix()); return $e->encode(['a']) . $e->encode(['b']); })());
    }

    public function testQuotingAll(): void
    {
        $f = new Format(quoting: Quoting::All);
        $this->assertSame("\"a\",\"1\",\"\",\"\"\r\n", self::row(['a', 1, '', null], $f));
    }

    public function testQuotingNonNumeric(): void
    {
        $f = new Format(quoting: Quoting::NonNumeric);
        $this->assertSame("\"a\",1,2.5,\"3\",\"\",\"1\"\r\n", self::row(['a', 1, 2.5, '3', null, true], $f));
        $this->assertSame("10,\"H\"\r\n", self::row([Level::High, Suit::Hearts], $f));
    }

    public function testQuotingNeverThrowsWhenQuotingWouldBeNeeded(): void
    {
        $f = new Format(quoting: Quoting::Never);
        $this->assertSame("a,b\"c,d e\r\n", self::row(['a', 'b"c', 'd e'], $f)); // the enclosure is not special
        foreach ([['a,b'], ["a\nb"], ["a\rb"], [''], ['x', "a\r\nb"]] as $row) {
            try {
                self::row($row, $f);
                $this->fail('expected UnexpectedValueException for ' . json_encode($row));
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\UnexpectedValueException::class);
        self::row(["a\tb"], Format::tsv());
    }

    public function testNulInFieldsWithEmptyTokenMask(): void
    {
        $f = new Format('::', '"', '<>', Quoting::Never); // no single-byte token at all
        $this->assertSame("a\0b::c<>", self::row(["a\0b", 'c'], $f));
    }

    // ---- field conversion --------------------------------------------------------------------

    public function testFieldTypes(): void
    {
        $this->assertSame("1,-2,1.5,0.30000000000000004,1.0,1,0,,x\r\n", self::row([1, -2, 1.5, 0.1 + 0.2, 1.0, true, false, null, 'x']));
    }

    public function testFloatsRoundTripExactly(): void
    {
        mt_srand(5);
        foreach ([0.1, 1 / 3, 1e100, 1.7976931348623157e308, 5e-324, -0.0, 123456789.123456789, M_PI] as $x) {
            $this->assertSame($x, (float) rtrim(self::row([$x])), "float $x");
        }
        for ($i = 0; $i < 200; $i++) {
            $x = unpack('d', random_bytes(8))[1];
            if (is_finite($x)) {
                $this->assertSame($x, (float) rtrim(self::row([$x])));
            }
        }
    }

    public function testBackedEnumsBecomeTheirValue(): void
    {
        $this->assertSame("H,\"S,\",1,10\r\n", self::row([Suit::Hearts, Suit::Spades, Level::Low, Level::High]));
    }

    public function testNullUsesTheFormatNullText(): void
    {
        $this->assertSame("a,,\r\n", self::row(['a', null, '']), 'default: an empty field');
        $this->assertSame("a,\\N,\r\n", self::row(['a', null, ''], new Format(null: '\N')));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupported')]
    public function testUnsupportedTypesThrowTypeError(mixed $value): void
    {
        $this->expectException(\TypeError::class);
        self::row(['ok', $value]);
    }

    public static function unsupported(): iterable
    {
        yield 'array' => [[1]];
        yield 'object' => [new \stdClass()];
        yield 'Stringable' => [new class implements \Stringable { public function __toString(): string { return 's'; } }];
        yield 'pure enum' => [Pure::A];
        yield 'resource' => [fopen('php://memory', 'r')];
        yield 'closure' => [fn() => 1];
    }

    public function testFloatFormattingIsLocaleIndependent(): void
    {
        $old = setlocale(LC_NUMERIC, '0');
        if (!setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'nb_NO.UTF-8')) {
            $this->markTestSkipped('no comma-decimal locale installed');
        }
        try {
            $this->assertSame("1.5\r\n", self::row([1.5]));
        } finally {
            setlocale(LC_NUMERIC, $old);
        }
    }

    // ---- BOM ---------------------------------------------------------------------------------

    public function testBomIsWrittenOnceBeforeTheFirstRecord(): void
    {
        $e = new Encoder(Format::excel());
        $this->assertSame("\xEF\xBB\xBFa,b\r\n", $e->encode(['a', 'b']));
        $this->assertSame("c,d\r\n", $e->encode(['c', 'd']));
        $this->assertSame("a,b\r\n", self::row(['a', 'b']));
    }

    public function testBomIsNotRepeatedAfterAnEmptyFirstRow(): void
    {
        $e = new Encoder(Format::excel());
        $this->assertSame("\xEF\xBB\xBF\r\n", $e->encode([]));
        $this->assertSame("a\r\n", $e->encode(['a']));
    }

    public function testBomWithHeaderComesFirst(): void
    {
        $e = new Encoder(Format::excel(), ['a']);
        $this->assertSame("\xEF\xBB\xBFa\r\n1\r\n", $e->encode([1]));
    }

    public function testFirstFieldStartingWithABomSurvivesRoundTrip(): void
    {
        $rows = [["\xEF\xBB\xBFx", 'y']];
        $this->assertSame($rows, Support::decode(Support::encode($rows), null, false, Mode::Strict));
    }

    // ---- header ------------------------------------------------------------------------------

    public function testHeaderTrueDerivesNamesFromTheFirstRow(): void
    {
        $rows = [['id' => 1, 'name' => 'Ann'], ['name' => 'Bob', 'id' => 2], ['id' => 3]];
        $this->assertSame("id,name\r\n1,Ann\r\n2,Bob\r\n3,\r\n", Support::encode($rows, null, true));
    }

    public function testHeaderIsEmittedByTheFirstCall(): void
    {
        $e = new Encoder(header: true);
        $this->assertSame("a,b\r\n1,2\r\n", $e->encode(['a' => 1, 'b' => 2]));
        $this->assertSame("3,4\r\n", $e->encode(['a' => 3, 'b' => 4]));
    }

    public function testGivenHeader(): void
    {
        $this->assertSame("a,b\r\n1,2\r\n3,4\r\n", Support::encode([['b' => 2, 'a' => 1], [3, 4]], null, ['a', 'b']));
    }

    public function testGivenHeaderIsReindexed(): void
    {
        $this->assertSame("a,b\r\n2,1\r\n", Support::encode([['b' => 1, 'a' => 2]], null, [3 => 'a', 7 => 'b']));
    }

    public function testNullForMissingKeysUsesTheNullText(): void
    {
        $this->assertSame("a,b\r\n1,\\N\r\n", Support::encode([['a' => 1]], new Format(null: '\N'), ['a', 'b']));
    }

    public function testUnknownKeysThrow(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('zzz');
        Support::encode([['a' => 1], ['a' => 1, 'zzz' => 2]], null, true);
    }

    public function testHeaderTrueNeedsAnAssociativeFirstRow(): void
    {
        $this->expectException(\ValueError::class);
        (new Encoder(header: true))->encode([1, 2]);
    }

    public function testWithoutAHeaderRowsAreWrittenAsTheyAre(): void
    {
        $this->assertSame("1,2\r\n", self::row(['x' => 1, 'y' => 2]));
    }

    public function testHeaderNamesAreQuotedLikeFields(): void
    {
        $this->assertSame("\"a,b\",c\r\n1,2\r\n", Support::encode([['a,b' => 1, 'c' => 2]], null, true));
    }

    public function testHeaderThenListRowsAreWrittenAsGiven(): void
    {
        $this->assertSame("a,b\r\n1,2\r\n", Support::encode([[1, 2]], null, ['a', 'b']));
    }

    // ---- streams -----------------------------------------------------------------------------

    public function testWritingToAStream(): void
    {
        $h = fopen('php://memory', 'w+');
        $encoder = new Encoder(header: true);
        foreach ([['a' => 1, 'b' => 'x,y'], ['a' => 2, 'b' => "z\n"]] as $row) {
            fwrite($h, $encoder->encode($row));
        }
        rewind($h);
        $this->assertSame("a,b\r\n1,\"x,y\"\r\n2,\"z\n\"\r\n", stream_get_contents($h));
    }

    // ---- multibyte formats: tokens that could form across field boundaries -------------------

    public function testMultibyteFormatEnclosesFieldsThatCouldFormTokens(): void
    {
        $f = new Format('||', "'", "\r\n");
        // "a|" followed by the delimiter "||" would read as "a" + "||" + "|"
        $this->assertSame("'a|'||b\r\n", self::row(['a|', 'b'], $f));
        $this->assertSame("|a||b\r\n", self::row(['|a', 'b'], $f)); // a leading fragment is harmless
        $this->assertSame("a||b\r\n", self::row(['a', 'b'], $f));
        $this->assertSame("'x||y'||'it''s'\r\n", self::row(['x||y', "it's"], $f));
        $f = new Format('→', '«', '¶');
        $this->assertSame("é→«x→y«¶", self::row(['é', 'x→y'], $f));
    }

    public function testEncoderStateIsPerInstance(): void
    {
        $a = new Encoder(Format::excel());
        $b = new Encoder(Format::excel());
        $a->encode(['x']);
        $this->assertSame("\xEF\xBB\xBFx\r\n", $b->encode(['x']));
    }
}
