<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\JsonIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * extendIndex() brings an index of a sequence up to a file that has only been appended to — a log —
 * reading from its last checkpoint on; and count(), getFirst(), getLast() and getNth() take an index
 * in place of a path.
 */
final class ExtendIndexTest extends TestCase
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
        $path = (string)tempnam(sys_get_temp_dir(), 'extend_index_');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    private static function lines(int $from, int $to): string
    {
        return implode('', array_map(static fn(int $i): string => '{"id":' . $i . ',"s":"}{"}' . "\n", range($from, $to)));
    }

    public function testAnAppendedFileIsReadAsIfIndexedAfresh(): void
    {
        $path = $this->file(self::lines(0, 2999));
        $reader = new JsonChunkReader();
        $index = $reader->indexValues($path, every: 100);

        file_put_contents($path, self::lines(3000, 4999), FILE_APPEND);
        $extended = $reader->extendIndex($index);

        self::assertSame(5000, $extended->count());
        self::assertSame(100, $extended->every);
        $all = iterator_to_array($reader->readValues($path));
        foreach ([0, 2999, 3000, 3001, 4000, 4999] as $offset) {
            self::assertSame(
                iterator_to_array($reader->readValues($path, 3, $offset)),
                iterator_to_array($reader->readFrom($extended, $offset, 3)),
                'offset ' . $offset,
            );
        }

        // The old checkpoints are kept: only what came after the last of them was read again.
        $old = array_map(static fn($c): array => [$c->entry, $c->byte], $index->checkpoints());
        $new = array_map(static fn($c): array => [$c->entry, $c->byte], $extended->checkpoints());
        self::assertSame($old, array_slice($new, 0, count($old)));
        self::assertGreaterThan(count($old), count($new));

        $read = [];
        foreach ($extended->partitions(4) as $part) {
            foreach ($reader->readFrom($extended, $part->offset, $part->limit) as $number => $line) {
                $read[$number] = $line;
            }
        }
        self::assertSame($all, $read);
    }

    public function testAFileThatHasNotChangedGivesTheSameIndex(): void
    {
        $reader = new JsonChunkReader();
        $index = $reader->indexValues($this->file(self::lines(0, 99)));

        self::assertSame($index, $reader->extendIndex($index));
    }

    /**
     * A log is indexed while its writer is half-way through a line: the file ends inside a document.
     * That is not a broken document but one not finished yet — left out of the index, not refused,
     * and read whole once the index is extended. Reads from the index stop before it.
     *
     * @return iterable<string, array{0: string, 1: string, 2: mixed}>
     */
    public static function cutOff(): iterable
    {
        yield 'inside a string' => ['{"id":500,"s":"hal', 'f"}' . "\n", ['id' => 500, 's' => 'half']];
        yield 'inside an object' => ['{"id":500,"s":"half"', '}' . "\n", ['id' => 500, 's' => 'half']];
        yield 'inside a literal' => ['tr', 'ue' . "\n", true];
        yield 'inside a number' => ['-', '12.5' . "\n", -12.5];
    }

    #[DataProvider('cutOff')]
    public function testADocumentCutOffWhenIndexedIsLeftOutAndReadOnceExtended(string $begun, string $finished, mixed $expected): void
    {
        $path = $this->file(self::lines(0, 499) . $begun);
        $reader = new JsonChunkReader();
        $index = $reader->indexValues($path, every: 100);
        self::assertSame(500, $index->count());
        self::assertSame(['id' => 499, 's' => '}{'], $reader->getLast($index));
        self::assertCount(500, iterator_to_array($reader->readFrom($index, 450)) + range(0, 449));

        file_put_contents($path, $finished . self::lines(501, 600), FILE_APPEND);
        $extended = $reader->extendIndex($index);

        self::assertSame(601, $extended->count());
        self::assertSame($expected, $reader->getNth($extended, 500));
        self::assertSame(['id' => 600, 's' => '}{'], $reader->getLast($extended));
    }

    /**
     * A file that ends inside a document is still refused by readValues(), which reads a file as it
     * is, not as it is being written.
     */
    public function testReadValuesStillRefusesAFileCutOff(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unexpected end of input in string');

        iterator_to_array((new JsonChunkReader())->readValues($this->file(self::lines(0, 9) . '{"id":10,"s":"ha')));
    }

    /**
     * @return iterable<string, array{0: callable(string): void}>
     */
    public static function changes(): iterable
    {
        yield 'cut shorter' => [static function (string $path): void {
            file_put_contents($path, self::lines(0, 100));
        }];
        yield 'rotated and written again, longer' => [static function (string $path): void {
            file_put_contents($path, self::lines(5000, 9000));
        }];
        yield 'a line near the end rewritten, then appended to' => [static function (string $path): void {
            $contents = (string)file_get_contents($path);
            file_put_contents($path, substr($contents, 0, -30) . str_replace('"s":"}{"', '"s":"{}"', substr($contents, -30)) . self::lines(300, 310));
        }];
    }

    /**
     * @param callable(string): void $change
     */
    #[DataProvider('changes')]
    public function testAFileChangedRatherThanAppendedToIsRefused(callable $change): void
    {
        $path = $this->file(self::lines(0, 299));
        $reader = new JsonChunkReader();
        $index = $reader->indexValues($path, every: 50);
        $change($path);
        clearstatcache();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was changed, not only appended to');

        $reader->extendIndex($index);
    }

    public function testOnlyAnIndexOfASequenceIsExtended(): void
    {
        $reader = new JsonChunkReader();
        $index = $reader->index($this->file('[1,2,3]'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only an index of a sequence');

        $reader->extendIndex($index);
    }

    public function testAnIndexSavedBefore220IsNotExtended(): void
    {
        $path = $this->file(self::lines(0, 99));
        $reader = new JsonChunkReader();
        $saved = json_decode($reader->indexValues($path)->toJson(), true);
        self::assertIsArray($saved);
        unset($saved['tail'], $saved['every']);
        $old = JsonIndex::fromJson((string)json_encode($saved));
        file_put_contents($path, self::lines(100, 110), FILE_APPEND);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('saved before 2.2.0');

        $reader->extendIndex($old);
    }

    public function testASavedIndexIsExtended(): void
    {
        $path = $this->file(self::lines(0, 999));
        $reader = new JsonChunkReader();
        $saved = $reader->indexValues($path, every: 250)->toJson();
        file_put_contents($path, self::lines(1000, 1499), FILE_APPEND);

        $extended = $reader->extendIndex(JsonIndex::fromJson($saved));

        self::assertSame(1500, $extended->count());
        self::assertSame(250, $extended->every);
        self::assertSame(['id' => 1499, 's' => '}{'], $reader->getLast($extended));
    }

    public function testReadingAGrownSequenceSuggestsExtendingIt(): void
    {
        $path = $this->file(self::lines(0, 9));
        $reader = new JsonChunkReader();
        $index = $reader->indexValues($path);
        file_put_contents($path, self::lines(10, 11), FILE_APPEND);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extendIndex()');

        $reader->readFrom($index);
    }

    /**
     * @return iterable<string, array{0: string, 1: callable(JsonChunkReader, string): JsonIndex, 2: string|array<int, string>|null}>
     */
    public static function indexedContainers(): iterable
    {
        $records = array_map(static fn(int $i): array => ['id' => $i], range(0, 2499));
        yield 'a list' => [(string)json_encode(['users' => $records]), static fn(JsonChunkReader $reader, string $path): JsonIndex => $reader->index($path, 'users', every: 100), 'users'];
        yield 'an object' => [(string)json_encode(array_combine(array_map(static fn(int $i): string => 'u' . $i, range(0, 2499)), $records)), static fn(JsonChunkReader $reader, string $path): JsonIndex => $reader->index($path, every: 100), null];
        yield 'NDJSON' => [implode("\n", array_map(static fn(array $r): string => (string)json_encode($r), $records)) . "\n", static fn(JsonChunkReader $reader, string $path): JsonIndex => $reader->indexValues($path, every: 100), null];
    }

    /**
     * @param callable(JsonChunkReader, string): JsonIndex $makeIndex
     * @param string|array<int, string>|null $keyPath
     */
    #[DataProvider('indexedContainers')]
    public function testCountFirstLastAndNthTakeAnIndex(string $contents, callable $makeIndex, string|array|null $keyPath): void
    {
        $path = $this->file($contents);
        $reader = new JsonChunkReader();
        $index = $makeIndex($reader, $path);
        $isSequence = $index->container === JsonIndex::VALUES;
        $plain = static fn(string $method, mixed ...$arguments): mixed => $isSequence
            ? match ($method) {
                'count' => iterator_count($reader->readValues($path)),
                'getFirst' => $reader->readValues($path, 1)->current(),
                'getLast' => $reader->readValues($path, 1, 2499)->current(),
                default => $reader->readValues($path, 1, $arguments[0])->current(),
            }
            : $reader->{$method}($path, ...[...$arguments, $keyPath]);

        self::assertSame($plain('count'), $reader->count($index));
        self::assertSame($plain('getFirst'), $reader->getFirst($index));
        self::assertSame($plain('getLast'), $reader->getLast($index));
        foreach ([0, 99, 100, 1234, 2499] as $n) {
            self::assertSame($plain('getNth', $n), $reader->getNth($index, $n), 'entry ' . $n);
        }

        try {
            $reader->getNth($index, 2500);
            self::fail('An entry past the end was found.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Index 2500 not found', $exception->getMessage());
        }
    }

    public function testAKeyPathBesideAnIndexIsRefused(): void
    {
        $reader = new JsonChunkReader();
        $index = $reader->index($this->file('{"users":[1,2]}'), 'users');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pass no key path');

        $reader->count($index, 'users');
    }

    public function testCountingFromAnIndexOfAChangedFileIsRefused(): void
    {
        $path = $this->file('[1,2,3]');
        $reader = new JsonChunkReader();
        $index = $reader->index($path);
        file_put_contents($path, '[1,2,3,4]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has changed since it was indexed');

        $reader->count($index);
    }

    /**
     * The last document of a sequence may be a refused one; getLast() gives the last one read.
     */
    public function testTheLastOfASequenceIsTheLastDocumentRead(): void
    {
        $path = $this->file(self::lines(0, 299) . '{"id":01}' . "\n");
        $reader = new JsonChunkReader(onInvalidItem: static function (): void {
        });
        $index = $reader->indexValues($path, every: 100);

        self::assertSame(301, $reader->count($index));
        self::assertSame(['id' => 299, 's' => '}{'], $reader->getLast($index));
    }
}
