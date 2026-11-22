<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\JsonPointer;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A JSON Pointer — RFC 6901, the path syntax JsonMachine and most JSON tools use — read into this
 * library's key path, so a path written for them works here unchanged.
 */
final class JsonPointerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: array<int, string>}>
     */
    public static function pointers(): iterable
    {
        yield 'the whole document' => ['', []];
        yield 'one name' => ['/orders', ['orders']];
        yield 'an index' => ['/orders/0/sku', ['orders', '0', 'sku']];
        yield '"-" is every element, as JsonMachine reads it' => ['/orders/-/sku', ['orders', '*', 'sku']];
        yield '~1 is a slash' => ['/a~1b', ['a/b']];
        yield '~0 is a tilde' => ['/m~0n', ['m~n']];
        yield '~01 is a tilde and a one, not a slash' => ['/~01', ['~1']];
        yield 'a dot is just a character' => ['/a.b/c', ['a.b', 'c']];
        yield 'the empty name' => ['/', ['']];
        yield 'empty names in a row' => ['/a//b', ['a', '', 'b']];
    }

    /**
     * @param array<int, string> $segments
     */
    #[DataProvider('pointers')]
    public function testItReadsAPointerIntoSegments(string $pointer, array $segments): void
    {
        self::assertSame($segments, JsonPointer::segments($pointer));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function broken(): iterable
    {
        yield 'no leading slash' => ['orders/0'];
        yield 'a tilde before anything but 0 or 1' => ['/a~2b'];
        yield 'a tilde at the end' => ['/a~'];
    }

    #[DataProvider('broken')]
    public function testABrokenPointerIsRefused(string $pointer): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON Pointer');

        JsonPointer::segments($pointer);
    }

    public function testThePathReadsWhatThePointerNames(): void
    {
        $document = '{"orders":[{"sku":"A-17"},{"sku":"B-4"}],"a/b":{"m~n":[1,2]},"a.b":{"c":[3]}}';
        $reader = new JsonChunkReader();

        self::assertSame(['A-17', 'B-4'], iterator_to_array($reader->readGenerator(new StringSource($document), keyPath: JsonPointer::segments('/orders/-/sku')), false));
        self::assertSame([1, 2], iterator_to_array($reader->readGenerator(new StringSource($document), keyPath: JsonPointer::segments('/a~1b/m~0n'))));
        self::assertSame([3], iterator_to_array($reader->readGenerator(new StringSource($document), keyPath: JsonPointer::segments('/a.b/c'))));
        self::assertSame('B-4', $reader->getValue(new StringSource($document), JsonPointer::segments('/orders/1/sku')));
    }
}
