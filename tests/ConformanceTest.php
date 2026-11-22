<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every root-array document of JSONTestSuite — the corpus written to break JSON parsers — gets the
 * verdict json_decode() gives it, and an accepted one the same values.
 *
 * The README claimed this from a run by hand. The reads have since been made faster by finding an
 * element's end with strpos() and letting json_decode() settle it, which is exactly the kind of change
 * that could let a document through that json_decode() refuses — so the claim is a test now.
 */
final class ConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function documents(): iterable
    {
        $files = glob(__DIR__ . '/fixtures/json-test-suite/*.json');
        self::assertNotEmpty($files);

        foreach ((array)$files as $file) {
            yield basename((string)$file) => [(string)$file];
        }
    }

    #[DataProvider('documents')]
    public function testTheVerdictIsJsonDecodes(string $file): void
    {
        $bytes = (string)file_get_contents($file);

        try {
            $expected = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            $accepted = true;
        } catch (Throwable) {
            $expected = null;
            $accepted = false;
        }

        try {
            $values = iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($bytes)), false);
            $read = true;
        } catch (Throwable $exception) {
            $values = $exception::class . ': ' . $exception->getMessage();
            $read = false;
        }

        self::assertSame($accepted, $read, sprintf(
            'json_decode() %s it; this reader %s it%s',
            $accepted ? 'accepts' : 'refuses',
            $read ? 'accepts' : 'refuses',
            is_string($values) ? ' — ' . $values : '',
        ));

        if ($accepted) {
            self::assertSame(json_encode($expected), json_encode($values));
        }
    }
}
