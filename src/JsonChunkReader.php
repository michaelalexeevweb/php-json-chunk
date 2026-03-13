<?php

declare(strict_types=1);

namespace PhpJsonChunk;

use ArrayIterator;
use Generator;
use InvalidArgumentException;
use JsonException;
use PhpJsonChunk\Contract\JsonChunkReaderInterface;
use Iterator;
use RuntimeException;

final class JsonChunkReader implements JsonChunkReaderInterface
{
    /**
     * @return array<int, array<int, mixed>>
     */
    public function read(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
    ): array
    {
        $decoded = $this->loadList($filePath, $keyPath);
        $window = $this->applyWindow($decoded, $limit, $offset);

        $this->assertChunkSize($chunkSize);

        if ($chunkSize === null) {
            return [$window];
        }

        $chunks = array_chunk($window, $chunkSize);

        return $chunks;
    }

    public function readIterator(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
    ): Iterator
    {
        $decoded = $this->loadList($filePath, $keyPath);
        $window = $this->applyWindow($decoded, $limit, $offset);

        if ($chunkSize === null) {
            return new ArrayIterator($window);
        }

        $this->assertChunkSize($chunkSize);

        return new ArrayIterator(array_chunk($window, $chunkSize));
    }

    /**
     * @return Generator<int, mixed>
     */
    public function readGenerator(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
    ): Generator
    {
        $decoded = $this->loadList($filePath, $keyPath);
        $window = $this->applyWindow($decoded, $limit, $offset);

        if ($chunkSize === null) {
            foreach ($window as $item) {
                yield $item;
            }

            return;
        }

        $this->assertChunkSize($chunkSize);

        foreach (array_chunk($window, $chunkSize) as $chunk) {
            yield $chunk;
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function loadList(string $filePath, string|null $keyPath): array
    {
        if (!is_file($filePath)) {
            throw new InvalidArgumentException(sprintf('JSON file "%s" was not found.', $filePath));
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read JSON file "%s".', $filePath));
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": %s', $filePath, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if ($keyPath !== null && $keyPath !== '') {
            $decoded = $this->resolveByPath($decoded, $keyPath);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            if ($keyPath !== null && $keyPath !== '') {
                throw new RuntimeException(sprintf('Resolved key path "%s" must point to a JSON array list.', $keyPath));
            }

            throw new InvalidArgumentException('The JSON root value must be an array.');
        }

        return $decoded;
    }

    private function resolveByPath(mixed $data, string $keyPath): mixed
    {
        $segments = explode('.', $keyPath);
        $current = $data;

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException('Key path must not contain empty segments.');
            }

            if (!is_array($current) || !array_key_exists($segment, $current)) {
                throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<int, mixed> $data
     *
     * @return array<int, mixed>
     */
    private function applyWindow(array $data, int|null $limit, int $offset): array
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be greater than or equal to 0.');
        }

        if ($limit !== null && $limit <= 0) {
            throw new InvalidArgumentException('Limit must be greater than 0.');
        }

        return array_slice($data, $offset, $limit);
    }

    private function assertChunkSize(int|null $chunkSize): void
    {
        if ($chunkSize !== null && $chunkSize <= 0) {
            throw new InvalidArgumentException('Chunk size must be greater than 0.');
        }
    }
}
