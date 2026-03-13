<?php

declare(strict_types=1);

namespace PhpJsonChunk;

use Generator;
use InvalidArgumentException;
use Iterator;
use JsonException;
use PhpJsonChunk\Contract\JsonChunkReaderInterface;
use RuntimeException;

final class JsonChunkReader implements JsonChunkReaderInterface
{
    /**
     * @var array<int, string>
     */
    private array $charBuffer = [];

    /**
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
    ): array {
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        $result = [];

        foreach ($this->readGenerator($filePath, $chunkSize, $limit, $offset, $keyPath) as $item) {
            $result[] = $item;
        }

        if ($chunkSize === null) {
            return [$result];
        }

        return $result;
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function readIterator(
        string $filePath,
        int|null $chunkSize = null,
        int|null $limit = null,
        int $offset = 0,
        string|null $keyPath = null,
    ): Iterator {
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        return $this->readGenerator($filePath, $chunkSize, $limit, $offset, $keyPath);
    }

    /**
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
    ): Generator {
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        $handle = $this->openFile($filePath);
        $this->charBuffer = [];

        try {
            $this->positionStreamAtTargetArrayStart($handle, $keyPath);

            $processed = 0;
            $taken = 0;
            $currentChunk = [];

            foreach ($this->streamArrayValues($handle, $filePath) as $value) {
                if ($processed < $offset) {
                    $processed++;
                    continue;
                }

                if ($limit !== null && $taken >= $limit) {
                    break;
                }

                if ($chunkSize === null) {
                    yield $value;
                } else {
                    $currentChunk[] = $value;

                    if (count($currentChunk) >= $chunkSize) {
                        yield $currentChunk;
                        $currentChunk = [];
                    }
                }

                $processed++;
                $taken++;
            }

            if ($chunkSize !== null && $currentChunk !== []) {
                yield $currentChunk;
            }
        } finally {
            fclose($handle);
            $this->charBuffer = [];
        }
    }

    /**
     * @return resource
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function openFile(string $filePath)
    {
        if (!is_file($filePath)) {
            throw new InvalidArgumentException(sprintf('JSON file "%s" was not found.', $filePath));
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to read JSON file "%s".', $filePath));
        }

        return $handle;
    }

    /**
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function positionStreamAtTargetArrayStart($handle, string|null $keyPath): void
    {
        $first = $this->readNonWhitespaceChar($handle);
        if ($first === null) {
            throw new InvalidArgumentException('Invalid JSON in file: empty content.');
        }

        if ($keyPath !== null && $keyPath !== '') {
            $segments = explode('.', $keyPath);

            foreach ($segments as $segment) {
                if ($segment === '') {
                    throw new InvalidArgumentException('Key path must not contain empty segments.');
                }
            }

            $resolved = $this->seekPath($handle, $first, $segments, 0, $keyPath);

            if ($resolved !== '[') {
                throw new RuntimeException(
                    sprintf('Resolved key path "%s" must point to a JSON array list.', $keyPath),
                );
            }

            return;
        }

        if ($first !== '[') {
            throw new InvalidArgumentException('The JSON root value must be an array.');
        }
    }

    /**
     * @param resource $handle
     *
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function streamArrayValues($handle, string $filePath): Generator
    {
        $next = $this->readNonWhitespaceChar($handle);
        if ($next === ']') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": unexpected end of input in array.', $filePath),
            );
        }

        $this->unreadChar($next);

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($handle);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": unexpected end of input in array value.', $filePath),
                );
            }

            $rawValue = $this->readValueAsJson($handle, $valueFirst, $filePath);

            try {
                yield json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": %s', $filePath, $exception->getMessage()),
                    0,
                    $exception,
                );
            }

            $delimiter = $this->readNonWhitespaceChar($handle);

            if ($delimiter === ']') {
                break;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": expected "," or "]" in array.', $filePath),
                );
            }
        }
    }

    /**
     * @param resource $handle
     * @param array<int, string> $segments
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function seekPath($handle, string $firstChar, array $segments, int $depth, string $keyPath): string
    {
        if ($depth >= count($segments)) {
            return $firstChar;
        }

        $segment = $segments[$depth];

        if ($firstChar === '{') {
            return $this->seekPathInObject($handle, $segments, $depth, $segment, $keyPath);
        }

        if ($firstChar === '[') {
            if (!ctype_digit($segment)) {
                throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
            }

            return $this->seekPathInArray($handle, $segments, $depth, (int)$segment, $keyPath);
        }

        throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
    }

    /**
     * @param resource $handle
     * @param array<int, string> $segments
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function seekPathInObject($handle, array $segments, int $depth, string $segment, string $keyPath): string
    {
        $next = $this->readNonWhitespaceChar($handle);
        if ($next === '}') {
            throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
        }

        if ($next === null) {
            throw new InvalidArgumentException('Invalid JSON in file: unexpected end of input in object.');
        }

        $this->unreadChar($next);

        while (true) {
            $keyFirst = $this->readNonWhitespaceChar($handle);
            if ($keyFirst !== '"') {
                throw new InvalidArgumentException('Invalid JSON in file: object key must be a string.');
            }

            $keyToken = $this->readStringToken($handle, null);

            try {
                $decodedKey = json_decode($keyToken, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage(null, $exception->getMessage()),
                    0,
                    $exception,
                );
            }

            $separator = $this->readNonWhitespaceChar($handle);
            if ($separator !== ':') {
                throw new InvalidArgumentException('Invalid JSON in file: expected ":" after object key.');
            }

            $valueFirst = $this->readNonWhitespaceChar($handle);
            if ($valueFirst === null) {
                throw new InvalidArgumentException('Invalid JSON in file: unexpected end of input in object value.');
            }

            if ($decodedKey === $segment) {
                return $this->seekPath($handle, $valueFirst, $segments, $depth + 1, $keyPath);
            }

            $this->skipValue($handle, $valueFirst, null);

            $delimiter = $this->readNonWhitespaceChar($handle);
            if ($delimiter === '}') {
                break;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException('Invalid JSON in file: expected "," or "}" in object.');
            }
        }

        throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
    }

    /**
     * @param resource $handle
     * @param array<int, string> $segments
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function seekPathInArray($handle, array $segments, int $depth, int $targetIndex, string $keyPath): string
    {
        $next = $this->readNonWhitespaceChar($handle);
        if ($next === ']') {
            $segment = $segments[$depth];
            throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
        }

        if ($next === null) {
            throw new InvalidArgumentException('Invalid JSON in file: unexpected end of input in array.');
        }

        $this->unreadChar($next);

        $index = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($handle);
            if ($valueFirst === null) {
                throw new InvalidArgumentException('Invalid JSON in file: unexpected end of input in array value.');
            }

            if ($index === $targetIndex) {
                return $this->seekPath($handle, $valueFirst, $segments, $depth + 1, $keyPath);
            }

            $this->skipValue($handle, $valueFirst, null);

            $delimiter = $this->readNonWhitespaceChar($handle);
            if ($delimiter === ']') {
                break;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException('Invalid JSON in file: expected "," or "]" in array.');
            }

            $index++;
        }

        $segment = $segments[$depth];
        throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
    }

    /**
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     */
    private function readValueAsJson($handle, string $firstChar, string $filePath): string
    {
        return match ($firstChar) {
            '{' => $this->readObjectAsJson($handle, $filePath),
            '[' => $this->readArrayAsJson($handle, $filePath),
            '"' => $this->readStringToken($handle, $filePath),
            default => $this->readPrimitiveToken($handle, $firstChar),
        };
    }

    /**
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     */
    private function readObjectAsJson($handle, string|null $filePath): string
    {
        $pairs = [];
        $next = $this->readNonWhitespaceChar($handle);

        if ($next === '}') {
            return '{}';
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in object.'),
            );
        }

        $this->unreadChar($next);

        while (true) {
            $keyFirst = $this->readNonWhitespaceChar($handle);
            if ($keyFirst !== '"') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'object key must be a string.'),
                );
            }

            $key = $this->readStringToken($handle, $filePath);

            $separator = $this->readNonWhitespaceChar($handle);
            if ($separator !== ':') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected ":" after object key.'),
                );
            }

            $valueFirst = $this->readNonWhitespaceChar($handle);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in object value.'),
                );
            }

            $pairs[] = $key . ':' . $this->readValueAsJson($handle, $valueFirst, $filePath ?? '');

            $delimiter = $this->readNonWhitespaceChar($handle);
            if ($delimiter === '}') {
                break;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "}" in object.'),
                );
            }
        }

        return '{' . implode(',', $pairs) . '}';
    }

    /**
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     */
    private function readArrayAsJson($handle, string|null $filePath): string
    {
        $items = [];
        $next = $this->readNonWhitespaceChar($handle);

        if ($next === ']') {
            return '[]';
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in array.'),
            );
        }

        $this->unreadChar($next);

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($handle);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.'),
                );
            }

            $items[] = $this->readValueAsJson($handle, $valueFirst, $filePath ?? '');

            $delimiter = $this->readNonWhitespaceChar($handle);
            if ($delimiter === ']') {
                break;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "]" in array.'),
                );
            }
        }

        return '[' . implode(',', $items) . ']';
    }

    /**
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     */
    private function readStringToken($handle, string|null $filePath): string
    {
        $token = '"';
        $escaped = false;

        while (true) {
            $char = $this->readChar($handle);
            if ($char === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in string.'),
                );
            }

            $token .= $char;

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                break;
            }
        }

        return $token;
    }

    /**
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     */
    private function readPrimitiveToken($handle, string $firstChar): string
    {
        $token = $firstChar;

        while (true) {
            $char = $this->readChar($handle);
            if ($char === null) {
                break;
            }

            if ($this->isJsonDelimiter($char)) {
                $this->unreadChar($char);
                break;
            }

            $token .= $char;
        }

        return $token;
    }

    /**
     * @param resource $handle
     */
    private function skipValue($handle, string $firstChar, string|null $filePath): void
    {
        if ($firstChar === '"') {
            $this->readStringToken($handle, $filePath);
            return;
        }

        if ($firstChar === '{') {
            $next = $this->readNonWhitespaceChar($handle);
            if ($next === '}') {
                return;
            }

            if ($next === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in object.'),
                );
            }

            $this->unreadChar($next);

            while (true) {
                $keyFirst = $this->readNonWhitespaceChar($handle);
                if ($keyFirst !== '"') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'object key must be a string.'),
                    );
                }

                $this->readStringToken($handle, $filePath);

                $separator = $this->readNonWhitespaceChar($handle);
                if ($separator !== ':') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'expected ":" after object key.'),
                    );
                }

                $valueFirst = $this->readNonWhitespaceChar($handle);
                if ($valueFirst === null) {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'unexpected end of input in object value.'),
                    );
                }

                $this->skipValue($handle, $valueFirst, $filePath);

                $delimiter = $this->readNonWhitespaceChar($handle);
                if ($delimiter === '}') {
                    return;
                }

                if ($delimiter !== ',') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'expected "," or "}" in object.'),
                    );
                }
            }
        }

        if ($firstChar === '[') {
            $next = $this->readNonWhitespaceChar($handle);
            if ($next === ']') {
                return;
            }

            if ($next === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in array.'),
                );
            }

            $this->unreadChar($next);

            while (true) {
                $valueFirst = $this->readNonWhitespaceChar($handle);
                if ($valueFirst === null) {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.'),
                    );
                }

                $this->skipValue($handle, $valueFirst, $filePath);

                $delimiter = $this->readNonWhitespaceChar($handle);
                if ($delimiter === ']') {
                    return;
                }

                if ($delimiter !== ',') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'expected "," or "]" in array.'),
                    );
                }
            }
        }

        $this->readPrimitiveToken($handle, $firstChar);
    }

    /**
     * @param resource $handle
     */
    private function readNonWhitespaceChar($handle): string|null
    {
        while (true) {
            $char = $this->readChar($handle);
            if ($char === null) {
                return null;
            }

            if (!ctype_space($char)) {
                return $char;
            }
        }
    }

    /**
     * @param resource $handle
     */
    private function readChar($handle): string|null
    {
        if ($this->charBuffer !== []) {
            return array_pop($this->charBuffer);
        }

        $char = fgetc($handle);

        if ($char === false) {
            return null;
        }

        return $char;
    }

    private function unreadChar(string $char): void
    {
        $this->charBuffer[] = $char;
    }

    private function isJsonDelimiter(string $char): bool
    {
        return ctype_space($char) || $char === ',' || $char === ']' || $char === '}';
    }

    private function invalidJsonMessage(string|null $filePath, string $detail): string
    {
        if ($filePath === null || $filePath === '') {
            return sprintf('Invalid JSON in file: %s', $detail);
        }

        return sprintf('Invalid JSON in file "%s": %s', $filePath, $detail);
    }

    private function assertLimitAndOffset(int|null $limit, int $offset): void
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be greater than or equal to 0.');
        }

        if ($limit !== null && $limit <= 0) {
            throw new InvalidArgumentException('Limit must be greater than 0.');
        }
    }

    private function assertChunkSize(int|null $chunkSize): void
    {
        if ($chunkSize !== null && $chunkSize <= 0) {
            throw new InvalidArgumentException('Chunk size must be greater than 0.');
        }
    }
}
