<?php

declare(strict_types=1);

namespace PhpJsonChunk\Internal;

use PhpJsonChunk\Contract\JsonSourceInterface;

/**
 * A source that keeps its first blocks, for JsonChunkReader::validate() to read a root that is not a
 * container whole — from a stream too, which cannot be read again. Its close() leaves the source open;
 * release() closes it.
 *
 * @internal
 */
final class ReplayingSource implements JsonSourceInterface
{
    /** @var array<int, string> */
    private array $kept = [];

    public function __construct(private readonly JsonSourceInterface $inner)
    {
    }

    public function readBlock(int $size): string|null
    {
        $block = $this->inner->readBlock($size);
        // The root is known after the first block: no more is kept than two.
        if ($block !== null && count($this->kept) < 2) {
            $this->kept[] = $block;
        }

        return $block;
    }

    public function describe(): string
    {
        return $this->inner->describe();
    }

    public function close(): void
    {
    }

    /**
     * What was kept, and the rest of the source after it.
     */
    public function replay(): string
    {
        $whole = implode('', $this->kept);
        while (($block = $this->inner->readBlock(JsonStream::BLOCK_SIZE)) !== null) {
            $whole .= $block;
        }

        return $whole;
    }

    public function release(): void
    {
        $this->inner->close();
    }
}
