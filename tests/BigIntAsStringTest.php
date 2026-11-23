<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\TestCase;

/**
 * An integer bigger than PHP's — an id of 20 digits, an amount in the smallest unit — becomes a float
 * when decoded, and its last digits are gone. With `bigIntAsString` it comes back as a string holding
 * every digit, through every way the reader decodes.
 */
final class BigIntAsStringTest extends TestCase
{
    private const BIG = '12345678901234567890';

    public function testWithoutTheOptionABigIntegerBecomesAFloat(): void
    {
        $value = (new JsonChunkReader())->getFirst(new StringSource('[' . self::BIG . ']'));

        self::assertIsFloat($value);
        self::assertNotSame(self::BIG, number_format($value, 0, '', ''));
    }

    public function testEveryReadKeepsEveryDigit(): void
    {
        $records = [];
        for ($i = 0; $i < 3000; $i++) {
            $records[] = '{"id":' . self::BIG . $i % 10 . ',"n":' . $i . ',"tags":[' . self::BIG . ']}';
        }
        $list = '{"users":[' . implode(',', $records) . '],"total":' . self::BIG . '}';
        $map = '{' . implode(',', array_map(static fn(int $i, string $r): string => '"k' . $i . '":' . $r, array_keys($records), $records)) . '}';
        $reader = new JsonChunkReader(bigIntAsString: true);
        $ids = array_map(static fn(int $i): string => self::BIG . $i % 10, range(0, 2999));

        self::assertSame(self::BIG . '0', $reader->getFirst(new StringSource($list), 'users')['id']);
        self::assertSame($ids, array_column(iterator_to_array($reader->readGenerator(new StringSource($list), keyPath: 'users'), false), 'id'));
        self::assertSame($ids, iterator_to_array($reader->readGenerator(new StringSource($list), keyPath: 'users.*.id'), false));
        self::assertSame($ids, iterator_to_array($reader->readGenerator(new StringSource($list), keyPath: '**.id'), false));
        self::assertSame($ids, array_column(iterator_to_array($reader->readGenerator(new StringSource($map)), false), 'id'));
        self::assertSame($ids, iterator_to_array($reader->readGenerator(new StringSource($map), keyPath: '*.id'), false));
        self::assertSame(self::BIG, $reader->getValue(new StringSource($list), 'total'));
        self::assertSame([self::BIG], $reader->getValue(new StringSource($list), 'users.5.tags'));

        $paths = [];
        foreach ($reader->readPaths(new StringSource($list), ['total', 'users.0.id']) as $path => $value) {
            $paths[$path] = $value;
        }
        self::assertSame(['users.0.id' => self::BIG . '0', 'total' => self::BIG], $paths);

        $objects = (new JsonChunkReader(false, bigIntAsString: true))->getFirst(new StringSource($list), 'users');
        self::assertSame(self::BIG . '0', $objects->id);
    }

    public function testTemporaryChunksKeepEveryDigit(): void
    {
        $directory = sys_get_temp_dir() . '/bigint_' . uniqid('', true);
        try {
            $chunks = (new JsonChunkReader(bigIntAsString: true))->read(
                new StringSource('[' . self::BIG . ',1,' . self::BIG . '1]'),
                chunkSize: 2,
                tempChunkDir: $directory,
            );
            self::assertSame([[self::BIG, 1], [self::BIG . '1']], $chunks);
        } finally {
            foreach ((array)glob($directory . '/*') as $file) {
                @unlink((string)$file);
            }
            @rmdir($directory);
        }
    }

    public function testOrdinaryNumbersAreUntouched(): void
    {
        $values = iterator_to_array((new JsonChunkReader(bigIntAsString: true))->readGenerator(new StringSource('[1,-7,9223372036854775807,1.5,1e3,-0.0]')), false);

        self::assertSame([1, -7, PHP_INT_MAX, 1.5, 1000.0, -0.0], $values);
    }
}
