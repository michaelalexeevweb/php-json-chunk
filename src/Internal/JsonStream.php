<?php

declare(strict_types=1);

namespace PhpJsonChunk\Internal;

use PhpJsonChunk\Contract\JsonSourceInterface;

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
 * Where the bytes come from is a `JsonSourceInterface`, so a file, a string and an open stream all
 * read the same way. The reader never seeks — it holds one block and a single pushed-back character —
 * which is what makes a read-once source enough.
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

    /**
     * Bytes of the document that came before the current block.
     *
     * Kept so a complaint can say WHERE. "Syntax error" on a 200 MB file is an invitation to search by
     * hand; the reader knows the answer and used not to say it. One addition per 64 KB block is not a
     * cost worth weighing against that.
     */
    private int $bytesBeforeBuffer = 0;

    /** Null once the read is over, so a closed stream reads as end of file rather than as an error. */
    private JsonSourceInterface|null $source;

    public function __construct(JsonSourceInterface $source)
    {
        $this->source = $source;
    }

    /**
     * The next block, or null at end of file.
     */
    public function readBlock(): string|null
    {
        return $this->source?->readBlock(self::BLOCK_SIZE);
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

        $this->adopt($chunk);

        return true;
    }

    /**
     * Takes a freshly read block as the current buffer, starting at $startPos.
     *
     * The one place the buffer is replaced, so the one place that has to remember how much of the
     * document is already behind it.
     */
    public function adopt(string $chunk, int $startPos = 0): void
    {
        $this->bytesBeforeBuffer += $this->bufLen;
        $this->buf = $chunk;
        $this->bufLen = strlen($chunk);
        $this->bufPos = $startPos;
    }

    /**
     * How many bytes of the document have been consumed — the position a complaint should name.
     *
     * Characters pushed back have been read but not consumed, so they are subtracted: the offset must
     * point at the byte the reader is about to look at, not one past it.
     */
    public function offset(): int
    {
        return $this->bytesBeforeBuffer + $this->bufPos - count($this->charBuffer);
    }

    public function pushBack(string $char): void
    {
        $this->charBuffer[] = $char;
    }

    public function close(): void
    {
        $this->source?->close();
        $this->source = null;

        $this->charBuffer = [];
        $this->buf = '';
        $this->bufPos = 0;
        $this->bufLen = 0;
        $this->bytesBeforeBuffer = 0;
    }
}
