<?php

declare(strict_types=1);

namespace PhpJsonChunk;

/**
 * A place a JsonIndex remembers: entry number $entry starts at byte $byte of the file.
 */
final class Checkpoint
{
    public function __construct(
        public readonly int $entry,
        public readonly int $byte,
    ) {
    }
}
