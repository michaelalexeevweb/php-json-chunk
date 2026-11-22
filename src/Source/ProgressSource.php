<?php

declare(strict_types=1);

namespace PhpJsonChunk\Source;

use Closure;
use PhpJsonChunk\Contract\JsonSourceInterface;

/**
 * Any source, reporting how far a read has got.
 *
 * The reader keeps nothing about a read on itself, so there is no reader to ask for a position. The
 * source is where the bytes pass through anyway: this one hands each block on untouched and tells the
 * callback how many bytes have gone by. Once per block — 64 KB — is as often as a progress bar needs,
 * and it adds nothing to the parse itself.
 *
 * The count is of bytes taken from the source, so it runs up to one block ahead of the item being
 * read; a read that stops early reports only what it took.
 */
final class ProgressSource implements JsonSourceInterface
{
    /** @var Closure(int, int|null): mixed */
    private readonly Closure $onProgress;
    private int $bytesRead = 0;

    /**
     * @param callable(int $bytesRead, int|null $totalBytes): mixed $onProgress
     * @param int|null $totalBytes passed back as it is — `filesize()`, a `Content-Length` — for a percentage
     */
    public function __construct(
        private readonly JsonSourceInterface $source,
        callable $onProgress,
        private readonly int|null $totalBytes = null,
    ) {
        $this->onProgress = $onProgress(...);
    }

    public function readBlock(int $size): string|null
    {
        $block = $this->source->readBlock($size);
        if ($block === null) {
            return null;
        }

        $this->bytesRead += strlen($block);
        ($this->onProgress)($this->bytesRead, $this->totalBytes);

        return $block;
    }

    public function describe(): string
    {
        return $this->source->describe();
    }

    public function close(): void
    {
        $this->source->close();
    }
}
