<?php

declare(strict_types=1);

namespace PhpJsonChunk;

/**
 * How many bytes the source is read in at a time — the one setting that trades memory for speed.
 *
 * The block is the reader's memory: it holds one block, plus the element in hand. A smaller block
 * holds less. But an element is read the fast way — found and decoded in C — only when it ends within
 * the block in hand; an element bigger than the block is read by the PHP scanner, several times
 * slower. So:
 *
 * - keep the default, 64 KB, unless there is a reason not to;
 * - go smaller to shave memory when every element is small: 16 KB holds about a third less, and reads
 *   small records a few percent slower;
 * - go larger when elements are large — tens or hundreds of kilobytes each — so that they fit in a
 *   block and are read fast, at the cost of that much more memory.
 */
enum BlockSize: int
{
    case Kb8 = 8192;
    case Kb16 = 16384;
    case Kb32 = 32768;
    case Kb64 = 65536;
    case Kb128 = 131072;
    case Kb256 = 262144;
    case Kb512 = 524288;
    case Mb1 = 1048576;
}
