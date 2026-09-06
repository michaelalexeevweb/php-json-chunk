<?php

declare(strict_types=1);

namespace PhpJsonChunk\Internal;

use SplFileObject;

/**
 * One read in progress: the open file together with the block buffer that belongs to it.
 *
 * These four values used to be fields on the reader itself, which quietly made a reader usable for
 * exactly one read at a time. A generator is lazy, so two generators taken from the SAME reader —
 * the shape a container hands out, the class being final and taking no constructor arguments —
 * shared one buffer. Items came back from the wrong file, and the read then died reporting invalid
 * JSON in a file that was perfectly valid, which sends the reader hunting for a data problem that
 * does not exist.
 *
 * Binding the buffer to the file leaves the reader holding no read state at all, so any number of
 * reads can be in flight at once.
 *
 * `SplFileObject` rather than an `fopen()` handle for one reason: `resource` is not a type PHP can
 * declare — write it and the engine reads it as a class name and warns — so a handle can only travel
 * untyped, with a docblock asking to be believed. An object can be declared, and reading a block
 * through it costs the same: over a 20 MB file both forms take 10-15 ms, against the ~500 ms the
 * parsing above it takes.
 *
 * The buffer fields are public on purpose: the scanners walk them with `strcspn()`/`strspn()` at C
 * level, and putting an accessor call in that loop is exactly the cost this library exists to avoid.
 *
 * @internal
 */
final class JsonStream
{
    /** Size of each read block in bytes. */
    public const BLOCK_SIZE = 65536;

    /**
     * One-char look-ahead stack.
     *
     * @var array<int, string>
     */
    public array $charBuffer = [];

    /** Current read block content. */
    public string $buf = '';

    /** Next unread byte position inside $buf. */
    public int $bufPos = 0;

    /** Number of valid bytes in $buf. */
    public int $bufLen = 0;

    /** Null once the read is over, so a closed stream reads as end of file rather than as an error. */
    private SplFileObject|null $file;

    public function __construct(SplFileObject $file)
    {
        $this->file = $file;
    }

    /**
     * The next block, or null at end of file.
     */
    public function readBlock(): string|null
    {
        if ($this->file === null || $this->file->eof()) {
            return null;
        }

        $chunk = $this->file->fread(self::BLOCK_SIZE);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        return $chunk;
    }

    /**
     * Reads the next block straight into the buffer. Returns false at end of file, so the caller
     * decides whether that is the end of a value or a truncated document.
     */
    public function fillBuffer(): bool
    {
        $chunk = $this->readBlock();

        if ($chunk === null) {
            return false;
        }

        $this->buf = $chunk;
        $this->bufLen = strlen($chunk);
        $this->bufPos = 0;

        return true;
    }

    public function pushBack(string $char): void
    {
        $this->charBuffer[] = $char;
    }

    public function close(): void
    {
        // SplFileObject closes the file when the last reference to it goes.
        $this->file = null;

        $this->charBuffer = [];
        $this->buf = '';
        $this->bufPos = 0;
        $this->bufLen = 0;
    }
}
