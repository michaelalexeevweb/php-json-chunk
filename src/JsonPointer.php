<?php

declare(strict_types=1);

namespace PhpJsonChunk;

use InvalidArgumentException;

/**
 * A JSON Pointer — RFC 6901, the path syntax of JsonMachine and most JSON tools — read into this
 * library's key path, as segments taken literally:
 *
 *     $reader->readGenerator($file, keyPath: JsonPointer::segments('/orders/-/sku'));
 *
 * - every `/` starts a segment; `~1` in one is a slash and `~0` a tilde, as the RFC has it;
 * - `-` is every element of a list, as JsonMachine reads it (the RFC uses it only for writing);
 * - `''` is the whole document.
 *
 * Two limits are this library's, not the pointer's. A segment `*` or `**` keeps its meaning here — a
 * wildcard — so a member literally named `*` cannot be reached, by a pointer or otherwise. And `-` is
 * read as every element wherever it stands: the RFC gives it that meaning only in a list, so on an
 * object it would name a member called `-` — here it walks every member instead, and such a member
 * cannot be reached on its own.
 */
final class JsonPointer
{
    /**
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     */
    public static function segments(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        if ($pointer[0] !== '/') {
            throw new InvalidArgumentException(sprintf('JSON Pointer "%s" must be empty or start with "/".', $pointer));
        }

        $segments = [];
        foreach (explode('/', substr($pointer, 1)) as $token) {
            if (preg_match('/~(?![01])/', $token) === 1) {
                throw new InvalidArgumentException(sprintf(
                    'JSON Pointer "%s" has a "~" that is not "~0" or "~1".',
                    $pointer,
                ));
            }

            // ~1 first, then ~0: "~01" is a tilde and a one, never a slash.
            $segment = str_replace(['~1', '~0'], ['/', '~'], $token);
            $segments[] = $segment === '-' ? '*' : $segment;
        }

        return $segments;
    }
}
