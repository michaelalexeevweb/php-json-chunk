<?php

declare(strict_types=1);

namespace PhpJsonChunk\Contract;

/**
 * Where the bytes come from.
 *
 * The reader is strictly forward-only — it never seeks, and holds one block plus a single pushed-back
 * character — so anything able to hand over the next chunk of bytes can feed it. That was not obvious
 * from the outside, and this library said the opposite of itself for a while: it refused stream
 * wrappers on the grounds that it "seeks and re-reads within the file", which it never did.
 *
 * Implementations must be readable once, in order. Nothing asks them to rewind.
 */
interface JsonSourceInterface
{
    /**
     * The next block of at most $size bytes, or null once there are none left.
     */
    public function readBlock(int $size): string|null;

    /**
     * A name for this source, used in messages. A path where there is one; anything recognisable
     * otherwise.
     */
    public function describe(): string;

    /**
     * Releases whatever the source holds. Called exactly once, and never read from afterwards.
     */
    public function close(): void;
}
