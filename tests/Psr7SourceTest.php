<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\Psr7Source;
use PhpJsonChunk\Source\StreamSource;
use PHPUnit\Framework\TestCase;

/**
 * A PSR-7 stream — an HTTP response's body — read as it arrives; and a gzipped file, through PHP's
 * own zlib stream.
 */
final class Psr7SourceTest extends TestCase
{
    /**
     * Stands for a PSR-7 body: read() gives up to what is asked — less, at random, as a network does —
     * eof() says when it is done, and close() is counted, as the reader must not call it.
     */
    private function body(string $contents): object
    {
        return new class ($contents) {
            public int $closed = 0;
            private int $at = 0;

            public function __construct(private readonly string $contents)
            {
            }

            public function read(int $length): string
            {
                $take = min($length, random_int(1, 9000));
                $chunk = substr($this->contents, $this->at, $take);
                $this->at += strlen($chunk);

                return $chunk;
            }

            public function eof(): bool
            {
                return $this->at >= strlen($this->contents);
            }

            public function close(): void
            {
                $this->closed++;
            }
        };
    }

    public function testABodyIsReadAsItArrives(): void
    {
        $records = array_map(static fn(int $i): array => ['id' => $i, 's' => str_repeat('}{', $i % 30)], range(0, 4999));
        $body = $this->body((string)json_encode(['data' => $records]));

        self::assertSame($records, iterator_to_array((new JsonChunkReader())->readGenerator(new Psr7Source($body), keyPath: 'data')));
        self::assertSame(0, $body->closed, 'the body belongs to the response, not to the reader');
    }

    public function testABodyCanBeValidatedAndReadAsASequence(): void
    {
        $lines = implode("\n", array_map(static fn(int $i): string => '{"id":' . $i . '}', range(0, 999))) . "\n";

        self::assertNull((new JsonChunkReader())->validateValues(new Psr7Source($this->body($lines))));
        self::assertCount(1000, iterator_to_array((new JsonChunkReader())->readValues(new Psr7Source($this->body($lines)))));
    }

    public function testAnObjectThatIsNotAStreamIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('read() and eof()');

        new Psr7Source(new \stdClass());
    }

    /**
     * A gzipped file is read through PHP's zlib stream, handed over as a stream: the reader takes no
     * path that names a wrapper, compress.zlib:// among them.
     */
    public function testAGzippedFileIsReadThroughZlib(): void
    {
        if (!in_array('compress.zlib', stream_get_wrappers(), true)) {
            self::markTestSkipped('PHP has no zlib here.');
        }

        $records = array_map(static fn(int $i): array => ['id' => $i], range(0, 9999));
        $path = (string)tempnam(sys_get_temp_dir(), 'gz_') . '.json.gz';
        file_put_contents($path, (string)gzencode((string)json_encode($records)));

        try {
            $handle = fopen('compress.zlib://' . $path, 'rb');
            self::assertIsResource($handle);
            self::assertSame($records, iterator_to_array((new JsonChunkReader())->readGenerator(new StreamSource($handle))));
            fclose($handle);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('stream wrapper');
            iterator_to_array((new JsonChunkReader())->readGenerator('compress.zlib://' . $path));
        } finally {
            @unlink($path);
        }
    }
}
