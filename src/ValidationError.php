<?php

declare(strict_types=1);

namespace PhpJsonChunk;

use Throwable;

/**
 * Why a document is not valid JSON, from JsonChunkReader::validate() — what reading it would have
 * thrown, with the byte it went wrong at.
 */
final class ValidationError
{
    /**
     * @param string $message what reading it would have said, the file and the byte included
     * @param string $reason the complaint alone: `Syntax error`, `unexpected end of input in string.`
     * @param int|null $byte where it went wrong, when the reader could tell
     */
    public function __construct(
        public readonly string $message,
        public readonly string $reason,
        public readonly int|null $byte,
    ) {
    }

    /**
     * From what a read threw. The reader words where it went wrong as `… at byte N: reason`, and
     * nothing else does; a message without it has no byte.
     *
     * @internal
     */
    public static function fromException(Throwable $exception): self
    {
        $message = $exception->getMessage();
        if (preg_match('/ at byte (\d+): (.*)$/s', $message, $where) === 1) {
            return new self($message, $where[2], (int)$where[1]);
        }

        // An element over maxElementBytes says how far it was read.
        if (preg_match('/ read up to byte (\d+) /', $message, $where) === 1) {
            return new self($message, $message, (int)$where[1]);
        }

        return new self($message, $message, null);
    }
}
