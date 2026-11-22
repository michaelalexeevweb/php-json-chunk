<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\FileSource;
use PhpJsonChunk\Source\ProgressSource;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\TestCase;
use SplFileObject;

/**
 * A long read can say how far it has got. The reader keeps nothing about a read on itself, so the
 * count lives on the source: it is told about every block the reader takes, which costs the parse
 * nothing and is as fine-grained as a progress bar needs.
 */
final class ProgressSourceTest extends TestCase
{
    public function testEveryBlockReportsTheBytesReadSoFar(): void
    {
        $json = '[' . implode(',', array_fill(0, 30_000, '{"id":12345,"name":"item"}')) . ']';
        $reports = [];
        $source = new ProgressSource(
            new StringSource($json),
            static function (int $bytesRead, int|null $totalBytes) use (&$reports): void {
                $reports[] = [$bytesRead, $totalBytes];
            },
            strlen($json),
        );

        self::assertSame(30_000, (new JsonChunkReader())->count($source));

        self::assertGreaterThan(1, count($reports));
        self::assertSame([strlen($json), strlen($json)], $reports[count($reports) - 1]);
        $previous = 0;
        foreach ($reports as [$bytesRead, $totalBytes]) {
            self::assertGreaterThan($previous, $bytesRead);
            self::assertSame(strlen($json), $totalBytes);
            $previous = $bytesRead;
        }
    }

    public function testTheTotalIsOptional(): void
    {
        $totals = [];
        $source = new ProgressSource(new StringSource('[1,2,3]'), static function (int $bytesRead, int|null $totalBytes) use (&$totals): void {
            $totals[] = $totalBytes;
        });

        self::assertSame([1, 2, 3], (new JsonChunkReader())->read($source)[0]);
        self::assertSame([null], $totals);
    }

    public function testAReadThatStopsEarlyReportsOnlyWhatItRead(): void
    {
        $json = '[' . implode(',', array_fill(0, 30_000, '{"id":12345,"name":"item"}')) . ']';
        $last = 0;
        $source = new ProgressSource(new StringSource($json), static function (int $bytesRead) use (&$last): void {
            $last = $bytesRead;
        });

        self::assertSame(['id' => 12345, 'name' => 'item'], (new JsonChunkReader())->getFirst($source));
        self::assertLessThan(strlen($json), $last);
    }

    public function testItWrapsAFileAndNamesIt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'progress_');
        self::assertIsString($path);
        file_put_contents($path, '{"items":[1,2]}');

        try {
            $seen = 0;
            $source = new ProgressSource(
                new FileSource(new SplFileObject($path, 'rb'), $path),
                static function (int $bytesRead) use (&$seen): void {
                    $seen = $bytesRead;
                },
                (int)filesize($path),
            );

            self::assertSame($path, $source->describe());
            self::assertSame([1, 2], iterator_to_array((new JsonChunkReader())->readGenerator($source, keyPath: 'items')));
            self::assertSame(15, $seen);
        } finally {
            unlink($path);
        }
    }
}
