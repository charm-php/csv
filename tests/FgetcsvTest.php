<?php

declare(strict_types=1);

namespace FrodeBorli\Csv\Tests;

use FrodeBorli\Csv\Reader;
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

        $this->assertSame($expected, Reader::fromFile($file)->toArray());
        foreach ([1 << 10, 4099, 1 << 16] as $chunk) {
            $this->assertSame($expected, Reader::fromFile($file)->withChunkSize($chunk)->toArray(), "chunk $chunk");
        }
        $this->assertSame($expected, Reader::fromString(file_get_contents($file))->toArray());
    }

    public function testFixturesAreWellFormedForTheStrictReader(): void
    {
        $this->assertNotEmpty(Reader::fromFile(__DIR__ . '/fixtures/messy-sample.csv', new \FrodeBorli\Csv\Dialect(strict: true))->toArray());
    }

    public function testColumnsAreTheTransposedRows(): void
    {
        $file = __DIR__ . '/fixtures/plain-sample.csv';
        $rows = Reader::fromFile($file)->toArray();
        $cols = Reader::fromFile($file)->withChunkSize(4096)->columns();
        $this->assertSame(array_map(null, ...$rows), $cols);
    }
}
