<?php

declare(strict_types=1);

namespace PhpJsonChunk\Source;

use PhpJsonChunk\Contract\JsonSourceInterface;
use SplFileObject;

/**
 * A file on disk — what every read used before there was anything else.
 *
 * `SplFileObject` rather than an `fopen()` handle for one reason: `resource` is not a type PHP can
 * declare, so a handle can only travel untyped behind a docblock. Reading a block through the object
 * costs the same.
 */
final class FileSource implements JsonSourceInterface
{
    private SplFileObject|null $file;

    public function __construct(SplFileObject $file, private readonly string $path)
    {
        $this->file = $file;
    }

    public function readBlock(int $size): string|null
    {
        if ($this->file === null || $size < 1 || $this->file->eof()) {
            return null;
        }

        $chunk = $this->file->fread($size);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        return $chunk;
    }

    public function describe(): string
    {
        return $this->path;
    }

    public function close(): void
    {
        // SplFileObject closes the file when the last reference to it goes.
        $this->file = null;
    }
}
