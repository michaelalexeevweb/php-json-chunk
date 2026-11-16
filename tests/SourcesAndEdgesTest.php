<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\FileSource;
use PhpJsonChunk\Source\StreamSource;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\TestCase;
use SplFileObject;
use RuntimeException;

/**
 * The edges no read through a well-formed document reaches: the sources' own refusals and the
 * reader's answers to an empty target or an unusable temporary directory — each three levels down.
 */
final class SourcesAndEdgesTest extends TestCase
{
    public function testAStreamSourceNeedsAnOpenStream(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamSource('not a stream');
    }

    public function testAStreamSourceReadsNestedDataAndStopsAtItsEnd(): void
    {
        $handle = fopen('php://memory', 'r+b');
        self::assertIsResource($handle);
        fwrite($handle, '{"a":{"b":{"c":[{"d":[1,{"e":2}]}]}}}');
        rewind($handle);
        $source = new StreamSource($handle, 'memory');

        self::assertSame([['d' => [1, ['e' => 2]]]], (new JsonChunkReader())->read($source, keyPath: 'a.b.c')[0]);
        self::assertNull($source->readBlock(0), 'nothing is read for a zero-sized block');
        self::assertNull($source->readBlock(16), 'nothing is left');
        self::assertSame('memory', $source->describe());
        fclose($handle);
    }

    public function testAFileSourceNamesItsPathInComplaints(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'php_json_chunk_');
        self::assertIsString($path);
        file_put_contents($path, '{"a":{"b":{"c":[1,}}}');
        try {
            self::assertSame($path, (new FileSource(new SplFileObject($path), $path))->describe());
            try {
                (new JsonChunkReader())->read($path, keyPath: 'a.b.c');
                self::fail('the broken list was expected to be refused');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($path, $exception->getMessage());
            }
        } finally {
            unlink($path);
        }
    }

    public function testFirstOfAnEmptyNestedListIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is empty');
        (new JsonChunkReader())->getFirst(new StringSource('{"a":{"b":{"c":[]}}}', 'empty'), 'a.b.c');
    }

    public function testATemporaryDirectoryThatCannotBeCreatedIsReported(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'php_json_chunk_');
        self::assertIsString($file);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to create temporary chunk directory');
            iterator_to_array((new JsonChunkReader())->readGenerator(
                new StringSource('{"a":{"b":{"c":[1,2]}}}'),
                keyPath: 'a.b.c',
                tempChunkDir: $file . '/below-a-file',
            ));
        } finally {
            unlink($file);
        }
    }
}
