<?php

declare(strict_types=1);

namespace PhpJsonChunk\Internal;

use InvalidArgumentException;

/**
 * The source ended inside a value. Still an InvalidArgumentException to whoever reads — a document cut
 * off is not a document — but told apart inside, where an index of a growing file leaves out the
 * document a writer has not finished yet.
 *
 * @internal
 */
final class CutOffException extends InvalidArgumentException
{
}
