<?php

declare(strict_types=1);

namespace PhpJsonChunk;

use InvalidArgumentException;
use JsonException;
use PhpJsonChunk\Contract\JsonChunkWriterInterface;
use RuntimeException;
use Throwable;

/**
 * Writes JSON from an iterable, an entry at a time — a list, an object, a sequence of documents — so
 * that what is written can be as large as the disk, not the memory. The other half of the reader: a
 * file read with readGenerator() or readRaw(), filtered, written back, never whole in memory.
 *
 * What it writes is what json_encode() of the whole would give, byte for byte — pretty-printed too —
 * with the flags given here; but writeArray() writes a list whatever they are, JSON_FORCE_OBJECT
 * applying inside each entry. A path that is a link is written through: the file it names is replaced,
 * and keeps its permissions. An entry json_encode() refuses — INF, a string that is not UTF-8 — throws
 * a JsonException that names it. To a path, it writes to a temporary file beside it and renames that into
 * place once all is written: a failure on the way — an entry that cannot be encoded, an exception from
 * the iterable — leaves the path as it was. To a stream — `php://output`, a socket,
 * `compress.zlib://` for gzip — it writes as it goes, and leaves the stream open.
 */
final class JsonChunkWriter implements JsonChunkWriterInterface
{
    /** Bytes gathered before they are written: one write a block, not one an entry. */
    private const BUFFER_BYTES = 65536;

    /** Entries encoded by one json_encode(): the call costs more than the encoding of a small one. */
    private const BATCH = 256;

    private readonly int $flags;

    /**
     * @param int $flags json_encode()'s flags — JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES,
     *                   JSON_PRETTY_PRINT, … ; JSON_THROW_ON_ERROR is always added
     */
    public function __construct(int $flags = 0)
    {
        $this->flags = $flags | JSON_THROW_ON_ERROR;
    }

    /**
     * A JSON list of the entries, in order; keys are not written. With $raw, each entry is a string of
     * JSON — what readRaw() gives — written as it is, not encoded and not checked.
     *
     * @param resource|string $target a path, or an open stream
     * @param iterable<mixed> $entries
     *
     * @return int how many entries were written
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function writeArray(mixed $target, iterable $entries, bool $raw = false): int
    {
        return $this->write($target, function ($out) use ($entries, $raw): int {
            return $this->container($out, '[', ']', $entries, $raw, false);
        });
    }

    /**
     * A JSON object of the entries, `key => value`, in order. With $raw, each value is a string of
     * JSON, written as it is.
     *
     * @param resource|string $target a path, or an open stream
     * @param iterable<mixed, mixed> $members
     *
     * @return int how many members were written
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function writeObject(mixed $target, iterable $members, bool $raw = false): int
    {
        return $this->write($target, function ($out) use ($members, $raw): int {
            return $this->container($out, '{', '}', $members, $raw, true);
        });
    }

    /**
     * A sequence of documents, one to a line — JSON Lines, NDJSON — what readValues() reads. Never
     * pretty-printed, whatever the flags: a document of several lines is not one to a line. With $raw,
     * each document is a string of JSON, written as it is.
     *
     * @param resource|string $target a path, or an open stream
     * @param iterable<mixed> $documents
     *
     * @return int how many documents were written
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function writeValues(mixed $target, iterable $documents, bool $raw = false): int
    {
        return $this->write($target, fn($out): int => $this->lines($out, $documents, $raw));
    }

    /**
     * Documents added to the end of a sequence — a log — one to a line, the file locked meanwhile:
     * other processes appending through this method wait, rather than have their lines run into
     * these. A file that does not end in a line break gets one first, so that the first of these
     * starts a line of its own; a file that is not there is made.
     *
     * Not put in place whole, as the other writes are — what is appended to is the file itself: lines
     * are written whole, a block at a time, and an exception from the iterable leaves those written
     * so far. Then extendIndex() brings an index of the file up to it.
     *
     * @param iterable<mixed> $documents
     *
     * @return int how many documents were appended
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function appendValues(string $path, iterable $documents, bool $raw = false): int
    {
        $this->assertPath($path);
        $out = @fopen($path, 'c+b');
        if ($out === false) {
            throw new RuntimeException(sprintf('Unable to open "%s" to append to it.', $path));
        }

        try {
            if (!flock($out, LOCK_EX)) {
                throw new RuntimeException(sprintf('Unable to lock "%s".', $path));
            }

            fseek($out, 0, SEEK_END);
            if ((int)ftell($out) > 0) {
                fseek($out, -1, SEEK_END);
                $lastByte = fread($out, 1);
                fseek($out, 0, SEEK_END);
                if ($lastByte !== "\n") {
                    self::put($out, "\n");
                }
            }

            $count = $this->lines($out, $documents, $raw);
            fflush($out);

            return $count;
        } finally {
            flock($out, LOCK_UN);
            fclose($out);
        }
    }

    /**
     * Documents one to a line, never pretty-printed: a document of several lines is not one to a line.
     * Only whole lines are written, a block at a time.
     *
     * @param resource $out
     * @param iterable<mixed> $documents
     */
    private function lines($out, iterable $documents, bool $raw): int
    {
        $flags = $this->flags & ~JSON_PRETTY_PRINT;
        $buffer = '';
        $count = 0;
        foreach ($documents as $document) {
            $buffer .= ($raw ? self::rawText($document, $count) : $this->encode($document, $flags, $count)) . "\n";
            $count++;
            if (strlen($buffer) >= self::BUFFER_BYTES) {
                self::put($out, $buffer);
                $buffer = '';
            }
        }

        self::put($out, $buffer);

        return $count;
    }

    /**
     * @param resource $out
     * @param iterable<mixed, mixed> $entries
     */
    private function container($out, string $open, string $close, iterable $entries, bool $raw, bool $keyed): int
    {
        $pretty = ($this->flags & JSON_PRETTY_PRINT) !== 0;
        self::put($out, $open);

        $count = 0;
        $batch = [];
        $fed = 0;
        foreach ($entries as $key => $entry) {
            // An object's members as pairs: an array keyed by them would keep one of a key given twice.
            $batch[] = $keyed ? [(string)$key, $entry] : $entry;
            $fed++;
            if ($fed === self::BATCH) {
                $count = $this->writeBatch($out, $batch, $fed, $count, $raw, $keyed, $pretty);
                $batch = [];
                $fed = 0;
            }
        }

        if ($fed > 0) {
            $count = $this->writeBatch($out, $batch, $fed, $count, $raw, $keyed, $pretty);
        }

        self::put($out, ($count > 0 && $pretty ? "\n" : '') . $close);

        return $count;
    }

    /**
     * A batch of entries written: encoded by ONE json_encode(), and the brackets around it dropped —
     * json_encode() lays out the entries inside, pretty-printed too, as they are to stand in the whole.
     * A batch of an object that lost members — the iterable gave a key twice, and the array kept one —
     * or one that json_encode() refuses, is written an entry at a time, the second to name the entry.
     *
     * @param resource $out
     * @param array<int, mixed> $batch entries, or `[key, value]` pairs of an object
     *
     * @return int how many entries are written now
     */
    private function writeBatch($out, array $batch, int $fed, int $count, bool $raw, bool $keyed, bool $pretty): int
    {
        $members = [];
        if ($keyed) {
            foreach ($batch as [$key, $value]) {
                $members[$key] = $value;
            }
        }

        // JSON_FORCE_OBJECT would make a batch of a list an object: a list is written an entry at a
        // time then, the flag applying inside each.
        $listForced = !$keyed && ($this->flags & JSON_FORCE_OBJECT) !== 0;
        if (!$raw && !$listForced && (!$keyed || count($members) === $fed)) {
            try {
                $whole = json_encode($keyed ? (object)$members : $batch, $this->flags);
            } catch (JsonException) {
                $whole = false;
            }

            if ($whole !== false) {
                // "[…]" or "{…}"; pretty-printed, "[\n    …\n]", whose inside ends in its own "\n".
                $inside = $pretty ? substr($whole, 1, -2) : substr($whole, 1, -1);
                self::put($out, ($count === 0 ? '' : ',') . $inside);

                return $count + $fed;
            }
        }

        $keyFlags = $this->flags & ~JSON_PRETTY_PRINT;
        $buffer = '';
        foreach ($batch as $item) {
            [$key, $entry] = $keyed ? $item : [null, $item];
            $text = $raw ? self::rawText($entry, $count) : $this->encode($entry, $this->flags, $count);
            if ($pretty) {
                $text = str_replace("\n", "\n    ", $text);
            }

            $buffer .= ($count === 0 ? '' : ',') . ($pretty ? "\n    " : '')
                . ($keyed ? $this->encode((string)$key, $keyFlags, $count) . ($pretty ? ': ' : ':') : '')
                . $text;
            $count++;
            if (strlen($buffer) >= self::BUFFER_BYTES) {
                self::put($out, $buffer);
                $buffer = '';
            }
        }

        self::put($out, $buffer);

        return $count;
    }

    /**
     * One entry encoded, or refused with which it is.
     */
    private function encode(mixed $value, int $flags, int $entry): string
    {
        try {
            $json = json_encode($value, $flags);
        } catch (JsonException $exception) {
            throw new JsonException(sprintf('Entry %d cannot be written as JSON: %s', $entry, $exception->getMessage()), $exception->getCode(), $exception);
        }

        if ($json === false) {
            throw new JsonException(sprintf('Entry %d cannot be written as JSON.', $entry));
        }

        return $json;
    }

    private static function rawText(mixed $entry, int $number): string
    {
        if (!is_string($entry)) {
            throw new InvalidArgumentException(sprintf('Entry %d is to be written raw, as a string of JSON, and is %s.', $number, get_debug_type($entry)));
        }

        return $entry;
    }

    /**
     * @param resource $out
     *
     * @throws RuntimeException
     */
    private static function put($out, string $bytes): void
    {
        for ($written = 0, $length = strlen($bytes); $written < $length; $written += $chunk) {
            $chunk = fwrite($out, substr($bytes, $written));
            if ($chunk === false || $chunk === 0) {
                throw new RuntimeException('Unable to write: the disk is full, or the stream closed.');
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertPath(string $path): void
    {
        if ($path === '') {
            throw new InvalidArgumentException('A target is a path or an open stream.');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 && !str_starts_with(strtolower($path), 'file://')) {
            throw new InvalidArgumentException(sprintf('Target "%s" is a stream wrapper; open it and pass the stream — a write through a wrapper cannot be put in place whole.', $path));
        }
    }

    /**
     * Writes through $body to the target: to a stream as it is, to a path through a temporary file
     * beside it, renamed into place only once the body has finished.
     *
     * @param resource|string $target
     * @param callable(resource): int $body
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function write(mixed $target, callable $body): int
    {
        if (is_resource($target)) {
            return $body($target);
        }

        if (!is_string($target)) {
            throw new InvalidArgumentException('A target is a path or an open stream.');
        }

        $this->assertPath($target);

        // Through a link to the file it names: the link stays, as a write through it would leave it.
        if (is_link($target)) {
            $resolved = realpath($target);
            if ($resolved === false) {
                throw new RuntimeException(sprintf('Target "%s" is a link to nothing.', $target));
            }
            $target = $resolved;
        }

        $directory = dirname($target);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException(sprintf('Unable to write into "%s".', $directory));
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $out = @fopen($temporary, 'xb');
        if ($out === false) {
            throw new RuntimeException(sprintf('Unable to create "%s".', $temporary));
        }

        try {
            $count = $body($out);
            if (!fflush($out) || !fclose($out)) {
                throw new RuntimeException(sprintf('Unable to finish writing "%s".', $temporary));
            }
            $out = null;

            // The file it replaces keeps its permissions: a file only its owner could read would
            // otherwise come back readable by all — found in review. Its owner cannot be kept but by
            // root.
            clearstatcache(true, $target);
            if (is_file($target)) {
                $mode = fileperms($target);
                if ($mode !== false) {
                    chmod($temporary, $mode & 07777);
                }
            }

            if (!@rename($temporary, $target)) {
                throw new RuntimeException(sprintf('Unable to put "%s" in place of "%s".', $temporary, $target));
            }

            return $count;
        } catch (Throwable $exception) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($temporary);

            throw $exception;
        }
    }
}
