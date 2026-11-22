<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\ElementTooLargeException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\TestCase;

/**
 * A document from someone else can hold one element bigger than the memory there is: a 500 MB string
 * is still one element, and reading it whole ends PHP with a fatal error nothing can catch. With
 * `maxElementBytes` such an element is refused with an exception while it is being read — before it is
 * all in memory.
 */
final class MaxElementBytesTest extends TestCase
{
    public function testThereIsNoLimitUnlessOneIsGiven(): void
    {
        $document = '[' . json_encode(str_repeat('x', 300_000)) . ',1]';

        self::assertCount(2, iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document))));
    }

    public function testAnElementWithinTheLimitIsRead(): void
    {
        $records = [];
        for ($i = 0; $i < 2000; $i++) {
            $records[] = ['id' => $i, 'text' => str_repeat('y', $i % 300)];
        }
        $document = (string)json_encode($records);

        foreach ([400, 1000, 100_000] as $limit) {
            self::assertSame($records, iterator_to_array((new JsonChunkReader(maxElementBytes: $limit))->readGenerator(new StringSource($document))), (string)$limit);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string|array<int, string>|null}>
     */
    public static function tooLarge(): iterable
    {
        yield 'a long string' => ['[1,' . json_encode(str_repeat('x', 5000)) . ',2]', null];
        yield 'a long number' => ['[1,' . str_repeat('9', 5000) . ',2]', null];
        yield 'an object with many members' => ['[1,{' . rtrim(str_repeat('"k":1,', 1000), ',') . '},2]', null];
        yield 'an object of nested objects' => ['[1,' . json_encode(array_fill(0, 300, ['a' => ['b' => 1]])) . ',2]', null];
        yield 'a long key on the path' => ['{' . json_encode(str_repeat('k', 5000)) . ':1,"t":[1]}', 't'];
        yield 'a value under a wildcard' => ['{"a":[{"v":' . json_encode(str_repeat('x', 5000)) . '}]}', 'a.*.v'];
        yield 'a value under **' => ['{"a":{"b":{"v":' . json_encode(str_repeat('x', 5000)) . '}}}', '**.v'];
    }

    /**
     * @param string|array<int, string>|null $keyPath
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tooLarge')]
    public function testAnElementOverTheLimitIsRefused(string $document, string|array|null $keyPath): void
    {
        $this->expectException(ElementTooLargeException::class);
        $this->expectExceptionMessage('maxElementBytes');

        iterator_to_array((new JsonChunkReader(maxElementBytes: 1000))->readGenerator(new StringSource($document), keyPath: $keyPath), false);
    }

    public function testGetValueIsLimitedToo(): void
    {
        $this->expectException(ElementTooLargeException::class);

        (new JsonChunkReader(maxElementBytes: 1000))->getValue(new StringSource('{"v":' . json_encode(str_repeat('x', 5000)) . '}'), 'v');
    }

    /**
     * A branch the path goes past is skipped without being held, so its size costs nothing and is not
     * limited.
     */
    public function testABranchThePathSkipsIsNotLimited(): void
    {
        $document = '{"skip":' . json_encode(str_repeat('x', 50_000)) . ',"also":' . json_encode(array_fill(0, 2000, ['k' => 'v'])) . ',"t":[1,2]}';

        self::assertSame([1, 2], iterator_to_array((new JsonChunkReader(maxElementBytes: 1000))->readGenerator(new StringSource($document), keyPath: 't')));
    }

    /**
     * The point of the option: the refusal comes while the element is being read, so memory stays far
     * below the element's size.
     */
    public function testTheRefusalComesBeforeTheElementIsInMemory(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'max_element_');
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        fwrite($handle, '[1,"');
        $chunk = str_repeat('x', 1_048_576);
        for ($i = 0; $i < 40; $i++) {
            fwrite($handle, $chunk);
        }
        fwrite($handle, '",2]');
        fclose($handle);
        unset($chunk);

        try {
            gc_collect_cycles();
            // memory_reset_peak_usage() is PHP 8.2's; without it the peak is the whole run's, and says
            // nothing about this read — so on 8.1 only the refusal is checked.
            $measurable = function_exists('memory_reset_peak_usage');
            if ($measurable) {
                memory_reset_peak_usage();
            }
            $before = memory_get_usage();
            try {
                iterator_to_array((new JsonChunkReader(maxElementBytes: 1_000_000))->readGenerator($path), false);
                self::fail('no exception');
            } catch (ElementTooLargeException) {
            }

            if ($measurable) {
                self::assertLessThan(8 * 1_048_576, memory_get_peak_usage() - $before, 'a 40 MB element must not be read whole');
            }
        } finally {
            unlink($path);
        }
    }

    public function testTheLimitMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonChunkReader(maxElementBytes: 0);
    }

    public function testATempChunkDirectoryMustNotBeAStreamWrapper(): void
    {
        foreach (['phar:///tmp/x', 'PHAR:///tmp/x', 'php://memory', 'data:,x', 'ftp://host/dir'] as $directory) {
            try {
                iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource('[1,2]'), chunkSize: 1, tempChunkDir: $directory), false);
                self::fail($directory . ': no exception');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('stream wrapper', $exception->getMessage(), $directory);
            }
        }
    }
}
