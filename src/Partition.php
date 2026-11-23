<?php

declare(strict_types=1);

namespace PhpJsonChunk;

/**
 * One part of an indexed container, from JsonIndex::partitions(): $limit consecutive entries from
 * entry number $offset, to be read with JsonChunkReader::readFrom($index, $offset, $limit).
 */
final class Partition
{
    /**
     * @param int $number the part's place among the parts, from 0
     * @param int $offset the first entry's number
     * @param int $limit how many entries, never fewer than one
     */
    public function __construct(
        public readonly int $number,
        public readonly int $offset,
        public readonly int $limit,
    ) {
    }
}
