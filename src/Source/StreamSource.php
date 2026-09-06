<?php

declare(strict_types=1);

namespace PhpJsonChunk\Source;

use InvalidArgumentException;
use PhpJsonChunk\Contract\JsonSourceInterface;

/**
 * Any PHP stream: an upload, a socket, `php://input`, the output of a pipe.
 *
 * The reader never seeks, so a stream that can only be read once is enough. The handle belongs to
 * whoever opened it — this does not close it, because a caller who passed `php://input` or a socket
 * rarely means to hand over ownership of it.
 */
final class StreamSource implements JsonSourceInterface
{
    /** @var resource|null */
    private $handle;

    /**
     * @param resource $handle
     */
    public function __construct($handle, private readonly string $name = 'stream')
    {
        if (!is_resource($handle)) {
            throw new InvalidArgumentException('A stream source needs an open stream resource.');
        }

        $this->handle = $handle;
    }

    public function readBlock(int $size): string|null
    {
        if ($this->handle === null || $size < 1) {
            return null;
        }

        $chunk = fread($this->handle, $size);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        return $chunk;
    }

    public function describe(): string
    {
        return $this->name;
    }

    public function close(): void
    {
        // Deliberately not fclose(): the caller opened it and may still want it.
        $this->handle = null;
    }
}
