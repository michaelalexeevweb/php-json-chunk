<?php

declare(strict_types=1);

namespace PhpJsonChunk\Contract;

use Generator;
use InvalidArgumentException;
use Iterator;
use PhpJsonChunk\Contract\JsonSourceInterface;
use RuntimeException;

interface JsonChunkReaderInterface
{
    /**
     * Returns the total number of elements in the target JSON array.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function count(string|JsonSourceInterface $filePath, string|array|null $keyPath = null): int;

    /**
     * Reads a JSON file and returns data split into chunks.
     *
     * @return array<int, array<int, mixed>>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function read(
        string|JsonSourceInterface $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|array|null $keyPath = null,
        string|null $tempChunkDir = null,
    ): array;

    /**
     * Returns an iterator of items (or chunks when chunk size is provided).
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function readIterator(
        string|JsonSourceInterface $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|array|null $keyPath = null,
        string|null $tempChunkDir = null,
    ): Iterator;

    /**
     * Returns a generator of items (or chunks when chunk size is provided).
     *
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function readGenerator(
        string|JsonSourceInterface $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|array|null $keyPath = null,
        string|null $tempChunkDir = null,
    ): Generator;

    /**
     * Returns the first element of the target JSON array.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function getFirst(string|JsonSourceInterface $filePath, string|array|null $keyPath = null): mixed;

    /**
     * Returns the last element of the target JSON array.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function getLast(string|JsonSourceInterface $filePath, string|array|null $keyPath = null): mixed;

    /**
     * Returns the Nth element (0-based index) of the target JSON array.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function getNth(string|JsonSourceInterface $filePath, int $index, string|array|null $keyPath = null): mixed;

    /**
     * Several key paths, read in ONE pass over the document, yielding `path => value`.
     *
     * The key is the path that matched, so a `foreach` reads as "this value, from that path".
     * Generators allow repeated keys, so a path matching many values simply appears many times.
     *
     * @param array<int, string|array<int, string>> $keyPaths
     *
     * @return Generator<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function readPaths(string|JsonSourceInterface $filePath, array $keyPaths): Generator;

    /**
     * Executes a callback for each element in the target JSON array.
     *
     * @param callable(mixed): mixed $callback returning `false` stops the walk; any other value,
     *                                          including none, carries on
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     *
     * @return int Total number of elements processed
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function forEach(
        string|JsonSourceInterface $filePath,
        callable $callback,
        string|array|null $keyPath = null,
    ): int;
}
