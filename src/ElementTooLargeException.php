<?php

declare(strict_types=1);

namespace PhpJsonChunk;

use RuntimeException;

/**
 * An element was bigger than the reader's `maxElementBytes`, and was refused while it was being read —
 * before it was all in memory. The document is not broken; it holds more in one element than the
 * reader was allowed to take.
 */
final class ElementTooLargeException extends RuntimeException
{
}
