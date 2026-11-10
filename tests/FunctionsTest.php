<?php

declare(strict_types=1);

namespace Charm\Csv\Tests;

use Charm\Csv\ParseException;
use PHPUnit\Framework\TestCase;

use function Charm\Csv\array_to_row;
use function Charm\Csv\buffer_to_collection;
use function Charm\Csv\buffer_to_collection_lax;
use function Charm\Csv\collection_to_buffer;
use function Charm\Csv\collection_to_file;
use function Charm\Csv\row_to_array;

final class FunctionsTest extends TestCase
{
    public function testArrayToRowAndBack(): void
    {
        $this->assertSame("a,\"b,c\",\"d\"\"e\"\r\n", array_to_row(['a', 'b,c', 'd"e']));
        $this->assertSame("a;b\n", array_to_row(['a', 'b'], ';', '"', "\n"));
        $this->assertSame(['a', 'b,c', 'd"e'], row_to_array(array_to_row(['a', 'b,c', 'd"e'])));
        $this->assertSame(['a', 'b'], row_to_array('a;b', ';'));
    }

    public function testRowToArrayWantsExactlyOneRow(): void
    {
        foreach (['', "a\nb"] as $input) {
            try {
                row_to_array($input);
                $this->fail('expected ValueError');
            } catch (\ValueError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCollections(): void
    {
        $rows = [['a', 'b'], ['1', "x\ny"]];
        $this->assertSame("a,b\r\n1,\"x\ny\"\r\n", collection_to_buffer($rows));
        $this->assertSame($rows, buffer_to_collection(collection_to_buffer($rows)));
        $this->assertSame($rows, buffer_to_collection_lax(collection_to_buffer($rows)));
        $this->assertSame($rows, buffer_to_collection(collection_to_buffer((fn() => yield from $rows)())));
    }

    public function testStrictRejectsMalformedInputAndRaggedRows(): void
    {
        foreach (["a,\"b\n", "a,b\"c\n", "\"a\"b\n", "a,b\n1\n"] as $bad) {
            try {
                buffer_to_collection($bad);
                $this->fail('expected ParseException for ' . json_encode($bad));
            } catch (ParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLaxRecoversAndAllowsRaggedRows(): void
    {
        $this->assertSame([['a', 'b'], ['1'], ['x', 'yz', 'q']], buffer_to_collection_lax("a,b\n1\nx,\"y\"z,q\n"));
    }

    public function testCollectionToFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        try {
            collection_to_file($path, [['a', 'b'], ['1', '2']], ';');
            $this->assertSame("a;b\r\n1;2\r\n", file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testInvalidDialectIsAValueError(): void
    {
        $this->expectException(\ValueError::class);
        array_to_row(['a'], '"', '"');
    }
}
