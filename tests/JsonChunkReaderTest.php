<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use RuntimeException;

final class JsonChunkReaderTest extends \PHPUnit\Framework\TestCase
{
    private JsonChunkReader $reader;

    #[\Override]
    protected function setUp(): void
    {
        $this->reader = new JsonChunkReader();
    }

    public function testReadReturnsAllItemsAsSingleChunkWhenSizeIsNotProvided(): void
    {
        $filePath = __DIR__ . '/fixtures/sample-array.json';

        $result = $this->reader->read($filePath);

        self::assertCount(1, $result);
        self::assertSame(
            [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
            $result[0],
        );
    }

    public function testCountReturnsTotalForRootArray(): void
    {
        $count = $this->reader->count(__DIR__ . '/fixtures/sample-array.json');

        self::assertSame(3, $count);
    }

    public function testCountReturnsTotalForNestedKeyPath(): void
    {
        $count = $this->reader->count(__DIR__ . '/fixtures/nested.json', 'key1.0.key2.0.key3');

        self::assertSame(3, $count);
    }

    public function testCountIsIndependentFromLimitAndOffset(): void
    {
        $window = $this->reader->read(__DIR__ . '/fixtures/sample-array.json', null, 1, 1);
        $count = $this->reader->count(__DIR__ . '/fixtures/sample-array.json');

        self::assertSame([[['id' => 2]]], $window);
        self::assertSame(3, $count);
    }

    public function testReadReturnsChunksWithGivenSize(): void
    {
        $filePath = __DIR__ . '/fixtures/sample-array.json';

        $result = $this->reader->read($filePath, 2);

        self::assertSame(
            [
                [
                    ['id' => 1],
                    ['id' => 2],
                ],
                [
                    ['id' => 3],
                ],
            ],
            $result,
        );
    }

    public function testReadSupportsLimitAndOffset(): void
    {
        $result = $this->reader->read(__DIR__ . '/fixtures/sample-array.json', null, 1, 1);

        self::assertSame([[['id' => 2]]], $result);
    }

    public function testReadSupportsNestedKeyPath(): void
    {
        $result = $this->reader->read(
            __DIR__ . '/fixtures/nested.json',
            null,
            null,
            0,
            'key1.0.key2.0.key3',
        );

        self::assertSame(
            [
                [
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ]
            ],
            $result,
        );
    }

    public function testReadSupportsTemporaryChunkDirectory(): void
    {
        $tempChunkDir = sys_get_temp_dir() . '/php_json_chunk_' . uniqid(more_entropy: true);

        try {
            $result = $this->reader->read(
                __DIR__ . '/fixtures/sample-array.json',
                tempChunkDir: $tempChunkDir,
            );

            self::assertSame(
                [[
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ]],
                $result,
            );
            self::assertDirectoryExists($tempChunkDir);
            self::assertSame([], glob($tempChunkDir . '/*') ?: []);
        } finally {
            $this->removeDirectoryIfExists($tempChunkDir);
        }
    }

    public function testReadIteratorSupportsTemporaryChunkDirectory(): void
    {
        $tempChunkDir = sys_get_temp_dir() . '/php_json_chunk_iterator_' . uniqid(more_entropy: true);

        try {
            $iterator = $this->reader->readIterator(
                __DIR__ . '/fixtures/sample-array.json',
                2,
                tempChunkDir: $tempChunkDir,
            );

            self::assertSame(
                [
                    [
                        ['id' => 1],
                        ['id' => 2],
                    ],
                    [
                        ['id' => 3],
                    ],
                ],
                iterator_to_array($iterator, false),
            );
            self::assertDirectoryExists($tempChunkDir);
            self::assertSame([], glob($tempChunkDir . '/*') ?: []);
        } finally {
            $this->removeDirectoryIfExists($tempChunkDir);
        }
    }

    public function testReadGeneratorSupportsTemporaryChunkDirectory(): void
    {
        $tempChunkDir = sys_get_temp_dir() . '/php_json_chunk_generator_' . uniqid(more_entropy: true);

        try {
            $generator = $this->reader->readGenerator(
                __DIR__ . '/fixtures/sample-array.json',
                tempChunkDir: $tempChunkDir,
            );

            self::assertSame(
                [
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ],
                iterator_to_array($generator, false),
            );
            self::assertDirectoryExists($tempChunkDir);
            self::assertSame([], glob($tempChunkDir . '/*') ?: []);
        } finally {
            $this->removeDirectoryIfExists($tempChunkDir);
        }
    }

    public function testReadIteratorReturnsItemsWhenChunkSizeIsNotProvided(): void
    {
        $iterator = $this->reader->readIterator(__DIR__ . '/fixtures/sample-array.json', null, 2, 1);

        self::assertSame(
            [
                ['id' => 2],
                ['id' => 3],
            ],
            iterator_to_array($iterator, false),
        );
    }

    public function testReadIteratorReturnsChunksWhenChunkSizeIsProvided(): void
    {
        $iterator = $this->reader->readIterator(__DIR__ . '/fixtures/sample-array.json', 2);

        self::assertSame(
            [
                [
                    ['id' => 1],
                    ['id' => 2],
                ],
                [
                    ['id' => 3],
                ],
            ],
            iterator_to_array($iterator, false),
        );
    }

    public function testReadIteratorSupportsNestedKeyPath(): void
    {
        $iterator = $this->reader->readIterator(
            __DIR__ . '/fixtures/nested.json',
            null,
            2,
            1,
            'key1.0.key2.0.key3',
        );

        self::assertSame(
            [
                ['id' => 2],
                ['id' => 3],
            ],
            iterator_to_array($iterator, false),
        );
    }

    public function testReadGeneratorSupportsLimitAndOffset(): void
    {
        $generator = $this->reader->readGenerator(__DIR__ . '/fixtures/sample-array.json', null, 2, 0);

        self::assertSame(
            [
                ['id' => 1],
                ['id' => 2],
            ],
            iterator_to_array($generator, false),
        );
    }

    public function testReadGeneratorSupportsNestedKeyPathAndChunking(): void
    {
        $generator = $this->reader->readGenerator(
            __DIR__ . '/fixtures/nested.json',
            2,
            null,
            0,
            'key1.0.key2.0.key3',
        );

        self::assertSame(
            [
                [
                    ['id' => 1],
                    ['id' => 2],
                ],
                [
                    ['id' => 3],
                ],
            ],
            iterator_to_array($generator, false),
        );
    }

    public function testReadGeneratorStreamsLargeTopLevelArray(): void
    {
        $filePath = $this->createTempJsonFile($this->buildArrayJson(5000));

        try {
            $generator = $this->reader->readGenerator($filePath, null, 3, 1000);
            $items = iterator_to_array($generator, false);

            self::assertSame(
                [
                    ['id' => 1001],
                    ['id' => 1002],
                    ['id' => 1003],
                ],
                $items,
            );
        } finally {
            $this->removeFileIfExists($filePath);
        }
    }

    public function testReadGeneratorStreamsLargeNestedArrayByKeyPath(): void
    {
        $nestedItems = $this->buildArrayJson(3000);
        $json = '{"data":[{"items":' . $nestedItems . '}]}';
        $filePath = $this->createTempJsonFile($json);

        try {
            $generator = $this->reader->readGenerator($filePath, 2, 4, 10, 'data.0.items');
            $items = iterator_to_array($generator, false);

            self::assertSame(
                [
                    [
                        ['id' => 11],
                        ['id' => 12],
                    ],
                    [
                        ['id' => 13],
                        ['id' => 14],
                    ],
                ],
                $items,
            );
        } finally {
            $this->removeFileIfExists($filePath);
        }
    }

    public function testReadThrowsWhenFileDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('was not found');

        $this->reader->read(__DIR__ . '/fixtures/missing.json');
    }

    public function testReadThrowsWhenChunkSizeIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be greater than 0.');

        $this->reader->read(__DIR__ . '/fixtures/sample-array.json', 0);
    }

    public function testReadThrowsWhenLimitIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be greater than 0.');

        $this->reader->read(__DIR__ . '/fixtures/sample-array.json', null, 0);
    }

    public function testReadThrowsWhenOffsetIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be greater than or equal to 0.');

        $this->reader->read(__DIR__ . '/fixtures/sample-array.json', null, null, -1);
    }

    public function testReadThrowsWhenJsonIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->reader->read(__DIR__ . '/fixtures/invalid.json');
    }

    public function testReadThrowsWhenRootIsNotArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('root value must be an array');

        $this->reader->read(__DIR__ . '/fixtures/object.json');
    }

    public function testReadThrowsRuntimeExceptionWhenResolvedKeyPathIsNotList(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must point to a JSON array list');

        $this->reader->read(
            __DIR__ . '/fixtures/nested-non-list.json',
            null,
            null,
            0,
            'key1.key2.key3',
        );
    }

    public function testGetFirstReturnsFirstItemForRootArray(): void
    {
        $item = $this->reader->getFirst(__DIR__ . '/fixtures/sample-array.json');

        self::assertSame(['id' => 1], $item);
    }

    public function testGetFirstReturnsFirstItemForNestedKeyPath(): void
    {
        $item = $this->reader->getFirst(__DIR__ . '/fixtures/nested.json', 'key1.0.key2.0.key3');

        self::assertSame(['id' => 1], $item);
    }

    public function testGetLastReturnsLastItemForRootArray(): void
    {
        $item = $this->reader->getLast(__DIR__ . '/fixtures/sample-array.json');

        self::assertSame(['id' => 3], $item);
    }

    public function testGetLastThrowsWhenTargetArrayIsEmpty(): void
    {
        $filePath = $this->createTempJsonFile('[]');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is empty');

            $this->reader->getLast($filePath);
        } finally {
            $this->removeFileIfExists($filePath);
        }
    }

    public function testGetNthReturnsItemByIndex(): void
    {
        $item = $this->reader->getNth(__DIR__ . '/fixtures/sample-array.json', 1);

        self::assertSame(['id' => 2], $item);
    }

    public function testGetNthThrowsWhenIndexIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Index must be greater than or equal to 0.');

        $this->reader->getNth(__DIR__ . '/fixtures/sample-array.json', -1);
    }

    public function testGetNthThrowsWhenIndexIsOutOfRange(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index 99 not found');

        $this->reader->getNth(__DIR__ . '/fixtures/sample-array.json', 99);
    }

    public function testForEachProcessesAllItemsAndReturnsCount(): void
    {
        $collected = [];

        $count = $this->reader->forEach(
            __DIR__ . '/fixtures/sample-array.json',
            static function (mixed $item) use (&$collected): void {
                $collected[] = $item;
            },
        );

        self::assertSame(3, $count);
        self::assertSame([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ], $collected);
    }

    public function testForEachSupportsNestedKeyPath(): void
    {
        $ids = [];

        $count = $this->reader->forEach(
            __DIR__ . '/fixtures/nested.json',
            static function (mixed $item) use (&$ids): void {
                $ids[] = $item['id'] ?? null;
            },
            'key1.0.key2.0.key3',
        );

        self::assertSame(3, $count);
        self::assertSame([1, 2, 3], $ids);
    }

    public function testForEachBubblesCallbackException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stop');

        $this->reader->forEach(
            __DIR__ . '/fixtures/sample-array.json',
            static function (): void {
                throw new RuntimeException('stop');
            },
        );
    }

    private function createTempJsonFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'json_chunk_');

        if ($path === false) {
            self::fail('Unable to create temp file for test.');
        }

        $written = file_put_contents($path, $content);
        if ($written === false) {
            $this->removeFileIfExists($path);
            self::fail('Unable to write temp JSON fixture for test.');
        }

        return $path;
    }

    private function removeFileIfExists(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function removeDirectoryIfExists(string $path): void
    {
        if (is_dir($path)) {
            rmdir($path);
        }
    }

    private function buildArrayJson(int $count): string
    {
        $parts = [];

        for ($i = 1; $i <= $count; $i++) {
            $parts[] = sprintf('{"id":%d}', $i);
        }

        return '[' . implode(',', $parts) . ']';
    }
}
