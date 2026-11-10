<?php

declare(strict_types=1);

namespace Charm\Csv\Tests;

use Charm\Csv\Format;
use Charm\Csv\Quoting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testDefaultsAreRfc4180(): void
    {
        $d = new Format();
        $this->assertSame([',', '"', "\r\n", Quoting::Minimal, false, false, null],
            [$d->delimiter, $d->enclosure, $d->eolSequence, $d->quoting, $d->skipInitialSpace, $d->bom, $d->null]);
        $this->assertEquals($d, Format::rfc4180());
    }

    public function testPresets(): void
    {
        $this->assertTrue(Format::excel()->bom);
        $this->assertSame("\t", Format::tsv()->delimiter);
        $this->assertSame(Quoting::Never, Format::tsv()->quoting);
        $this->assertSame("\n", Format::unix()->eolSequence);
    }

    public function testWithReplacesOnlyTheGivenArguments(): void
    {
        $d = Format::rfc4180()->with(delimiter: ';', null: '\\N');
        $this->assertSame(';', $d->delimiter);
        $this->assertSame('\\N', $d->null);
        $this->assertSame('"', $d->enclosure);
    }

    public function testUniversalEol(): void
    {
        $this->assertTrue((new Format())->universalEol());
        $this->assertTrue((new Format(eolSequence: "\r"))->universalEol());
        $this->assertFalse((new Format(eolSequence: "\x1e"))->universalEol());
    }

    #[DataProvider('invalid')]
    public function testRejectsEmptyAndAmbiguousFormats(array $args): void
    {
        $this->expectException(\ValueError::class);
        new Format(...$args);
    }

    public function testIsReadonly(): void
    {
        $this->expectException(\Error::class);
        $f = new Format();
        $f->delimiter = ';'; // @phpstan-ignore-line
    }

    public function testEolSequences(): void
    {
        $this->assertSame(["\r\n", "\n", "\r"], (new Format())->eols());
        $this->assertSame(["\x1e"], (new Format(eolSequence: "\x1e"))->eols());
    }

    public static function invalid(): iterable
    {
        yield 'empty delimiter' => [['delimiter' => '']];
        yield 'empty enclosure' => [['enclosure' => '']];
        yield 'empty terminator' => [['eolSequence' => '']];
        yield 'delimiter == enclosure' => [['delimiter' => '"']];
        yield 'delimiter prefix of enclosure' => [['delimiter' => '|', 'enclosure' => '||']];
        yield 'enclosure prefix of delimiter' => [['delimiter' => '::', 'enclosure' => ':']];
        yield 'enclosure suffix of delimiter' => [['delimiter' => '>>', 'enclosure' => '>']];
        yield 'delimiter is newline' => [['delimiter' => "\n"]];
        yield 'delimiter contains CR' => [['delimiter' => "a\rb"]];
        yield 'enclosure is newline' => [['enclosure' => "\n"]];
        yield 'custom EOL equals delimiter' => [['delimiter' => ';', 'eolSequence' => ';']];
        yield 'custom EOL contains delimiter' => [['delimiter' => ';', 'eolSequence' => ";;"]];
        yield 'custom EOL inside enclosure' => [['enclosure' => "''", 'eolSequence' => "'"]];
        yield 'self-overlapping enclosure' => [['delimiter' => ',', 'enclosure' => '||']];
        yield 'skipInitialSpace with space delimiter' => [['delimiter' => ' ', 'skipInitialSpace' => true]];
    }

    public function testAcceptsUnambiguousMultibyteFormats(): void
    {
        $d = new Format('→', '«', '¶');
        $this->assertSame('→', $d->delimiter);
        $d = new Format('||', "'", "\x1e");   // a self-overlapping delimiter is fine, only the enclosure may not overlap itself
        $this->assertSame('||', $d->delimiter);
    }
}
