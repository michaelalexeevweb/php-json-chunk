<?php

declare(strict_types=1);

namespace PhpJsonChunk;

/**
 * An element json_decode() refused, handed to the reader's `onInvalidItem` handler instead of ending
 * the read.
 */
final class InvalidItem
{
    /**
     * @param string $json the element's raw bytes, as they stand in the document
     * @param string $reason json_decode()'s own complaint
     * @param int $offset the byte the element starts at
     * @param int|string|null $key its index in a list or its name in an object; null under a wildcard
     *                             or in readPaths(), where no single container is being walked
     */
    public function __construct(
        public readonly string $json,
        public readonly string $reason,
        public readonly int $offset,
        public readonly int|string|null $key,
    ) {
    }
}
