<?php

declare(strict_types=1);

namespace PhpJsonChunk\Contract;

use Generator;
use InvalidArgumentException;
use Iterator;
use RuntimeException;

interface JsonChunkReaderInterface
{
    /**
     * Returns the total number of elements in the target JSON array.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function count(string $filePath, string|null $keyPath = null): int;

    /**
     * Reads a JSON file and returns data split into chunks.
     *
     * @return array<int, array<int, mixed>>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function read(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
        string|null $tempChunkDir = null,
    ): array;

    /**
     * Returns an iterator of items (or chunks when chunk size is provided).
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function readIterator(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
        string|null $tempChunkDir = null,
    ): Iterator;

    /**
     * Returns a generator of items (or chunks when chunk size is provided).
     *
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function readGenerator(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
        string|null $tempChunkDir = null,
    ): Generator;

    /**
     * Returns the first element of the target JSON array.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getFirst(string $filePath, string|null $keyPath = null): mixed;

    /**
     * Returns the last element of the target JSON array.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getLast(string $filePath, string|null $keyPath = null): mixed;

    /**
     * Returns the Nth element (0-based index) of the target JSON array.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getNth(string $filePath, int $index, string|null $keyPath = null): mixed;

    /**
     * Executes a callback for each element in the target JSON array.
     *
     * @param callable(mixed): void $callback
     *
     * @return int Total number of elements processed
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function forEach(
        string $filePath,
        callable $callback,
        string|null $keyPath = null,
    ): int;
}
