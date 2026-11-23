<?php

declare(strict_types=1);

namespace PhpJsonChunk;

use InvalidArgumentException;
use JsonException;

/**
 * Where the entries of one container in one file start — the first, then about every `every` — so
 * that JsonChunkReader::readFrom() can seek to the entry asked for instead of reading every one
 * before it. Made by JsonChunkReader::index(); saved with toJson() and loaded with fromJson(), so one
 * process can build it and others use it.
 *
 * It belongs to the file as it was when indexed: its size and modification time are kept, and a file
 * that has changed since is refused rather than read at the wrong places.
 */
final class JsonIndex
{
    /** The $container of an index of a sequence of documents, from JsonChunkReader::indexValues(). */
    public const VALUES = 'values';

    /**
     * @param string|array<int, string>|null $keyPath
     * @param array<int, array{0: int, 1: int}> $checkpoints `[entry, byte]` pairs, entries ascending
     * @param string|null $tail a digest of the last bytes before $size, for extendIndex() to tell a
     *                          file only appended to from one changed; null in an index saved before 2.2.0
     * @param int|null $every how far apart the checkpoints were asked to be, for extendIndex() to go on
     */
    public function __construct(
        public readonly string $path,
        public readonly string|array|null $keyPath,
        public readonly string $container,
        private readonly int $count,
        private readonly array $checkpoints,
        public readonly int $size,
        public readonly int $modified,
        public readonly string|null $tail = null,
        public readonly int|null $every = null,
    ) {
        // Looked up by binary search, which takes them to ascend; one out of place would land a read at
        // another entry's start and number every entry after it wrongly, without a word.
        if ($count < 0) {
            throw new InvalidArgumentException('Not an index: the count is negative.');
        }

        if ($every !== null && $every < 1) {
            throw new InvalidArgumentException('Not an index: every must be at least 1.');
        }

        if ($count > 0 && ($checkpoints[0][0] ?? null) !== 0) {
            throw new InvalidArgumentException('Not an index: there is no checkpoint for the first entry.');
        }

        $previous = null;
        foreach ($checkpoints as [$entry, $byte]) {
            if ($byte < 0) {
                throw new InvalidArgumentException(sprintf('Not an index: checkpoint %d is at a negative byte.', $entry));
            }

            if ($entry >= $count) {
                throw new InvalidArgumentException(sprintf('Not an index: checkpoint %d is past the %d entries.', $entry, $count));
            }

            if ($previous !== null && ($entry <= $previous[0] || $byte <= $previous[1])) {
                throw new InvalidArgumentException(sprintf('Not an index: checkpoints must be ascending, and %d at byte %d comes after %d at byte %d.', $entry, $byte, $previous[0], $previous[1]));
            }

            $previous = [$entry, $byte];
        }
    }

    /**
     * How many entries the container has.
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Kept as `[entry, byte]` pairs — thousands of them in a large index, and a pair costs less than an
     * object — and handed out as objects.
     *
     * @return array<int, Checkpoint> entries ascending
     */
    public function checkpoints(): array
    {
        return array_map(static fn(array $pair): Checkpoint => new Checkpoint($pair[0], $pair[1]), $this->checkpoints);
    }

    /**
     * The last checkpoint at or before $entry.
     *
     * @return Checkpoint|null null when there is none — an empty container
     */
    public function checkpointBefore(int $entry): Checkpoint|null
    {
        $low = 0;
        $high = count($this->checkpoints) - 1;
        $found = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->checkpoints[$middle][0] <= $entry) {
                $found = $this->checkpoints[$middle];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found === null ? null : new Checkpoint($found[0], $found[1]);
    }

    /**
     * The container's entries split into at most $parts runs of consecutive entries, as even as can be
     * — sizes differ by one at most — for separate workers to read side by side, each with
     * JsonChunkReader::readFrom($index, $part->offset, $part->limit). No part is empty, so there are
     * fewer parts than asked when there are fewer entries.
     *
     * @return array<int, Partition> keyed by each part's number, from 0
     *
     * @throws InvalidArgumentException
     */
    public function partitions(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('At least one part is needed.');
        }

        $parts = min($parts, $this->count);
        $partitions = [];
        $offset = 0;
        for ($part = 0; $part < $parts; $part++) {
            $limit = intdiv($this->count, $parts) + ($part < $this->count % $parts ? 1 : 0);
            $partitions[] = new Partition($part, $offset, $limit);
            $offset += $limit;
        }

        return $partitions;
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode([
            'path' => $this->path,
            'keyPath' => $this->keyPath,
            'container' => $this->container,
            'count' => $this->count,
            'checkpoints' => $this->checkpoints,
            'size' => $this->size,
            'modified' => $this->modified,
            'tail' => $this->tail,
            'every' => $this->every,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Not a saved index: ' . $exception->getMessage(), 0, $exception);
        }

        if (
            !is_array($data)
            || !is_string($data['path'] ?? null)
            || !in_array($data['container'] ?? null, ['[', '{', self::VALUES], true)
            || !is_int($data['count'] ?? null)
            || !is_array($data['checkpoints'] ?? null)
            || !is_int($data['size'] ?? null)
            || !is_int($data['modified'] ?? null)
        ) {
            throw new InvalidArgumentException('Not a saved index: a field is missing or of the wrong type.');
        }

        $keyPath = $data['keyPath'] ?? null;
        if (!is_string($keyPath) && !is_array($keyPath) && $keyPath !== null) {
            throw new InvalidArgumentException('Not a saved index: keyPath is of the wrong type.');
        }

        $checkpoints = [];
        foreach ($data['checkpoints'] as $checkpoint) {
            if (!is_array($checkpoint) || !is_int($checkpoint[0] ?? null) || !is_int($checkpoint[1] ?? null)) {
                throw new InvalidArgumentException('Not a saved index: a checkpoint is not an [entry, byte] pair.');
            }
            $checkpoints[] = [$checkpoint[0], $checkpoint[1]];
        }

        // Both missing from an index saved before 2.2.0.
        $tail = $data['tail'] ?? null;
        $every = $data['every'] ?? null;
        if ((!is_string($tail) && $tail !== null) || (!is_int($every) && $every !== null)) {
            throw new InvalidArgumentException('Not a saved index: tail or every is of the wrong type.');
        }

        /** @var array<int, string>|string|null $keyPath */
        return new self($data['path'], $keyPath, $data['container'], $data['count'], $checkpoints, $data['size'], $data['modified'], $tail, $every);
    }
}
