<?php

declare(strict_types=1);

namespace PhpJsonChunk\Source;

use InvalidArgumentException;
use PhpJsonChunk\Contract\JsonSourceInterface;

/**
 * A PSR-7 stream — the body of a Guzzle or Symfony HTTP response, an uploaded file — read as it
 * arrives, never whole.
 *
 * Takes any object with PSR-7's read() and eof(), so the library needs no PSR package. Like
 * StreamSource, it does not close the stream: the response belongs to whoever made the request.
 */
final class Psr7Source implements JsonSourceInterface
{
    /** @var (\Closure(int): mixed)|null */
    private \Closure|null $read;

    /** @var \Closure(): mixed */
    private \Closure $eof;

    /**
     * @param object $stream a Psr\Http\Message\StreamInterface
     */
    public function __construct(object $stream, private readonly string $name = 'PSR-7 stream')
    {
        if (!method_exists($stream, 'read') || !method_exists($stream, 'eof')) {
            throw new InvalidArgumentException(sprintf('A PSR-7 source needs a stream with read() and eof(); %s has not.', $stream::class));
        }

        $this->read = \Closure::fromCallable([$stream, 'read']);
        $this->eof = \Closure::fromCallable([$stream, 'eof']);
    }

    public function readBlock(int $size): string|null
    {
        $read = $this->read;
        if ($read === null || $size < 1 || ($this->eof)() === true) {
            return null;
        }

        $chunk = $read($size);

        // An empty read before the end is a stream with nothing yet: for a blocking body, the end.
        return is_string($chunk) && $chunk !== '' ? $chunk : null;
    }

    public function describe(): string
    {
        return $this->name;
    }

    public function close(): void
    {
        // Deliberately not $stream->close(): the caller's response, which it may still want.
        $this->read = null;
    }
}
