<?php

declare(strict_types=1);

namespace PhpJsonChunk;

use Generator;
use InvalidArgumentException;
use Iterator;
use JsonException;
use PhpJsonChunk\Contract\JsonChunkReaderInterface;
use PhpJsonChunk\Internal\JsonStream;
use RuntimeException;
use SplFileObject;

final class JsonChunkReader implements JsonChunkReaderInterface
{
    private const DEFAULT_TEMP_CHUNK_SIZE = 1000;

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function count(string $filePath, string|null $keyPath = null): int
    {
        if ($keyPath !== null && $keyPath !== '' && $this->hasWildcardSegment($keyPath)) {
            return $this->countForWildcardKeyPath($filePath, $keyPath);
        }

        $stream = $this->openFile($filePath);

        try {
            $this->positionStreamAtTargetArrayStart($stream, $keyPath);

            $total = $this->countArrayValues($stream, $filePath);
            $this->assertNothingFollowsRootArray($stream, $filePath, $keyPath);

            return $total;
        } finally {
            $stream->close();
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
        // The asserts live in readGenerator() now, and run at the call rather than at the first
        // iteration — repeating them here would say the same thing twice.
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
        // Deliberately NOT a generator function. The two asserts below used to sit at the top of one,
        // and a generator function runs no part of its body until the first iteration — so they were
        // written, they read as a guard, and they never fired at the call. `readGenerator($f, 0)`
        // handed back a Generator and reported the invalid chunk size later, from wherever the caller
        // happened to start iterating; `readIterator()` beside it refused the same call immediately,
        // which is what says the intent was to refuse it here too.
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        return $this->readGeneratorAfterValidation($filePath, $chunkSize, $limit, $offset, $keyPath, $tempChunkDir);
    }

    /**
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function readGeneratorAfterValidation(
        string $filePath,
        int|null $chunkSize,
        int|null $limit,
        int $offset,
        string|null $keyPath,
        string|null $tempChunkDir,
    ): Generator {
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

        $stream = $this->openFile($filePath);

        try {
            $this->positionStreamAtTargetArrayStart($stream, $keyPath);

            $processed = 0;
            $taken = 0;
            $currentChunk = [];
            $readToTheEnd = true;

            foreach ($this->streamArrayValues($stream, $filePath) as $value) {
                if ($processed < $offset) {
                    $processed++;
                    continue;
                }

                if ($limit !== null && $taken >= $limit) {
                    // Stopped early on purpose, so the array was NOT read to its end and the check
                    // below has nothing to stand on.
                    $readToTheEnd = false;

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

            if ($readToTheEnd) {
                $this->assertNothingFollowsRootArray($stream, $filePath, $keyPath);
            }
        } finally {
            $stream->close();
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
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function openFile(string $filePath): JsonStream
    {
        if (!is_file($filePath)) {
            throw new InvalidArgumentException(sprintf('JSON file "%s" was not found.', $filePath));
        }

        try {
            $file = new SplFileObject($filePath, 'rb');
        } catch (RuntimeException $exception) {
            throw new RuntimeException(sprintf('Unable to read JSON file "%s".', $filePath), 0, $exception);
        }

        // The buffer travels with the open file, so nothing about this read lives on the reader.
        return new JsonStream($file);
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function positionStreamAtTargetArrayStart(JsonStream $stream, string|null $keyPath): void
    {
        $first = $this->readNonWhitespaceChar($stream);
        if ($first === null) {
            throw new InvalidArgumentException('Invalid JSON in file: empty content.');
        }

        $this->assertNoByteOrderMark($first);

        if ($keyPath !== null && $keyPath !== '') {
            $segments = $this->splitAndValidateKeyPath($keyPath);

            $resolved = $this->seekPath($stream, $first, $segments, 0, $keyPath);

            if ($resolved !== '[') {
                throw new RuntimeException(
                    sprintf('Resolved key path "%s" must point to a JSON array list.', $keyPath),
                );
            }

            return;
        }

        if ($first !== '[') {
            // The commonest reason to land here is a document whose root is an object, which this
            // library reads perfectly well — with a keyPath. Saying so turns the message into the
            // next step rather than a verdict.
            throw new InvalidArgumentException(
                'The JSON root value must be an array. '
                . 'If the root is an object, pass a keyPath pointing at the array list inside it.',
            );
        }
    }

    /**
     * A UTF-8 BOM is not whitespace and not JSON.
     *
     * `json_decode()` refuses a document that starts with one, so this reader does too — the point is
     * only that it must not blame the wrong thing. The byte is invisible in every editor, and a file
     * exported from Windows or a spreadsheet carries one routinely, so "the root value must be an
     * array" over a file whose root plainly IS an array leaves the reader nothing to act on.
     *
     * Every entry point runs this, because a wildcard path walks from its own first character and
     * used to answer the same document with `Key path "*" was not found`.
     *
     * No JSON document can begin with this byte otherwise: a value starts with `{`, `[`, `"`, a digit,
     * `-`, `t`, `f` or `n`.
     *
     * @throws InvalidArgumentException
     */
    private function assertNoByteOrderMark(string $firstChar): void
    {
        if ($firstChar !== "\xEF") {
            return;
        }

        throw new InvalidArgumentException(
            'Invalid JSON in file: the content starts with a UTF-8 byte order mark. '
            . 'Strip it before reading — JSON has no byte order mark, and PHP\'s own json_decode() '
            . 'refuses one too.',
        );
    }

    /**
     * A root array is the whole document: nothing may follow it.
     *
     * `[{"id":1}] whatever` and `[{"id":1}]]` were both read as `[{"id":1}]` and no error was raised —
     * the reader stops at the closing bracket and never looks past it. `json_decode()` refuses both,
     * and so does `file_get_contents() + json_decode()`, the pair this library stands in for. A file
     * that is two documents concatenated, or one truncated and appended to, read as just the first
     * with nothing to say it had been cut.
     *
     * Only this case is checked, and deliberately so. It is the one where the reader is already
     * sitting at the end of the file, so the check costs a read that returns nothing. With a
     * `keyPath` the target array is nested and the rest of the document legitimately follows it;
     * verifying THAT would mean reading the whole file, which is the cost this library exists to
     * avoid. A `limit` that stops early is not checked either, for the same reason: the array was
     * never read to its end.
     *
     * @throws InvalidArgumentException
     */
    private function assertNothingFollowsRootArray(
        JsonStream $stream,
        string $filePath,
        string|null $keyPath,
    ): void {
        if ($keyPath !== null && $keyPath !== '') {
            return;
        }

        $trailing = $this->readNonWhitespaceChar($stream);
        if ($trailing === null) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Invalid JSON in file "%s": unexpected "%s" after the root array ended.',
            $filePath,
            $trailing,
        ));
    }

    /**
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function streamArrayValues(JsonStream $stream, string $filePath): Generator
    {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === ']') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": unexpected end of input in array.', $filePath),
            );
        }

        $stream->pushBack($next);

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": unexpected end of input in array value.', $filePath),
                );
            }

            // Hot path: use block-buffer scanners (strcspn at C level) instead of
            // per-character readValueAsJson + string concatenation.
            $rawValue = $this->scanValue($stream, $valueFirst, $filePath);

            try {
                yield json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": %s', $filePath, $exception->getMessage()),
                    0,
                    $exception,
                );
            }

            $delimiter = $this->readNonWhitespaceChar($stream);

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
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function refillBuffer(JsonStream $stream, string|null $filePath = null, string $detail = ''): void
    {
        $chunk = $stream->readBlock();

        if ($chunk === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, $detail ?: 'unexpected end of input.'),
            );
        }

        $stream->buf = $chunk;
        $stream->bufLen = strlen($chunk);
        $stream->bufPos = 0;
    }

    /**
     * Dispatches to the correct scanner based on the first character.
     *
     * Returns the raw JSON bytes of the value (ready for json_decode).
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function scanValue(JsonStream $stream, string $firstChar, string $filePath): string
    {
        if ($firstChar === '"') {
            return '"' . $this->scanStringRemainder($stream, $filePath);
        }

        if ($firstChar === '{' || $firstChar === '[') {
            return $this->scanNested($stream, $firstChar, $filePath);
        }

        return $this->scanPrimitive($stream, $firstChar);
    }

    /**
     * Scans the body of a JSON string from the byte AFTER the opening `"` until the
     * matching closing `"` (inclusive).  Uses strcspn() to skip plain bytes in bulk.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function scanStringRemainder(JsonStream $stream, string $filePath): string
    {
        $result = '';

        while (true) {
            // Refill block buffer if needed.
            if ($stream->bufPos >= $stream->bufLen) {
                $this->refillBuffer($stream, $filePath, 'unexpected end of input in string.');
            }

            // Find the next `\` or `"` in the current block (C-level scan).
            $spanLen = strcspn($stream->buf, '\\"', $stream->bufPos);
            $segEnd = $stream->bufPos + $spanLen;

            if ($segEnd >= $stream->bufLen) {
                // No special char before end of block — consume all remaining bytes.
                $result .= substr($stream->buf, $stream->bufPos);
                $stream->bufPos = $stream->bufLen;
                continue;
            }

            $special = $stream->buf[$segEnd];
            $result .= substr($stream->buf, $stream->bufPos, $spanLen + 1); // include special char
            $stream->bufPos = $segEnd + 1;

            if ($special === '"') {
                return $result; // closing quote found
            }

            // Escape sequence (`\`): consume one more byte (the escaped character).
            if ($stream->bufPos >= $stream->bufLen) {
                $this->refillBuffer($stream, $filePath, 'unexpected end of input in string.');
            }

            $result .= $stream->buf[$stream->bufPos];
            $stream->bufPos++;
        }
    }

    /**
     * Scans a JSON object `{…}` or array `[…]` from the opening bracket until the
     * balanced closing bracket.  Uses strcspn() to skip non-structural bytes in bulk.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function scanNested(JsonStream $stream, string $firstChar, string $filePath): string
    {
        $result = $firstChar;
        $depth = 1;

        while ($depth > 0) {
            if ($stream->bufPos >= $stream->bufLen) {
                $this->refillBuffer($stream, $filePath, 'unexpected end of input.');
            }

            // Find next structural byte: `{`, `}`, `[`, `]`, or `"`.
            $spanLen = strcspn($stream->buf, '{}[]"', $stream->bufPos);
            $segEnd = $stream->bufPos + $spanLen;

            if ($segEnd >= $stream->bufLen) {
                $result .= substr($stream->buf, $stream->bufPos);
                $stream->bufPos = $stream->bufLen;
                continue;
            }

            $struct = $stream->buf[$segEnd];
            $result .= substr($stream->buf, $stream->bufPos, $spanLen + 1);
            $stream->bufPos = $segEnd + 1;

            if ($struct === '"') {
                // Scan string body so that `{`, `}`, `[`, `]` inside strings are skipped.
                $result .= $this->scanStringRemainder($stream, $filePath);
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
     */
    private function scanPrimitive(JsonStream $stream, string $firstChar): string
    {
        $result = $firstChar;

        while (true) {
            if ($stream->bufPos >= $stream->bufLen) {
                $chunk = $stream->readBlock();

                if ($chunk === null) {
                    break; // EOF or error — valid for the very last value in a file
                }

                $stream->buf = $chunk;
                $stream->bufLen = strlen($chunk);
                $stream->bufPos = 0;
            }

            $spanLen = strcspn($stream->buf, ",]} \t\n\r", $stream->bufPos);
            $segEnd = $stream->bufPos + $spanLen;

            if ($segEnd >= $stream->bufLen) {
                $result .= substr($stream->buf, $stream->bufPos);
                $stream->bufPos = $stream->bufLen;
                continue;
            }

            $result .= substr($stream->buf, $stream->bufPos, $spanLen);
            $stream->bufPos = $segEnd; // leave delimiter in buffer (not consumed)
            break;
        }

        return $result;
    }

    // ─── end block-buffer scanners ────────────────────────────────────────────

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function countArrayValues(JsonStream $stream, string $filePath): int
    {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === ']') {
            return 0;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": unexpected end of input in array.', $filePath),
            );
        }

        $stream->pushBack($next);

        $count = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": unexpected end of input in array value.', $filePath),
                );
            }

            $this->skipValue($stream, $valueFirst, $filePath);
            $count++;

            $delimiter = $this->readNonWhitespaceChar($stream);
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
     * @param array<int, string> $segments
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function seekPath(JsonStream $stream, string $firstChar, array $segments, int $depth, string $keyPath): string
    {
        if ($depth >= count($segments)) {
            return $firstChar;
        }

        $segment = $segments[$depth];

        if ($firstChar === '{') {
            return $this->seekPathInObject($stream, $segments, $depth, $segment, $keyPath);
        }

        if ($firstChar === '[') {
            if (!ctype_digit($segment)) {
                throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
            }

            return $this->seekPathInArray($stream, $segments, $depth, (int)$segment, $keyPath);
        }

        throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
    }

    /**
     * @param array<int, string> $segments
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function seekPathInObject(JsonStream $stream, array $segments, int $depth, string $segment, string $keyPath): string
    {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === '}') {
            throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
        }

        if ($next === null) {
            throw new InvalidArgumentException('Invalid JSON in file: unexpected end of input in object.');
        }

        $stream->pushBack($next);

        while (true) {
            $keyFirst = $this->readNonWhitespaceChar($stream);
            if ($keyFirst !== '"') {
                throw new InvalidArgumentException('Invalid JSON in file: object key must be a string.');
            }

            $keyToken = $this->readStringToken($stream, null);

            try {
                $decodedKey = json_decode($keyToken, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage(null, $exception->getMessage()),
                    0,
                    $exception,
                );
            }

            $separator = $this->readNonWhitespaceChar($stream);
            if ($separator !== ':') {
                throw new InvalidArgumentException('Invalid JSON in file: expected ":" after object key.');
            }

            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException('Invalid JSON in file: unexpected end of input in object value.');
            }

            if ($decodedKey === $segment) {
                return $this->seekPath($stream, $valueFirst, $segments, $depth + 1, $keyPath);
            }

            $this->skipValue($stream, $valueFirst, null);

            $delimiter = $this->readNonWhitespaceChar($stream);
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
     * @param array<int, string> $segments
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function seekPathInArray(JsonStream $stream, array $segments, int $depth, int $targetIndex, string $keyPath): string
    {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === ']') {
            $segment = $segments[$depth];
            throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $keyPath, $segment));
        }

        if ($next === null) {
            throw new InvalidArgumentException('Invalid JSON in file: unexpected end of input in array.');
        }

        $stream->pushBack($next);

        $index = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException('Invalid JSON in file: unexpected end of input in array value.');
            }

            if ($index === $targetIndex) {
                return $this->seekPath($stream, $valueFirst, $segments, $depth + 1, $keyPath);
            }

            $this->skipValue($stream, $valueFirst, null);

            $delimiter = $this->readNonWhitespaceChar($stream);
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
        $total = 0;

        foreach ($this->streamWildcardValues($filePath, $keyPath) as $ignored) {
            $total++;
        }

        return $total;
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
        $values = $this->streamWildcardValues($filePath, $keyPath);
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
     * Streams every value a wildcard key path resolves to, in ONE pass over the file.
     *
     * The first version of this resolved `*` by `file_get_contents()` + `json_decode()` and walked the
     * decoded arrays: correct, and it cost the whole file — 162 MB of peak on a 20 MB document, where
     * the same read through a concrete path cost 2 MB.
     *
     * The second expanded `*` into concrete indices and read each resulting path with the ordinary
     * seek. Memory went flat, but every branch restarted the seek from byte zero, so the time grew
     * with the number of branches: at a constant 24 000 items in a 5 MB file, one branch took 0.98 s
     * and thirty-two took 15.2 s. That trades a broken memory promise for a broken time one.
     *
     * So the walk descends the path as it reads, and never goes back: a `*` iterates the list it sits
     * on, each element is descended into and then finished, and a value the path does not want is
     * skipped rather than parsed. Each method here consumes EXACTLY the value it was handed, which is
     * what lets the caller carry on reading the container it came from.
     *
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function streamWildcardValues(string $filePath, string $keyPath): Generator
    {
        $segments = $this->splitAndValidateKeyPath($keyPath);
        $stream = $this->openFile($filePath);
        $matched = false;

        try {
            $first = $this->readNonWhitespaceChar($stream);
            if ($first === null) {
                throw new InvalidArgumentException('Invalid JSON in file: empty content.');
            }

            $this->assertNoByteOrderMark($first);

            yield from $this->walkKeyPath($stream, $first, $segments, 0, $filePath, $matched);

            if (!$matched) {
                throw new RuntimeException(sprintf('Key path "%s" was not found.', $keyPath));
            }
        } finally {
            $stream->close();
        }
    }

    /**
     * Descends one value by the remaining path segments, and consumes that value whole.
     *
     * A segment the value cannot satisfy — `*` on something that is not a list, a name on something
     * that is not an object — resolves to nothing rather than to an error, which is what walking the
     * decoded arrays did by returning an empty list.
     *
     * @param array<int, string> $segments
     * @param bool $matched raised once any branch reaches the end of the path, so "no match at all"
     *                      can be told apart from "matched a list that happens to be empty"
     *
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function walkKeyPath(
        JsonStream $stream,
        string $firstChar,
        array $segments,
        int $depth,
        string $filePath,
        bool &$matched,
    ): Generator {
        if ($depth >= count($segments)) {
            $matched = true;

            if ($firstChar === '[') {
                yield from $this->streamArrayValues($stream, $filePath);

                return;
            }

            // A leaf that is not a list is yielded whole — `data.*.name` over scalars is a documented
            // use, not an error.
            yield $this->decodeScannedValue($this->scanValue($stream, $firstChar, $filePath), $filePath);

            return;
        }

        $segment = $segments[$depth];

        if ($segment === '*') {
            if ($firstChar !== '[') {
                $this->skipValue($stream, $firstChar, $filePath);

                return;
            }

            yield from $this->walkWildcardList($stream, $segments, $depth, $filePath, $matched);

            return;
        }

        if ($firstChar === '{') {
            yield from $this->walkObjectMember($stream, $segments, $depth, $segment, $filePath, $matched);

            return;
        }

        if ($firstChar === '[' && ctype_digit($segment)) {
            yield from $this->walkArrayIndex($stream, $segments, $depth, (int)$segment, $filePath, $matched);

            return;
        }

        $this->skipValue($stream, $firstChar, $filePath);
    }

    /**
     * `*`: descend into every element of this list, in order, without ever seeking backwards.
     *
     * @param array<int, string> $segments
     *
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function walkWildcardList(
        JsonStream $stream,
        array $segments,
        int $depth,
        string $filePath,
        bool &$matched,
    ): Generator {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === ']') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": unexpected end of input in array.', $filePath),
            );
        }

        $stream->pushBack($next);

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": unexpected end of input in array value.', $filePath),
                );
            }

            yield from $this->walkKeyPath($stream, $valueFirst, $segments, $depth + 1, $filePath, $matched);

            $delimiter = $this->readNonWhitespaceChar($stream);
            if ($delimiter === ']') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": expected "," or "]" in array.', $filePath),
                );
            }
        }
    }

    /**
     * A named segment inside an object: descend into the member that carries the name, skip the rest,
     * and leave the stream just past the object's closing brace.
     *
     * A repeated key resolves to its FIRST occurrence, which is what `seekPathInObject()` does for a
     * path without a wildcard — the two must not disagree about the same document.
     *
     * @param array<int, string> $segments
     *
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function walkObjectMember(
        JsonStream $stream,
        array $segments,
        int $depth,
        string $segment,
        string $filePath,
        bool &$matched,
    ): Generator {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === '}') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": unexpected end of input in object.', $filePath),
            );
        }

        $stream->pushBack($next);
        $taken = false;

        while (true) {
            $keyFirst = $this->readNonWhitespaceChar($stream);
            if ($keyFirst !== '"') {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": object key must be a string.', $filePath),
                );
            }

            $decodedKey = $this->decodeScannedValue($this->readStringToken($stream, $filePath), $filePath);

            $separator = $this->readNonWhitespaceChar($stream);
            if ($separator !== ':') {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": expected ":" after object key.', $filePath),
                );
            }

            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": unexpected end of input in object value.', $filePath),
                );
            }

            if (!$taken && $decodedKey === $segment) {
                $taken = true;

                yield from $this->walkKeyPath($stream, $valueFirst, $segments, $depth + 1, $filePath, $matched);
            } else {
                $this->skipValue($stream, $valueFirst, $filePath);
            }

            $delimiter = $this->readNonWhitespaceChar($stream);
            if ($delimiter === '}') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": expected "," or "}" in object.', $filePath),
                );
            }
        }
    }

    /**
     * A numeric segment inside a list: descend into that one element, skip the others, and leave the
     * stream just past the list's closing bracket.
     *
     * @param array<int, string> $segments
     *
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function walkArrayIndex(
        JsonStream $stream,
        array $segments,
        int $depth,
        int $targetIndex,
        string $filePath,
        bool &$matched,
    ): Generator {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === ']') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": unexpected end of input in array.', $filePath),
            );
        }

        $stream->pushBack($next);
        $index = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": unexpected end of input in array value.', $filePath),
                );
            }

            if ($index === $targetIndex) {
                yield from $this->walkKeyPath($stream, $valueFirst, $segments, $depth + 1, $filePath, $matched);
            } else {
                $this->skipValue($stream, $valueFirst, $filePath);
            }

            $delimiter = $this->readNonWhitespaceChar($stream);
            if ($delimiter === ']') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    sprintf('Invalid JSON in file "%s": expected "," or "]" in array.', $filePath),
                );
            }

            $index++;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function decodeScannedValue(string $rawValue, string $filePath): mixed
    {
        try {
            return json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSON in file "%s": %s', $filePath, $exception->getMessage()),
                0,
                $exception,
            );
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function readValueAsJson(JsonStream $stream, string $firstChar, string $filePath): string
    {
        return match ($firstChar) {
            '{' => $this->readObjectAsJson($stream, $filePath),
            '[' => $this->readArrayAsJson($stream, $filePath),
            '"' => $this->readStringToken($stream, $filePath),
            default => $this->readPrimitiveToken($stream, $firstChar),
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    private function readObjectAsJson(JsonStream $stream, string|null $filePath): string
    {
        $pairs = [];
        $next = $this->readNonWhitespaceChar($stream);

        if ($next === '}') {
            return '{}';
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in object.'),
            );
        }

        $stream->pushBack($next);

        while (true) {
            $keyFirst = $this->readNonWhitespaceChar($stream);
            if ($keyFirst !== '"') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'object key must be a string.'),
                );
            }

            $key = $this->readStringToken($stream, $filePath);

            $separator = $this->readNonWhitespaceChar($stream);
            if ($separator !== ':') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected ":" after object key.'),
                );
            }

            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in object value.'),
                );
            }

            $pairs[] = $key . ':' . $this->readValueAsJson($stream, $valueFirst, $filePath ?? '');

            $delimiter = $this->readNonWhitespaceChar($stream);
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
     * @throws InvalidArgumentException
     */
    private function readArrayAsJson(JsonStream $stream, string|null $filePath): string
    {
        $items = [];
        $next = $this->readNonWhitespaceChar($stream);

        if ($next === ']') {
            return '[]';
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in array.'),
            );
        }

        $stream->pushBack($next);

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.'),
                );
            }

            $items[] = $this->readValueAsJson($stream, $valueFirst, $filePath ?? '');

            $delimiter = $this->readNonWhitespaceChar($stream);
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
     * @throws InvalidArgumentException
     */
    private function readStringToken(JsonStream $stream, string|null $filePath): string
    {
        $token = '"';
        $escaped = false;

        while (true) {
            $char = $this->readChar($stream);
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
     * @throws InvalidArgumentException
     */
    private function readPrimitiveToken(JsonStream $stream, string $firstChar): string
    {
        $token = $firstChar;

        while (true) {
            $char = $this->readChar($stream);
            if ($char === null) {
                break;
            }

            if ($this->isJsonDelimiter($char)) {
                $stream->pushBack($char);
                break;
            }

            $token .= $char;
        }

        return $token;
    }

    private function skipValue(JsonStream $stream, string $firstChar, string|null $filePath): void
    {
        if ($firstChar === '"') {
            $this->readStringToken($stream, $filePath);
            return;
        }

        if ($firstChar === '{') {
            $next = $this->readNonWhitespaceChar($stream);
            if ($next === '}') {
                return;
            }

            if ($next === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in object.'),
                );
            }

            $stream->pushBack($next);

            while (true) {
                $keyFirst = $this->readNonWhitespaceChar($stream);
                if ($keyFirst !== '"') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'object key must be a string.'),
                    );
                }

                $this->readStringToken($stream, $filePath);

                $separator = $this->readNonWhitespaceChar($stream);
                if ($separator !== ':') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'expected ":" after object key.'),
                    );
                }

                $valueFirst = $this->readNonWhitespaceChar($stream);
                if ($valueFirst === null) {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'unexpected end of input in object value.'),
                    );
                }

                $this->skipValue($stream, $valueFirst, $filePath);

                $delimiter = $this->readNonWhitespaceChar($stream);
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
            $next = $this->readNonWhitespaceChar($stream);
            if ($next === ']') {
                return;
            }

            if ($next === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in array.'),
                );
            }

            $stream->pushBack($next);

            while (true) {
                $valueFirst = $this->readNonWhitespaceChar($stream);
                if ($valueFirst === null) {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.'),
                    );
                }

                $this->skipValue($stream, $valueFirst, $filePath);

                $delimiter = $this->readNonWhitespaceChar($stream);
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

        $this->readPrimitiveToken($stream, $firstChar);
    }

    private function readNonWhitespaceChar(JsonStream $stream): string|null
    {
        // Drain any look-ahead chars first.
        while ($stream->charBuffer !== []) {
            $c = array_pop($stream->charBuffer);

            if (!ctype_space($c)) {
                return $c;
            }
        }

        // Scan the block buffer, skipping whitespace in bulk via strspn (C-level).
        while (true) {
            if ($stream->bufPos >= $stream->bufLen) {
                $chunk = $stream->readBlock();

                if ($chunk === null) {
                    return null;
                }

                $stream->buf = $chunk;
                $stream->bufLen = strlen($chunk);
                $stream->bufPos = 0;
            }

            // Skip leading whitespace in one C-level call.
            $stream->bufPos += strspn($stream->buf, " \t\n\r", $stream->bufPos);

            if ($stream->bufPos < $stream->bufLen) {
                return $stream->buf[$stream->bufPos++];
            }
            // Buffer was all whitespace — refill and try again.
        }
    }

    private function readChar(JsonStream $stream): string|null
    {
        if ($stream->charBuffer !== []) {
            return array_pop($stream->charBuffer);
        }

        if ($stream->bufPos < $stream->bufLen) {
            return $stream->buf[$stream->bufPos++];
        }

        $chunk = $stream->readBlock();

        if ($chunk === null) {
            return null;
        }

        $stream->buf = $chunk;
        $stream->bufPos = 1;
        $stream->bufLen = strlen($stream->buf);

        return $stream->buf[0];
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
        // `@` because this failure is REPORTED, not ignored: the throw below says the same thing with
        // the path in it, and a library that raises an exception should not also print to the output
        // buffer on its way there — under a web SAPI that lands in the response body.
        //
        // 0775 rather than 0777: a umask of 0022 trims either to 0755, but a process running with a
        // umask of 0 would get a world-writable temp directory out of the wider mode, and nothing
        // here needs one.
        if (!is_dir($tempChunkDir) && !@mkdir($tempChunkDir, 0775, true) && !is_dir($tempChunkDir)) {
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
