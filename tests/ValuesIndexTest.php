<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\JsonIndex;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An index of a sequence of documents — JSON Lines, NDJSON, documents one after another — made by
 * indexValues(): readFrom() seeks into it and must give what readValues() gives from the start, keys
 * included, and the parts of partitions() must cover it.
 */
final class ValuesIndexTest extends TestCase
{
    /** @var array<int, string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function file(string $contents): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'values_index_');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function sequences(): iterable
    {
        $lines = [];
        for ($i = 0; $i < 3000; $i++) {
            $lines[] = (string)json_encode(['id' => $i, 's' => $i % 7 === 0 ? '}{"x":1}' : 'plain', 'n' => [$i]]);
        }
        yield 'NDJSON of objects' => [implode("\n", $lines) . "\n"];

        $mixed = [];
        for ($i = 0; $i < 2000; $i++) {
            $mixed[] = match ($i % 6) {
                0 => (string)json_encode(['id' => $i]),
                1 => (string)json_encode([$i, [$i]]),
                2 => (string)$i,
                3 => (string)json_encode('text ' . $i . ' }{'),
                4 => $i % 4 === 0 ? 'true' : 'null',
                default => (string)json_encode(['id' => $i, 'pad' => str_repeat('p', $i % 300)], JSON_PRETTY_PRINT),
            };
        }
        yield 'every kind of document, some pretty-printed' => [implode("\n", $mixed)];
        yield 'record separators' => ["\x1E" . implode("\n\x1E", array_slice($lines, 0, 1500)) . "\n"];
        yield 'nothing between objects' => [implode('', array_slice($lines, 0, 1500))];
        yield 'one document' => ['{"only":1}'];
        yield 'documents larger than a block' => [implode("\n", array_map(
            static fn(int $i): string => (string)json_encode(['id' => $i, 'pad' => str_repeat('x', $i % 3 === 0 ? 90_000 : 10)]),
            range(0, 40),
        ))];
    }

    #[DataProvider('sequences')]
    public function testItReadsFromAnyDocumentAsReadValuesDoes(string $contents): void
    {
        $path = $this->file($contents);
        $reader = new JsonChunkReader();
        $all = iterator_to_array($reader->readValues($path));

        foreach ([1, 7, 100] as $every) {
            $index = $reader->indexValues($path, every: $every);
            self::assertSame(JsonIndex::VALUES, $index->container);
            self::assertSame(count($all), $index->count());
            self::assertSame(0, $index->checkpoints()[0]->entry);

            $count = count($all);
            foreach ([0, 1, $every - 1, $every, $every + 1, intdiv($count, 2), $count - 2, $count - 1, $count, $count + 5] as $offset) {
                if ($offset < 0) {
                    continue;
                }
                foreach ([null, 1, 3, 250] as $limit) {
                    self::assertSame(
                        iterator_to_array($reader->readValues($path, $limit, $offset)),
                        iterator_to_array($reader->readFrom($index, $offset, $limit)),
                        sprintf('every %d, offset %d, limit %s', $every, $offset, var_export($limit, true)),
                    );
                }
            }

            $read = [];
            foreach (JsonIndex::fromJson($index->toJson())->partitions(4) as $part) {
                foreach ($reader->readFrom($index, $part->offset, $part->limit) as $number => $document) {
                    $read[$number] = $document;
                }
            }
            self::assertSame($all, $read);
        }
    }

    /**
     * readValues() numbers a refused document too, so the documents after it keep their place; an
     * index numbers them the same, and the window of readFrom() is of document numbers.
     */
    public function testRefusedDocumentsKeepTheirNumbers(): void
    {
        $lines = [];
        for ($i = 0; $i < 500; $i++) {
            $lines[] = $i % 50 === 25 ? '{"id":' . $i . ',"bad":01}' : (string)json_encode(['id' => $i]);
        }
        $path = $this->file(implode("\n", $lines));
        $refused = [];
        $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$refused): void {
            $refused[] = $item->key;
        });

        $all = iterator_to_array($reader->readValues($path));
        $index = $reader->indexValues($path, every: 10);
        self::assertSame(500, $index->count());
        self::assertSame(range(25, 475, 50), array_values(array_unique($refused)));

        $window = iterator_to_array($reader->readFrom($index, 20, 10));
        self::assertSame([20, 21, 22, 23, 24, 26, 27, 28, 29], array_keys($window));
        self::assertSame(array_filter($all, static fn(int $number): bool => $number >= 20 && $number < 30, ARRAY_FILTER_USE_KEY), $window);
        self::assertSame(array_column($all, 'id'), array_keys($all));
    }

    public function testAnEmptyFileHasNothingToRead(): void
    {
        $reader = new JsonChunkReader();
        $index = $reader->indexValues($this->file("\n \n"));

        self::assertSame(0, $index->count());
        self::assertSame([], $index->checkpoints());
        self::assertSame([], $index->partitions(3));
        self::assertSame([], iterator_to_array($reader->readFrom($index)));
    }

    public function testABrokenDocumentFailsTheIndex(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JsonChunkReader())->indexValues($this->file("{\"a\":1}\n{\"a\":01}\n{\"a\":3}"));
    }

    public function testAChangedFileIsRefused(): void
    {
        $path = $this->file("{\"a\":1}\n{\"a\":2}\n");
        $reader = new JsonChunkReader();
        $index = $reader->indexValues($path);
        file_put_contents($path, "{\"a\":1}\n{\"a\":2}\n{\"a\":3}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has changed');

        iterator_to_array($reader->readFrom($index, 1));
    }

    public function testOnlyAFileCanBeIndexed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JsonChunkReader())->indexValues(new StringSource('{"a":1}'));
    }

    public function testEveryIsAtLeastOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JsonChunkReader())->indexValues($this->file('1'), every: 0);
    }

    /**
     * An index that points into the middle of a document is refused, not read from a wrong place.
     */
    public function testAnIndexOfAnotherFileIsRefused(): void
    {
        $reader = new JsonChunkReader();
        $index = $reader->indexValues($this->file("{\"a\":1}\n{\"a\":2}\n{\"a\":3}\n"), every: 1);
        // Byte 20 is the colon of the third document.
        $forged = new JsonIndex($index->path, null, JsonIndex::VALUES, 3, [[0, 0], [2, 20]], $index->size, $index->modified);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no entry starts at byte 20');

        iterator_to_array($reader->readFrom($forged, 2));
    }
}
