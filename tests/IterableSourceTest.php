<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\IterableSource;
use PHPUnit\Framework\TestCase;

/**
 * Bytes that arrive in pieces — an HTTP client's stream of a response, a generator reading a socket,
 * a decompressor — read as a source, without being gathered first.
 */
final class IterableSourceTest extends TestCase
{
    /**
     * @return array<int, array<string, int|string>>
     */
    private function records(): array
    {
        $records = [];
        for ($i = 0; $i < 3000; $i++) {
            $records[] = ['id' => $i, 'name' => 'record ' . $i];
        }

        return $records;
    }

    /**
     * Pieces of every size: one byte, a few, more than the reader asks for at once, empty ones between.
     */
    public function testPiecesOfAnySizeReadAsTheWhole(): void
    {
        $json = (string)json_encode(['data' => $this->records()]);

        foreach ([1, 7, 1000, 70_000, 200_000] as $size) {
            $pieces = (static function () use ($json, $size): \Generator {
                foreach (str_split($json, $size) as $piece) {
                    yield '';
                    yield $piece;
                }
            })();

            self::assertSame(
                $this->records(),
                iterator_to_array((new JsonChunkReader())->readGenerator(new IterableSource($pieces, 'pieces'), keyPath: 'data')),
                $size . ' bytes a piece',
            );
        }
    }

    public function testAnArrayOfPiecesReadsToo(): void
    {
        self::assertSame([1, 2, 3], iterator_to_array((new JsonChunkReader())->readGenerator(new IterableSource(['[1,', '2', ',3]']))));
    }

    /**
     * A source is read once, in order, and never asked for more than it was given; a read that stops
     * early stops taking pieces.
     */
    public function testAReadThatStopsEarlyStopsTakingPieces(): void
    {
        $taken = 0;
        $json = (string)json_encode($this->records());
        $pieces = (static function () use ($json, &$taken): \Generator {
            foreach (str_split($json, 100) as $piece) {
                $taken++;
                yield $piece;
            }
        })();

        self::assertSame(0, (new JsonChunkReader())->getFirst(new IterableSource($pieces))['id']);
        self::assertLessThan(count(str_split($json, 100)), $taken);
    }

    public function testItNamesItself(): void
    {
        self::assertSame('an http response', (new IterableSource([], 'an http response'))->describe());
        self::assertSame('iterable', (new IterableSource([]))->describe());
    }

    public function testAPieceThatIsNotAStringIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new JsonChunkReader())->readGenerator(new IterableSource(['[1,', 2, ']'])));
    }
}
