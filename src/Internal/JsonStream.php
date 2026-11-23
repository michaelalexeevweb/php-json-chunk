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
    /** Size of each read block in bytes, unless the reader is given another. */
    public const BLOCK_SIZE = 65536;

    /** The largest block an adaptive stream grows to. */
    public const MAX_BLOCK_SIZE = 1048576;

    /**
     * How many elements of the size just read an adaptive block is grown to hold. An element is read
     * slowly when it straddles two blocks; with eight to a block, one in eight at most. Two to a block
     * was tried first: elements of 186 KB grew it to 512 KB, and read in 1.10 s against 0.71 s at 1 MB.
     */
    private const ELEMENTS_PER_BLOCK = 8;

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

    /**
     * Where each byte sequence a `**` read looks for next occurs in $buf, at or after a position it was
     * asked from — or $bufLen for nowhere. Looked up once per stretch of the block, not once per
     * container, so the look costs one pass over the block whatever the number of containers in it.
     * Cleared whenever $buf is replaced.
     *
     * @var array<string, int>
     */
    public array $nextHit = [];

    /**
     * Fast skips that did not come off since the last one that did, in this block. A run of them is
     * the sign of a stretch the guess cannot settle — a deep nest, say, where every level would count
     * brackets over the same long span again — and the skip stops trying until the next block.
     */
    public int $fastMisses = 0;

    /**
     * Where a run — several entries decoded at once — may next be tried, by the kind of run: past the
     * window the last failed run of that kind looked at. A run that failed is not tried again over the
     * same bytes, however often a single entry read fast in between clears $fastMisses — or every entry
     * of a bad stretch would search the same kilobytes again. By kind, so that a run of a nested map's
     * members that fails does not keep the list around it from its own runs.
     *
     * @var array<string, int>
     */
    public array $noRunBefore = [];

    /** Null once the read is over, so a closed stream reads as end of file rather than as an error. */
    private JsonSourceInterface|null $source;

    /**
     * @param bool $adaptive grow the block for elements that do not fit in it, up to MAX_BLOCK_SIZE
     */
    public function __construct(
        JsonSourceInterface $source,
        private int $blockSize = self::BLOCK_SIZE,
        private readonly bool $adaptive = false,
    ) {
        $this->source = $source;
    }

    /**
     * The stream starts $offset bytes into the document — its source was positioned there, as an
     * index does — so that every offset it reports is the document's own. Before anything is read.
     */
    public function beginAt(int $offset): void
    {
        $this->bytesBeforeBuffer = $offset;
    }

    /**
     * An element of $bytes was just read the slow way — it did not fit, or straddled two blocks. An
     * adaptive stream reads on in blocks that hold ELEMENTS_PER_BLOCK of it — doubling, up to
     * MAX_BLOCK_SIZE — so that elements of the kind are read fast. The block in hand is kept; the next
     * read is larger. Small records never get here often enough, nor big enough, to move it.
     */
    public function fitElement(int $bytes): void
    {
        $wanted = $bytes * self::ELEMENTS_PER_BLOCK;
        if (!$this->adaptive || $wanted <= $this->blockSize || $this->blockSize >= self::MAX_BLOCK_SIZE) {
            return;
        }

        $size = $this->blockSize;
        while ($size < $wanted && $size < self::MAX_BLOCK_SIZE) {
            $size *= 2;
        }

        $this->blockSize = min($size, self::MAX_BLOCK_SIZE);
    }

    /**
     * The next block, or null at end of file.
     */
    public function readBlock(): string|null
    {
        return $this->source?->readBlock($this->blockSize);
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
        $this->nextHit = [];
        $this->fastMisses = 0;
        $this->noRunBefore = [];
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

    /**
     * Puts the pushed-back characters back in front of the block buffer, so a scanner that walks the
     * buffer directly sees them where they were. The byte offset is unchanged.
     */
    public function absorbPushBack(): void
    {
        if ($this->charBuffer === []) {
            return;
        }

        $offset = $this->offset();
        $this->nextHit = [];
        $this->fastMisses = 0;
        $this->noRunBefore = [];
        $this->buf = implode('', array_reverse($this->charBuffer)) . substr($this->buf, $this->bufPos);
        $this->bufLen = strlen($this->buf);
        $this->bufPos = 0;
        $this->bytesBeforeBuffer = $offset;
        $this->charBuffer = [];
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
