<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\Checkpoint;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\JsonIndex;
use PhpJsonChunk\JsonPointer;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An index remembers where entries of one container start — every thousandth or so — so that reading
 * from entry four million seeks there instead of reading the four million before it. Whatever it reads
 * must be exactly what reading from the start would have given, keys included.
 */
final class JsonIndexTest extends TestCase
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
        $path = (string)tempnam(sys_get_temp_dir(), 'json_index_');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(int $count): array
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = ['id' => $i, 'name' => 'record ' . $i, 's' => $i % 11 === 0 ? '},{"x":[1]}' : 'plain', 'tags' => ['a']];
        }

        return $records;
    }

    public function testItReadsFromAnyEntryAsReadingFromTheStartWould(): void
    {
        $records = $this->records(5000);
        foreach ([0, JSON_PRETTY_PRINT] as $flags) {
            $path = $this->file((string)json_encode(['meta' => ['n' => 1], 'users' => $records], $flags));
            $reader = new JsonChunkReader();
            $index = $reader->index($path, 'users', every: 100);

            self::assertSame(5000, $index->count());
            foreach ([0, 1, 99, 100, 101, 2345, 4999, 5000, 7000] as $offset) {
                foreach ([1, 3, null] as $limit) {
                    self::assertSame(
                        iterator_to_array($reader->readGenerator($path, limit: $limit, offset: $offset, keyPath: 'users')),
                        iterator_to_array($reader->readFrom($index, $offset, $limit)),
                        sprintf('offset %d, limit %s, flags %d', $offset, var_export($limit, true), $flags),
                    );
                }
            }
        }
    }

    /**
     * The checkpoints sit where the reader passes an entry's start; inside a run of entries decoded at
     * once there is none, so they come at most a run apart from every `every` — never further.
     */
    public function testCheckpointsAreCloseEnoughToReadLittle(): void
    {
        $path = $this->file((string)json_encode($this->records(20_000)));
        $index = (new JsonChunkReader())->index($path, every: 1000);

        $previous = null;
        foreach ($index->checkpoints() as $checkpoint) {
            self::assertInstanceOf(Checkpoint::class, $checkpoint);
            if ($previous !== null) {
                self::assertGreaterThan($previous, $checkpoint->entry);
                self::assertLessThanOrEqual(1100, $checkpoint->entry - $previous);
            }
            $previous = $checkpoint->entry;
        }
        self::assertSame(0, $index->checkpoints()[0]->entry);
        self::assertSame(0, $index->checkpointBefore(0)?->entry);
        self::assertSame(1, $index->checkpointBefore(0)?->byte);
        self::assertSame($index->checkpoints()[1]->entry, $index->checkpointBefore($index->checkpoints()[1]->entry + 5)?->entry);
        self::assertGreaterThanOrEqual(18, count($index->checkpoints()));
    }

    public function testAnObjectKeyedByIdIsIndexedByMember(): void
    {
        $members = [];
        foreach ($this->records(3000) as $record) {
            $members['u' . $record['id']] = $record;
        }
        $path = $this->file((string)json_encode(['users' => $members]));
        $reader = new JsonChunkReader();
        $index = $reader->index($path, JsonPointer::segments('/users'), every: 50);

        self::assertSame(3000, $index->count());
        self::assertSame(
            iterator_to_array($reader->readGenerator($path, limit: 4, offset: 2222, keyPath: 'users')),
            iterator_to_array($reader->readFrom($index, 2222, 4)),
        );
    }

    public function testAnIndexIsSavedAndLoaded(): void
    {
        $path = $this->file((string)json_encode($this->records(3000)));
        $reader = new JsonChunkReader();
        $saved = $reader->index($path, every: 100)->toJson();

        $loaded = JsonIndex::fromJson($saved);

        self::assertSame(3000, $loaded->count());
        self::assertSame($this->records(3000)[2999], $reader->readFrom($loaded, 2999, 1)->current());
    }

    /**
     * An index is saved for other processes to use, and theirs need not be the directory it was made
     * in: a relative path was kept as given, and read elsewhere it named another file, or none.
     */
    public function testAnIndexMadeFromARelativePathIsReadFromAnywhere(): void
    {
        $path = $this->file((string)json_encode($this->records(50)));
        $directory = getcwd();
        self::assertIsString($directory);

        try {
            chdir(dirname($path));
            $index = (new JsonChunkReader())->index(basename($path), every: 10);
            chdir(sys_get_temp_dir() . '/..');

            self::assertSame((string)realpath($path), $index->path);
            self::assertSame(['id' => 42, 'name' => 'record 42', 's' => 'plain', 'tags' => ['a']], (new JsonChunkReader())->readFrom($index, 42, 1)->current());
        } finally {
            chdir($directory);
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: array<int, array{0: int, 1: int}>, 2: string}>
     */
    public static function brokenCheckpoints(): iterable
    {
        yield 'out of order' => [100, [[0, 1], [50, 900], [20, 400]], 'ascending'];
        yield 'the same entry twice' => [100, [[0, 1], [20, 400], [20, 500]], 'ascending'];
        yield 'bytes going back' => [100, [[0, 1], [20, 400], [50, 300]], 'ascending'];
        yield 'a negative byte' => [100, [[0, -1]], 'negative'];
        yield 'an entry past the count' => [100, [[0, 1], [100, 900]], 'past'];
        yield 'none for the first entry' => [100, [[20, 400]], 'first entry'];
        yield 'a negative count' => [-1, [], 'count'];
    }

    /**
     * Checkpoints are looked up by binary search, which takes them to ascend; a saved index that was
     * damaged or put together by hand could put one at another entry's start and read the wrong
     * entries without a word. Such an index is refused.
     *
     * @param array<int, array{0: int, 1: int}> $checkpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenCheckpoints')]
    public function testAnIndexWithCheckpointsOutOfOrderIsRefused(int $count, array $checkpoints, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        JsonIndex::fromJson((string)json_encode(['path' => '/x.json', 'keyPath' => null, 'container' => '[', 'count' => $count, 'checkpoints' => $checkpoints, 'size' => 10, 'modified' => 1]));
    }

    /**
     * A read from an index seeks to a checkpoint before its first entry and reads one past its last;
     * an invalid entry it passed on the way was handed to onInvalidItem, so the parts of partitions()
     * handed over the ones between them twice or more. Each read now hands over only its own.
     *
     * @return iterable<string, array{0: string, 1: callable(JsonChunkReader, string): JsonIndex}>
     */
    public static function containersWithInvalidEntries(): iterable
    {
        $entries = array_map(static fn(int $i): string => $i % 50 === 7 ? '{"id":0' . $i . '}' : '{"id":' . $i . '}', range(0, 999));
        yield 'a list' => ['{"users":[' . implode(',', $entries) . ']}', static fn(JsonChunkReader $reader, string $path): JsonIndex => $reader->index($path, 'users', every: 300)];
        yield 'an object' => ['{' . implode(',', array_map(static fn(int $i, string $entry): string => '"u' . $i . '":' . $entry, array_keys($entries), $entries)) . '}', static fn(JsonChunkReader $reader, string $path): JsonIndex => $reader->index($path, every: 300)];
        yield 'NDJSON' => [implode("\n", $entries) . "\n", static fn(JsonChunkReader $reader, string $path): JsonIndex => $reader->indexValues($path, every: 300)];
    }

    /**
     * @param callable(JsonChunkReader, string): JsonIndex $index
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('containersWithInvalidEntries')]
    public function testEachPartHandsOverOnlyItsOwnInvalidEntries(string $contents, callable $index): void
    {
        $path = $this->file($contents);
        $refused = [];
        $reader = new JsonChunkReader(onInvalidItem: static function (\PhpJsonChunk\InvalidItem $item) use (&$refused): void {
            $refused[] = $item->offset;
        });
        $built = $index($reader, $path);
        $all = $refused;
        self::assertCount(20, $all);

        foreach ([3, 7, 13] as $parts) {
            $refused = [];
            foreach ($built->partitions($parts) as $part) {
                iterator_to_array($reader->readFrom($built, $part->offset, $part->limit));
            }
            self::assertSame($all, $refused, $parts . ' parts');
        }
    }

    public function testAFileChangedSinceItWasIndexedIsRefused(): void
    {
        $path = $this->file((string)json_encode($this->records(300)));
        $reader = new JsonChunkReader();
        $index = $reader->index($path);
        file_put_contents($path, (string)json_encode($this->records(301)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed');

        iterator_to_array($reader->readFrom($index, 10, 1));
    }

    public function testOnlyAFileCanBeIndexed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('file');

        (new JsonChunkReader())->index(new StringSource('[1,2]'));
    }

    public function testOnlyOneContainerCanBeIndexed(): void
    {
        $path = $this->file('{"a":[{"b":[1]}]}');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wildcard');

        (new JsonChunkReader())->index($path, 'a.*.b');
    }

    public function testAnEmptyListIndexesToNothing(): void
    {
        $path = $this->file('{"users":[]}');
        $reader = new JsonChunkReader();
        $index = $reader->index($path, 'users');

        self::assertSame(0, $index->count());
        self::assertSame([], iterator_to_array($reader->readFrom($index)));
    }
}
