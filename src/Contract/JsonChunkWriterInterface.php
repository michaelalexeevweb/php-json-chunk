<?php

declare(strict_types=1);

namespace PhpJsonChunk\Contract;

use InvalidArgumentException;
use RuntimeException;

/**
 * Writes JSON from an iterable, an entry at a time: what json_encode() of the whole would give, with
 * memory that does not grow with it.
 */
interface JsonChunkWriterInterface
{
    /**
     * @param resource|string $target a path — written whole or not at all — or an open stream
     * @param iterable<mixed> $entries
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function writeArray(mixed $target, iterable $entries, bool $raw = false): int;

    /**
     * @param resource|string $target
     * @param iterable<mixed, mixed> $members
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function writeObject(mixed $target, iterable $members, bool $raw = false): int;

    /**
     * @param resource|string $target
     * @param iterable<mixed> $documents
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function writeValues(mixed $target, iterable $documents, bool $raw = false): int;

    /**
     * Documents added to the end of a sequence, one to a line, the file locked meanwhile.
     *
     * @param iterable<mixed> $documents
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function appendValues(string $path, iterable $documents, bool $raw = false): int;
}
