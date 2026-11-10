<?php

declare(strict_types=1);

namespace Charm\Csv\Tests;

use Charm\Csv\Decoder;
use Charm\Csv\Mode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** On well-formed data the reader must agree with fgetcsv() (with the legacy escape character disabled). */
final class FgetcsvTest extends TestCase
{
    public static function fixtures(): iterable
    {
        yield 'plain' => [__DIR__ . '/fixtures/plain-sample.csv'];
        yield 'messy (quoted fields, embedded newlines, doubled quotes)' => [__DIR__ . '/fixtures/messy-sample.csv'];
    }

    #[DataProvider('fixtures')]
    public function testSameRowsAsFgetcsv(string $file): void
    {
        $h = fopen($file, 'rb');
        $expected = [];
        while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
            $expected[] = $row;
        }
        fclose($h);
        $this->assertGreaterThan(1000, count($expected));

        $csv = file_get_contents($file);
        $this->assertSame($expected, Support::decode($csv));
        foreach ([1 << 10, 4099, 1 << 16] as $chunk) {
            $this->assertSame($expected, Support::decode($csv, null, false, Mode::Lax, $chunk), "chunk $chunk");
        }
        // the same through a real stream, the way the README does it
        $h = fopen($file, 'rb');
        $decoder = new Decoder(mode: Mode::Lax);
        $rows = [];
        while (!feof($h)) {
            foreach ($decoder->decode(fread($h, 8192)) as $row) { $rows[] = $row; }
        }
        array_push($rows, ...$decoder->finish());
        fclose($h);
        $this->assertSame($expected, $rows);
    }

    public function testFixturesAreWellFormedForStrictMode(): void
    {
        $csv = file_get_contents(__DIR__ . '/fixtures/messy-sample.csv');
        $this->assertNotEmpty(Support::decode($csv, null, false, Mode::Strict));
    }

    public function testHeaderRowsAgreeWithFgetcsvAndArrayCombine(): void
    {
        $h = fopen(__DIR__ . '/fixtures/plain-sample.csv', 'rb');
        $names = fgetcsv($h, null, ',', '"', '');
        $expected = [];
        while (($row = fgetcsv($h, null, ',', '"', '')) !== false) { $expected[] = array_combine($names, $row); }
        fclose($h);
        $this->assertSame($expected, Support::decode(file_get_contents(__DIR__ . '/fixtures/plain-sample.csv'), null, true, Mode::Strict, 5000));
    }
}
