<?php

declare(strict_types=1);

namespace PhpJsonChunk;

/**
 * How many bytes the source is read in at a time — the one setting that trades memory for speed.
 *
 * The block is the reader's memory: it holds one block, plus the element in hand. A smaller block
 * holds less. But an element is read the fast way — found and decoded in C — only when it ends within
 * the block in hand; an element bigger than the block is read by the PHP scanner, several times
 * slower. What decides is the size of ONE element, not of the file. So:
 *
 * - keep the default, Auto, unless there is a reason not to: it reads in 64 KB blocks and doubles the
 *   block whenever an element does not fit, up to 1 MB — small records never move it, large elements
 *   get the block they need;
 * - pick a fixed size to hold memory to a bound: 16 KB holds about a third less than 64 KB and reads
 *   small records a few percent slower;
 * - pick a larger fixed size when every element is known to be large, to skip the few slow elements
 *   Auto reads before it has grown.
 */
enum BlockSize: int
{
    /** 64 KB to start with, doubled for each element that does not fit, up to 1 MB. */
    case Auto = 0;
    case Kb8 = 8192;
    case Kb16 = 16384;
    case Kb32 = 32768;
    case Kb64 = 65536;
    case Kb128 = 131072;
    case Kb256 = 262144;
    case Kb512 = 524288;
    case Mb1 = 1048576;
}
