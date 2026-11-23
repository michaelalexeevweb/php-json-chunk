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
    /**
     * @param string|array<int, string>|null $keyPath
     * @param array<int, array{0: int, 1: int}> $checkpoints `[entry, byte]` pairs, entries ascending
     */
    public function __construct(
        public readonly string $path,
        public readonly string|array|null $keyPath,
        public readonly string $container,
        private readonly int $count,
        private readonly array $checkpoints,
        public readonly int $size,
        public readonly int $modified,
    ) {
    }

    /**
     * How many entries the container has.
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * @return array<int, array{0: int, 1: int}> `[entry, byte]`: entry number `entry` starts at `byte`
     */
    public function checkpoints(): array
    {
        return $this->checkpoints;
    }

    /**
     * The last checkpoint at or before $entry.
     *
     * @return array{0: int, 1: int}|null
     */
    public function checkpointBefore(int $entry): array|null
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

        return $found;
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
            || !in_array($data['container'] ?? null, ['[', '{'], true)
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

        /** @var array<int, string>|string|null $keyPath */
        return new self($data['path'], $keyPath, $data['container'], $data['count'], $checkpoints, $data['size'], $data['modified']);
    }
}
