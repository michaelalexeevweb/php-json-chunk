<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\TestCase;

/**
 * A dirty export — one element with a bad number or a broken escape among a million good ones — used
 * to end the read at the first bad element. With an `onInvalidItem` handler the element is handed to
 * the handler and the read carries on. A document whose STRUCTURE is broken still fails: there is no
 * telling where the next element starts.
 */
final class InvalidItemsTest extends TestCase
{
    private const LIST = '[{"id":1},{"id":01},{"id":3},"\x",{"id":5}]';

    /** @var array<int, InvalidItem> */
    private array $invalid = [];

    private function reader(): JsonChunkReader
    {
        return new JsonChunkReader(onInvalidItem: function (InvalidItem $item): void {
            $this->invalid[] = $item;
        });
    }

    public function testWithoutAHandlerTheFirstInvalidItemStopsTheRead(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at byte 10');

        iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource(self::LIST)), false);
    }

    public function testAnInvalidItemIsHandedOverAndSkipped(): void
    {
        self::assertSame(
            [['id' => 1], ['id' => 3], ['id' => 5]],
            iterator_to_array($this->reader()->readGenerator(new StringSource(self::LIST)), false),
        );

        self::assertCount(2, $this->invalid);
        self::assertSame('{"id":01}', $this->invalid[0]->json);
        self::assertSame(10, $this->invalid[0]->offset);
        self::assertSame(1, $this->invalid[0]->key);
        self::assertSame('Syntax error', $this->invalid[0]->reason);
        self::assertSame('"\x"', $this->invalid[1]->json);
        self::assertSame(3, $this->invalid[1]->key);
    }

    public function testCountAndChunksLeaveInvalidItemsOut(): void
    {
        self::assertSame(3, $this->reader()->count(new StringSource(self::LIST)));
        self::assertSame([[['id' => 1], ['id' => 3]], [['id' => 5]]], $this->reader()->read(new StringSource(self::LIST), chunkSize: 2));
    }

    public function testAnObjectMemberKeepsItsName(): void
    {
        $values = iterator_to_array($this->reader()->readGenerator(new StringSource('{"a":1,"b":tru,"c":3}')));

        self::assertSame(['a' => 1, 'c' => 3], $values);
        self::assertSame('b', $this->invalid[0]->key);
        self::assertSame('tru', $this->invalid[0]->json);
    }

    public function testWildcardAndSeveralPathsSkipToo(): void
    {
        $document = '{"g":[{"v":1},{"v":-},{"v":3}]}';

        self::assertSame([1, 3], iterator_to_array($this->reader()->readGenerator(new StringSource($document), keyPath: 'g.*.v'), false));
        self::assertSame([1, 3], iterator_to_array($this->reader()->readPaths(new StringSource($document), ['g.*.v']), false));
        self::assertCount(2, $this->invalid);
        self::assertSame('-', $this->invalid[0]->json);
    }

    public function testABrokenStructureStillFails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($this->reader()->readGenerator(new StringSource('[{"id":1},{"id":2]')), false);
    }
}
