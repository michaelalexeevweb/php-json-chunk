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
    private const DEFAULT_TEMP_CHUNK_SIZE = 1000;

    /** Size of each fread() block in bytes. */
    private const BLOCK_SIZE = 65536;

    /**
     * One-char look-ahead stack (used only by seekPath methods).
     *
     * @var array<int, string>
     */
    private array $charBuffer = [];

    /** Current read block content. */
    private string $buf = '';

    /** Next unread byte position inside $buf. */
    private int $bufPos = 0;

    /** Number of valid bytes in $buf. */
    private int $bufLen = 0;

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function count(string $filePath, string|null $keyPath = null): int
    {
        if ($keyPath !== null && $keyPath !== '' && $this->hasWildcardSegment($keyPath)) {
            return $this->countForWildcardKeyPath($filePath, $keyPath);
        }

        $handle = $this->openFile($filePath);
        $this->resetReadState();

        try {
            $this->positionStreamAtTargetArrayStart($handle, $keyPath);

            return $this->countArrayValues($handle, $filePath);
        } finally {
            fclose($handle);
            $this->resetReadState();
        }
    }

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
        string|null $tempChunkDir = null,
    ): array {
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        $result = [];

        foreach ($this->readGenerator($filePath, $chunkSize, $limit, $offset, $keyPath, $tempChunkDir) as $item) {
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
        string|null $tempChunkDir = null,
    ): Iterator {
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        return $this->readGenerator($filePath, $chunkSize, $limit, $offset, $keyPath, $tempChunkDir);
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
        string|null $tempChunkDir = null,
    ): Generator {
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        if ($tempChunkDir !== null && $tempChunkDir !== '') {
            $chunkFiles = $this->createTemporaryChunkFiles(
                filePath: $filePath,
                chunkSize: $chunkSize,
                limit: $limit,
                offset: $offset,
                keyPath: $keyPath,
                tempChunkDir: $tempChunkDir,
            );

            yield from $this->yieldFromTemporaryChunks($chunkFiles, $chunkSize === null);

            return;
        }

        yield from $this->readGeneratorWithoutTemporaryChunks($filePath, $chunkSize, $limit, $offset, $keyPath);
    }

    /**
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function readGeneratorWithoutTemporaryChunks(
        string $filePath,
        int|null $chunkSize,
        int|null $limit,
        int $offset,
        string|null $keyPath,
    ): Generator {
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        if ($keyPath !== null && $keyPath !== '' && $this->hasWildcardSegment($keyPath)) {
            yield from $this->readGeneratorWithWildcardKeyPath($filePath, $chunkSize, $limit, $offset, $keyPath);

            return;
        }

        $handle = $this->openFile($filePath);
        $this->resetReadState();

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
            $this->resetReadState();
        }
    }

    /**
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function createTemporaryChunkFiles(
        string $filePath,
        int|null $chunkSize,
        int|null $limit,
        int $offset,
        string|null $keyPath,
        string $tempChunkDir,
    ): array {
        $resolvedTempChunkDir = $this->prepareTempChunkDirectory($tempChunkDir);
        $streamChunkSize = $chunkSize ?? self::DEFAULT_TEMP_CHUNK_SIZE;
        $chunkFiles = [];

        try {
            foreach (
                $this->readGeneratorWithoutTemporaryChunks(
                    filePath: $filePath,
                    chunkSize: $streamChunkSize,
                    limit: $limit,
                    offset: $offset,
                    keyPath: $keyPath,
                ) as $chunk
            ) {
                if (!is_array($chunk)) {
                    throw new RuntimeException('Temporary chunk serialization expects an array chunk.');
                }

                $chunkFiles[] = $this->writeTemporaryChunk($resolvedTempChunkDir, $chunk);
            }
        } catch (RuntimeException|InvalidArgumentException $exception) {
            foreach ($chunkFiles as $chunkFile) {
                $this->removeFileIfExists($chunkFile);
            }

            throw $exception;
        }

        return $chunkFiles;
    }

    /**
     * @param array<int, string> $chunkFiles
     *
     * @return Generator<int, mixed>
     *
     * @throws RuntimeException
     */
    private function yieldFromTemporaryChunks(array $chunkFiles, bool $yieldItems): Generator
    {
        try {
            foreach ($chunkFiles as $chunkFile) {
                $chunk = $this->readTemporaryChunk($chunkFile);

                if ($yieldItems) {
                    foreach ($chunk as $item) {
                        yield $item;
                    }

                    continue;
                }

                yield $chunk;
            }
        } finally {
            foreach ($chunkFiles as $chunkFile) {
                $this->removeFileIfExists($chunkFile);
            }
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
            $segments = $this->splitAndValidateKeyPath($keyPath);

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

            // Hot path: use block-buffer scanners (strcspn at C level) instead of
            // per-character readValueAsJson + string concatenation.
            $rawValue = $this->scanValue($handle, $valueFirst, $filePath);

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

    // ─── block-buffer scanners (hot path) ────────────────────────────────────

    /**
     * Refills the internal buffer from the handle.
     *
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function refillBuffer($handle, string|null $filePath = null, string $detail = ''): void
    {
        $chunk = fread($handle, self::BLOCK_SIZE);

        if ($chunk === false || $chunk === '') {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, $detail ?: 'unexpected end of input.'),
            );
        }

        $this->buf = $chunk;
        $this->bufLen = strlen($chunk);
        $this->bufPos = 0;
    }

    /**
     * Dispatches to the correct scanner based on the first character.
     *
     * Returns the raw JSON bytes of the value (ready for json_decode).
     *
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function scanValue($handle, string $firstChar, string $filePath): string
    {
        if ($firstChar === '"') {
            return '"' . $this->scanStringRemainder($handle, $filePath);
        }

        if ($firstChar === '{' || $firstChar === '[') {
            return $this->scanNested($handle, $firstChar, $filePath);
        }

        return $this->scanPrimitive($handle, $firstChar);
    }

    /**
     * Scans the body of a JSON string from the byte AFTER the opening `"` until the
     * matching closing `"` (inclusive).  Uses strcspn() to skip plain bytes in bulk.
     *
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function scanStringRemainder($handle, string $filePath): string
    {
        $result = '';

        while (true) {
            // Refill block buffer if needed.
            if ($this->bufPos >= $this->bufLen) {
                $this->refillBuffer($handle, $filePath, 'unexpected end of input in string.');
            }

            // Find the next `\` or `"` in the current block (C-level scan).
            $spanLen = strcspn($this->buf, '\\"', $this->bufPos);
            $segEnd = $this->bufPos + $spanLen;

            if ($segEnd >= $this->bufLen) {
                // No special char before end of block — consume all remaining bytes.
                $result .= substr($this->buf, $this->bufPos);
                $this->bufPos = $this->bufLen;
                continue;
            }

            $special = $this->buf[$segEnd];
            $result .= substr($this->buf, $this->bufPos, $spanLen + 1); // include special char
            $this->bufPos = $segEnd + 1;

            if ($special === '"') {
                return $result; // closing quote found
            }

            // Escape sequence (`\`): consume one more byte (the escaped character).
            if ($this->bufPos >= $this->bufLen) {
                $this->refillBuffer($handle, $filePath, 'unexpected end of input in string.');
            }

            $result .= $this->buf[$this->bufPos];
            $this->bufPos++;
        }
    }

    /**
     * Scans a JSON object `{…}` or array `[…]` from the opening bracket until the
     * balanced closing bracket.  Uses strcspn() to skip non-structural bytes in bulk.
     *
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function scanNested($handle, string $firstChar, string $filePath): string
    {
        $result = $firstChar;
        $depth = 1;

        while ($depth > 0) {
            if ($this->bufPos >= $this->bufLen) {
                $this->refillBuffer($handle, $filePath, 'unexpected end of input.');
            }

            // Find next structural byte: `{`, `}`, `[`, `]`, or `"`.
            $spanLen = strcspn($this->buf, '{}[]"', $this->bufPos);
            $segEnd = $this->bufPos + $spanLen;

            if ($segEnd >= $this->bufLen) {
                $result .= substr($this->buf, $this->bufPos);
                $this->bufPos = $this->bufLen;
                continue;
            }

            $struct = $this->buf[$segEnd];
            $result .= substr($this->buf, $this->bufPos, $spanLen + 1);
            $this->bufPos = $segEnd + 1;

            if ($struct === '"') {
                // Scan string body so that `{`, `}`, `[`, `]` inside strings are skipped.
                $result .= $this->scanStringRemainder($handle, $filePath);
            } elseif ($struct === '{' || $struct === '[') {
                $depth++;
            } else { // `}` or `]`
                $depth--;
            }
        }

        return $result;
    }

    /**
     * Scans a JSON primitive (number / boolean / null) from `$firstChar` until a
     * delimiter byte is found.  The delimiter is left in the buffer (not consumed).
     *
     * @param resource $handle
     */
    private function scanPrimitive($handle, string $firstChar): string
    {
        $result = $firstChar;

        while (true) {
            if ($this->bufPos >= $this->bufLen) {
                $chunk = fread($handle, self::BLOCK_SIZE);

                if (!is_string($chunk) || $chunk === '') {
                    break; // EOF or error — valid for the very last value in a file
                }

                $this->buf = $chunk;
                $this->bufLen = strlen($chunk);
                $this->bufPos = 0;
            }

            $spanLen = strcspn($this->buf, ",]} \t\n\r", $this->bufPos);
            $segEnd = $this->bufPos + $spanLen;

            if ($segEnd >= $this->bufLen) {
                $result .= substr($this->buf, $this->bufPos);
                $this->bufPos = $this->bufLen;
                continue;
            }

            $result .= substr($this->buf, $this->bufPos, $spanLen);
            $this->bufPos = $segEnd; // leave delimiter in buffer (not consumed)
            break;
        }

        return $result;
    }

    // ─── end block-buffer scanners ────────────────────────────────────────────

    /**
     * @param resource $handle
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function countArrayValues($handle, string $filePath): int
    {
        $next = $this->readNonWhitespaceChar($handle);
        if ($next === ']') {
            return 0;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": unexpected end of input in array.', $filePath),
            );
        }

        $this->unreadChar($next);

        $count = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($handle);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": unexpected end of input in array value.', $filePath),
                );
            }

            $this->skipValue($handle, $valueFirst, $filePath);
            $count++;

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

        return $count;
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
     * @return array<int, string>
     */
    private function splitAndValidateKeyPath(string $keyPath): array
    {
        $segments = explode('.', $keyPath);

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException('Key path must not contain empty segments.');
            }
        }

        return $segments;
    }

    private function hasWildcardSegment(string $keyPath): bool
    {
        return in_array('*', $this->splitAndValidateKeyPath($keyPath), true);
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function countForWildcardKeyPath(string $filePath, string $keyPath): int
    {
        return count($this->resolveWildcardValues($filePath, $keyPath));
    }

    /**
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function readGeneratorWithWildcardKeyPath(
        string $filePath,
        int|null $chunkSize,
        int|null $limit,
        int $offset,
        string $keyPath,
    ): Generator {
        $values = $this->resolveWildcardValues($filePath, $keyPath);
        $processed = 0;
        $taken = 0;
        $currentChunk = [];

        foreach ($values as $value) {
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
    }

    /**
     * @return array<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function resolveWildcardValues(string $filePath, string $keyPath): array
    {
        $json = $this->decodeJsonFile($filePath);
        $segments = $this->splitAndValidateKeyPath($keyPath);
        $resolvedNodes = $this->resolveKeyPathNodes($json, $segments, 0);

        if ($resolvedNodes === []) {
            throw new RuntimeException(sprintf('Key path "%s" was not found.', $keyPath));
        }

        $values = [];

        foreach ($resolvedNodes as $node) {
            if (is_array($node) && array_is_list($node)) {
                foreach ($node as $item) {
                    $values[] = $item;
                }

                continue;
            }

            $values[] = $node;
        }

        return $values;
    }

    /**
     * @return array<int, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function decodeJsonFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new InvalidArgumentException(sprintf('Invalid JSON in file "%s": unable to read file.', $filePath));
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

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": root value must be object or array.', $filePath),
            );
        }

        return $decoded;
    }

    /**
     * @param array<int, string> $segments
     *
     * @return array<int, mixed>
     */
    private function resolveKeyPathNodes(mixed $node, array $segments, int $depth): array
    {
        if ($depth >= count($segments)) {
            return [$node];
        }

        if (!is_array($node)) {
            return [];
        }

        $segment = $segments[$depth];

        if ($segment === '*') {
            if (!array_is_list($node)) {
                return [];
            }

            $resolved = [];

            foreach ($node as $item) {
                foreach ($this->resolveKeyPathNodes($item, $segments, $depth + 1) as $matchedNode) {
                    $resolved[] = $matchedNode;
                }
            }

            return $resolved;
        }

        if (array_is_list($node)) {
            if (!ctype_digit($segment)) {
                return [];
            }

            $index = (int)$segment;

            if (!array_key_exists($index, $node)) {
                return [];
            }

            return $this->resolveKeyPathNodes($node[$index], $segments, $depth + 1);
        }

        if (!array_key_exists($segment, $node)) {
            return [];
        }

        return $this->resolveKeyPathNodes($node[$segment], $segments, $depth + 1);
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
        // Drain any look-ahead chars first.
        while ($this->charBuffer !== []) {
            $c = array_pop($this->charBuffer);

            if (!ctype_space($c)) {
                return $c;
            }
        }

        // Scan the block buffer, skipping whitespace in bulk via strspn (C-level).
        while (true) {
            if ($this->bufPos >= $this->bufLen) {
                $chunk = fread($handle, self::BLOCK_SIZE);

                if ($chunk === false || $chunk === '') {
                    return null;
                }

                $this->buf = $chunk;
                $this->bufLen = strlen($chunk);
                $this->bufPos = 0;
            }

            // Skip leading whitespace in one C-level call.
            $this->bufPos += strspn($this->buf, " \t\n\r", $this->bufPos);

            if ($this->bufPos < $this->bufLen) {
                return $this->buf[$this->bufPos++];
            }
            // Buffer was all whitespace — refill and try again.
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

        if ($this->bufPos < $this->bufLen) {
            return $this->buf[$this->bufPos++];
        }

        $chunk = fread($handle, self::BLOCK_SIZE);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $this->buf = $chunk;
        $this->bufPos = 1;
        $this->bufLen = strlen($this->buf);

        return $this->buf[0];
    }

    private function unreadChar(string $char): void
    {
        $this->charBuffer[] = $char;
    }

    private function resetReadState(): void
    {
        $this->charBuffer = [];
        $this->buf = '';
        $this->bufPos = 0;
        $this->bufLen = 0;
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

    /**
     * @throws RuntimeException
     */
    private function prepareTempChunkDirectory(string $tempChunkDir): string
    {
        if (!is_dir($tempChunkDir) && !mkdir($tempChunkDir, 0777, true) && !is_dir($tempChunkDir)) {
            throw new RuntimeException(sprintf('Unable to create temporary chunk directory "%s".', $tempChunkDir));
        }

        return rtrim($tempChunkDir, DIRECTORY_SEPARATOR);
    }

    /**
     * @param array<int, mixed> $chunk
     *
     * @throws RuntimeException
     */
    private function writeTemporaryChunk(string $tempChunkDir, array $chunk): string
    {
        $tempFilePath = tempnam($tempChunkDir, 'json_chunk_');
        if ($tempFilePath === false) {
            throw new RuntimeException(sprintf('Unable to create temporary chunk file in "%s".', $tempChunkDir));
        }

        try {
            $encodedChunk = json_encode($chunk, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->removeFileIfExists($tempFilePath);

            throw new RuntimeException('Unable to encode temporary chunk file.', 0, $exception);
        }

        $writtenBytes = file_put_contents($tempFilePath, $encodedChunk);
        if ($writtenBytes === false) {
            $this->removeFileIfExists($tempFilePath);

            throw new RuntimeException(sprintf('Unable to write temporary chunk file "%s".', $tempFilePath));
        }

        return $tempFilePath;
    }


    /**
     * @return array<int, mixed>
     *
     * @throws RuntimeException
     */
    private function readTemporaryChunk(string $chunkFile): array
    {
        $content = file_get_contents($chunkFile);
        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read temporary chunk file "%s".', $chunkFile));
        }

        try {
            $decodedChunk = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Invalid temporary chunk file "%s".', $chunkFile), 0, $exception);
        }

        if (!is_array($decodedChunk) || !array_is_list($decodedChunk)) {
            throw new RuntimeException(
                sprintf('Temporary chunk file "%s" must contain a JSON array list.', $chunkFile),
            );
        }

        return $decodedChunk;
    }

    private function removeFileIfExists(string $filePath): void
    {
        if (is_file($filePath)) {
            unlink($filePath);
        }
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

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getFirst(string $filePath, string|null $keyPath = null): mixed
    {
        foreach (
            $this->readGenerator(
                filePath: $filePath,
                limit: 1,
                keyPath: $keyPath,
            ) as $item
        ) {
            return $item;
        }

        throw new RuntimeException(sprintf('Target array in "%s" is empty.', $filePath));
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getLast(string $filePath, string|null $keyPath = null): mixed
    {
        $last = null;
        $found = false;

        foreach (
            $this->readGenerator(
                filePath: $filePath,
                keyPath: $keyPath,
            ) as $item
        ) {
            $last = $item;
            $found = true;
        }

        if (!$found) {
            throw new RuntimeException(sprintf('Target array in "%s" is empty.', $filePath));
        }

        return $last;
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getNth(string $filePath, int $index, string|null $keyPath = null): mixed
    {
        if ($index < 0) {
            throw new InvalidArgumentException('Index must be greater than or equal to 0.');
        }

        foreach (
            $this->readGenerator(
                filePath: $filePath,
                limit: 1,
                offset: $index,
                keyPath: $keyPath,
            ) as $item
        ) {
            return $item;
        }

        throw new RuntimeException(sprintf('Index %d not found in target array of "%s".', $index, $filePath));
    }

    /**
     * @param callable(mixed): void $callback
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function forEach(
        string $filePath,
        callable $callback,
        string|null $keyPath = null,
    ): int {
        $count = 0;

        foreach (
            $this->readGenerator(
                filePath: $filePath,
                keyPath: $keyPath,
            ) as $item
        ) {
            $callback($item);
            $count++;
        }

        return $count;
    }
}
