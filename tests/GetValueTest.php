<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * getValue() returns the ONE value a path names — a scalar as readily as a container — and stops
 * reading there. `meta.total` at the head of a gigabyte export used to be out of reach: every read
 * wanted the path to land on an array or an object it could stream.
 */
final class GetValueTest extends TestCase
{
    private const DOCUMENT = '{"meta":{"total":5,"name":"export","ratio":0.5,"done":false,"next":null},'
        . '"items":[{"id":1},{"id":2},{"id":3}],"a.b":{"c":"dotted"}}';

    private JsonChunkReader $reader;

    protected function setUp(): void
    {
        $this->reader = new JsonChunkReader();
    }

    /**
     * @return iterable<string, array{0: string|array<int, string>, 1: mixed}>
     */
    public static function paths(): iterable
    {
        yield 'an int' => ['meta.total', 5];
        yield 'a string' => ['meta.name', 'export'];
        yield 'a float' => ['meta.ratio', 0.5];
        yield 'false' => ['meta.done', false];
        yield 'null' => ['meta.next', null];
        yield 'an object, whole' => ['meta', ['total' => 5, 'name' => 'export', 'ratio' => 0.5, 'done' => false, 'next' => null]];
        yield 'a list, whole' => ['items', [['id' => 1], ['id' => 2], ['id' => 3]]];
        yield 'through a list index' => ['items.2.id', 3];
        yield 'a key with a dot, as segments' => [['a.b', 'c'], 'dotted'];
    }

    /**
     * @param string|array<int, string> $keyPath
     */
    #[DataProvider('paths')]
    public function testItReturnsTheValueAtThePath(string|array $keyPath, mixed $expected): void
    {
        self::assertSame($expected, $this->reader->getValue(new StringSource(self::DOCUMENT), $keyPath));
    }

    public function testObjectsModeReturnsStdClass(): void
    {
        $meta = (new JsonChunkReader(false))->getValue(new StringSource(self::DOCUMENT), 'meta');

        self::assertInstanceOf(stdClass::class, $meta);
        self::assertSame(5, $meta->total);
    }

    /**
     * The point of the method: a value near the head of a large document costs the bytes up to it.
     */
    public function testItStopsReadingAtTheValue(): void
    {
        $tail = str_repeat('{"filler":"' . str_repeat('x', 1000) . '"},', 10_000);
        $source = new CountingJsonSource('{"meta":{"total":7},"items":[' . $tail . '{}]}');

        self::assertSame(7, $this->reader->getValue($source, 'meta.total'));
        self::assertSame(1, $source->blocksRead);
    }

    public function testAMissingPathIsNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found');

        $this->reader->getValue(new StringSource(self::DOCUMENT), 'meta.missing');
    }

    /**
     * @return iterable<string, array{0: string|array<int, string>, 1: string}>
     */
    public static function refusedPaths(): iterable
    {
        yield 'a wildcard names many values' => ['items.*.id', 'readGenerator'];
        yield 'an empty path' => ['', 'key path'];
        yield 'an empty list' => [[], 'key path'];
    }

    /**
     * @param string|array<int, string> $keyPath
     */
    #[DataProvider('refusedPaths')]
    public function testAPathThatNamesNoSingleValueIsRefused(string|array $keyPath, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->reader->getValue(new StringSource(self::DOCUMENT), $keyPath);
    }

    public function testABrokenValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->reader->getValue(new StringSource('{"meta":{"total":5x}}'), 'meta.total');
    }
}
