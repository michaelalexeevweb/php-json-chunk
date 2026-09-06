<?php

declare(strict_types=1);

namespace PhpJsonChunk\Source;

use PhpJsonChunk\Contract\JsonSourceInterface;

/**
 * A document already in memory.
 *
 * There is nothing to stream away from here — the whole thing is held — so this buys no memory. What
 * it buys is the same reading, the same key paths and the same messages for a document that arrived
 * as a string, without writing it to a file first.
 */
final class StringSource implements JsonSourceInterface
{
    private int $position = 0;

    public function __construct(private readonly string $contents, private readonly string $name = 'string')
    {
    }

    public function readBlock(int $size): string|null
    {
        if ($this->position >= strlen($this->contents)) {
            return null;
        }

        $chunk = substr($this->contents, $this->position, $size);
        $this->position += strlen($chunk);

        return $chunk === '' ? null : $chunk;
    }

    public function describe(): string
    {
        return $this->name;
    }

    public function close(): void
    {
        $this->position = strlen($this->contents);
    }
}
