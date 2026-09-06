<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\Contract\JsonSourceInterface;

/**
 * A source that remembers how often it was asked for bytes.
 *
 * Whether a read is one pass or two cannot be seen in the values — they are identical either way,
 * which is exactly how a second traversal goes unnoticed. Counting the blocks is what tells them
 * apart.
 */
final class CountingJsonSource implements JsonSourceInterface
{
    public int $blocksRead = 0;

    private int $position = 0;

    public function __construct(private readonly string $contents)
    {
    }

    public function readBlock(int $size): string|null
    {
        if ($size < 1 || $this->position >= strlen($this->contents)) {
            return null;
        }

        $this->blocksRead++;
        $chunk = substr($this->contents, $this->position, $size);
        $this->position += strlen($chunk);

        return $chunk === '' ? null : $chunk;
    }

    public function describe(): string
    {
        return 'counting source';
    }

    public function close(): void
    {
        $this->position = strlen($this->contents);
    }
}
