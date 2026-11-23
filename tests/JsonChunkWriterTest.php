<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use JsonException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\JsonChunkWriter;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The writer gives what json_encode() of the whole gives, byte for byte, an entry at a time; to a path,
 * whole or not at all.
 */
final class JsonChunkWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/writer_' . uniqid('', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ((array)glob($this->directory . '/{,.}*', GLOB_BRACE) as $file) {
            if (is_file((string)$file)) {
                unlink((string)$file);
            }
        }
        @rmdir($this->directory);
    }

    /**
     * @return array<int, mixed>
     */
    private static function entries(): array
    {
        mt_srand(424242);
        $entries = [];
        for ($i = 0; $i < 3000; $i++) {
            $entries[] = match ($i % 7) {
                0 => ['id' => $i, 'name' => "line\nbreak \"quoted\" é/€", 'tags' => ['a', 'b'], 'empty' => [], 'object' => new \stdClass()],
                1 => [$i, [$i, [$i]], null, true, 1.5],
                2 => 'text ' . str_repeat('x', $i % 100),
                3 => $i / 3,
                4 => ['nested' => ['deeper' => ['deepest' => [1, 2, ['k' => 'v']]]]],
                5 => [],
                default => null,
            };
        }

        return $entries;
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function flags(): iterable
    {
        yield 'compact' => [0];
        yield 'pretty' => [JSON_PRETTY_PRINT];
        yield 'pretty, unescaped' => [JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES];
        yield 'compact, unescaped' => [JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION];
    }

    #[DataProvider('flags')]
    public function testAListIsWhatJsonEncodeGives(int $flags): void
    {
        $entries = self::entries();
        $path = $this->directory . '/list.json';

        $count = (new JsonChunkWriter($flags))->writeArray($path, (static fn() => yield from $entries)());

        self::assertSame(3000, $count);
        self::assertSame(json_encode($entries, $flags), file_get_contents($path));
    }

    #[DataProvider('flags')]
    public function testAnObjectIsWhatJsonEncodeGives(int $flags): void
    {
        $members = [];
        foreach (self::entries() as $i => $entry) {
            $members['k' . $i . ($i % 11 === 0 ? ' "é"/' : '')] = $entry;
        }
        $members[42] = 'an integer key';
        $path = $this->directory . '/object.json';

        (new JsonChunkWriter($flags))->writeObject($path, (static fn() => yield from $members)());

        self::assertSame(json_encode((object)$members, $flags), file_get_contents($path));
    }

    /**
     * A generator may give a key twice, which no PHP array can hold: both members are written, as
     * they came — a batch that lost one to the array is written a member at a time.
     */
    public function testAKeyGivenTwiceIsWrittenTwice(): void
    {
        $members = (static function (): \Generator {
            yield 'a' => 1;
            yield 'b' => 2;
            yield 'a' => 3;
        })();
        $path = $this->directory . '/twice.json';

        self::assertSame(3, (new JsonChunkWriter())->writeObject($path, $members));
        self::assertSame('{"a":1,"b":2,"a":3}', file_get_contents($path));
    }

    #[DataProvider('flags')]
    public function testEmptyContainersAreWhatJsonEncodeGives(int $flags): void
    {
        $writer = new JsonChunkWriter($flags);
        $writer->writeArray($this->directory . '/a.json', []);
        $writer->writeObject($this->directory . '/o.json', []);

        self::assertSame(json_encode([], $flags), file_get_contents($this->directory . '/a.json'));
        self::assertSame(json_encode(new \stdClass(), $flags), file_get_contents($this->directory . '/o.json'));
    }

    public function testASequenceIsOneDocumentToALineAndReadsBack(): void
    {
        $entries = self::entries();
        $path = $this->directory . '/lines.ndjson';

        // Pretty-printing asked for, and not done: a document of several lines is not one to a line.
        (new JsonChunkWriter(JSON_PRETTY_PRINT))->writeValues($path, $entries);

        $lines = explode("\n", rtrim((string)file_get_contents($path), "\n"));
        self::assertCount(3000, $lines);
        self::assertSame(json_encode($entries[0]), $lines[0]);
        // Read back as objects, or an empty object would come back an empty list.
        self::assertSame(json_encode($entries), json_encode(iterator_to_array((new JsonChunkReader(false))->readValues($path), false)));
    }

    /**
     * Read raw, written raw: a compact document comes out as it went in — its large integers too,
     * which a decode and an encode would turn to floats.
     */
    public function testRawEntriesAreWrittenAsTheyAre(): void
    {
        $document = '[{"id":12345678901234567890,"price":1.10},{"id":2},[],"s",null]';
        $copy = $this->directory . '/copy.json';
        $reader = new JsonChunkReader();
        $writer = new JsonChunkWriter();

        $writer->writeArray($copy, $reader->readRaw(new StringSource($document)), raw: true);
        self::assertSame($document, file_get_contents($copy));

        $writer->writeValues($this->directory . '/copy.ndjson', $reader->readRaw(new StringSource($document)), raw: true);
        self::assertSame(['{"id":12345678901234567890,"price":1.10}', '{"id":2}', '[]', '"s"', 'null'], iterator_to_array($reader->readValuesRaw($this->directory . '/copy.ndjson')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry 1 is to be written raw');
        $writer->writeArray($this->directory . '/bad.json', ['{}', ['not' => 'a string']], raw: true);
    }

    /**
     * A failure on the way leaves the path as it was, and no temporary file behind.
     *
     * @return iterable<string, array{0: callable(): iterable<mixed>, 1: class-string<\Throwable>}>
     */
    public static function failures(): iterable
    {
        yield 'an exception from the iterable' => [static function (): iterable {
            for ($i = 0; $i < 100_000; $i++) {
                if ($i === 50_000) {
                    throw new RuntimeException('the source went away');
                }
                yield ['id' => $i];
            }
        }, RuntimeException::class];
        yield 'an entry json_encode() refuses' => [static fn(): iterable => [1, 2, INF, 4], JsonException::class];
        yield 'a string that is not UTF-8' => [static fn(): iterable => ['ok', "\xB1\x31"], JsonException::class];
    }

    /**
     * @param callable(): iterable<mixed> $entries
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('failures')]
    public function testAFailureLeavesThePathAsItWas(callable $entries, string $exception): void
    {
        $path = $this->directory . '/export.json';
        file_put_contents($path, '["the old export"]');

        try {
            (new JsonChunkWriter())->writeArray($path, $entries());
            self::fail('The write went through.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
        }

        self::assertSame('["the old export"]', file_get_contents($path));
        self::assertSame([$path], array_values(array_filter((array)glob($this->directory . '/{,.}*', GLOB_BRACE), 'is_file')));
    }

    public function testAnEntryThatCannotBeEncodedIsNamed(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('Entry 2 cannot be written as JSON: Inf and NaN cannot be JSON encoded');

        (new JsonChunkWriter())->writeArray($this->directory . '/x.json', [1, 2, INF]);
    }

    /**
     * A stream is written as it goes and left open: a gzipped file through PHP's zlib stream, read
     * back by the reader the same way.
     */
    public function testAStreamIsWrittenAndLeftOpen(): void
    {
        $entries = self::entries();
        $gz = $this->directory . '/export.json.gz';
        $out = fopen('compress.zlib://' . $gz, 'wb');
        self::assertIsResource($out);

        (new JsonChunkWriter())->writeArray($out, $entries);
        self::assertIsResource($out, 'the writer must not close a stream it was given');
        fclose($out);

        self::assertSame(json_encode($entries), gzdecode((string)file_get_contents($gz)));
    }

    /**
     * Found in review: the temporary file put in place came with the default permissions, and a file
     * only its owner could read came back readable by all.
     */
    public function testAFileReplacedKeepsItsPermissions(): void
    {
        $path = $this->directory . '/secret.json';
        file_put_contents($path, '[]');
        chmod($path, 0600);

        (new JsonChunkWriter())->writeArray($path, [1]);
        clearstatcache();

        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame('[1]', file_get_contents($path));
    }

    /**
     * Found in review: a path that was a link was replaced by a file, the link gone and the file it
     * named unchanged. It is written through now.
     */
    public function testALinkIsWrittenThrough(): void
    {
        $real = $this->directory . '/real.json';
        $link = $this->directory . '/link.json';
        file_put_contents($real, '[]');
        symlink($real, $link);

        (new JsonChunkWriter())->writeArray($link, [1, 2]);
        clearstatcache();

        self::assertTrue(is_link($link));
        self::assertSame('[1,2]', file_get_contents($real));

        unlink($link);
        symlink($this->directory . '/nothing.json', $link);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a link to nothing');
        (new JsonChunkWriter())->writeArray($link, [1]);
    }

    /**
     * Found in review: JSON_FORCE_OBJECT made a batch of a list an object, and its inside, cut out as
     * a list's, was not JSON. A list is written a list, the flag applying inside each entry.
     */
    public function testAListStaysAListUnderForceObject(): void
    {
        $path = $this->directory . '/forced.json';

        (new JsonChunkWriter(JSON_FORCE_OBJECT))->writeArray($path, [[1, 2], [3], 'x']);

        self::assertSame('[{"0":1,"1":2},{"0":3},"x"]', file_get_contents($path));
    }

    public function testAWrapperPathIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stream wrapper');

        (new JsonChunkWriter())->writeArray('compress.zlib://' . $this->directory . '/x.gz', [1]);
    }

    /**
     * What is written is never whole in memory: a hundred thousand entries, the memory the same as for
     * a hundred.
     */
    public function testMemoryDoesNotGrowWithWhatIsWritten(): void
    {
        $entries = static function (int $count): \Generator {
            for ($i = 0; $i < $count; $i++) {
                yield ['id' => $i, 'name' => 'record ' . $i, 'pad' => str_repeat('p', 100)];
            }
        };
        $writer = new JsonChunkWriter(JSON_PRETTY_PRINT);

        $writer->writeArray($this->directory . '/small.json', $entries(100));
        $before = memory_get_usage();
        $writer->writeArray($this->directory . '/large.json', $entries(100_000));

        self::assertGreaterThan(15_000_000, filesize($this->directory . '/large.json'));
        self::assertLessThan(256 * 1024, memory_get_usage() - $before);
    }
}
