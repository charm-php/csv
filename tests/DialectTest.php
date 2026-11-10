<?php

declare(strict_types=1);

namespace FrodeBorli\Csv\Tests;

use FrodeBorli\Csv\Dialect;
use FrodeBorli\Csv\Quoting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DialectTest extends TestCase
{
    public function testDefaultsAreRfc4180(): void
    {
        $d = new Dialect();
        $this->assertSame([',', '"', "\r\n", Quoting::MINIMAL, false, false, false],
            [$d->delimiter, $d->enclosure, $d->lineTerminator, $d->quoting, $d->skipInitialSpace, $d->strict, $d->bom]);
        $this->assertEquals($d, Dialect::rfc4180());
    }

    public function testPresets(): void
    {
        $this->assertTrue(Dialect::excel()->bom);
        $this->assertSame("\t", Dialect::tsv()->delimiter);
        $this->assertSame(Quoting::NONE, Dialect::tsv()->quoting);
        $this->assertSame("\n", Dialect::unix()->lineTerminator);
    }

    public function testWithReplacesOnlyTheGivenArguments(): void
    {
        $d = Dialect::rfc4180()->with(delimiter: ';', strict: true);
        $this->assertSame(';', $d->delimiter);
        $this->assertTrue($d->strict);
        $this->assertSame('"', $d->enclosure);
    }

    public function testUniversalEol(): void
    {
        $this->assertTrue((new Dialect())->universalEol());
        $this->assertTrue((new Dialect(lineTerminator: "\r"))->universalEol());
        $this->assertFalse((new Dialect(lineTerminator: "\x1e"))->universalEol());
    }

    #[DataProvider('invalid')]
    public function testRejectsEmptyAndAmbiguousDialects(array $args): void
    {
        $this->expectException(\ValueError::class);
        new Dialect(...$args);
    }

    public static function invalid(): iterable
    {
        yield 'empty delimiter' => [['delimiter' => '']];
        yield 'empty enclosure' => [['enclosure' => '']];
        yield 'empty terminator' => [['lineTerminator' => '']];
        yield 'delimiter == enclosure' => [['delimiter' => '"']];
        yield 'delimiter prefix of enclosure' => [['delimiter' => '|', 'enclosure' => '||']];
        yield 'enclosure prefix of delimiter' => [['delimiter' => '::', 'enclosure' => ':']];
        yield 'enclosure suffix of delimiter' => [['delimiter' => '>>', 'enclosure' => '>']];
        yield 'delimiter is newline' => [['delimiter' => "\n"]];
        yield 'delimiter contains CR' => [['delimiter' => "a\rb"]];
        yield 'enclosure is newline' => [['enclosure' => "\n"]];
        yield 'custom EOL equals delimiter' => [['delimiter' => ';', 'lineTerminator' => ';']];
        yield 'custom EOL contains delimiter' => [['delimiter' => ';', 'lineTerminator' => ";;"]];
        yield 'custom EOL inside enclosure' => [['enclosure' => "''", 'lineTerminator' => "'"]];
        yield 'self-overlapping enclosure' => [['delimiter' => ',', 'enclosure' => '||']];
        yield 'skipInitialSpace with space delimiter' => [['delimiter' => ' ', 'skipInitialSpace' => true]];
    }

    public function testAcceptsUnambiguousMultibyteDialects(): void
    {
        $d = new Dialect('→', '«', '¶');
        $this->assertSame('→', $d->delimiter);
        $d = new Dialect('||', "'", "\x1e");   // a self-overlapping delimiter is fine, only the enclosure may not overlap itself
        $this->assertSame('||', $d->delimiter);
    }
}
