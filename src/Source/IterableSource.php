<?php

declare(strict_types=1);

namespace PhpJsonChunk\Source;

use ArrayIterator;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use PhpJsonChunk\Contract\JsonSourceInterface;

/**
 * Bytes that arrive in pieces: an HTTP client's stream of a response, a generator reading a socket or
 * a decompressor — anything that can be iterated for strings. The pieces are read as they come, one at
 * a time, never gathered; they can be any size, empty ones included.
 *
 * The iterable is walked once, in order. A read that stops early stops taking pieces.
 */
final class IterableSource implements JsonSourceInterface
{
    private Iterator|null $pieces;
    private bool $started = false;
    private string $pending = '';

    /**
     * @param iterable<mixed, string> $pieces
     */
    public function __construct(iterable $pieces, private readonly string $name = 'iterable')
    {
        $this->pieces = match (true) {
            $pieces instanceof Iterator => $pieces,
            $pieces instanceof IteratorAggregate => self::iteratorOf($pieces),
            default => new ArrayIterator((array)$pieces),
        };
    }

    public function readBlock(int $size): string|null
    {
        if ($size < 1) {
            return null;
        }

        while ($this->pending === '') {
            $piece = $this->nextPiece();
            if ($piece === null) {
                return null;
            }

            $this->pending = $piece;
        }

        // A piece bigger than the block asked for is handed over a block at a time.
        $block = substr($this->pending, 0, $size);
        $this->pending = (string)substr($this->pending, strlen($block));

        return $block;
    }

    public function describe(): string
    {
        return $this->name;
    }

    public function close(): void
    {
        $this->pieces = null;
        $this->pending = '';
    }

    /**
     * @throws InvalidArgumentException
     */
    private function nextPiece(): string|null
    {
        if ($this->pieces === null) {
            return null;
        }

        if ($this->started) {
            $this->pieces->next();
        } else {
            $this->pieces->rewind();
            $this->started = true;
        }

        if (!$this->pieces->valid()) {
            return null;
        }

        $piece = $this->pieces->current();
        if (!is_string($piece)) {
            throw new InvalidArgumentException(sprintf(
                'Source "%s" gave a piece of type %s; every piece must be a string.',
                $this->name,
                get_debug_type($piece),
            ));
        }

        return $piece;
    }

    /**
     * @param IteratorAggregate<mixed, string> $aggregate
     */
    private static function iteratorOf(IteratorAggregate $aggregate): Iterator
    {
        $iterator = $aggregate->getIterator();
        while ($iterator instanceof IteratorAggregate) {
            $iterator = $iterator->getIterator();
        }

        return $iterator instanceof Iterator ? $iterator : new ArrayIterator(iterator_to_array($iterator));
    }
}
