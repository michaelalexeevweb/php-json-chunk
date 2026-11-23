<?php

declare(strict_types=1);

namespace PhpJsonChunk;

use Closure;
use Generator;
use InvalidArgumentException;
use Iterator;
use JsonException;
use PhpJsonChunk\Contract\JsonChunkReaderInterface;
use PhpJsonChunk\Internal\JsonStream;
use RuntimeException;
use PhpJsonChunk\Contract\JsonSourceInterface;
use PhpJsonChunk\Source\FileSource;
use PhpJsonChunk\Source\StringSource;
use SplFileObject;
use stdClass;
use Throwable;

/**
 * @phpstan-type Cursor array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}
 * @phpstan-type NodePlan array{
 *     cursors: array<int, Cursor>,
 *     arrived: array<int, Cursor>,
 *     active: array<int, Cursor>,
 *     activeSignature: string,
 *     leafLists: bool,
 *     endsOnAny: bool,
 *     endsOn: array<string, bool>,
 *     indexFree: bool,
 *     kids: array<string, array{0: array<int, Cursor>, 1: string}>,
 *     labels: array<string, array<int, string>>,
 * }
 */
final class JsonChunkReader implements JsonChunkReaderInterface
{
    /**
     * @param bool $associative what an item comes back as: `true` for arrays, `false` for `stdClass`.
     *
     * A constructor flag rather than a seventh parameter on methods that already take six. It is the
     * kind of choice a caller makes once for a whole application, not per read — and every one of
     * `read()`, `readIterator()`, `readGenerator()`, `getFirst()`, `getLast()`, `getNth()` and
     * `forEach()` would otherwise have had to grow the same argument.
     *
     * Object KEYS are decoded as strings whatever this says: a key is a name, not a value.
     *
     * @param (callable(InvalidItem): mixed)|null $onInvalidItem an element json_decode() refuses — a bad
     *        number, a broken escape — is handed here and skipped, instead of ending the read. A broken
     *        STRUCTURE still ends it: past an unbalanced bracket there is no telling where the next
     *        element starts. getValue() reads one value, not elements, and always throws.
     * @param BlockSize $blockSize how many bytes the source is read in at a time — less memory, or room
     *        for large elements to be read fast; see BlockSize. The default, Auto, grows the block for
     *        elements that need it and suits most documents.
     * @param int|null $maxElementBytes the most bytes one element — a value handed over, a key on the
     *        way to it — may take; a bigger one is refused with ElementTooLargeException while it is being
     *        read, before it is all in memory. Null, the default, sets no limit. Give one when the document
     *        comes from someone else: a single 500 MB string otherwise ends PHP with a fatal error. A
     *        branch the path only skips is never held, and is not limited.
     * @param bool $bigIntAsString an integer bigger than PHP's — a 20-digit id, an amount in the smallest
     *        unit — comes back as a string with every digit, instead of a float that has lost the last of
     *        them. Integers that fit stay integers.
     */
    public function __construct(
        private readonly bool $associative = true,
        callable|null $onInvalidItem = null,
        private readonly BlockSize $blockSize = BlockSize::Auto,
        private readonly int|null $maxElementBytes = null,
        bool $bigIntAsString = false,
    ) {
        if ($maxElementBytes !== null && $maxElementBytes < 1) {
            throw new InvalidArgumentException('maxElementBytes must be at least 1, or null for no limit.');
        }

        $this->decodeFlags = JSON_THROW_ON_ERROR | ($bigIntAsString ? JSON_BIGINT_AS_STRING : 0);

        $this->onInvalidItem = $onInvalidItem === null ? null : $onInvalidItem(...);
    }

    /** @var (Closure(InvalidItem): mixed)|null */
    private readonly Closure|null $onInvalidItem;

    /** The flags every value is decoded with: JSON_THROW_ON_ERROR, and JSON_BIGINT_AS_STRING when asked. */
    private readonly int $decodeFlags;

    private const DEFAULT_TEMP_CHUNK_SIZE = 1000;

    /**
     * An item read at json_decode()'s own 512 levels sits two deeper in a chunk file — the list of
     * pairs, and the pair — so the file is written and read with room for both.
     */
    private const TEMP_CHUNK_DEPTH = 514;

    /**
     * Walking a path recurses once per segment, and a few thousand of them end the process with a
     * segmentation fault — nothing to catch. A path often comes from a request, so it is capped at
     * json_decode()'s own depth: nothing deeper could have been read by the pair this library
     * stands in for either.
     */
    private const MAX_KEY_PATH_SEGMENTS = 512;

    /**
     * How deep `**` walks. It descends into every branch, so the depth is the document's, not the
     * path's — and the walk recurses once per level. json_decode()'s own limit: nothing deeper could be
     * decoded anyway, and a document that goes on is refused instead of running PHP out of stack.
     */
    private const MAX_RECURSIVE_DEPTH = 512;

    /**
     * How much of a list is decoded by one json_decode() call: a run of whole elements up to this many
     * bytes. Measured on 100 000 records — 4 KB reads them in 47 ms at a 0.17 MB peak, against 193 ms
     * one element at a time; 16 KB and 64 KB gain 2% to 6% more and cost 0.40 MB and 1.20 MB. Memory
     * is what this library is for, so the smallest run that gets nearly all of the speed.
     */
    private const RUN_BYTES = 4096;

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function count(string|JsonSourceInterface $filePath, string|array|null $keyPath = null): int
    {
        if ($keyPath !== null && $keyPath !== '' && $this->hasWildcardSegment($keyPath)) {
            return $this->countForWildcardKeyPath($filePath, $keyPath);
        }

        $stream = $this->openFile($filePath);
        // Past this point the source is just a name: everything below reports, it does not read.
        $filePath = $this->describeSource($filePath);

        try {
            $container = $this->positionStreamAtTargetArrayStart($stream, $keyPath);

            $total = 0;
            foreach ($this->streamContainer($stream, $filePath, $container) as $ignored) {
                $total++;
            }
            $this->assertNothingFollowsRootArray($stream, $filePath, $keyPath);

            return $total;
        } finally {
            $stream->close();
        }
    }

    /**
     * @return array<int, array<int|string, mixed>> a chunk of a list is a list; an object's keeps its names
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
    ): array {
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        if ($chunkSize !== null) {
            $chunks = [];
            foreach ($this->readGenerator($filePath, $chunkSize, $limit, $offset, $keyPath, $tempChunkDir) as $chunk) {
                $chunks[] = $chunk;
            }

            return $chunks;
        }

        // An object's items keep their names — chunked reads always did, and an unchunked one returned
        // the values alone, so the same object read two ways answered two different shapes. An object
        // yields its names as strings, `"10"` included; a list yields int indices, which a window would
        // leave starting at the offset: dropped.
        $items = [];
        foreach ($this->readGenerator($filePath, null, $limit, $offset, $keyPath, $tempChunkDir) as $key => $item) {
            if (is_string($key)) {
                $items[$key] = $item;
            } else {
                $items[] = $item;
            }
        }

        return [$items];
    }

    /**
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
    ): Iterator {
        // The asserts live in readGenerator() now, and run at the call rather than at the first
        // iteration — repeating them here would say the same thing twice.
        return $this->readGenerator($filePath, $chunkSize, $limit, $offset, $keyPath, $tempChunkDir);
    }

    /**
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
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    private function readGeneratorAfterValidation(
        string|JsonSourceInterface $filePath,
        int|null $chunkSize,
        int|null $limit,
        int $offset,
        string|array|null $keyPath,
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
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    private function readGeneratorWithoutTemporaryChunks(
        string|JsonSourceInterface $filePath,
        int|null $chunkSize,
        int|null $limit,
        int $offset,
        string|array|null $keyPath,
    ): Generator {
        $this->assertChunkSize($chunkSize);
        $this->assertLimitAndOffset($limit, $offset);

        if ($keyPath !== null && $keyPath !== '' && $this->hasWildcardSegment($keyPath)) {
            yield from $this->readGeneratorWithWildcardKeyPath($filePath, $chunkSize, $limit, $offset, $keyPath);

            return;
        }

        $stream = $this->openFile($filePath);
        // Past this point the source is just a name: everything below reports, it does not read.
        $filePath = $this->describeSource($filePath);

        try {
            $container = $this->positionStreamAtTargetArrayStart($stream, $keyPath);

            $readToTheEnd = true;

            yield from $this->applyWindow(
                values: $this->streamContainer($stream, $filePath, $container),
                preserveKeys: $container === '{',
                chunkSize: $chunkSize,
                limit: $limit,
                offset: $offset,
                readToTheEnd: $readToTheEnd,
            );

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
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    private function createTemporaryChunkFiles(
        string|JsonSourceInterface $filePath,
        int|null $chunkSize,
        int|null $limit,
        int $offset,
        string|array|null $keyPath,
        string $tempChunkDir,
    ): array {
        $resolvedTempChunkDir = $this->prepareTempChunkDirectory($tempChunkDir);
        $chunkFiles = [];

        try {
            // Item by item, the pairs are the keys the stream yields — an object's names as the
            // strings they are, `"10"` included. Chunked, a chunk's own keys are what a chunk holds.
            $pairs = [];
            foreach (
                $this->readGeneratorWithoutTemporaryChunks(
                    filePath: $filePath,
                    chunkSize: $chunkSize,
                    limit: $limit,
                    offset: $offset,
                    keyPath: $keyPath,
                ) as $key => $value
            ) {
                if ($chunkSize === null) {
                    $pairs[] = [$key, $value];
                    if (count($pairs) >= self::DEFAULT_TEMP_CHUNK_SIZE) {
                        $chunkFiles[] = $this->writeTemporaryChunk($resolvedTempChunkDir, $pairs);
                        $pairs = [];
                    }

                    continue;
                }

                if (!is_array($value)) {
                    throw new RuntimeException('Temporary chunk serialization expects an array chunk.');
                }

                $chunkPairs = [];
                foreach ($value as $chunkKey => $item) {
                    $chunkPairs[] = [$chunkKey, $item];
                }
                $chunkFiles[] = $this->writeTemporaryChunk($resolvedTempChunkDir, $chunkPairs);
            }

            if ($pairs !== []) {
                $chunkFiles[] = $this->writeTemporaryChunk($resolvedTempChunkDir, $pairs);
            }
        } catch (Throwable $exception) {
            // Whatever stopped the read, the chunks written so far belong to nobody now.
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
     * @return Generator<int|string, mixed>
     *
     * @throws RuntimeException
     */
    private function yieldFromTemporaryChunks(array $chunkFiles, bool $yieldItems): Generator
    {
        try {
            foreach ($chunkFiles as $chunkFile) {
                $pairs = $this->readTemporaryChunk($chunkFile);

                if ($yieldItems) {
                    foreach ($pairs as [$key, $item]) {
                        yield $key => $item;
                    }

                    continue;
                }

                $chunk = [];
                foreach ($pairs as [$key, $item]) {
                    $chunk[$key] = $item;
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
     * Refuses an element that has grown past maxElementBytes — called as it grows, so the refusal comes
     * before the whole of it is in memory.
     *
     * @throws ElementTooLargeException
     */
    private function assertWithinLimit(JsonStream $stream, int $bytes): void
    {
        if ($this->maxElementBytes !== null && $bytes > $this->maxElementBytes) {
            throw new ElementTooLargeException(sprintf(
                'An element read up to byte %d is larger than maxElementBytes, %d bytes.',
                $stream->offset(),
                $this->maxElementBytes,
            ));
        }
    }

    /**
     * Whether a path names a stream wrapper rather than the filesystem. PHP finds a wrapper whatever
     * case its scheme is in, so the match ignores case too — `FILE://` is a file, `PHAR://` is a wrapper.
     * `data:` is the one scheme PHP opens without `//`.
     */
    private function isStreamWrapper(string $path): bool
    {
        return preg_match('#^(?:(?!file://)[a-z][a-z0-9+.\-]*://|data:)#i', $path) === 1;
    }

    /**
     * A stream over $source in the block size this reader was given — or, for Auto, 64 KB to start with
     * and growing for elements that do not fit.
     */
    private function newStream(JsonSourceInterface $source): JsonStream
    {
        if ($this->blockSize === BlockSize::Auto) {
            return new JsonStream($source, JsonStream::BLOCK_SIZE, true);
        }

        return new JsonStream($source, $this->blockSize->value);
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function openFile(string|JsonSourceInterface $filePath): JsonStream
    {
        // A source was handed over ready to read: nothing to find, nothing to open.
        if ($filePath instanceof JsonSourceInterface) {
            return $this->newStream($filePath);
        }

        // A stream wrapper is not a missing file, and saying "was not found" about `php://memory`
        // sends the reader looking for something that was never supposed to exist. A wrapper CAN be
        // read — pass it as a `StreamSource` and it is — but a bare string of one is ambiguous enough
        // to be worth refusing out loud. `file://` names a real file and is left to `is_file()`
        // below, which accepts it. PHP finds a wrapper whatever case its scheme is in, so the match
        // ignores case too — `FILE://` is a file, `PHAR://` is a wrapper. `data:` is the one scheme
        // PHP opens without `//`; `is_file()` turned it away only because the data wrapper cannot
        // stat, and that is not a guarantee worth leaning on.
        if ($this->isStreamWrapper($filePath)) {
            throw new InvalidArgumentException(sprintf(
                'JSON source "%s" is a stream wrapper; this reader needs a filesystem path.',
                $filePath,
            ));
        }

        if (!is_file($filePath)) {
            throw new InvalidArgumentException(sprintf('JSON file "%s" was not found.', $filePath));
        }

        try {
            $file = new SplFileObject($filePath, 'rb');
        } catch (RuntimeException $exception) {
            throw new RuntimeException(sprintf('Unable to read JSON file "%s".', $filePath), 0, $exception);
        }

        // The buffer travels with the source, so nothing about this read lives on the reader.
        return $this->newStream(new FileSource($file, $filePath));
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    private function positionStreamAtTargetArrayStart(JsonStream $stream, string|array|null $keyPath): string
    {
        $first = $this->readNonWhitespaceChar($stream);
        if ($first === null) {
            throw new InvalidArgumentException($this->invalidJsonMessage(null, 'empty content.', $stream));
        }

        $this->assertNoByteOrderMark($first);

        if ($keyPath !== null && $keyPath !== '') {
            $segments = $this->splitAndValidateKeyPath($keyPath);

            $resolved = $this->seekPath($stream, $first, $segments, 0, $keyPath);

            if ($resolved !== '[' && $resolved !== '{') {
                throw new RuntimeException(sprintf(
                    'Resolved key path "%s" must point to a JSON array or object; getValue() reads a single value.',
                    $this->describeKeyPath($keyPath),
                ));
            }

            return $resolved;
        }

        if ($first !== '[' && $first !== '{') {
            throw new InvalidArgumentException(
                'The JSON root value must be an array or an object. '
                . 'A string, a number, a boolean or null is a whole document with nothing to stream.',
            );
        }

        return $first;
    }

    /**
     * Whichever container the path landed on, walked one entry at a time.
     *
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function streamContainer(JsonStream $stream, string $filePath, string $container): Generator
    {
        if ($container === '{') {
            yield from $this->streamObjectMembers($stream, $filePath);

            return;
        }

        yield from $this->streamArrayValues($stream, $filePath);
    }

    /**
     * The members of a JSON object, one at a time, as `key => value`.
     *
     * A sibling of `streamArrayValues()` for the other container. A document keyed by id —
     * `{"u1": {...}, "u2": {...}}` — is an ordinary shape for an export, and until this existed it
     * could not be read at all: a key path had to end at an ARRAY, so a map of a hundred thousand
     * entries had no way in.
     *
     * @return Generator<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function streamObjectMembers(JsonStream $stream, string $filePath): Generator
    {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === '}') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in object.', $stream),
            );
        }

        $stream->pushBack($next);

        while (true) {
            $keyFirst = $this->readNonWhitespaceChar($stream);
            if ($keyFirst !== '"') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'object key must be a string.', $stream),
                );
            }

            $members = $this->decodeMemberRunFast($stream);
            if ($members !== null) {
                foreach ($members as $memberKey => $memberValue) {
                    yield (string)$memberKey => $memberValue;
                }

                $delimiter = $this->readNonWhitespaceChar($stream);
                if ($delimiter === '}') {
                    return;
                }

                if ($delimiter !== ',') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'expected "," or "}" in object.', $stream),
                    );
                }

                continue;
            }

            $key = $this->decodeScannedValue($this->readStringToken($stream, $filePath), $filePath, $stream);

            $separator = $this->readNonWhitespaceChar($stream);
            if ($separator !== ':') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected ":" after object key.', $stream),
                );
            }

            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in object value.', $stream),
                );
            }

            $valueOffset = $stream->offset() - 1;
            if ($this->decodeContainerFast($stream, $valueFirst, $value)) {
                yield (string)$key => $value;
            } else {
                $rawValue = $this->scanValue($stream, $valueFirst, $filePath);

                try {
                    yield (string)$key => json_decode($rawValue, $this->associative, 512, $this->decodeFlags);
                } catch (JsonException $exception) {
                    $this->handOverInvalidItem($exception, $rawValue, $valueOffset, (string)$key, $filePath);
                }
            }

            $delimiter = $this->readNonWhitespaceChar($stream);

            if ($delimiter === '}') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "}" in object.', $stream),
                );
            }
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
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    private function assertNothingFollowsRootArray(
        JsonStream $stream,
        string $filePath,
        string|array|null $keyPath,
    ): void {
        if ($keyPath !== null && $keyPath !== '') {
            return;
        }

        $trailing = $this->readNonWhitespaceChar($stream);
        if ($trailing === null) {
            return;
        }

        throw new InvalidArgumentException($this->invalidJsonMessage(
            $filePath,
            sprintf('unexpected "%s" after the root array ended.', $trailing),
            $stream,
        ));
    }

    /**
     * @return Generator<int|string, mixed>
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
                $this->invalidJsonMessage($filePath, 'unexpected end of input in array.', $stream),
            );
        }

        $stream->pushBack($next);
        $index = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.', $stream),
                );
            }

            // Where this value STARTS, not where the scanner happened to stop. A complaint about an
            // element is only useful if it points at the element: by the time `json_decode()` refuses
            // it, the stream has already run past its end.
            $valueOffset = $stream->offset() - 1;

            $run = $this->decodeRunFast($stream, $valueFirst);
            if ($run !== null) {
                foreach ($run as $value) {
                    yield $value;
                }

                $index += count($run) - 1;
            } elseif ($this->decodeContainerFast($stream, $valueFirst, $value)) {
                yield $value;
            } else {
                // Hot path: use block-buffer scanners (strcspn at C level) instead of
                // reading it a character at a time and concatenating.
                $rawValue = $this->scanValue($stream, $valueFirst, $filePath);

                try {
                    yield json_decode($rawValue, $this->associative, 512, $this->decodeFlags);
                } catch (JsonException $exception) {
                    $this->handOverInvalidItem($exception, $rawValue, $valueOffset, $index, $filePath);
                }
            }

            $index++;
            $delimiter = $this->readNonWhitespaceChar($stream);

            if ($delimiter === ']') {
                break;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "]" in array.', $stream),
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
                $this->invalidJsonMessage($filePath, $detail ?: 'unexpected end of input.', $stream),
            );
        }

        $stream->adopt($chunk);
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
            $raw = $this->scanNested($stream, $firstChar, $filePath);
            $stream->fitElement(strlen($raw));

            return $raw;
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
                $this->assertWithinLimit($stream, strlen($result));
                continue;
            }

            $special = $stream->buf[$segEnd];
            $result .= substr($stream->buf, $stream->bufPos, $spanLen + 1); // include special char
            $stream->bufPos = $segEnd + 1;

            if ($special === '"') {
                if ($this->maxElementBytes !== null) {
                    $this->assertWithinLimit($stream, strlen($result));
                }

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
                if ($this->maxElementBytes !== null) {
                    $this->assertWithinLimit($stream, strlen($result));
                }

                continue;
            }

            $struct = $stream->buf[$segEnd];
            $result .= substr($stream->buf, $stream->bufPos, $spanLen + 1);
            $stream->bufPos = $segEnd + 1;

            if ($struct === '"') {
                // Scan string body so that `{`, `}`, `[`, `]` inside strings are skipped.
                $result .= $this->scanStringRemainder($stream, $filePath);
                if ($this->maxElementBytes !== null) {
                    $this->assertWithinLimit($stream, strlen($result));
                }
            } elseif ($struct === '{' || $struct === '[') {
                $depth++;
            } else { // `}` or `]`
                $depth--;
            }
        }

        // Growth is checked at each string and each block; this catches the rest of the element.
        if ($this->maxElementBytes !== null) {
            $this->assertWithinLimit($stream, strlen($result));
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

                $stream->adopt($chunk);
            }

            $spanLen = strcspn($stream->buf, ",]} \t\n\r", $stream->bufPos);
            $segEnd = $stream->bufPos + $spanLen;

            if ($segEnd >= $stream->bufLen) {
                $result .= substr($stream->buf, $stream->bufPos);
                $stream->bufPos = $stream->bufLen;
                $this->assertWithinLimit($stream, strlen($result));
                continue;
            }

            $result .= substr($stream->buf, $stream->bufPos, $spanLen);
            $stream->bufPos = $segEnd; // leave delimiter in buffer (not consumed)
            break;
        }

        if ($this->maxElementBytes !== null) {
            $this->assertWithinLimit($stream, strlen($result));
        }

        return $result;
    }

    // ─── end block-buffer scanners ────────────────────────────────────────────

    /**
     * @param array<int, string> $segments
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string> $keyPath a dotted path, or its segments taken literally
     */
    private function seekPath(JsonStream $stream, string $firstChar, array $segments, int $depth, string|array $keyPath): string
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
                throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $this->describeKeyPath($keyPath), $segment));
            }

            return $this->seekPathInArray($stream, $segments, $depth, (int)$segment, $keyPath);
        }

        throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $this->describeKeyPath($keyPath), $segment));
    }

    /**
     * @param array<int, string> $segments
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string> $keyPath a dotted path, or its segments taken literally
     */
    private function seekPathInObject(JsonStream $stream, array $segments, int $depth, string $segment, string|array $keyPath): string
    {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === '}') {
            throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $this->describeKeyPath($keyPath), $segment));
        }

        if ($next === null) {
            throw new InvalidArgumentException($this->invalidJsonMessage(null, 'unexpected end of input in object.', $stream));
        }

        $stream->pushBack($next);

        while (true) {
            $keyFirst = $this->readNonWhitespaceChar($stream);
            if ($keyFirst !== '"') {
                throw new InvalidArgumentException($this->invalidJsonMessage(null, 'object key must be a string.', $stream));
            }

            $decodedKey = $this->keyFromToken($this->readStringToken($stream, null), null, $stream);

            $separator = $this->readNonWhitespaceChar($stream);
            if ($separator !== ':') {
                throw new InvalidArgumentException($this->invalidJsonMessage(null, 'expected ":" after object key.', $stream));
            }

            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException($this->invalidJsonMessage(null, 'unexpected end of input in object value.', $stream));
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
                throw new InvalidArgumentException($this->invalidJsonMessage(null, 'expected "," or "}" in object.', $stream));
            }
        }

        throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $this->describeKeyPath($keyPath), $segment));
    }

    /**
     * @param array<int, string> $segments
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string> $keyPath a dotted path, or its segments taken literally
     */
    private function seekPathInArray(JsonStream $stream, array $segments, int $depth, int $targetIndex, string|array $keyPath): string
    {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === ']') {
            $segment = $segments[$depth];
            throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $this->describeKeyPath($keyPath), $segment));
        }

        if ($next === null) {
            throw new InvalidArgumentException($this->invalidJsonMessage(null, 'unexpected end of input in array.', $stream));
        }

        $stream->pushBack($next);

        $index = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException($this->invalidJsonMessage(null, 'unexpected end of input in array value.', $stream));
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
                throw new InvalidArgumentException($this->invalidJsonMessage(null, 'expected "," or "]" in array.', $stream));
            }

            $index++;
        }

        $segment = $segments[$depth];
        throw new RuntimeException(sprintf('Key path "%s" was not found at segment "%s".', $this->describeKeyPath($keyPath), $segment));
    }

    /**
     * @return array<int, string>
     */
    /**
     * The path as a list of segments.
     *
     * A dotted string is the short form, and it cannot name a key that contains a dot — `{"a.b": []}`
     * was simply unreachable, and dots in keys are ordinary (a domain, a version, `user.name`). So the
     * path may also be given as an ARRAY, whose elements are taken literally:
     *
     *     keyPath: 'data.0.items'
     *     keyPath: ['a.b']            // one key, which happens to contain a dot
     *
     * `'*'` still means "every element of this list" in both forms. A key named exactly `*` is the one
     * name that stays unreachable, which is a far rarer thing to be called than anything with a dot.
     *
     * @param string|array<int, mixed> $keyPath accepted loosely on purpose: proving the segments are
     *                                           strings is what this method is for
     *
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     */
    private function splitAndValidateKeyPath(string|array $keyPath): array
    {
        $segments = is_array($keyPath) ? array_values($keyPath) : explode('.', $keyPath);

        if (count($segments) > self::MAX_KEY_PATH_SEGMENTS) {
            throw new InvalidArgumentException(sprintf(
                'Key path must have at most %d segments, %d given.',
                self::MAX_KEY_PATH_SEGMENTS,
                count($segments),
            ));
        }

        foreach ($segments as $index => $segment) {
            if (!is_string($segment)) {
                throw new InvalidArgumentException('Key path segments must be strings.');
            }

            // `a.**`, `**.*` and `**.**` would hand over every node below — each value, then each value
            // inside it, again and again. `**` is for finding something by name.
            if ($segment === '**') {
                $next = $segments[$index + 1] ?? null;
                if (!is_string($next) || $next === '*' || $next === '**') {
                    throw new InvalidArgumentException(sprintf(
                        'In key path "%s", "**" must be followed by a name or an index.',
                        is_string($keyPath) ? $keyPath : implode('", "', array_filter($segments, 'is_string')),
                    ));
                }
            }

            // `a..b` is a typo in the dotted form. In the list form every segment is taken literally,
            // and `""` is a key JSON allows — refusing it left such a member out of reach entirely.
            if ($segment === '' && !is_array($keyPath)) {
                throw new InvalidArgumentException('Key path must not contain empty segments.');
            }
        }

        return $segments;
    }

    /**
     * @param string|array<int, string> $keyPath
     */
    private function hasWildcardSegment(string|array $keyPath): bool
    {
        $segments = $this->splitAndValidateKeyPath($keyPath);

        return in_array('*', $segments, true) || in_array('**', $segments, true);
    }

    /**
     * The path as it should read back in a message — the dotted form for a string, and a bracketed
     * list for an array, so a key with a dot in it is not reported as two segments.
     *
     * @param string|array<int, string> $keyPath
     */
    /**
     * A name for the thing being read, for messages: the path for a path, whatever the source calls
     * itself otherwise.
     */
    private function describeSource(string|JsonSourceInterface $filePath): string
    {
        return $filePath instanceof JsonSourceInterface ? $filePath->describe() : $filePath;
    }

    /**
     * @param string|array<int, string> $keyPath
     */
    private function describeKeyPath(string|array $keyPath): string
    {
        if (!is_array($keyPath)) {
            return $keyPath;
        }

        return '[' . implode(', ', array_map(static fn(string $s): string => '"' . $s . '"', $keyPath)) . ']';
    }

    /**
     * Whether a list the path ends on is streamed item by item. It is — `users` gives the users — unless
     * the path ends in `*`: that leaf is one element of a list, handed over as it is.
     *
     * @param array<int, string> $segments
     */
    private function walksLeafList(array $segments): bool
    {
        return $segments === [] || $segments[count($segments) - 1] !== '*';
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string> $keyPath a dotted path, or its segments taken literally
     */
    private function countForWildcardKeyPath(string|JsonSourceInterface $filePath, string|array $keyPath): int
    {
        $total = 0;

        foreach ($this->streamWildcardValues($filePath, $keyPath) as $ignored) {
            $total++;
        }

        return $total;
    }

    /**
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string> $keyPath a dotted path, or its segments taken literally
     */
    private function readGeneratorWithWildcardKeyPath(
        string|JsonSourceInterface $filePath,
        int|null $chunkSize,
        int|null $limit,
        int $offset,
        string|array $keyPath,
    ): Generator {
        // Whether the wildcard walk reached the end is of no interest here: the trailing-content rule
        // applies to a ROOT array only, and a wildcard path is never one.
        $reachedTheEnd = true;

        yield from $this->applyWindow(
            values: $this->streamWildcardValues($filePath, $keyPath),
            chunkSize: $chunkSize,
            limit: $limit,
            offset: $offset,
            readToTheEnd: $reachedTheEnd,
        );
    }

    /**
     * `offset`, `limit` and `chunkSize` applied to a stream of values, one value at a time.
     *
     * Both reads windowed their values with the same twenty lines, copied. The copies did not drift in
     * behaviour, but they drifted in COVERAGE: changing `>=` to `>` in the wildcard copy's chunking
     * left the whole suite green, because every wildcard test read the values whole. One body cannot
     * do that.
     *
     * @param iterable<int|string, mixed> $values
     * @param bool $readToTheEnd lowered when `limit` cuts the read short, so the caller can tell
     *                           "the source ended" from "we stopped asking"
     *
     * @return Generator<int|string, mixed>
     */
    private function applyWindow(
        iterable $values,
        int|null $chunkSize,
        int|null $limit,
        int $offset,
        bool &$readToTheEnd,
        bool $preserveKeys = false,
    ): Generator {
        $skipped = 0;
        $taken = 0;
        $currentChunk = [];

        foreach ($values as $key => $value) {
            if ($skipped < $offset) {
                $skipped++;

                continue;
            }

            if ($limit !== null && $taken >= $limit) {
                $readToTheEnd = false;

                break;
            }

            // Item by item the key always travels: for an array source it is the index the caller
            // would have got anyway, and for an object it is the name they came for. No test of
            // $preserveKeys on the hot path.
            //
            // Chunks are where the difference bites: an array's indices would make the second chunk
            // start at 3 rather than 0, so there they are dropped — while an object's names are kept.
            if ($chunkSize === null) {
                yield $key => $value;
            } else {
                if ($preserveKeys) {
                    $currentChunk[$key] = $value;
                } else {
                    $currentChunk[] = $value;
                }

                if (count($currentChunk) >= $chunkSize) {
                    yield $currentChunk;
                    $currentChunk = [];
                }
            }

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
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string> $keyPath a dotted path, or its segments taken literally
     */
    private function streamWildcardValues(string|JsonSourceInterface $filePath, string|array $keyPath): Generator
    {
        $segments = $this->splitAndValidateKeyPath($keyPath);
        $stream = $this->openFile($filePath);
        // Past this point the source is just a name: everything below reports, it does not read.
        $filePath = $this->describeSource($filePath);
        $matched = false;

        try {
            $first = $this->readNonWhitespaceChar($stream);
            if ($first === null) {
                throw new InvalidArgumentException($this->invalidJsonMessage(null, 'empty content.', $stream));
            }

            $this->assertNoByteOrderMark($first);

            if (in_array('**', $segments, true)) {
                // `**` can stand on several places in one path at once — a level it spans and the
                // level after it — which is what the cursors of readPaths() are for.
                $found = [];
                $cursors = [['segments' => $segments, 'depth' => 0, 'label' => '', 'id' => 0, 'needle' => $this->needleFor($segments)]];
                foreach ($this->walkCursors($stream, $first, $cursors, $filePath, $found, 0) as $value) {
                    yield $value;
                }
                $matched = $found !== [];
            } else {
                yield from $this->walkKeyPath($stream, $first, $segments, 0, $filePath, $matched);
            }

            if (!$matched) {
                throw new RuntimeException(sprintf('Key path "%s" was not found.', $this->describeKeyPath($keyPath)));
            }
        } finally {
            $stream->close();
        }
    }

    /**
     * Several key paths, read in ONE pass, yielding `path => value`.
     *
     * Two paths into the same document used to mean two reads of it. The walk already descends the
     * document once; what it lacked was the ability to carry more than one place in more than one path
     * at a time. A cursor is exactly that — a path, and how far along it we are — and every method
     * below takes a LIST of them instead of a single `$segments`/`$depth` pair.
     *
     * The key is the path that matched, so a `foreach` reads as "this value, from that path".
     * Generators allow repeated keys, so a path that matches many values simply appears many times.
     *
     * @param array<int, string|array<int, string>> $keyPaths
     *
     * @return Generator<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function streamManyPaths(string|JsonSourceInterface $filePath, array $keyPaths): Generator
    {
        $cursors = [];
        foreach ($keyPaths as $keyPath) {
            $segments = $this->splitAndValidateKeyPath($keyPath);
            $cursors[] = [
                'segments' => $segments,
                'depth' => 0,
                'label' => $this->describeKeyPath($keyPath),
                'id' => count($cursors),
                'needle' => $this->needleFor($segments),
            ];
        }

        if ($cursors === []) {
            throw new InvalidArgumentException('At least one key path is required.');
        }

        $stream = $this->openFile($filePath);
        $sourceName = $this->describeSource($filePath);
        $matched = [];

        try {
            $first = $this->readNonWhitespaceChar($stream);
            if ($first === null) {
                throw new InvalidArgumentException($this->invalidJsonMessage(null, 'empty content.', $stream));
            }

            $this->assertNoByteOrderMark($first);

            yield from $this->walkCursors($stream, $first, $cursors, $sourceName, $matched, 0);

            $missed = [];
            foreach ($cursors as $cursor) {
                if (!array_key_exists($cursor['label'], $matched)) {
                    $missed[] = $cursor['label'];
                }
            }

            if ($missed !== []) {
                throw new RuntimeException(sprintf(
                    'Key path%s not found: %s.',
                    count($missed) === 1 ? '' : 's',
                    implode(', ', array_map(static fn(string $p): string => '"' . $p . '"', $missed)),
                ));
            }
        } finally {
            $stream->close();
        }
    }

    /**
     * One value, and every cursor currently standing on it. Consumes the value whole.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     * @param array<string, bool> $matched
     *
     * @return Generator<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function walkCursors(
        JsonStream $stream,
        string $firstChar,
        array $cursors,
        string $filePath,
        array &$matched,
        int $level,
    ): Generator {
        if ($level > self::MAX_RECURSIVE_DEPTH) {
            throw new InvalidArgumentException($this->invalidJsonMessage(
                $filePath,
                sprintf('nesting deeper than %d levels; "**" does not walk below json_decode()\'s own depth.', self::MAX_RECURSIVE_DEPTH),
                $stream,
            ));
        }

        $cursors = $this->expandRecursiveCursors($cursors);
        $arrived = [];
        $active = [];

        foreach ($cursors as $cursor) {
            if ($cursor['depth'] >= count($cursor['segments'])) {
                $arrived[] = $cursor;
            } else {
                $active[] = $cursor;
            }
        }

        // A value can only be consumed once, so a node several paths arrived at is read once and the
        // result handed to each of them. Distinct paths reach the same node only through wildcards,
        // which is rare and does not deserve a second read of the document.
        if ($arrived !== []) {
            foreach ($arrived as $cursor) {
                $matched[$cursor['label']] = true;
            }

            if ($firstChar === '[' && count($arrived) === 1 && $active === [] && $this->walksLeafList($arrived[0]['segments'])) {
                $label = $arrived[0]['label'];
                foreach ($this->streamArrayValues($stream, $filePath) as $item) {
                    yield $label => $item;
                }

                return;
            }

            // A list several paths stand on — one arrived, others going on below, as `**` always does —
            // is walked one element at a time: each element is handed to the arrived paths, then
            // searched by the rest, and only that one element is ever held. Holding the whole list to
            // search it afterwards made `**.items` keep every `items` list in memory.
            if ($firstChar === '[' && $active !== [] && $this->allWalkLeafLists($arrived)) {
                yield from $this->walkListForCursors($stream, $arrived, $active, $filePath, $matched, $level);

                return;
            }

            $valueOffset = $stream->offset() - 1;
            $raw = $this->scanValue($stream, $firstChar, $filePath);
            if ($this->onInvalidItem === null) {
                $value = $this->decodeScannedValue($raw, $filePath, $stream);
            } else {
                try {
                    $value = json_decode($raw, $this->associative, 512, $this->decodeFlags);
                } catch (JsonException $exception) {
                    // The paths that go on below this value would walk the same broken bytes.
                    $this->handOverInvalidItem($exception, $raw, $valueOffset, null, $filePath);

                    return;
                }
            }

            foreach ($arrived as $cursor) {
                if (is_array($value) && $firstChar === '[' && $this->walksLeafList($cursor['segments'])) {
                    foreach ($value as $item) {
                        yield $cursor['label'] => $item;
                    }

                    continue;
                }

                yield $cursor['label'] => $value;
            }

            // Paths that go on below this node — `a` and `a.0.b` together — were dropped here: the
            // value is consumed once, and they never saw it, so a path plainly in the document was
            // reported missing. They walk the scanned copy instead of the stream.
            if ($active !== [] && ($firstChar === '{' || $firstChar === '[')) {
                $copy = $this->newStream(new StringSource($raw, $filePath));
                try {
                    $copyFirst = $this->readNonWhitespaceChar($copy);
                    if ($copyFirst !== null) {
                        yield from $this->walkCursors($copy, $copyFirst, $active, $filePath, $matched, $level);
                    }
                } finally {
                    $copy->close();
                }
            }

            return;
        }

        if ($firstChar === '{') {
            yield from $this->walkCursorsInObject($stream, $active, $filePath, $matched, $level);

            return;
        }

        if ($firstChar === '[') {
            yield from $this->walkCursorsInList($stream, $active, $filePath, $matched, $level);

            return;
        }

        $this->skipValue($stream, $firstChar, $filePath);
    }

    /**
     * The cursors standing on a node, with every `**` also taken as spanning no level at all: a cursor
     * at `**` stays — it goes on into every child — and a copy of it moves to the name after `**`, to
     * be matched against this node's own children. Two ways to one place are one cursor.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     *
     * @return array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}>
     */
    private function expandRecursiveCursors(array $cursors): array
    {
        // Keyed by path and depth — an int, as a path has at most 512 segments — so two ways to one
        // place are one cursor.
        $expanded = [];
        foreach ($cursors as $cursor) {
            $expanded[$cursor['id'] * 1024 + $cursor['depth']] = $cursor;
            if (($cursor['segments'][$cursor['depth']] ?? null) === '**') {
                $cursor['depth']++;
                $expanded[$cursor['id'] * 1024 + $cursor['depth']] = $cursor;
            }
        }

        return array_values($expanded);
    }

    /**
     * The cursors that go on into one child: `**` stays where it is, `*` and a matching name or index
     * move one segment on, and the rest stop here.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     * @param string|null $key the member's name, or the element's index as a string; null when no
     *                         cursor here needs the name, which then was never decoded
     *
     * @return array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}>
     */
    private function cursorsIntoChild(array $cursors, string|null $key): array
    {
        $wanted = [];
        foreach ($cursors as $cursor) {
            $segment = $cursor['segments'][$cursor['depth']];
            if ($segment === '**') {
                $wanted[] = $cursor;
            } elseif ($segment === '*' || $segment === $key) {
                $cursor['depth']++;
                $wanted[] = $cursor;
            }
        }

        return $wanted;
    }

    /**
     * Whether a child has anything to give these cursors. A scalar has nothing below it, so unless a
     * path ends exactly on it, it is skipped without a walk — under `**` that is most values.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     */
    private function worthWalking(array $cursors, string $valueFirst): bool
    {
        if ($cursors === []) {
            return false;
        }

        if ($valueFirst === '{' || $valueFirst === '[') {
            return true;
        }

        foreach ($cursors as $cursor) {
            if ($cursor['depth'] >= count($cursor['segments'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a container the stream has just entered cannot hold anything these paths are after —
     * decided from its raw bytes, so it can be skipped at the speed of a skipped branch instead of
     * walked member by member.
     *
     * Every path ends on a name, and anything a path finds inside the container is a member by that
     * name, so its key — `"email"` — is in the container's bytes. Bytes without it cannot hold a match.
     * That holds only for a key written plainly: an escape could spell the same name differently, so
     * a container with a backslash anywhere in it is walked. So is one that does not end within the
     * block in hand, one a path ends ON, and one a path could reach by an index or `*`.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     */
    private function holdsNoTarget(JsonStream $stream, array $cursors): bool
    {
        if ($stream->charBuffer !== []) {
            return false;
        }

        // A path without `**` goes only where its names lead, so what it enters nearly always holds
        // what it is after, and the look would cost more than it saves.
        $recursive = false;
        foreach ($cursors as $cursor) {
            if ($cursor['needle'] === null || $cursor['depth'] >= count($cursor['segments'])) {
                return false;
            }

            if ($cursor['segments'][$cursor['depth']] === '**') {
                $recursive = true;
            }
        }

        if (!$recursive) {
            return false;
        }

        // The first place any of them — or a backslash — occurs ahead. If the container closes before
        // that, none of them is inside it.
        $limit = min($stream->bufLen, $stream->bufPos + 16384, $this->nextHit($stream, '\\'));
        foreach ($cursors as $cursor) {
            $limit = min($limit, $this->nextHit($stream, (string)$cursor['needle']));
        }

        return $this->containerClosesBefore($stream, $limit);
    }

    /**
     * The bytes a path's last segment shows as a key written plainly — `"email"` — or null when it
     * cannot be looked for that way: `*`, an index, or a name JSON would have to escape.
     *
     * @param array<int, string> $segments
     */
    private function needleFor(array $segments): string|null
    {
        $last = $segments === [] ? '*' : $segments[count($segments) - 1];
        if ($last === '*' || ctype_digit($last) || preg_match('/["\\\\\x00-\x1f]/', $last) === 1) {
            return null;
        }

        return '"' . $last . '"';
    }

    /**
     * Where $needle next occurs in the block, at or after the read position; the block's length when
     * it does not. Cached on the stream until the read position passes it.
     */
    private function nextHit(JsonStream $stream, string $needle): int
    {
        $at = $stream->nextHit[$needle] ?? -1;
        if ($at < $stream->bufPos) {
            $found = strpos($stream->buf, $needle, $stream->bufPos);
            $at = $found === false ? $stream->bufLen : $found;
            $stream->nextHit[$needle] = $at;
        }

        return $at;
    }

    /**
     * Whether the container the stream has just entered closes before $limit. It only looks for the
     * closing bracket: the skip that follows checks the structure.
     */
    private function containerClosesBefore(JsonStream $stream, int $limit): bool
    {
        $buf = $stream->buf;
        $pos = $stream->bufPos;

        // Without a closing bracket before $limit it cannot close there — one C-level look, and the
        // usual answer when what is looked for sits near the container's start.
        if (strcspn($buf, '}]', $pos, max(0, $limit - $pos)) >= $limit - $pos) {
            return false;
        }

        $depth = 1;

        while (true) {
            $pos += strcspn($buf, '{}[]"', $pos, max(0, $limit - $pos));
            if ($pos >= $limit) {
                return false;
            }

            $char = $buf[$pos++];
            if ($char === '"') {
                // No backslash before $limit, so the next quote closes the string.
                $quote = strpos($buf, '"', $pos);
                if ($quote === false || $quote >= $limit) {
                    return false;
                }

                $pos = $quote + 1;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif (--$depth === 0) {
                return true;
            }
        }
    }

    /**
     * A container these cursors go into, read fast when it ends within the block in hand: decoded
     * whole in C, then walked in the decoded value by followCursorsDecoded() — the `[label, value]`
     * pairs walkCursors() would have yielded, in its order. Null, with the stream untouched, when it
     * cannot be read that way; walkCursors() then reads it from the stream.
     *
     * The decoded walk and the stream walk part in one place, a repeated name — the stream walks each
     * occurrence, json_decode() keeps the last — so an element whose bytes show one the paths could
     * meet is walked the stream way, from its bytes. And a document deeper than `**` walks is refused
     * the same way: json_decode() is given only the depth left, so an element that goes deeper is
     * left to the stream walk, which refuses it.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     * @param array<string, bool> $matched
     *
     * @return array<int, array{0: string, 1: mixed}>|null
     *
     * @throws InvalidArgumentException
     */
    private function followCursorsFast(
        JsonStream $stream,
        string $valueFirst,
        array $cursors,
        string $filePath,
        array &$matched,
        int $level,
    ): array|null {
        $maxDepth = min(512, self::MAX_RECURSIVE_DEPTH + 2 - $level);
        if ($maxDepth < 2) {
            return null;
        }

        $needles = [];
        $wildcard = false;
        foreach ($cursors as $cursor) {
            for ($index = $cursor['depth'], $count = count($cursor['segments']); $index < $count; $index++) {
                $segment = $cursor['segments'][$index];
                if ($segment === '*' || $segment === '**') {
                    $wildcard = true;

                    continue;
                }

                if (str_contains($segment, '/') || preg_match('/["\\\\\x00-\x1f]/', $segment) === 1) {
                    return null;
                }

                $needles['"' . $segment . '"'] = true;
            }
        }

        if (!$this->decodeContainerFast($stream, $valueFirst, $element, false, $raw, $maxDepth)) {
            return null;
        }

        $raw = (string)$raw;
        $safe = $needles === [] || !str_contains($raw, '\\u');
        foreach ($needles as $needle => $ignored) {
            if (!$safe || substr_count($raw, (string)$needle) > 1) {
                $safe = false;

                break;
            }
        }

        if ($safe && $wildcard) {
            $safe = $this->repeatsNoName($raw, $element);
        }

        $pairs = [];
        if ($safe) {
            $this->followCursorsDecoded($element, $cursors, $matched, $pairs);

            return $pairs;
        }

        $copy = $this->newStream(new StringSource($raw, $filePath));
        try {
            $copyFirst = $this->readNonWhitespaceChar($copy);
            if ($copyFirst !== null) {
                foreach ($this->walkCursors($copy, $copyFirst, $cursors, $filePath, $matched, $level) as $label => $value) {
                    $pairs[] = [(string)$label, $value];
                }
            }
        } finally {
            $copy->close();
        }

        return $pairs;
    }

    /**
     * The names to count during a decoded walk of a run, each at 0 — the named segments the paths have
     * left — or null when counting cannot stand in for the whole-run check: without `**` the walk does
     * not pass every member, and with a backslash in the run a name could be spelled unseen.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     *
     * @return array<string, int>|null
     */
    private function namesToCount(array $cursors, string $raw): array|null
    {
        $recursive = false;
        foreach ($cursors as $cursor) {
            if (($cursor['segments'][$cursor['depth']] ?? null) === '**') {
                $recursive = true;
            }
        }

        if (!$recursive || str_contains($raw, '\\')) {
            return null;
        }

        $seen = [];
        foreach ($cursors as $cursor) {
            for ($index = $cursor['depth'], $count = count($cursor['segments']); $index < $count; $index++) {
                $segment = $cursor['segments'][$index];
                if ($segment === '*' || $segment === '**') {
                    continue;
                }

                if (preg_match('/["\x00-\x1f]/', $segment) === 1) {
                    return null;
                }

                $seen[$segment] = 0;
            }
        }

        return $seen;
    }

    /**
     * Whether a decoded walk that counted $seen passed every one of those names the run's bytes hold.
     *
     * @param array<string, int> $seen
     */
    private function countedAll(array $seen, string $raw): bool
    {
        foreach ($seen as $name => $times) {
            if (substr_count($raw, '"' . $name . '"') !== $times) {
                return false;
            }
        }

        return true;
    }

    /**
     * A run of an object's members, decoded at once as objects and walked by the object's cursors as
     * if it were that object — the same members, in the same order. A repeated key among the members
     * already kept the run from being taken; one inside them is caught as for a list run.
     *
     * @param array<int|string, mixed> $members
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     * @param array<string, bool> $matched
     * @param array<string, NodePlan> $memo
     *
     * @return array<int, array{0: string, 1: mixed}>
     *
     * @throws InvalidArgumentException
     */
    private function followMemberRun(array $members, string $raw, array $cursors, string $filePath, array &$matched, int $level, array &$memo): array
    {
        $seen = $this->namesToCount($cursors, $raw);
        $pairs = [];
        $walkMatched = $matched;
        $safe = $seen !== null;
        if (!$safe) {
            $stripped = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"/', '', $raw);
            $safe = $stripped !== null && substr_count($stripped, ':') === count($members) + $this->memberCount($members);
        }

        if ($safe) {
            $this->followCursorsDecoded((object)$members, $cursors, $walkMatched, $pairs, $seen, $memo);
            if ($seen === null || $this->countedAll($seen, $raw)) {
                $matched = $walkMatched;

                return $pairs;
            }
        }

        $pairs = [];
        $copy = $this->newStream(new StringSource('{' . $raw . '}', $filePath));
        try {
            $this->readNonWhitespaceChar($copy);
            foreach ($this->walkCursorsInObject($copy, $cursors, $filePath, $matched, $level, false) as $label => $value) {
                $pairs[] = [(string)$label, $value];
            }
        } finally {
            $copy->close();
        }

        return $pairs;
    }

    /**
     * A run of list elements, decoded at once as objects, walked by the cursors that go into each — the
     * `[label, value]` pairs walkCursorsInList() would have yielded for them, in order.
     *
     * The run must not have lost a member to a repeated name. Under `**` the decoded walk visits every
     * member anyway, so it counts the members named like a segment of the paths as it goes, and those
     * are checked against the same names counted in the run's bytes — one substr_count() each. A
     * repeated name collapsed by json_decode(), an escaped spelling, the name as a string value: each
     * makes the two differ. Without `**` the whole-run check is used. A run that fails either is walked
     * from its bytes the stream way, and what the decoded walk found is dropped unseen.
     *
     * @param array<int, mixed> $run
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors the list's
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $wanted each element's
     * @param array<string, bool> $matched
     * @param array<string, NodePlan> $memo
     *
     * @return array<int, array{0: string, 1: mixed}>
     *
     * @throws InvalidArgumentException
     */
    private function followCursorRun(array $run, string $raw, array $cursors, array $wanted, string $filePath, array &$matched, int $level, array &$memo): array
    {
        $seen = $this->namesToCount($wanted, $raw);
        $pairs = [];
        $walkMatched = $matched;
        if ($seen !== null || $this->repeatsNoName($raw, $run)) {
            foreach ($run as $element) {
                $this->followCursorsDecoded($element, $wanted, $walkMatched, $pairs, $seen, $memo);
            }

            if ($seen === null || $this->countedAll($seen, $raw)) {
                $matched = $walkMatched;

                return $pairs;
            }
        }

        $pairs = [];
        $copy = $this->newStream(new StringSource('[' . $raw . ']', $filePath));
        try {
            $this->readNonWhitespaceChar($copy);
            foreach ($this->walkCursorsInList($copy, $cursors, $filePath, $matched, $level, false) as $label => $value) {
                $pairs[] = [(string)$label, $value];
            }
        } finally {
            $copy->close();
        }

        return $pairs;
    }

    /**
     * walkCursors(), over a value already decoded with objects as stdClass: the same cursors, the same
     * matches, in the same order, collected as `[label, value]` pairs.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     * @param array<string, bool> $matched
     * @param array<int, array{0: string, 1: mixed}> $pairs
     * @param array<string, int>|null $seen members named like a path segment, counted as they are passed
     * @param array<string, NodePlan> $memo node plans, kept across the elements of a list
     */
    private function followCursorsDecoded(
        mixed $node,
        array $cursors,
        array &$matched,
        array &$pairs,
        array|null &$seen = null,
        array &$memo = [],
    ): void {
        $this->followPlanned($node, $this->cursorSignature($cursors), $cursors, $matched, $pairs, $seen, $memo);
    }

    /**
     * The cursors' identity — which path, how far along — as a string: two nodes with the same
     * signature are walked the same way, so the work of finding out how is done once.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     */
    private function cursorSignature(array $cursors): string
    {
        $signature = '';
        foreach ($cursors as $cursor) {
            $signature .= ($cursor['id'] * 1024 + $cursor['depth']) . ',';
        }

        return $signature;
    }

    /**
     * What a node's cursors do, worked out once per signature: `**` expanded, the cursors split into
     * those that end here and those that go on, and which paths end on a scalar child by which name.
     * Under `**` every element of a list has the same cursors, so a list of a million elements is
     * planned once, not a million times.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     *
     * @return NodePlan
     */
    private function planFor(array $cursors): array
    {
        $cursors = $this->expandRecursiveCursors($cursors);
        $arrived = [];
        $active = [];
        $endsOnAny = false;
        $endsOn = [];
        $indexFree = true;
        foreach ($cursors as $cursor) {
            if ($cursor['depth'] >= count($cursor['segments'])) {
                $arrived[] = $cursor;

                continue;
            }

            $active[] = $cursor;
            $segment = $cursor['segments'][$cursor['depth']];
            if (ctype_digit($segment)) {
                $indexFree = false;
            }

            if ($cursor['depth'] + 1 === count($cursor['segments'])) {
                if ($segment === '*') {
                    $endsOnAny = true;
                } elseif ($segment !== '**') {
                    $endsOn[$segment] = true;
                }
            }
        }

        return [
            'cursors' => $cursors,
            'arrived' => $arrived,
            'active' => $active,
            'activeSignature' => $this->cursorSignature($active),
            'leafLists' => $arrived !== [] && $this->allWalkLeafLists($arrived),
            'endsOnAny' => $endsOnAny,
            'endsOn' => $endsOn,
            'indexFree' => $indexFree,
            'kids' => [],
            'labels' => [],
        ];
    }

    /**
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     * @param array<string, bool> $matched
     * @param array<int, array{0: string, 1: mixed}> $pairs
     * @param array<string, int>|null $seen
     * @param array<string, NodePlan> $memo
     */
    private function followPlanned(
        mixed $node,
        string $signature,
        array $cursors,
        array &$matched,
        array &$pairs,
        array|null &$seen,
        array &$memo,
    ): void {
        if (!array_key_exists($signature, $memo)) {
            // A document of ever new shapes must not grow the memo without end.
            if (count($memo) >= 1024) {
                $memo = [];
            }

            $memo[$signature] = $this->planFor($cursors);
        }

        $plan = &$memo[$signature];
        $isList = is_array($node);
        $isContainer = $isList || $node instanceof stdClass;

        if ($plan['arrived'] !== []) {
            foreach ($plan['arrived'] as $cursor) {
                $matched[$cursor['label']] = true;
            }

            if ($isList && $plan['active'] !== [] && $plan['leafLists']) {
                foreach ($node as $index => $element) {
                    $value = $this->asConfigured($element);
                    foreach ($plan['arrived'] as $cursor) {
                        $pairs[] = [$cursor['label'], $value];
                    }

                    // As for any child: worked out once when no path names an index.
                    $kidKey = $plan['indexFree'] ? '[]active' : '[active' . $index;
                    if (!array_key_exists($kidKey, $plan['kids'])) {
                        $wanted = $this->cursorsIntoChild($plan['active'], (string)$index);
                        $kid = [$wanted, $this->cursorSignature($wanted)];
                        if (count($plan['kids']) < 256) {
                            $plan['kids'][$kidKey] = $kid;
                        }
                    } else {
                        $kid = $plan['kids'][$kidKey];
                    }

                    if ($kid[0] !== []) {
                        $this->followPlanned($element, $kid[1], $kid[0], $matched, $pairs, $seen, $memo);
                    }
                }

                return;
            }

            $value = $this->asConfigured($node);
            foreach ($plan['arrived'] as $cursor) {
                if ($isList && $this->walksLeafList($cursor['segments'])) {
                    foreach ($value as $item) {
                        $pairs[] = [$cursor['label'], $item];
                    }

                    continue;
                }

                $pairs[] = [$cursor['label'], $value];
            }

            if ($plan['active'] !== [] && $isContainer) {
                $this->followPlanned($node, $plan['activeSignature'], $plan['active'], $matched, $pairs, $seen, $memo);
            }

            return;
        }

        if (!$isContainer) {
            return;
        }

        foreach ($isList ? $node : get_object_vars($node) as $key => $child) {
            $key = (string)$key;
            if (!$isList && $seen !== null && array_key_exists($key, $seen)) {
                $seen[$key]++;
            }

            if (is_array($child) || $child instanceof stdClass) {
                // The cursors a child gets depend on its name — or, in a list no path indexes, on
                // nothing at all; either way they are worked out once and remembered.
                $kidKey = $isList && $plan['indexFree'] ? '[]' : ($isList ? '[' . $key : '{' . $key);
                if (!array_key_exists($kidKey, $plan['kids'])) {
                    $wanted = $this->cursorsIntoChild($plan['cursors'], $key);
                    $kid = [$wanted, $this->cursorSignature($wanted)];
                    if (count($plan['kids']) < 256) {
                        $plan['kids'][$kidKey] = $kid;
                    }
                } else {
                    $kid = $plan['kids'][$kidKey];
                }

                if ($kid[0] !== []) {
                    $this->followPlanned($child, $kid[1], $kid[0], $matched, $pairs, $seen, $memo);
                }

                continue;
            }

            // A scalar holds nothing: the one question is which paths end on it.
            if (!$plan['endsOnAny'] && !array_key_exists($key, $plan['endsOn'])) {
                continue;
            }

            if (!array_key_exists($key, $plan['labels'])) {
                // In cursor order, as the stream walk hands them over.
                $labels = [];
                foreach ($plan['cursors'] as $cursor) {
                    $segment = $cursor['segments'][$cursor['depth']] ?? null;
                    if ($cursor['depth'] + 1 === count($cursor['segments']) && ($segment === '*' || $segment === $key)) {
                        $labels[] = $cursor['label'];
                    }
                }

                if (count($plan['labels']) >= 256) {
                    $plan['labels'] = [];
                }
                $plan['labels'][$key] = $labels;
            }

            foreach ($plan['labels'][$key] as $label) {
                $matched[$label] = true;
                $pairs[] = [$label, $child];
            }
        }
    }

    /**
     * A scalar child: decoded once and paired with each path that ends on it, or skipped when none
     * does. Not a generator — a scalar is too small a thing to set one up for.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     * @param array<string, bool> $matched
     *
     * @return array<int, array{0: string, 1: mixed}>
     *
     * @throws InvalidArgumentException
     */
    private function scalarForArrived(JsonStream $stream, string $valueFirst, array $cursors, string $filePath, array &$matched): array
    {
        $labels = [];
        foreach ($cursors as $cursor) {
            if ($cursor['depth'] >= count($cursor['segments'])) {
                $labels[] = $cursor['label'];
            }
        }

        if ($labels === []) {
            $this->skipValue($stream, $valueFirst, $filePath);

            return [];
        }

        foreach ($labels as $label) {
            $matched[$label] = true;
        }

        $valueOffset = $stream->offset() - 1;
        $raw = $this->scanValue($stream, $valueFirst, $filePath);
        if ($this->onInvalidItem === null) {
            $value = $this->decodeScannedValue($raw, $filePath, $stream);
        } else {
            try {
                $value = json_decode($raw, $this->associative, 512, $this->decodeFlags);
            } catch (JsonException $exception) {
                $this->handOverInvalidItem($exception, $raw, $valueOffset, null, $filePath);

                return [];
            }
        }

        $pairs = [];
        foreach ($labels as $label) {
            $pairs[] = [$label, $value];
        }

        return $pairs;
    }

    /**
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     */
    private function allWalkLeafLists(array $cursors): bool
    {
        foreach ($cursors as $cursor) {
            if (!$this->walksLeafList($cursor['segments'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A list some paths arrived at and others go on below: each element is decoded and handed to the
     * arrived paths, then the others search a copy of that one element.
     *
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $arrived
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $active
     * @param array<string, bool> $matched
     *
     * @return Generator<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function walkListForCursors(
        JsonStream $stream,
        array $arrived,
        array $active,
        string $filePath,
        array &$matched,
        int $level,
    ): Generator {
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === ']') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in array.', $stream),
            );
        }

        $stream->pushBack($next);
        $index = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.', $stream),
                );
            }

            $valueOffset = $stream->offset() - 1;
            $raw = $this->scanValue($stream, $valueFirst, $filePath);

            $valid = true;
            try {
                $value = json_decode($raw, $this->associative, 512, $this->decodeFlags);
            } catch (JsonException $exception) {
                $this->handOverInvalidItem($exception, $raw, $valueOffset, $index, $filePath);
                $valid = false;
                $value = null;
            }

            if ($valid) {
                foreach ($arrived as $cursor) {
                    yield $cursor['label'] => $value;
                }

                $wanted = $this->cursorsIntoChild($active, (string)$index);
                if ($this->worthWalking($wanted, $valueFirst)) {
                    $copy = $this->newStream(new StringSource($raw, $filePath));
                    try {
                        $copyFirst = $this->readNonWhitespaceChar($copy);
                        if ($copyFirst !== null) {
                            yield from $this->walkCursors($copy, $copyFirst, $wanted, $filePath, $matched, $level + 1);
                        }
                    } finally {
                        $copy->close();
                    }
                }
            }

            $index++;
            $delimiter = $this->readNonWhitespaceChar($stream);
            if ($delimiter === ']') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "]" in array.', $stream),
                );
            }
        }
    }

    /**
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     * @param array<string, bool> $matched
     *
     * @return Generator<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function walkCursorsInObject(
        JsonStream $stream,
        array $cursors,
        string $filePath,
        array &$matched,
        int $level,
        bool $runs = true,
    ): Generator {
        $memo = [];
        $runDepth = min(512, self::MAX_RECURSIVE_DEPTH + 2 - ($level + 1)) + 1;
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === '}') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in object.', $stream),
            );
        }

        $stream->pushBack($next);

        // Only a named segment needs the name. When every path here is at `*`, the names are skipped
        // without being copied or decoded, as walkWildcardObject() does for a single path.
        $needsKey = false;
        foreach ($cursors as $cursor) {
            $segment = $cursor['segments'][$cursor['depth']];
            if ($segment !== '*' && $segment !== '**') {
                $needsKey = true;

                break;
            }
        }

        while (true) {
            $keyFirst = $this->readNonWhitespaceChar($stream);
            if ($keyFirst !== '"') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'object key must be a string.', $stream),
                );
            }

            if ($runs && $runDepth > 2 && ($members = $this->decodeMemberRunFast($stream, false, $runRaw, $runDepth)) !== null) {
                foreach ($this->followMemberRun($members, (string)$runRaw, $cursors, $filePath, $matched, $level, $memo) as [$label, $value]) {
                    yield $label => $value;
                }

                $delimiter = $this->readNonWhitespaceChar($stream);
                if ($delimiter === '}') {
                    return;
                }

                if ($delimiter !== ',') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'expected "," or "}" in object.', $stream),
                    );
                }

                continue;
            }

            if ($needsKey) {
                $key = $this->keyFromToken($this->readStringToken($stream, $filePath), $filePath, $stream);

                $separator = $this->readNonWhitespaceChar($stream);
                if ($separator !== ':') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'expected ":" after object key.', $stream),
                    );
                }

                $valueFirst = $this->readNonWhitespaceChar($stream);
                if ($valueFirst === null) {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'unexpected end of input in object value.', $stream),
                    );
                }
            } else {
                $key = null;
                $stream->absorbPushBack();
                $valueFirst = $this->skipObjectKey($stream, $keyFirst, $filePath);
            }

            $wanted = $this->cursorsIntoChild($cursors, $key);

            if ($wanted === []) {
                $this->skipValue($stream, $valueFirst, $filePath);
            } elseif ($valueFirst === '{' || $valueFirst === '[') {
                if ($this->holdsNoTarget($stream, $wanted)) {
                    $this->skipValue($stream, $valueFirst, $filePath);
                } elseif (($pairs = $this->followCursorsFast($stream, $valueFirst, $wanted, $filePath, $matched, $level + 1)) !== null) {
                    foreach ($pairs as [$label, $value]) {
                        yield $label => $value;
                    }
                } else {
                    yield from $this->walkCursors($stream, $valueFirst, $wanted, $filePath, $matched, $level + 1);
                }
            } else {
                // A scalar has nothing below it: hand it to the paths that end here, or skip it,
                // without a walk of its own — under `**` that is most of the values in a document.
                foreach ($this->scalarForArrived($stream, $valueFirst, $wanted, $filePath, $matched) as [$label, $value]) {
                    yield $label => $value;
                }
            }

            $delimiter = $this->readNonWhitespaceChar($stream);
            if ($delimiter === '}') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "}" in object.', $stream),
                );
            }
        }
    }

    /**
     * @param array<int, array{segments: array<int, string>, depth: int, label: string, id: int, needle: string|null}> $cursors
     * @param array<string, bool> $matched
     *
     * @return Generator<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function walkCursorsInList(
        JsonStream $stream,
        array $cursors,
        string $filePath,
        array &$matched,
        int $level,
        bool $runs = true,
    ): Generator {
        // Runs need every element to be walked by the same cursors: no path here may name an index.
        $runWanted = null;
        $memo = [];
        if ($runs) {
            $runWanted = [];
            foreach ($cursors as $cursor) {
                $segment = $cursor['segments'][$cursor['depth']];
                if (ctype_digit($segment)) {
                    $runWanted = null;

                    break;
                }

                // What any element gets: `**` stays, `*` moves on; a name never matches an element.
                if ($segment === '**') {
                    $runWanted[] = $cursor;
                } elseif ($segment === '*') {
                    $cursor['depth']++;
                    $runWanted[] = $cursor;
                }
            }
        }

        $next = $this->readNonWhitespaceChar($stream);
        if ($next === ']') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in array.', $stream),
            );
        }

        $stream->pushBack($next);
        $index = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.', $stream),
                );
            }

            $runDepth = min(512, self::MAX_RECURSIVE_DEPTH + 2 - ($level + 1)) + 1;
            if (
                $runWanted !== null && $runWanted !== [] && ($valueFirst === '{' || $valueFirst === '[') && $runDepth > 2
                && ($run = $this->decodeRunFast($stream, $valueFirst, false, $runRaw, $runDepth)) !== null
            ) {
                foreach ($this->followCursorRun($run, (string)$runRaw, $cursors, $runWanted, $filePath, $matched, $level, $memo) as [$label, $value]) {
                    yield $label => $value;
                }

                $index += count($run) - 1;
                $delimiter = $this->readNonWhitespaceChar($stream);
                if ($delimiter === ']') {
                    return;
                }

                if ($delimiter !== ',') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'expected "," or "]" in array.', $stream),
                    );
                }

                $index++;

                continue;
            }

            $wanted = $this->cursorsIntoChild($cursors, (string)$index);

            if ($wanted === []) {
                $this->skipValue($stream, $valueFirst, $filePath);
            } elseif ($valueFirst === '{' || $valueFirst === '[') {
                if ($this->holdsNoTarget($stream, $wanted)) {
                    $this->skipValue($stream, $valueFirst, $filePath);
                } elseif (($pairs = $this->followCursorsFast($stream, $valueFirst, $wanted, $filePath, $matched, $level + 1)) !== null) {
                    foreach ($pairs as [$label, $value]) {
                        yield $label => $value;
                    }
                } else {
                    yield from $this->walkCursors($stream, $valueFirst, $wanted, $filePath, $matched, $level + 1);
                }
            } else {
                // A scalar has nothing below it: hand it to the paths that end here, or skip it,
                // without a walk of its own — under `**` that is most of the values in a document.
                foreach ($this->scalarForArrived($stream, $valueFirst, $wanted, $filePath, $matched) as [$label, $value]) {
                    yield $label => $value;
                }
            }

            $delimiter = $this->readNonWhitespaceChar($stream);
            if ($delimiter === ']') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "]" in array.', $stream),
                );
            }

            $index++;
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
     * @return Generator<int|string, mixed>
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

            // A LIST leaf is walked. An object leaf is handed over WHOLE: walking it would drop the
            // member names — `{"k":1}` would arrive as `1` — and under a wildcard the leaf is the
            // value being collected, not a container the caller asked to stream.
            //
            // Not when the path ENDS in `*`: then the leaf is one element of the list, and it is
            // handed over as it is. Walking it made `a.*` flatten every element that happened to be a
            // list — `[[1,2],{"x":1},[]]` came back as `1, 2, {"x":1}` — so `a.*` and `a` disagreed,
            // and an empty list vanished from both the values and the count.
            if ($firstChar === '[' && $this->walksLeafList($segments)) {
                yield from $this->streamArrayValues($stream, $filePath);

                return;
            }

            // A leaf that is not a list is yielded whole — `data.*.name` over scalars is a documented
            // use, not an error.
            $valueOffset = $stream->offset() - 1;
            if ($this->decodeContainerFast($stream, $firstChar, $value)) {
                yield $value;

                return;
            }

            $raw = $this->scanValue($stream, $firstChar, $filePath);
            if ($this->onInvalidItem === null) {
                yield $this->decodeScannedValue($raw, $filePath, $stream);

                return;
            }

            try {
                $value = json_decode($raw, $this->associative, 512, $this->decodeFlags);
            } catch (JsonException $exception) {
                $this->handOverInvalidItem($exception, $raw, $valueOffset, null, $filePath);

                return;
            }

            yield $value;

            return;
        }

        $segment = $segments[$depth];

        if ($segment === '*') {
            if ($firstChar === '[') {
                yield from $this->walkWildcardList($stream, $segments, $depth, $filePath, $matched);
            } elseif ($firstChar === '{') {
                yield from $this->walkWildcardObject($stream, $segments, $depth, $filePath, $matched);
            } else {
                $this->skipValue($stream, $firstChar, $filePath);
            }

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
     * @return Generator<int|string, mixed>
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
        bool $runs = true,
    ): Generator {
        $fastNeedles = $this->decodedWalkNeedles($segments, $depth + 1);
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === ']') {
            return;
        }

        if ($next === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in array.', $stream),
            );
        }

        $stream->pushBack($next);

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.', $stream),
                );
            }

            if ($fastNeedles !== null && $runs && ($run = $this->decodeRunFast($stream, $valueFirst, false, $runRaw)) !== null) {
                yield from $this->walkDecodedRun($run, (string)$runRaw, $segments, $depth, $filePath, $matched);
            } elseif ($fastNeedles !== null && $this->decodeContainerFast($stream, $valueFirst, $element, false, $raw)) {
                yield from $this->walkDecodedElement($element, (string)$raw, $fastNeedles, $segments, $depth + 1, $filePath, $matched);
            } else {
                yield from $this->walkKeyPath($stream, $valueFirst, $segments, $depth + 1, $filePath, $matched);
            }

            $delimiter = $this->readNonWhitespaceChar($stream);
            if ($delimiter === ']') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "]" in array.', $stream),
                );
            }
        }
    }

    /**
     * `*` on an object: descend into every member's value, in document order. The names are not
     * wanted, so they are skipped the way a skipped branch skips them — no copy, no json_decode() —
     * which is what keeps a map keyed by id as cheap to walk as a list.
     *
     * A repeated name is walked each time it appears: this is a stream of members, not the array
     * json_decode() would collapse them into.
     *
     * @param array<int, string> $segments
     *
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function walkWildcardObject(
        JsonStream $stream,
        array $segments,
        int $depth,
        string $filePath,
        bool &$matched,
        bool $runs = true,
    ): Generator {
        $fastNeedles = $this->decodedWalkNeedles($segments, $depth + 1);
        $next = $this->readNonWhitespaceChar($stream);
        if ($next === '}') {
            return;
        }

        while (true) {
            if ($next === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in object.', $stream),
                );
            }

            $stream->absorbPushBack();
            if ($fastNeedles !== null && $runs && $next === '"' && ($members = $this->decodeMemberRunFast($stream, false, $runRaw)) !== null) {
                yield from $this->walkDecodedMemberRun(array_values($members), (string)$runRaw, $segments, $depth, $filePath, $matched);
            } else {
                $valueFirst = $this->skipObjectKey($stream, $next, $filePath);
                if ($fastNeedles !== null && $this->decodeContainerFast($stream, $valueFirst, $element, false, $raw)) {
                    yield from $this->walkDecodedElement($element, (string)$raw, $fastNeedles, $segments, $depth + 1, $filePath, $matched);
                } else {
                    yield from $this->walkKeyPath($stream, $valueFirst, $segments, $depth + 1, $filePath, $matched);
                }
            }

            $delimiter = $this->readNonWhitespaceChar($stream);
            if ($delimiter === '}') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "}" in object.', $stream),
                );
            }

            $next = $this->readNonWhitespaceChar($stream);
        }
    }

    /**
     * Whether the rest of a path, from $depth on, can be walked over an element decoded whole rather
     * than over the stream — and if so, the key bytes to check each element for. Null when it cannot:
     * a name with a slash, a quote, a backslash or a control character, which the check below cannot
     * see through.
     *
     * The decoded walk is how a small element is read fast: json_decode() reads it in C, and the path
     * is followed in a PHP array instead of key by key in the stream. It must answer exactly what the
     * stream walk answers, and the two differ in one place — a repeated name: the stream takes the
     * first, json_decode() keeps the last. So each element is checked for every name the path still
     * has to match, and one that holds a name more than once, or holds a `\u` escape that could spell
     * one, is walked the stream way instead.
     *
     * @param array<int, string> $segments
     *
     * @return array<int, string>|null
     */
    private function decodedWalkNeedles(array $segments, int $depth): array|null
    {
        // Nothing left to follow: the element itself is the value, and the leaf read already decodes it
        // fast, in the shape asked for — decoding it as objects only to convert it back would cost more.
        if ($depth >= count($segments)) {
            return null;
        }

        $needles = [];
        for ($index = $depth, $count = count($segments); $index < $count; $index++) {
            $segment = $segments[$index];
            if ($segment === '*' || $segment === '**') {
                continue;
            }

            if (str_contains($segment, '/') || preg_match('/["\\\\\x00-\x1f]/', $segment) === 1) {
                return null;
            }

            $needles['"' . $segment . '"'] = true;
        }

        return array_keys($needles);
    }

    /**
     * The cheap check for the commonest path, `*.field`: the field's key bytes are counted in the run
     * and against the elements that came back with that field. A repeated key, collapsed by
     * json_decode(), makes the bytes outnumber the elements; so does the name anywhere else — nested
     * deeper, or as a string value — and then the whole-run check decides instead. A `\u` escape could
     * spell the name unseen, so a run with one is left to that check too.
     *
     * @param array<int, mixed> $run
     * @param array<int, string> $segments
     */
    private function runRepeatsNoFieldName(string $raw, array $run, array $segments, int $depth): bool
    {
        if ($depth + 2 !== count($segments) || str_contains($raw, '\\u')) {
            return false;
        }

        $field = $segments[$depth + 1];
        if ($field === '*' || $field === '**') {
            return false;
        }

        $holding = 0;
        foreach ($run as $element) {
            if ($element instanceof stdClass && property_exists($element, $field)) {
                $holding++;
            }
        }

        return substr_count($raw, '"' . $field . '"') === $holding;
    }

    /**
     * walkDecodedRun() for a run of an object's members: their values, decoded as objects, each walked
     * by the rest of the path. A repeated key among the members themselves already kept the run from
     * being taken; one inside the values is checked here — the run's colons, one per member and one per
     * member of every object inside, against what was decoded — and sends the run's bytes to the stream
     * walk when it is there.
     *
     * @param array<int, mixed> $values
     * @param array<int, string> $segments
     *
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function walkDecodedMemberRun(array $values, string $raw, array $segments, int $depth, string $filePath, bool &$matched): Generator
    {
        $safe = $this->runRepeatsNoFieldName($raw, $values, $segments, $depth);
        if (!$safe) {
            // One colon per member of the run, and one per member of every object inside its values.
            $stripped = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"/', '', $raw);
            $safe = $stripped !== null && substr_count($stripped, ':') === count($values) + $this->memberCount($values);
        }

        if ($safe) {
            foreach ($values as $value) {
                $found = [];
                $this->followDecoded($value, $segments, $depth + 1, $found, $matched);
                foreach ($found as $item) {
                    yield $item;
                }
            }

            return;
        }

        $copy = $this->newStream(new StringSource('{' . $raw . '}', $filePath));
        try {
            $this->readNonWhitespaceChar($copy);
            yield from $this->walkWildcardObject($copy, $segments, $depth, $filePath, $matched, false);
        } finally {
            $copy->close();
        }
    }

    /**
     * A run of list elements decoded at once, as objects, each walked by the rest of the path. The run
     * is checked for a repeated name as a whole — the colons in its bytes against the members decoded —
     * and when one is there, its bytes are walked the stream way instead, one element at a time.
     *
     * @param array<int, mixed> $run
     * @param array<int, string> $segments
     *
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function walkDecodedRun(array $run, string $raw, array $segments, int $depth, string $filePath, bool &$matched): Generator
    {
        if ($this->runRepeatsNoFieldName($raw, $run, $segments, $depth) || $this->repeatsNoName($raw, $run)) {
            foreach ($run as $element) {
                $found = [];
                $this->followDecoded($element, $segments, $depth + 1, $found, $matched);
                foreach ($found as $value) {
                    yield $value;
                }
            }

            return;
        }

        $copy = $this->newStream(new StringSource('[' . $raw . ']', $filePath));
        try {
            $this->readNonWhitespaceChar($copy);
            yield from $this->walkWildcardList($copy, $segments, $depth, $filePath, $matched, false);
        } finally {
            $copy->close();
        }
    }

    /**
     * One element, decoded whole as objects, walked by the rest of the path — or, when its bytes show
     * a repeated or escaped name the path goes through, walked from those bytes the stream way.
     *
     * @param array<int, string> $needles
     * @param array<int, string> $segments
     *
     * @return Generator<int|string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function walkDecodedElement(
        mixed $element,
        string $raw,
        array $needles,
        array $segments,
        int $depth,
        string $filePath,
        bool &$matched,
    ): Generator {
        $safe = $needles === [] || !str_contains($raw, '\\u');
        foreach ($needles as $needle) {
            if (!$safe || substr_count($raw, $needle) > 1) {
                $safe = false;

                break;
            }
        }

        // A wildcard ahead hands over every member of an object it meets, a repeated one each time,
        // and no name in the path shows where one repeats — so the element as a whole must have none.
        if ($safe && in_array('*', array_slice($segments, $depth), true)) {
            $safe = $this->repeatsNoName($raw, $element);
        }

        if (!$safe) {
            $copy = $this->newStream(new StringSource($raw, $filePath));
            try {
                $copyFirst = $this->readNonWhitespaceChar($copy);
                if ($copyFirst !== null) {
                    yield from $this->walkKeyPath($copy, $copyFirst, $segments, $depth, $filePath, $matched);
                }
            } finally {
                $copy->close();
            }

            return;
        }

        $found = [];
        $this->followDecoded($element, $segments, $depth, $found, $matched);
        foreach ($found as $value) {
            yield $value;
        }
    }

    /**
     * Whether json_decode() kept every member of $raw — no object in it names one twice, however the
     * name is spelled. The members in the bytes are counted as the colons left once every string is cut
     * out, by one PCRE pass in C; the members kept are counted in the decoded value. A repeated name is
     * the one way for the two to differ.
     */
    private function repeatsNoName(string $raw, mixed $decoded): bool
    {
        $stripped = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"/', '', $raw);
        if (!is_string($stripped)) {
            return false;
        }

        return substr_count($stripped, ':') === $this->memberCount($decoded);
    }

    /**
     * Every member of every object in a value decoded with objects as stdClass.
     */
    private function memberCount(mixed $value): int
    {
        $count = 0;
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            $count = count($value);
        }

        if (is_array($value)) {
            foreach ($value as $child) {
                if (is_array($child) || $child instanceof stdClass) {
                    $count += $this->memberCount($child);
                }
            }
        }

        return $count;
    }

    /**
     * walkKeyPath(), over a value already decoded with objects as stdClass — so a list and an object
     * stay told apart, as they are in the stream.
     *
     * @param array<int, string> $segments
     * @param array<int, mixed> $found
     */
    private function followDecoded(mixed $node, array $segments, int $depth, array &$found, bool &$matched): void
    {
        if ($depth >= count($segments)) {
            $matched = true;
            if (is_array($node) && $this->walksLeafList($segments)) {
                foreach ($node as $item) {
                    $found[] = $this->asConfigured($item);
                }

                return;
            }

            $found[] = $this->asConfigured($node);

            return;
        }

        $segment = $segments[$depth];
        if ($segment === '*') {
            if (is_array($node) || $node instanceof stdClass) {
                foreach (is_array($node) ? $node : get_object_vars($node) as $child) {
                    $this->followDecoded($child, $segments, $depth + 1, $found, $matched);
                }
            }

            return;
        }

        if ($node instanceof stdClass) {
            if (property_exists($node, $segment)) {
                $this->followDecoded($node->{$segment}, $segments, $depth + 1, $found, $matched);
            }

            return;
        }

        if (is_array($node) && ctype_digit($segment) && array_key_exists((int)$segment, $node)) {
            $this->followDecoded($node[(int)$segment], $segments, $depth + 1, $found, $matched);
        }
    }

    /**
     * A value decoded with objects as stdClass, in the shape the reader was asked for.
     */
    private function asConfigured(mixed $value): mixed
    {
        if (!$this->associative) {
            return $value;
        }

        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_array($child) || $child instanceof stdClass) {
                    $value[$key] = $this->asConfigured($child);
                }
            }
        }

        return $value;
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
     * @return Generator<int|string, mixed>
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
                $this->invalidJsonMessage($filePath, 'unexpected end of input in object.', $stream),
            );
        }

        $stream->pushBack($next);

        while (true) {
            $keyFirst = $this->readNonWhitespaceChar($stream);
            if ($keyFirst !== '"') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'object key must be a string.', $stream),
                );
            }

            $decodedKey = $this->keyFromToken($this->readStringToken($stream, $filePath), $filePath, $stream);

            $separator = $this->readNonWhitespaceChar($stream);
            if ($separator !== ':') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected ":" after object key.', $stream),
                );
            }

            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in object value.', $stream),
                );
            }

            if ($decodedKey === $segment) {
                yield from $this->walkKeyPath($stream, $valueFirst, $segments, $depth + 1, $filePath, $matched);

                // The member is found. Whatever follows it — a repeated name included — is skipped in
                // ONE call, at the speed a skipped branch goes, not a key and three calls per member.
                $delimiter = $this->readNonWhitespaceChar($stream);
                if ($delimiter === '}') {
                    return;
                }

                if ($delimiter !== ',') {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'expected "," or "}" in object.', $stream),
                    );
                }

                $nextKey = $this->readNonWhitespaceChar($stream);
                $stream->absorbPushBack();
                $this->skipValue($stream, $this->skipObjectKey($stream, $nextKey, $filePath), $filePath, ['{']);

                return;
            }

            $this->skipValue($stream, $valueFirst, $filePath);

            $delimiter = $this->readNonWhitespaceChar($stream);
            if ($delimiter === '}') {
                return;
            }

            if ($delimiter !== ',') {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'expected "," or "}" in object.', $stream),
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
     * @return Generator<int|string, mixed>
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
                $this->invalidJsonMessage($filePath, 'unexpected end of input in array.', $stream),
            );
        }

        $stream->pushBack($next);
        $index = 0;

        while (true) {
            $valueFirst = $this->readNonWhitespaceChar($stream);
            if ($valueFirst === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.', $stream),
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
                    $this->invalidJsonMessage($filePath, 'expected "," or "]" in array.', $stream),
                );
            }

            $index++;
        }
    }

    /**
     * An object key, from its raw token, for comparing against a path segment.
     *
     * A key with no backslash in it is exactly the bytes between its quotes, so it needs no
     * json_decode() — and a path walking a hundred thousand objects compares half a million keys.
     * One with an escape goes through json_decode(), which also refuses a broken escape.
     *
     * @throws InvalidArgumentException
     */
    private function keyFromToken(string $token, string|null $filePath, JsonStream $stream): string
    {
        if (!str_contains($token, '\\')) {
            return substr($token, 1, -1);
        }

        try {
            return (string)json_decode($token, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, $exception->getMessage(), $stream),
                0,
                $exception,
            );
        }
    }

    /**
     * An object or a list the stream has just entered, found and decoded without the PHP scanner —
     * when the block in hand makes that possible. True with $value set; false with the stream
     * untouched, for the scanner to read it the usual way.
     *
     * The scanner stops at every quote and bracket of an element, in PHP; json_decode() then reads
     * the same bytes again, in C. Here C does both: strpos() finds where the element probably ends —
     * the next `},` (or `],`) — substr_count() rules out a place where the brackets do not balance,
     * and json_decode() decides. Its answer settles it: an object or a list is complete at exactly one
     * closing bracket, so a stretch from the opening one that decodes IS the element, whatever sits
     * inside its strings. A stretch that does not decode proves nothing either way, and the scanner
     * takes over — so a broken element is refused by the same code, with the same message, as ever.
     */
    private function decodeContainerFast(
        JsonStream $stream,
        string $valueFirst,
        mixed &$value,
        bool|null $associative = null,
        string|null &$raw = null,
        int $maxDepth = 512,
    ): bool {
        if (($valueFirst !== '{' && $valueFirst !== '[') || $stream->charBuffer !== []) {
            return false;
        }

        $start = $stream->bufPos - 1;
        if ($start < 0 || $stream->buf[$start] !== $valueFirst || $stream->fastMisses >= 8) {
            return false;
        }

        if ($this->decodesFast($stream, $valueFirst, $start, $value, $associative, $raw, $maxDepth)) {
            $stream->fastMisses = 0;

            return true;
        }

        // A run of misses is a stretch the guess cannot settle — brackets inside strings, say, that
        // never balance — and each would count to the end of the block: stop trying until the next.
        $stream->fastMisses++;

        return false;
    }

    /**
     * The guess and the check behind decodeContainerFast().
     */
    private function decodesFast(
        JsonStream $stream,
        string $valueFirst,
        int $start,
        mixed &$value,
        bool|null $associative,
        string|null &$raw,
        int $maxDepth,
    ): bool {
        $close = $valueFirst === '{' ? '}' : ']';
        $at = $this->nextHit($stream, $close . ',');

        // Each `},` passed is counted up to, from the last one: an element full of nested objects has
        // one at every object inside it, and the brackets balance only at its own end. Looking at a
        // fixed few and giving up sent every such element to the scanner, three times slower.
        $counted = $start;
        $depth = 0;

        while ($at < $stream->bufLen) {
            $span = $at + 1 - $counted;
            $depth += substr_count($stream->buf, $valueFirst, $counted, $span) - substr_count($stream->buf, $close, $counted, $span);
            $counted = $at + 1;
            if ($depth < 0) {
                // More closed than opened: a bracket inside a string. No later `},` can balance it.
                return false;
            }

            if ($depth === 0) {
                $length = $at + 1 - $start;
                if ($this->maxElementBytes !== null && $length > $this->maxElementBytes) {
                    // Left to the scanner, which refuses it at the limit with the usual message.
                    return false;
                }

                $raw = substr($stream->buf, $start, $length);
                try {
                    $value = json_decode($raw, $associative ?? $this->associative, max(1, $maxDepth), $this->decodeFlags);
                } catch (JsonException) {
                    return false;
                }

                $stream->bufPos = $at + 1;

                return true;
            }

            $found = strpos($stream->buf, $close . ',', $at + 1);
            $at = $found === false ? $stream->bufLen : $found;
        }

        return false;
    }

    /**
     * Several elements of a list at once, starting with the one the stream has just entered: every
     * whole element from here to the last `},` (or `],`) within RUN_BYTES, decoded by ONE json_decode()
     * of the run in brackets — so the PHP work per element, which costs four times the decode, is paid
     * once per run instead. Null, with the stream untouched, when no run of two or more can be had;
     * the elements are then read one at a time.
     *
     * Why a run that decodes is right: a cut inside a string leaves an odd number of quotes, which
     * never decodes, so a run that does ends between elements, where the document's own commas are —
     * its elements are the document's. A cut inside an element leaves its brackets unbalanced, which is
     * checked first, cheaply, and moves the cut back to an earlier `},`. A balanced run that still does
     * not decode holds a broken element, and is left to the one-at-a-time read, which refuses that
     * element — or hands it to `onInvalidItem` — exactly as it always has.
     *
     * @return array<int, mixed>|null
     */
    private function decodeRunFast(
        JsonStream $stream,
        string $valueFirst,
        bool|null $associative = null,
        string|null &$raw = null,
        int $maxDepth = 512,
    ): array|null {
        if (($valueFirst !== '{' && $valueFirst !== '[') || $stream->charBuffer !== []) {
            return null;
        }

        $start = $stream->bufPos - 1;
        if ($start < 0 || $stream->buf[$start] !== $valueFirst || $stream->fastMisses >= 8) {
            return null;
        }

        // Under a limit smaller than a run, one element of a run could pass it unseen.
        if ($this->maxElementBytes !== null && $this->maxElementBytes < self::RUN_BYTES) {
            return null;
        }

        $values = $this->decodesRun($stream, $valueFirst, $start, $associative ?? $this->associative, $raw, $maxDepth);
        if ($values !== null) {
            $stream->fastMisses = 0;

            return $values;
        }

        // Brackets inside strings, say, keep every cut unbalanced; each try counts over the whole run
        // again. A run of misses stops the trying until the next block — the elements are read one at
        // a time meanwhile, as they would be anyway.
        $stream->fastMisses++;

        return null;
    }

    /**
     * The cut and the check behind decodeRunFast().
     *
     * @return array<int, mixed>|null
     */
    private function decodesRun(JsonStream $stream, string $valueFirst, int $start, bool $associative, string|null &$raw, int $maxDepth = 512): array|null
    {
        $close = $valueFirst === '{' ? '}' : ']';
        $end = min($stream->bufLen, $start + self::RUN_BYTES);
        // With a negative offset strrpos() finds the last match STARTING at or before bufLen + offset,
        // so the `}` must start two bytes before $end for its `,` to fall inside the run's window.
        $searchFrom = $end - 2 - $stream->bufLen;

        for ($try = 0; $try < 8; $try++) {
            // The last `},` that ends at or before $end, searched backwards in the buffer itself.
            $found = $searchFrom < -$stream->bufLen ? false : strrpos($stream->buf, $close . ',', $searchFrom);
            if ($found === false || $found <= $start) {
                return null;
            }

            $length = $found + 1 - $start;
            $searchFrom = $found - 1 - $stream->bufLen;
            if (
                substr_count($stream->buf, '{', $start, $length) !== substr_count($stream->buf, '}', $start, $length)
                || substr_count($stream->buf, '[', $start, $length) !== substr_count($stream->buf, ']', $start, $length)
            ) {
                continue;
            }

            try {
                $raw = substr($stream->buf, $start, $length);
                $values = json_decode('[' . $raw . ']', $associative, max(1, $maxDepth), $this->decodeFlags);
            } catch (JsonException) {
                return null;
            }

            if (!is_array($values) || count($values) < 2) {
                return null;
            }

            $stream->bufPos = $start + $length;

            return $values;
        }

        return null;
    }

    /**
     * decodeRunFast() for an object's members: from the key the stream has just entered to the last
     * `},` within RUN_BYTES, every whole `"key": value` pair decoded by ONE json_decode() of the run in
     * braces. Null, with the stream untouched, when no run of two or more members can be had.
     *
     * The run's members are the document's for the same reason a list run's elements are. One thing
     * differs: json_decode() keeps one value for a repeated key, where the stream hands over each. So
     * the members are counted in the run's bytes too — strings and nested containers cut out, the
     * commas left — and a run that lost one to a repeated key is read a member at a time instead.
     *
     * @return array<int|string, mixed>|null
     */
    private function decodeMemberRunFast(
        JsonStream $stream,
        bool|null $associative = null,
        string|null &$raw = null,
        int $maxDepth = 512,
    ): array|null {
        if ($stream->charBuffer !== [] || $stream->fastMisses >= 8) {
            return null;
        }

        if ($this->maxElementBytes !== null && $this->maxElementBytes < self::RUN_BYTES) {
            return null;
        }

        $start = $stream->bufPos - 1;
        if ($start < 0 || $stream->buf[$start] !== '"') {
            return null;
        }

        $members = $this->decodesMemberRun($stream, $start, $associative ?? $this->associative, $raw, $maxDepth);
        if ($members !== null) {
            $stream->fastMisses = 0;

            return $members;
        }

        $stream->fastMisses++;

        return null;
    }

    /**
     * The cut and the checks behind decodeMemberRunFast().
     *
     * @return array<int|string, mixed>|null
     */
    private function decodesMemberRun(JsonStream $stream, int $start, bool $associative, string|null &$raw, int $maxDepth = 512): array|null
    {
        $end = min($stream->bufLen, $start + self::RUN_BYTES);
        $searchFrom = $end - 2 - $stream->bufLen;

        for ($try = 0; $try < 8; $try++) {
            $found = $searchFrom < -$stream->bufLen ? false : strrpos($stream->buf, '},', $searchFrom);
            if ($found === false || $found <= $start) {
                return null;
            }

            $length = $found + 1 - $start;
            $searchFrom = $found - 1 - $stream->bufLen;
            if (
                substr_count($stream->buf, '{', $start, $length) !== substr_count($stream->buf, '}', $start, $length)
                || substr_count($stream->buf, '[', $start, $length) !== substr_count($stream->buf, ']', $start, $length)
            ) {
                continue;
            }

            $raw = substr($stream->buf, $start, $length);
            try {
                $decoded = json_decode('{' . $raw . '}', $associative, max(1, $maxDepth), $this->decodeFlags);
            } catch (JsonException) {
                return null;
            }

            $members = $decoded instanceof stdClass ? get_object_vars($decoded) : $decoded;
            if (!is_array($members) || count($members) < 2 || $this->topLevelMemberCount($raw) !== count($members)) {
                return null;
            }

            $stream->bufPos = $start + $length;

            return $members;
        }

        return null;
    }

    /**
     * How many `"key": value` pairs a run of an object's members holds, counted in its bytes: every
     * string cut out, then every nested container, by PCRE in C — what is left is the run's own commas.
     * -1 when PCRE gives up, which no count matches.
     */
    private function topLevelMemberCount(string $raw): int
    {
        $flat = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"/', '', $raw);
        if (!is_string($flat)) {
            return -1;
        }

        $flat = preg_replace('/\{(?:[^{}\[\]]++|(?R))*+\}|\[(?:[^{}\[\]]++|(?R))*+\]/', '', $flat);

        return is_string($flat) ? substr_count($flat, ',') + 1 : -1;
    }

    /**
     * A container the stream has just entered, skipped without the PHP scanner when the block in hand
     * makes that possible — decodeContainerFast() with json_validate() in place of json_decode(): the
     * same guess at the end, settled without building a value. False, with the stream untouched, when
     * it cannot be: before PHP 8.3, which has no json_validate(), or when the guess does not validate
     * — then the scanner skips it, and refuses it if it is broken, as ever.
     */
    private function skipContainerFast(JsonStream $stream, string $firstChar): bool
    {
        if (($firstChar !== '{' && $firstChar !== '[') || $stream->charBuffer !== [] || $stream->fastMisses >= 8 || !function_exists('json_validate')) {
            return false;
        }

        if ($this->skipsFast($stream, $firstChar)) {
            $stream->fastMisses = 0;

            return true;
        }

        $stream->fastMisses++;

        return false;
    }

    /**
     * The guess and the check behind skipContainerFast().
     */
    private function skipsFast(JsonStream $stream, string $firstChar): bool
    {
        $start = $stream->bufPos - 1;
        if ($start < 0 || $stream->buf[$start] !== $firstChar) {
            return false;
        }

        $close = $firstChar === '{' ? '}' : ']';
        $at = $this->nextHit($stream, $close . ',');

        for ($try = 0; $try < 4 && $at < $stream->bufLen; $try++) {
            $length = $at + 1 - $start;
            if (substr_count($stream->buf, $firstChar, $start, $length) === substr_count($stream->buf, $close, $start, $length)) {
                if (!json_validate(substr($stream->buf, $start, $length))) {
                    return false;
                }

                $stream->bufPos = $at + 1;

                return true;
            }

            $found = strpos($stream->buf, $close . ',', $at + 1);
            $at = $found === false ? $stream->bufLen : $found;
        }

        return false;
    }

    /**
     * An element json_decode() refused: handed to `onInvalidItem` when there is one, and refused
     * with the byte it starts at when there is not.
     *
     * @throws InvalidArgumentException
     */
    private function handOverInvalidItem(
        JsonException $exception,
        string $rawValue,
        int $valueOffset,
        int|string|null $key,
        string $filePath,
    ): void {
        if ($this->onInvalidItem === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessageAt($filePath, $exception->getMessage(), $valueOffset),
                0,
                $exception,
            );
        }

        ($this->onInvalidItem)(new InvalidItem($rawValue, $exception->getMessage(), $valueOffset, $key));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function decodeScannedValue(string $rawValue, string $filePath, JsonStream|null $stream = null): mixed
    {
        try {
            return json_decode($rawValue, $this->associative, 512, $this->decodeFlags);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, $exception->getMessage(), $stream),
                0,
                $exception,
            );
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function readStringToken(JsonStream $stream, string|null $filePath): string
    {
        // With nothing pushed back, the rest of the string is in the block buffer, where strcspn() walks
        // it at C level. A character at a time cost a method call per byte — on every key of every
        // object a path walks past.
        if ($stream->charBuffer === []) {
            return '"' . $this->scanStringRemainder($stream, $filePath ?? '');
        }

        $token = '"';
        $escaped = false;

        while (true) {
            $char = $this->readChar($stream);
            if ($char === null) {
                throw new InvalidArgumentException(
                    $this->invalidJsonMessage($filePath, 'unexpected end of input in string.', $stream),
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

    /**
     * Consumes one value without keeping it — a branch the key path does not go down.
     *
     * Iterative on purpose, with the open containers on a stack of its own. It used to call itself
     * once per nesting level, so a skipped branch of twenty thousand `[` — forty kilobytes — ran the
     * PHP process out of C stack and killed it with a segmentation fault: no exception, no message, and
     * whoever controls the document controls when that happens.
     *
     * @param array<int, string> $open containers already entered around this value: `['{']` skips the
     *                                 value and then the rest of the object it sits in, in one call
     */
    private function skipValue(JsonStream $stream, string $firstChar, string|null $filePath, array $open = []): void
    {
        // Everything below reads the block buffer directly — `strspn()` over whitespace, `strcspn()`
        // over a string or a number — instead of a method call per character: a skipped branch is
        // most of the document a path read goes through, and the calls were most of its time.
        $stream->absorbPushBack();

        // Where a container skipped here started, so that one too big for the block can grow it.
        $started = $open === [] && ($firstChar === '{' || $firstChar === '[') ? $stream->offset() - 1 : null;

        // $open holds the containers entered and not yet closed, `{` or `[`.
        $char = $firstChar;

        while (true) {
            if ($char === '{' || $char === '[') {
                // A container that ends within the block is skipped whole, in C, when it validates;
                // only one that does not is walked here. Each element of a long skipped list gets the
                // chance, so a list too big for one block still goes at the speed of its elements.
                if (!$this->skipContainerFast($stream, $char)) {
                    $stream->bufPos += strspn($stream->buf, " \t\n\r", $stream->bufPos);
                    $next = $stream->bufPos < $stream->bufLen ? $stream->buf[$stream->bufPos++] : $this->readNonWhitespaceChar($stream);
                    if ($next === null) {
                        throw new InvalidArgumentException($this->invalidJsonMessage(
                            $filePath,
                            $char === '{' ? 'unexpected end of input in object.' : 'unexpected end of input in array.',
                        ));
                    }

                    if ($next !== ($char === '{' ? '}' : ']')) {
                        $open[] = $char;
                        $char = $char === '{' ? $this->skipObjectKey($stream, $next, $filePath) : $next;

                        continue;
                    }
                }
            } elseif ($char === '"') {
                // A string with no escape that ends inside this block — nearly all of them — is one
                // strcspn(); anything else takes the general path.
                $quote = $stream->bufPos + strcspn($stream->buf, '\\"', $stream->bufPos);
                if ($quote < $stream->bufLen && $stream->buf[$quote] === '"') {
                    $stream->bufPos = $quote + 1;
                } else {
                    $this->skipStringRemainder($stream, $filePath);
                }
            } else {
                $stream->bufPos += strcspn($stream->buf, ",]} \t\n\r\v\f", $stream->bufPos);
                if ($stream->bufPos >= $stream->bufLen) {
                    $this->skipPrimitive($stream, $char);
                }
            }

            // A value is finished: close every container that ends right after it, and stop at the
            // first one that carries on.
            while (true) {
                if ($open === []) {
                    if ($started !== null) {
                        $stream->fitElement($stream->offset() - $started);
                    }

                    return;
                }

                $container = $open[count($open) - 1];
                $stream->bufPos += strspn($stream->buf, " \t\n\r", $stream->bufPos);
                $delimiter = $stream->bufPos < $stream->bufLen ? $stream->buf[$stream->bufPos++] : $this->readNonWhitespaceChar($stream);
                if ($delimiter === ($container === '{' ? '}' : ']')) {
                    array_pop($open);

                    continue;
                }

                if ($delimiter !== ',') {
                    throw new InvalidArgumentException($this->invalidJsonMessage(
                        $filePath,
                        $container === '{' ? 'expected "," or "}" in object.' : 'expected "," or "]" in array.',
                    ));
                }

                $stream->bufPos += strspn($stream->buf, " \t\n\r", $stream->bufPos);
                $next = $stream->bufPos < $stream->bufLen ? $stream->buf[$stream->bufPos++] : $this->readNonWhitespaceChar($stream);
                if ($container === '{') {
                    $char = $this->skipObjectKey($stream, $next, $filePath);
                } elseif ($next === null) {
                    throw new InvalidArgumentException(
                        $this->invalidJsonMessage($filePath, 'unexpected end of input in array value.'),
                    );
                } else {
                    $char = $next;
                }

                break;
            }
        }
    }

    /**
     * An object member's `"key":`, read and dropped; returns the first character of its value.
     */
    private function skipObjectKey(JsonStream $stream, string|null $keyFirst, string|null $filePath): string
    {
        if ($keyFirst !== '"') {
            throw new InvalidArgumentException($this->invalidJsonMessage($filePath, 'object key must be a string.'));
        }

        $quote = $stream->bufPos + strcspn($stream->buf, '\\"', $stream->bufPos);
        if ($quote < $stream->bufLen && $stream->buf[$quote] === '"') {
            $stream->bufPos = $quote + 1;
        } else {
            $this->skipStringRemainder($stream, $filePath);
        }

        $stream->bufPos += strspn($stream->buf, " \t\n\r", $stream->bufPos);
        $separator = $stream->bufPos < $stream->bufLen ? $stream->buf[$stream->bufPos++] : $this->readNonWhitespaceChar($stream);
        if ($separator !== ':') {
            throw new InvalidArgumentException($this->invalidJsonMessage($filePath, 'expected ":" after object key.'));
        }

        $stream->bufPos += strspn($stream->buf, " \t\n\r", $stream->bufPos);
        $valueFirst = $stream->bufPos < $stream->bufLen ? $stream->buf[$stream->bufPos++] : $this->readNonWhitespaceChar($stream);
        if ($valueFirst === null) {
            throw new InvalidArgumentException(
                $this->invalidJsonMessage($filePath, 'unexpected end of input in object value.'),
            );
        }

        return $valueFirst;
    }

    /**
     * The rest of a string the skip does not keep, from the byte after its opening `"`: the buffer is
     * walked with strcspn() and nothing is copied. A skipped branch is most of what a path read sees, so
     * this is where its time goes.
     */
    private function skipStringRemainder(JsonStream $stream, string|null $filePath): void
    {
        if ($stream->charBuffer !== []) {
            $this->readStringToken($stream, $filePath);

            return;
        }

        while (true) {
            if ($stream->bufPos >= $stream->bufLen) {
                $this->refillBuffer($stream, $filePath, 'unexpected end of input in string.');
            }

            $special = $stream->bufPos + strcspn($stream->buf, '\\"', $stream->bufPos);
            if ($special >= $stream->bufLen) {
                $stream->bufPos = $stream->bufLen;

                continue;
            }

            $stream->bufPos = $special + 1;
            if ($stream->buf[$special] === '"') {
                return;
            }

            // A backslash: the byte after it is escaped, whatever it is — `\"` does not end the string.
            if ($stream->bufPos >= $stream->bufLen) {
                $this->refillBuffer($stream, $filePath, 'unexpected end of input in string.');
            }
            $stream->bufPos++;
        }
    }

    /**
     * A number, `true`, `false` or `null` the skip does not keep, up to the delimiter after it, which
     * is left unread. The delimiters are readPrimitiveToken()'s: JSON's own and the rest of ctype_space().
     */
    private function skipPrimitive(JsonStream $stream, string $firstChar): void
    {
        if ($stream->charBuffer !== []) {
            $this->readPrimitiveToken($stream, $firstChar);

            return;
        }

        while (true) {
            if ($stream->bufPos >= $stream->bufLen) {
                $chunk = $stream->readBlock();
                if ($chunk === null) {
                    return;
                }
                $stream->adopt($chunk);
            }

            $stream->bufPos += strcspn($stream->buf, ",]} \t\n\r\v\f", $stream->bufPos);
            if ($stream->bufPos < $stream->bufLen) {
                return;
            }
        }
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

                $stream->adopt($chunk);
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

        $stream->adopt($chunk, 1);

        return $stream->buf[0];
    }

    private function isJsonDelimiter(string $char): bool
    {
        return ctype_space($char) || $char === ',' || $char === ']' || $char === '}';
    }

    /**
     * The same complaint, positioned at an offset the caller remembered rather than wherever the
     * stream happens to be now.
     */
    private function invalidJsonMessageAt(string|null $filePath, string $detail, int $offset): string
    {
        if ($filePath === null || $filePath === '') {
            return sprintf('Invalid JSON in file at byte %d: %s', $offset, $detail);
        }

        return sprintf('Invalid JSON in file "%s" at byte %d: %s', $filePath, $offset, $detail);
    }

    private function invalidJsonMessage(
        string|null $filePath,
        string $detail,
        JsonStream|null $stream = null,
    ): string {
        // WHERE, whenever there is a stream to ask. `Syntax error` on a 200 MB export is an invitation
        // to search by hand, and the reader has always known the answer.
        $where = $stream === null ? '' : sprintf(' at byte %d', $stream->offset());

        if ($filePath === null || $filePath === '') {
            return sprintf('Invalid JSON in file%s: %s', $where, $detail);
        }

        return sprintf('Invalid JSON in file "%s"%s: %s', $filePath, $where, $detail);
    }

    /**
     * @throws RuntimeException
     */
    private function prepareTempChunkDirectory(string $tempChunkDir): string
    {
        // The directory is made, written to and read back: through a wrapper — `phar://` above all —
        // that is a filesystem operation the caller never meant to hand to one.
        if ($this->isStreamWrapper($tempChunkDir)) {
            throw new InvalidArgumentException(sprintf(
                'Temporary chunk directory "%s" is a stream wrapper; it must be a filesystem path.',
                $tempChunkDir,
            ));
        }

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
     * @param array<int, array{0: int|string, 1: mixed}> $pairs
     *
     * @throws RuntimeException
     */
    private function writeTemporaryChunk(string $tempChunkDir, array $pairs): string
    {
        $tempFilePath = tempnam($tempChunkDir, 'json_chunk_');
        if ($tempFilePath === false) {
            throw new RuntimeException(sprintf('Unable to create temporary chunk file in "%s".', $tempChunkDir));
        }

        // Written as `[key, value]` pairs: a JSON object would turn an object's numeric names into a
        // list's indices on the way back and lose their order, and a plain list would lose the names
        // outright — which is what made `tempChunkDir` refuse every object. JSON_PRESERVE_ZERO_FRACTION
        // because `3.0` written as `3` comes back an int, and the read must not depend on the detour.
        try {
            $encodedChunk = json_encode($pairs, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, self::TEMP_CHUNK_DEPTH);
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
     * @return array<int, array{0: int|string, 1: mixed}> the `[key, value]` pairs, keys as written
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
            // The same flag the items were produced with: a chunk written from `stdClass` items and read
        // back as arrays would make `tempChunkDir` quietly change what a read returns.
        $decodedChunk = json_decode($content, $this->associative, self::TEMP_CHUNK_DEPTH, $this->decodeFlags);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Invalid temporary chunk file "%s".', $chunkFile), 0, $exception);
        }

        if (!is_array($decodedChunk) || !array_is_list($decodedChunk)) {
            throw new RuntimeException(
                sprintf('Temporary chunk file "%s" must contain a JSON array list.', $chunkFile),
            );
        }

        $pairs = [];
        foreach ($decodedChunk as $pair) {
            if (
                !is_array($pair)
                || count($pair) !== 2
                || !array_key_exists(0, $pair)
                || !array_key_exists(1, $pair)
                || !(is_int($pair[0]) || is_string($pair[0]))
            ) {
                throw new RuntimeException(
                    sprintf('Temporary chunk file "%s" must contain [key, value] pairs.', $chunkFile),
                );
            }
            $pairs[] = [$pair[0], $pair[1]];
        }

        return $pairs;
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
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function getFirst(string|JsonSourceInterface $filePath, string|array|null $keyPath = null): mixed
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

        throw new RuntimeException(sprintf('Target array in "%s" is empty.', $this->describeSource($filePath)));
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function getLast(string|JsonSourceInterface $filePath, string|array|null $keyPath = null): mixed
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
            throw new RuntimeException(sprintf('Target array in "%s" is empty.', $this->describeSource($filePath)));
        }

        return $last;
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     */
    public function getNth(string|JsonSourceInterface $filePath, int $index, string|array|null $keyPath = null): mixed
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

        throw new RuntimeException(sprintf('Index %d not found in target array of "%s".', $index, $this->describeSource($filePath)));
    }

    /**
     * The one value a path names — a scalar, an object or a list, decoded whole — read up to that
     * value and no further. What follows it is never read, so it is never checked either: this is
     * a lookup, not a validation of the document.
     *
     * @param string|array<int, string> $keyPath a dotted path, or its segments taken literally
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getValue(string|JsonSourceInterface $filePath, string|array $keyPath): mixed
    {
        if ($keyPath === '' || $keyPath === []) {
            throw new InvalidArgumentException('A key path is required: getValue() reads the value a path names.');
        }

        $segments = $this->splitAndValidateKeyPath($keyPath);
        if (in_array('*', $segments, true) || in_array('**', $segments, true)) {
            throw new InvalidArgumentException(sprintf(
                'Key path "%s" has a wildcard and names many values; read them with readGenerator().',
                $this->describeKeyPath($keyPath),
            ));
        }

        $stream = $this->openFile($filePath);
        $sourceName = $this->describeSource($filePath);

        try {
            $first = $this->readNonWhitespaceChar($stream);
            if ($first === null) {
                throw new InvalidArgumentException($this->invalidJsonMessage(null, 'empty content.', $stream));
            }

            $this->assertNoByteOrderMark($first);

            $valueFirst = $this->seekPath($stream, $first, $segments, 0, $keyPath);

            return $this->decodeScannedValue($this->scanValue($stream, $valueFirst, $sourceName), $sourceName, $stream);
        } finally {
            $stream->close();
        }
    }

    /**
     * Several key paths, read in one pass, yielding `path => value`.
     *
     * Two paths into one document used to mean two reads of it. The key is the path that matched, so
     * a `foreach` reads as "this value, from that path" — generators allow repeated keys, so a path
     * matching many values simply appears many times.
     *
     * @param array<int, string|array<int, string>> $keyPaths
     *
     * @return Generator<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function readPaths(string|JsonSourceInterface $filePath, array $keyPaths): Generator
    {
        return $this->streamManyPaths($filePath, $keyPaths);
    }

    /**
     * @param callable(mixed): mixed $callback returning `false` stops the walk; any other value,
     *                                          including none, carries on
     * @param string|array<int, string>|null $keyPath a dotted path, or its segments taken literally
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function forEach(
        string|JsonSourceInterface $filePath,
        callable $callback,
        string|array|null $keyPath = null,
    ): int {
        $count = 0;

        foreach (
            $this->readGenerator(
                filePath: $filePath,
                keyPath: $keyPath,
            ) as $item
        ) {
            $count++;

            // `false` stops the walk. Anything else — including nothing at all, which is what a
            // callback with no return statement gives — carries on, so a callback written before this
            // existed behaves exactly as it did. Without it the only way out was to throw, which turns
            // an ordinary "I have seen enough" into an exception the caller then has to catch and
            // decide whether it was really an error.
            if ($callback($item) === false) {
                break;
            }
        }

        return $count;
    }
}
