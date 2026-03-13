<?php

declare(strict_types=1);

namespace PhpJsonChunk\Contract;

use Generator;
use Iterator;

interface JsonChunkReaderInterface
{
    /**
     * Reads a JSON file and returns data split into chunks.
     *
     * @return array<int, array<int, mixed>>
     */
    public function read(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
    ): array;

    /**
     * Returns an iterator of items (or chunks when chunk size is provided).
     */
    public function readIterator(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
    ): Iterator;

    /**
     * Returns a generator of items (or chunks when chunk size is provided).
     *
     * @return Generator<int, mixed>
     */
    public function readGenerator(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
    ): Generator;
}
